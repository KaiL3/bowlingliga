<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\Operations;

use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Building blocks of the server installation (deploy/install.sh): migration
 * runner and creating the first admin account from the command line – against
 * the test database.
 */
final class InstallationIntegrationTest extends TestCase
{
    private PDO $pdo;

    /** @var array<string, mixed> */
    private array $configuration;

    protected function setUp(): void
    {
        if (! in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The PDO MySQL driver is not available.');
        }

        $this->configuration = [
            'host'            => self::env('BOWLINGLIGA_TEST_DB_HOST', '127.0.0.1'),
            'port'            => (int) self::env('BOWLINGLIGA_TEST_DB_PORT', '3306'),
            'dbname'          => self::env('BOWLINGLIGA_TEST_DB_NAME', 'bowlingliga_test'),
            'user'            => self::env('BOWLINGLIGA_TEST_DB_USER', 'root'),
            'password'        => self::env('BOWLINGLIGA_TEST_DB_PASSWORD', ''),
            'charset'         => 'utf8mb4',
            'connect_timeout' => 5,
            'totp_key' => base64_encode(random_bytes(32)),
        ];

        try {
            $this->pdo = (new Database($this->configuration))->pdo();
        } catch (Throwable $e) {
            self::markTestSkipped('No reachable test database: ' . $e->getMessage());
        }

        $this->emptyDatabase();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->emptyDatabase();
        }
    }

    public function testFreshDatabaseGetsAllMigrationsExactlyOnce(): void
    {
        $runner = new MigrationRunner($this->pdo, $this->migrations());

        $fresh = $runner->run();

        self::assertSame(array_map('basename', $this->migrationFiles()), $fresh);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'lineup' AND column_name = 'is_blind'")->fetchColumn());
        self::assertSame([], $runner->run(), 'A second run applies nothing again.');
    }

    public function testLegacyDatabaseWithoutLogIsSafelyBroughtUpToDate(): void
    {
        // Database from before the migration runner: schema present, log missing.
        foreach ($this->migrationFiles() as $file) {
            $this->pdo->exec((string) file_get_contents($file));
        }

        $fresh = (new MigrationRunner($this->pdo, $this->migrations()))->run();

        self::assertCount(count($this->migrationFiles()), $fresh, 'All migrations are repeatable and are only recorded.');
    }

    public function testFirstAdminAccountViaCommandLine(): void
    {
        (new MigrationRunner($this->pdo, $this->migrations()))->run();
        $configFilePath = tempnam(sys_get_temp_dir(), 'mlkonfig') . '.php';
        file_put_contents($configFilePath, '<?php return ' . var_export($this->configuration, true) . ';');

        try {
            [$code, $output] = $this->admin(['erster', '--only-if-empty'], $configFilePath, 'Sehr-Geheim-123');
            self::assertSame(0, $code, $output);
            self::assertStringContainsString('Admin account "erster" created', $output);

            [$code, $output] = $this->admin(['zweiter', '--only-if-empty'], $configFilePath, 'Sehr-Geheim-123');
            self::assertSame(0, $code);
            self::assertStringContainsString('There is an admin account already', $output);

            [$code] = $this->admin(['third'], $configFilePath, 'kurz');
            self::assertSame(1, $code, 'Passwords that are too short are rejected.');

            $accounts = $this->pdo->query('SELECT username, password_hash FROM admin_account')->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(1, $accounts);
            self::assertTrue(password_verify('Sehr-Geheim-123', $accounts[0]['password_hash']));
        } finally {
            unlink($configFilePath);
        }
    }

    /**
     * The demo league is built through the use cases on the current schema:
     * planned season, confirmed results of the past matchdays, frozen
     * handicaps; a second run refuses to touch existing data.
     */
    public function testDemoLeagueIsSeededOnAnEmptyDatabaseOnly(): void
    {
        (new MigrationRunner($this->pdo, $this->migrations()))->run();
        $configFilePath = tempnam(sys_get_temp_dir(), 'mlkonfig') . '.php';
        file_put_contents($configFilePath, '<?php return ' . var_export($this->configuration + ['language' => 'en'], true) . ';');

        try {
            [$code, $output] = $this->script('bin/seed-demo.php', $configFilePath);
            self::assertSame(0, $code, $output);

            $count = fn (string $sql): int => (int) $this->pdo->query($sql)->fetchColumn();
            self::assertSame(1, $count('SELECT COUNT(*) FROM season'));
            self::assertSame(6, $count('SELECT COUNT(*) FROM team_season WHERE is_ghost_team = 0'));
            self::assertSame(30, $count('SELECT COUNT(*) FROM roster'));
            self::assertSame(10, $count('SELECT COUNT(*) FROM matchday'));
            $played = $count('SELECT COUNT(*) FROM matchday WHERE is_played = 1');
            self::assertSame(6, $played, 'The season starts six weeks ago, weekly matchdays.');
            self::assertSame(3 * $played, $count("SELECT COUNT(*) FROM fixture WHERE completion_status = 'complete'"));
            self::assertSame(0, $count('SELECT COUNT(*) FROM fixture WHERE lane_pair_id IS NULL'), 'Every fixture has its lanes.');
            self::assertGreaterThan(0, $count('SELECT COUNT(*) FROM frozen_handicap'));
            self::assertSame(1, $count("SELECT COUNT(*) FROM home_page_post WHERE title = 'Welcome to the demo league'"), 'Texts in the default language.');

            [$code, $output] = $this->script('bin/seed-demo.php', $configFilePath);
            self::assertSame(1, $code);
            self::assertStringContainsString('already holds seasons', $output);
        } finally {
            unlink($configFilePath);
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function script(string $script, string $configFilePath): array
    {
        $environment = getenv();
        $environment['BOWLINGLIGA_CONFIG'] = $configFilePath;

        $process = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/' . $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return [proc_close($process), (string) $output];
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function admin(array $arguments, string $configFilePath, string $password): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 3) . '/bin/create-admin.php'], $arguments);
        $environment = getenv();
        $environment['BOWLINGLIGA_CONFIG'] = $configFilePath;
        $environment['BOWLINGLIGA_ADMIN_PASSWORD'] = $password;

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return [proc_close($process), (string) $output];
    }

    private function migrations(): string
    {
        return dirname(__DIR__, 3) . '/db/migrations';
    }

    /**
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $files = glob($this->migrations() . '/*.sql') ?: [];
        sort($files);

        return $files;
    }

    private function emptyDatabase(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private static function env(string $name, string $standard): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $standard : $value;
    }
}
