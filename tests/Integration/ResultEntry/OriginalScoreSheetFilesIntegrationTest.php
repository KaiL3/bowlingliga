<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\ResultEntry;

use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\FileSystemBlobStore;
use Bowlingliga\Persistence\MigrationRunner;
use Bowlingliga\Persistence\OriginalScoreSheetRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Original score sheets as files in the content-addressed directory (R3.15):
 * storing, deduplication, deleting, cleanup after a cascade and the check –
 * against the test database and a temporary directory.
 */
final class OriginalScoreSheetFilesIntegrationTest extends TestCase
{
    private const PHOTO = "\xFF\xD8\xFF\xE0\x00\x10JFIF Spielzettel \x80\x81";

    /** @var array<string, mixed> */
    private array $configuration;

    private PDO $pdo;

    private FileSystemBlobStore $files;

    private OriginalScoreSheetRepository $repository;

    protected function setUp(): void
    {
        if (! in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The PDO MySQL driver is not available.');
        }

        $this->files = new FileSystemBlobStore(sys_get_temp_dir() . '/ml-sheets-' . bin2hex(random_bytes(4)));
        $this->configuration = [
            'host'            => self::env('BOWLINGLIGA_TEST_DB_HOST', '127.0.0.1'),
            'port'            => (int) self::env('BOWLINGLIGA_TEST_DB_PORT', '3306'),
            'dbname'          => self::env('BOWLINGLIGA_TEST_DB_NAME', 'bowlingliga_test'),
            'user'            => self::env('BOWLINGLIGA_TEST_DB_USER', 'root'),
            'password'        => self::env('BOWLINGLIGA_TEST_DB_PASSWORD', ''),
            'charset'         => self::env('BOWLINGLIGA_TEST_DB_CHARSET', 'utf8mb4'),
            'connect_timeout' => 5,
            'score_sheet_dir' => $this->files->directory(),
        ];

        try {
            $database = new Database($this->configuration);
            $this->pdo = $database->pdo();
        } catch (Throwable $e) {
            self::markTestSkipped('No reachable test database: ' . $e->getMessage());
        }

        $this->dropSchema();
        (new MigrationRunner($this->pdo, dirname(__DIR__, 3) . '/db/migrations'))->run();
        $this->repository = new OriginalScoreSheetRepository($database, $this->files);

        $this->pdo->exec("INSERT INTO season (id, name, start_date, end_date) VALUES (1, 'S', '2030-09-01', '2031-05-31')");
        $this->pdo->exec("INSERT INTO matchday (id, season_id, matchday_number, date) VALUES (1, 1, 1, '2030-09-04')");
        $this->pdo->exec('INSERT INTO fixture (id, matchday_id) VALUES (1, 1), (2, 1)');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
        $directory = $this->files->directory();
        if (is_dir($directory)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }

    public function testUploadLiesAsFileAndOnlyItsMetadataInTheDatabase(): void
    {
        $id = $this->repository->save(1, self::PHOTO, 'image/jpeg');

        $row = $this->pdo->query("SELECT content_hash, size_bytes, uploaded_at FROM original_score_sheet WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(hash('sha256', self::PHOTO), $row['content_hash']);
        self::assertSame(strlen(self::PHOTO), (int) $row['size_bytes']);
        self::assertArrayNotHasKey('file', $row, 'The content is no longer stored in the database.');
        self::assertNotNull($row['uploaded_at']);

        $found = $this->repository->find($id);
        self::assertSame(self::PHOTO, $found['file'] ?? null, 'Unchanged (R3.15).');
        self::assertSame('image/jpeg', $found['mime_type']);
        self::assertArrayNotHasKey('file', $this->repository->findAllForFixture(1)[0], 'The list carries no content.');
    }

    public function testTheSameFileTwiceIsKeptOnceAndOnlyDeletedWithTheLastRecord(): void
    {
        $first  = $this->repository->save(1, self::PHOTO, 'image/jpeg');
        $second = $this->repository->save(2, self::PHOTO, 'image/jpeg');
        $hash = hash('sha256', self::PHOTO);
        self::assertSame([$hash], $this->files->hashesOlderThan(0));

        $this->repository->delete($first);
        self::assertTrue($this->files->exists($hash), 'Still referenced by the second record.');

        $this->repository->delete($second);
        self::assertFalse($this->files->exists($hash), 'Deleted for real once no record needs it.');
    }

    public function testFilesOfDeletedFixturesAreRemovedByTheCleanup(): void
    {
        $this->repository->save(1, self::PHOTO, 'image/jpeg');
        $kept = $this->repository->save(2, 'anderer Zettel', 'image/png');
        $this->pdo->exec('DELETE FROM fixture WHERE id = 1');

        self::assertSame(0, $this->repository->removeOrphanedFiles(), 'Within the grace period nothing is removed.');
        self::assertSame(1, $this->repository->removeOrphanedFiles(0));

        self::assertFalse($this->files->exists(hash('sha256', self::PHOTO)));
        self::assertSame('anderer Zettel', $this->repository->find($kept)['file'] ?? null);
    }

    public function testTheCheckReportsMissingAndDamagedFiles(): void
    {
        $missing = $this->repository->save(1, 'fehlt gleich', 'image/png');
        $damaged = $this->repository->save(2, 'wird verändert', 'image/png');
        $this->files->delete(hash('sha256', 'fehlt gleich'));
        $hash = hash('sha256', 'wird verändert');
        file_put_contents($this->files->directory() . '/objects/' . substr($hash, 0, 2) . '/' . substr($hash, 2), 'manipuliert');

        $result = $this->repository->verifyFiles();

        self::assertSame(2, $result['checked']);
        self::assertSame([$missing], $result['missing']);
        self::assertSame([$damaged], $result['damaged']);
        self::assertNull($this->repository->find($missing)['file'] ?? null);
    }

    public function testCheckScriptFailsOnMissingFilesAndCleansUp(): void
    {
        $this->repository->save(1, self::PHOTO, 'image/jpeg');
        $orphan = $this->files->put('niemand braucht mich');
        touch($this->files->directory() . '/objects/' . substr($orphan, 0, 2) . '/' . substr($orphan, 2), time() - 3600);

        [$code, $output] = $this->checkScript(['--clean']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Files no longer needed deleted: 1', $output);
        self::assertFalse($this->files->exists($orphan));

        $this->files->delete(hash('sha256', self::PHOTO));
        [$code, $output] = $this->checkScript([]);
        self::assertSame(1, $code, $output);
        self::assertStringContainsString('File missing', $output);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function checkScript(array $arguments): array
    {
        $configFilePath = tempnam(sys_get_temp_dir(), 'mlkonfig') . '.php';
        file_put_contents($configFilePath, '<?php return ' . var_export($this->configuration, true) . ';');
        $environment = getenv();
        $environment['BOWLINGLIGA_CONFIG'] = $configFilePath;

        try {
            $process = proc_open(
                array_merge([PHP_BINARY, dirname(__DIR__, 3) . '/bin/check-score-sheets.php'], $arguments),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $environment
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

            return [proc_close($process), (string) $output];
        } finally {
            @unlink($configFilePath);
        }
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
