<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\Operations;

use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\FileSystemBlobStore;
use Bowlingliga\Persistence\LeagueDataTransfer;
use Bowlingliga\Persistence\MigrationRunner;
use Bowlingliga\Persistence\OriginalScoreSheetRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Transferring the data into another database (bin/transfer-data.php) – source
 * and target are the same test database one after another, each with its own
 * score sheet directory.
 */
final class LeagueDataTransferIntegrationTest extends TestCase
{
    private PDO $pdo;

    /** @var array<string, mixed> */
    private array $configuration;

    private FileSystemBlobStore $sourceFiles;

    private FileSystemBlobStore $targetFiles;

    /** Not valid UTF-8, must still arrive byte for byte. */
    private const PHOTO = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x80\x81\xFE";

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
        ];

        $base = sys_get_temp_dir() . '/ml-transfer-' . bin2hex(random_bytes(4));
        $this->sourceFiles = new FileSystemBlobStore($base . '/source');
        $this->targetFiles = new FileSystemBlobStore($base . '/target');
        $this->configuration['score_sheet_dir'] = $this->sourceFiles->directory();

        try {
            $this->pdo = (new Database($this->configuration))->pdo();
        } catch (Throwable $e) {
            self::markTestSkipped('No reachable test database: ' . $e->getMessage());
        }

        $this->dropSchema();
        $this->applySchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
        if (isset($this->sourceFiles)) {
            self::removeDirectory(dirname($this->sourceFiles->directory()));
        }
    }

    public function testImportRestoresTheExportedData(): void
    {
        $this->createRecord();
        $export = $this->viaJson((new LeagueDataTransfer($this->pdo, $this->sourceFiles))->export());
        $transfer = new LeagueDataTransfer($this->pdo, $this->targetFiles);

        self::assertArrayNotHasKey('admin_account', $export['tables']);
        self::assertArrayNotHasKey('password_history', $export['tables'], 'Password hashes never leave the installation (R23.18).');
        self::assertArrayNotHasKey('password_policy', $export['tables']);
        self::assertArrayNotHasKey('cache_league_table', $export['tables']);

        // The target has a different state, its own admin accounts, its own legal texts and caches.
        $this->pdo->exec("UPDATE player SET name = 'Geändert'");
        $this->pdo->exec("INSERT INTO player (id, name, gender) VALUES (99, 'Nur im Ziel', 'male')");
        $this->pdo->exec('DELETE FROM original_score_sheet');
        $this->pdo->exec("UPDATE legal_notice SET text = 'Impressum des Ziels'");
        $this->pdo->exec('DELETE FROM admin_account');
        $this->pdo->exec("INSERT INTO admin_account (username, password_hash) VALUES ('online', 'x')");
        $this->pdo->exec("INSERT INTO cache_league_table (cache_key, payload, raw_hash, computed_at)
            VALUES ('alt', '{}', 'h', NOW())");

        $inserted = $transfer->import($export, false);

        self::assertSame(2, $inserted['player']);
        self::assertSame(
            [['id' => 1, 'name' => 'Anna Müller'], ['id' => 2, 'name' => 'Bernd']],
            $this->pdo->query('SELECT id, name FROM player ORDER BY id')->fetchAll()
        );
        $hash = (string) $this->pdo->query('SELECT content_hash FROM original_score_sheet')->fetchColumn();
        self::assertSame(hash('sha256', self::PHOTO), $hash);
        self::assertSame(self::PHOTO, $this->targetFiles->read($hash), 'The score sheet file travels with the export into the target directory.');
        self::assertSame(['online'], $this->pdo->query('SELECT username FROM admin_account')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame('Impressum des Ziels', $this->pdo->query('SELECT text FROM legal_notice')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM cache_league_table')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn());

        $transfer->import($export, true);
        self::assertSame('Impressum der Demo', $this->pdo->query('SELECT text FROM legal_notice')->fetchColumn());
    }

    public function testPreviewCountsAndChangesNothing(): void
    {
        $this->createRecord();
        $transfer = new LeagueDataTransfer($this->pdo, $this->sourceFiles);
        $export = $this->viaJson($transfer->export());
        $this->pdo->exec("INSERT INTO player (id, name, gender) VALUES (99, 'Nur im Ziel', 'male')");

        $preview = $transfer->preview($export, false);

        self::assertSame(['before' => 3, 'after' => 2], $preview['player']);
        self::assertArrayNotHasKey('legal_notice', $preview);
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM player')->fetchColumn());
    }

    public function testDifferentSchemaIsRejectedWithoutChangingAnything(): void
    {
        $this->createRecord();
        $transfer = new LeagueDataTransfer($this->pdo, $this->sourceFiles);
        $export = $this->viaJson($transfer->export());
        $export['tables']['player']['columns'][] = 'gibt_es_nicht';

        try {
            $transfer->import($export, false);
            self::fail('An export with another schema must not be imported.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('player', $error->getMessage());
        }

        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM player')->fetchColumn());
    }

    public function testCommandLineExportsAndShowsOnlyThePreviewWithoutYes(): void
    {
        $this->createRecord();
        $configFilePath = tempnam(sys_get_temp_dir(), 'mlkonfig') . '.php';
        $exportFile = tempnam(sys_get_temp_dir(), 'mlexport') . '.json.gz';
        file_put_contents($configFilePath, '<?php return ' . var_export($this->configuration, true) . ';');

        try {
            [$code, $output] = $this->commandLine(['export', $exportFile], $configFilePath);
            self::assertSame(0, $code, $output);
            self::assertStringContainsString('Exported from', $output);

            $this->pdo->exec("UPDATE player SET name = 'Geändert'");
            [$code, $output] = $this->commandLine(['import', $exportFile], $configFilePath);
            self::assertSame(0, $code, $output);
            self::assertStringContainsString('Nothing changed', $output);
            self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM player WHERE name <> 'Geändert'")->fetchColumn());

            [$code, $output] = $this->commandLine(['import', $exportFile, '--yes'], $configFilePath);
            self::assertSame(0, $code, $output);
            self::assertSame('Anna Müller', $this->pdo->query('SELECT name FROM player WHERE id = 1')->fetchColumn());
        } finally {
            @unlink($configFilePath);
            @unlink($exportFile);
        }
    }

    public function testDamagedFileInTheExportIsRejectedWithoutChangingAnything(): void
    {
        $this->createRecord();
        $export = $this->viaJson((new LeagueDataTransfer($this->pdo, $this->sourceFiles))->export());
        $hash = array_key_first($export['files']);
        $export['files'][$hash] = base64_encode('verändert');

        try {
            (new LeagueDataTransfer($this->pdo, $this->targetFiles))->import($export, false);
            self::fail('A damaged file must not be imported.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Damaged score sheet file', $error->getMessage());
        }

        self::assertFalse($this->targetFiles->exists((string) $hash));
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM player')->fetchColumn());
    }

    public function testExportFailsIfAScoreSheetFileIsMissing(): void
    {
        $this->createRecord();
        $this->sourceFiles->delete(hash('sha256', self::PHOTO));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is missing in the score sheet directory');
        (new LeagueDataTransfer($this->pdo, $this->sourceFiles))->export();
    }

    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }

    private function createRecord(): void
    {
        $this->pdo->exec("INSERT INTO player (id, name, gender) VALUES (1, 'Anna Müller', 'female'), (2, 'Bernd', 'male')");
        $this->pdo->exec("INSERT INTO season (id, name, start_date, end_date) VALUES (1, 'Saison 2026/27', '2026-09-01', '2027-05-31')");
        $this->pdo->exec("INSERT INTO matchday (id, season_id, matchday_number, date) VALUES (1, 1, 1, '2026-09-02')");
        $this->pdo->exec('INSERT INTO fixture (id, matchday_id) VALUES (1, 1)');
        (new OriginalScoreSheetRepository(new Database($this->configuration), $this->sourceFiles))->save(1, self::PHOTO, 'image/jpeg');
        $this->pdo->exec("INSERT INTO legal_notice (text) VALUES ('Impressum der Demo')");
        $this->pdo->exec("INSERT INTO admin_account (username, password_hash) VALUES ('lokal', 'x')");
    }

    /**
     * As via the export file: once through JSON.
     *
     * @param array<string, mixed> $export
     *
     * @return array<string, mixed>
     */
    private function viaJson(array $export): array
    {
        return json_decode(json_encode($export, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function commandLine(array $arguments, string $configFilePath): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 3) . '/bin/transfer-data.php'], $arguments);
        $environment = getenv();
        $environment['BOWLINGLIGA_CONFIG'] = $configFilePath;

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return [proc_close($process), (string) $output];
    }

    private function applySchema(): void
    {
        (new MigrationRunner($this->pdo, dirname(__DIR__, 3) . '/db/migrations'))->run();
    }

    private function dropSchema(): void
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
