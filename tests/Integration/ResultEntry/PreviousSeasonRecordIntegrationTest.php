<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\ResultEntry;

use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\DatabaseConnectionException;
use Bowlingliga\Persistence\FrozenHandicapRepository;
use Bowlingliga\Persistence\MigrationRunner;
use Bowlingliga\Persistence\PreviousSeasonRecordRepository;
use Bowlingliga\Persistence\ResultEntry\HandicapFreezingRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: which previous season the average before the first
 * matchdays of a season uses (R11.3, R11.4, R29.2, R29.11).
 *
 * A player played three games of 150 in the preceding season. Without a
 * record of the new season those games count; a record of the new season
 * takes precedence over them, a record of another season does not.
 */
final class PreviousSeasonRecordIntegrationTest extends TestCase
{
    private PDO $pdo;
    private Database $database;
    private HandicapFreezingRepository $averages;
    private PreviousSeasonRecordRepository $records;
    private int $earlier;
    private int $later;
    private int $player;
    private int $firstMatchday;

    protected function setUp(): void
    {
        $config = [
            'host'            => self::env('BOWLINGLIGA_TEST_DB_HOST', '127.0.0.1'),
            'port'            => (int) self::env('BOWLINGLIGA_TEST_DB_PORT', '3306'),
            'dbname'          => self::env('BOWLINGLIGA_TEST_DB_NAME', 'bowlingliga_test'),
            'user'            => self::env('BOWLINGLIGA_TEST_DB_USER', 'root'),
            'password'        => self::env('BOWLINGLIGA_TEST_DB_PASSWORD', ''),
            'charset'         => 'utf8mb4',
            'connect_timeout' => 5,
        ];

        try {
            $this->database = new Database($config);
            $this->pdo      = $this->database->pdo();
        } catch (DatabaseConnectionException $e) {
            self::markTestSkipped('No reachable MySQL test database: ' . $e->getMessage());
        }

        $this->dropSchema();
        (new MigrationRunner($this->pdo, dirname(__DIR__, 3) . '/db/migrations'))->run();
        $this->averages = new HandicapFreezingRepository($this->database, new FrozenHandicapRepository($this->database));
        $this->records  = new PreviousSeasonRecordRepository($this->database);

        $this->earlier = $this->season('Saison 2024/2025', '2024-09-04');
        $this->later   = $this->season('Saison 2026/2027', '2026-09-02');
        $this->player  = $this->insert("INSERT INTO player (name, gender) VALUES ('Anna', 'female')");

        // Preceding season: one complete fixture with three games of 150.
        $team = $this->insert('INSERT INTO team () VALUES ()');
        $home = $this->insert("INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team) VALUES ($team, {$this->earlier}, 'Adler', 0)");
        $other = $this->insert("INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team) VALUES ($team, {$this->earlier}, 'Falken', 0)");
        $matchday = $this->insert("INSERT INTO matchday (season_id, matchday_number, date, matchday_type, is_played) VALUES ({$this->earlier}, 1, '2024-09-04', 'regular', 1)");
        $fixture = $this->insert("INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, completion_status) VALUES ($matchday, $home, $other, 'complete')");
        $lineup = $this->insert("INSERT INTO lineup (fixture_id, team_season_id, sheet_row, player_id) VALUES ($fixture, $home, 1, {$this->player})");
        foreach ([1, 2, 3] as $game) {
            $this->insert("INSERT INTO game_result (lineup_id, game_number, net) VALUES ($lineup, $game, 150)");
        }

        $this->firstMatchday = $this->insert("INSERT INTO matchday (season_id, matchday_number, date, matchday_type, is_played) VALUES ({$this->later}, 1, '2026-09-02', 'regular', 0)");
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
    }

    public function testWithoutARecordTheGamesOfThePrecedingSeasonCount(): void
    {
        $stats = $this->averages->stats($this->firstMatchday, $this->player);

        self::assertEqualsWithDelta(150.0, $stats['average'], 0.0001);
        self::assertSame(3, $stats['games']);
        self::assertSame(40, $stats['hdc'], '(200 − 150) × 0.8 = 40');
    }

    public function testARecordOfTheSeasonTakesPrecedenceOverThePrecedingSeason(): void
    {
        $this->records->save($this->later, $this->player, 30, 5400);

        $stats = $this->averages->stats($this->firstMatchday, $this->player);

        self::assertEqualsWithDelta(180.0, $stats['average'], 0.0001, 'Only the record counts, not the 150 games (R29.2).');
        self::assertSame(30, $stats['games']);
        self::assertSame(16, $stats['hdc']);
    }

    public function testARecordOfAnotherSeasonDoesNotCount(): void
    {
        $this->records->save($this->earlier, $this->player, 30, 5400);

        self::assertEqualsWithDelta(150.0, $this->averages->averageBefore($this->firstMatchday, $this->player), 0.0001);
        self::assertSame(
            [['season_id' => $this->earlier, 'game_count' => 30]],
            array_map(static fn (array $r): array => ['season_id' => (int) $r['season_id'], 'game_count' => (int) $r['game_count']], $this->records->findAllRecords())
        );
    }

    private function season(string $name, string $start): int
    {
        $id = $this->insert("INSERT INTO season (name, start_date, end_date) VALUES ('$name', '$start', DATE_ADD('$start', INTERVAL 9 MONTH))");
        $this->insert(
            "INSERT INTO season_config (season_id, lane_count, max_handicap, handicap_formula, target_score, previous_season_matchdays)
             VALUES ($id, 10, 64, 'standard', 200, 5)"
        );

        return $id;
    }

    private function insert(string $sql): int
    {
        $this->pdo->exec($sql);

        return (int) $this->pdo->lastInsertId();
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
