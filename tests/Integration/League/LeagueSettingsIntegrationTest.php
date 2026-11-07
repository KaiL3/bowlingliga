<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\League;

use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\DatabaseConnectionException;
use Bowlingliga\Persistence\LeagueSettingsRepository;
use Bowlingliga\Persistence\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration test of {@see LeagueSettingsRepository} against the test
 * database, with the schema of all migrations.
 *
 * Validates: R38.1, R38.2, R40.1, R4.3
 */
final class LeagueSettingsIntegrationTest extends TestCase
{
    private PDO $pdo;
    private LeagueSettingsRepository $repository;

    protected function setUp(): void
    {
        $config = [
            'host'            => self::env('BOWLINGLIGA_TEST_DB_HOST', '127.0.0.1'),
            'port'            => (int) self::env('BOWLINGLIGA_TEST_DB_PORT', '3306'),
            'dbname'          => self::env('BOWLINGLIGA_TEST_DB_NAME', 'bowlingliga_test'),
            'user'            => self::env('BOWLINGLIGA_TEST_DB_USER', 'root'),
            'password'        => self::env('BOWLINGLIGA_TEST_DB_PASSWORD', ''),
            'charset'         => self::env('BOWLINGLIGA_TEST_DB_CHARSET', 'utf8mb4'),
            'connect_timeout' => 5,
        ];

        try {
            $database  = new Database($config);
            $this->pdo = $database->pdo();
        } catch (DatabaseConnectionException $e) {
            self::markTestSkipped('No reachable MySQL test database: ' . $e->getMessage());
        }

        $this->dropSchema();
        (new MigrationRunner($this->pdo, dirname(__DIR__, 3) . '/db/migrations'))->run();
        $this->repository = new LeagueSettingsRepository($database);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
    }

    public function testMigrationStartsWithTheDefaultName(): void
    {
        self::assertSame('Bowlingliga', $this->repository->leagueName());
    }

    public function testSavingReplacesTheSingleRow(): void
    {
        $this->repository->save('Bowlingliga Musterstadt', false);
        $this->repository->save('Pin & Spare Liga', false);

        self::assertSame('Pin & Spare Liga', $this->repository->leagueName());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM league_setting')->fetchColumn());
    }

    public function testSavingRecreatesAMissingRow(): void
    {
        $this->pdo->exec('DELETE FROM league_setting');
        self::assertNull($this->repository->leagueName());

        $this->repository->save('Bowlingliga Musterstadt', false);

        self::assertSame('Bowlingliga Musterstadt', $this->repository->leagueName());
    }

    public function testTheMigrationIsRepeatableAndKeepsAChangedName(): void
    {
        $this->repository->save('Bowlingliga Musterstadt', false);

        $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 3) . '/db/migrations/001_schema.sql'));

        self::assertSame('Bowlingliga Musterstadt', $this->repository->leagueName());
    }

    public function testANewInstallationKeepsPlayerDataPrivate(): void
    {
        self::assertFalse($this->repository->playersPublic());

        $this->repository->save('Bowlingliga', true);
        self::assertTrue($this->repository->playersPublic());
        self::assertSame('Bowlingliga', $this->repository->leagueName());

        $this->repository->save('Bowlingliga', false);
        self::assertFalse($this->repository->playersPublic());
    }

    public function testTheMigrationMakesPlayerDataPublicForAnInstallationWithSeasons(): void
    {
        // State before migration 003: the column does not exist yet, but seasons do.
        $this->pdo->exec('ALTER TABLE league_setting DROP COLUMN players_public');
        $this->pdo->exec("INSERT INTO season (name, start_date, end_date) VALUES ('2025/26', '2025-09-01', '2026-06-30')");

        $migration = (string) file_get_contents(dirname(__DIR__, 3) . '/db/migrations/003_players_public.sql');
        $this->pdo->exec($migration);
        self::assertTrue($this->repository->playersPublic());

        // Repeating the migration keeps a choice made later.
        $this->repository->save('Bowlingliga', false);
        $this->pdo->exec($migration);
        self::assertFalse($this->repository->playersPublic());
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
