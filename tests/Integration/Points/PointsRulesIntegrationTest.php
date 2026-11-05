<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\Points;

use Bowlingliga\Domain\Points\BasePointsConfiguration;
use Bowlingliga\Domain\Points\BonusPointsScale;
use Bowlingliga\Domain\Points\PointsRules;
use Bowlingliga\Persistence\Aggregation\TableRawDataRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\DatabaseConnectionException;
use Bowlingliga\Persistence\FrozenHandicapRepository;
use Bowlingliga\Persistence\MigrationRunner;
use Bowlingliga\Persistence\ResultEntry\HandicapFreezingRepository;
use Bowlingliga\Persistence\SeasonConfigRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Points rules per season (R17.11) against the test database: migration 002
 * gives existing configurations the former rules, the repository saves and
 * reads them, and the readers of the table and the score sheet return the
 * rules of the right season.
 *
 * Validates: R17.1, R17.11, R4.5
 */
final class PointsRulesIntegrationTest extends TestCase
{
    private PDO $pdo;
    private Database $database;

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
            $this->database = new Database($config);
            $this->pdo = $this->database->pdo();
        } catch (DatabaseConnectionException $e) {
            self::markTestSkipped('No reachable MySQL test database: ' . $e->getMessage());
        }

        $this->dropSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
    }

    public function testMigrationGivesExistingSeasonsTheFormerRules(): void
    {
        $root = dirname(__DIR__, 3);
        $this->pdo->exec((string) file_get_contents($root . '/db/migrations/001_schema.sql'));
        $this->seasonWithMatchday(1, 10);
        $this->pdo->exec('INSERT INTO season_config (season_id, lane_count) VALUES (1, 4)');

        $this->pdo->exec((string) file_get_contents($root . '/db/migrations/002_points_rules.sql'));
        $this->pdo->exec((string) file_get_contents($root . '/db/migrations/002_points_rules.sql'));

        $rules = (new TableRawDataRepository($this->database))->pointsRulesForSeason(1);
        self::assertEquals(PointsRules::standard(), $rules, '2/1/0 and 2100/100/6 as before.');
    }

    public function testRulesAreSavedAndReadPerSeason(): void
    {
        (new MigrationRunner($this->pdo, dirname(__DIR__, 3) . '/db/migrations'))->run();
        $this->seasonWithMatchday(1, 10);
        $this->seasonWithMatchday(2, 20);

        $config = new SeasonConfigRepository($this->database);
        $custom = new PointsRules(new BasePointsConfiguration(3, 1, 0), new BonusPointsScale(2000, 50, 8));
        $config->save(1, 4, '5.00', '2.50', 64, 'standard', 200, points: $custom);
        $config->save(2, 4, '5.00', '2.50', 64, 'standard', 200);

        $row = $config->find(1);
        self::assertNotNull($row);
        self::assertSame([3, 1, 0, 2000, 50, 8], array_map('intval', [
            $row['win_points'], $row['draw_points'], $row['loss_points'],
            $row['bonus_threshold'], $row['bonus_step'], $row['bonus_maximum'],
        ]));

        $tables = new TableRawDataRepository($this->database);
        self::assertEquals($custom, $tables->pointsRulesForSeason(1));
        self::assertEquals($custom, $tables->pointsRulesForMatchday(10));
        self::assertEquals(PointsRules::standard(), $tables->pointsRulesForMatchday(20));
        self::assertEquals($custom, (new HandicapFreezingRepository($this->database, new FrozenHandicapRepository($this->database)))->pointsRules(1));
    }

    public function testDatabaseRejectsValuesOutsideTheRange(): void
    {
        (new MigrationRunner($this->pdo, dirname(__DIR__, 3) . '/db/migrations'))->run();
        $this->seasonWithMatchday(1, 10);

        $this->expectException(\PDOException::class);
        $this->pdo->exec('INSERT INTO season_config (season_id, lane_count, bonus_step) VALUES (1, 4, 0)');
    }

    private function seasonWithMatchday(int $seasonId, int $matchdayId): void
    {
        $this->pdo->prepare('INSERT INTO season (id, name, start_date, end_date) VALUES (?, ?, ?, ?)')
            ->execute([$seasonId, 'Saison ' . $seasonId, ($seasonId + 2024) . '-09-01', ($seasonId + 2025) . '-06-30']);
        $this->pdo->prepare('INSERT INTO matchday (id, season_id, matchday_number, date) VALUES (?, ?, 1, ?)')
            ->execute([$matchdayId, $seasonId, ($seasonId + 2024) . '-09-08']);
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
