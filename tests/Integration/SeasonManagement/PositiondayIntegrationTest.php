<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\SeasonManagement;

use Bowlingliga\Application\Aggregation\TableAggregator;
use Bowlingliga\Application\Positionday\PositiondayException;
use Bowlingliga\Application\Positionday\PositiondayManagement;
use Bowlingliga\Domain\Lane\LaneAssignmentService;
use Bowlingliga\Domain\Positionday\PositiondayService;
use Bowlingliga\Persistence\Aggregation\TableRawDataRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\Positionday\PositiondayContextRepository;
use Bowlingliga\Persistence\PositiondayFixtureRepository;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Integration test of the positionday formation against the test database.
 *
 * Validates: R31.2–R31.5, R31.7, R20
 *
 * Season with three teams and a ghost team (team season row), matchday 1
 * played, matchday 2 positionday. Checked are readiness, fixtures by standings
 * with lanes, the ghost team as a real team season row and the lock after
 * entered results.
 */
final class PositiondayIntegrationTest extends TestCase
{
    private Database $database;
    private PDO $pdo;
    private PositiondayContextRepository $context;
    private PositiondayManagement $management;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        if (! in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The PDO MySQL driver is not available.');
        }

        $this->database = new Database([
            'host' => getenv('BOWLINGLIGA_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('BOWLINGLIGA_TEST_DB_PORT') ?: 3306),
            'dbname' => getenv('BOWLINGLIGA_TEST_DB_NAME') ?: 'bowlingliga_test',
            'user' => getenv('BOWLINGLIGA_TEST_DB_USER') ?: 'root',
            'password' => getenv('BOWLINGLIGA_TEST_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'connect_timeout' => 5,
        ]);

        try {
            $this->pdo = $this->database->pdo();
        } catch (Throwable $throwable) {
            self::markTestSkipped('No reachable MySQL test database: ' . $throwable->getMessage());
        }

        $this->cleanUp();
        foreach (glob(dirname(__DIR__, 3) . '/db/migrations/*.sql') as $file) {
            $this->pdo->exec((string) file_get_contents($file));
        }
        $this->createData();

        $this->context = new PositiondayContextRepository(
            $this->database,
            new TableAggregator(new TableRawDataRepository($this->database)),
        );
        $this->management = new PositiondayManagement(
            $this->context,
            new PositiondayFixtureRepository($this->database),
            new PositiondayService(),
            new LaneAssignmentService(),
            new SwitchableWriteAccess(true),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->cleanUp();
        }
    }

    public function testFixturesByStandingsWithGhostTeamAndLanes(): void
    {
        self::assertTrue($this->context->allPreviousMatchdaysComplete($this->ids['positionday']));

        $result = $this->management->formAndSave($this->ids['positionday']);
        self::assertTrue($result->formed, (string) $result->note);

        $fixtures = $this->pdo->query(
            'SELECT fx.home_team_season_id AS home, fx.away_team_season_id AS away, b.lane_a
             FROM fixture fx LEFT JOIN lane_pair b ON b.id = fx.lane_pair_id
             WHERE fx.matchday_id = ' . $this->ids['positionday'] . ' ORDER BY b.lane_a'
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(2, $fixtures);
        foreach ($fixtures as $fixture) {
            self::assertNotNull($fixture['home'], 'Every fixture has a home team.');
            self::assertNotNull($fixture['away'], 'The ghost team is set as a team season row as well.');
            self::assertNotNull($fixture['lane_a'], 'Every fixture has a lane (R31.5).');
        }

        // Standings: ghost team (2 wins) and Adler on top, Eulen and Falken below.
        $teams = array_map(static fn (array $p): array => [(int) $p['home'], (int) $p['away']], $fixtures);
        $pairs = array_map(static function (array $p): array { sort($p); return $p; }, $teams);
        self::assertContains([$this->ids['adler'], $this->ids['ghosts']], $pairs);
        self::assertContains([$this->ids['eulen'], $this->ids['falken']], $pairs);
    }

    public function testNotReadyWhilePreviousMatchdayOpen(): void
    {
        $this->pdo->exec('UPDATE matchday SET is_played = 0 WHERE id = ' . $this->ids['matchday1']);

        $result = $this->management->formAndSave($this->ids['positionday']);

        self::assertFalse($result->formed);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fixture WHERE matchday_id = ' . $this->ids['positionday'])->fetchColumn());
    }

    public function testLockedAfterEnteredResults(): void
    {
        $this->management->formAndSave($this->ids['positionday']);
        $this->pdo->exec("UPDATE fixture SET completion_status = 'in_progress' WHERE matchday_id = " . $this->ids['positionday'] . ' LIMIT 1');

        $this->expectException(PositiondayException::class);
        $this->management->formAndSave($this->ids['positionday']);
    }

    private function createData(): void
    {
        $season = $this->insert("INSERT INTO season (name, start_date, end_date) VALUES ('Test', '2025-09-01', '2026-06-30')");
        $this->insert("INSERT INTO season_config (season_id, lane_count) VALUES ($season, 4)");

        foreach (['adler' => 'Die Adler', 'eulen' => 'Eulen', 'falken' => 'Falken', 'ghosts' => 'Geisterteam'] as $key => $name) {
            $team = $this->insert('INSERT INTO team () VALUES ()');
            $ghost = $key === 'ghosts' ? 1 : 0;
            $this->ids[$key] = $this->insert(
                "INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team) VALUES ($team, $season, '$name', $ghost)"
            );
        }

        $this->ids['matchday1'] = $this->insert(
            "INSERT INTO matchday (season_id, matchday_number, date, matchday_type, is_played) VALUES ($season, 1, '2025-09-10', 'regular', 1)"
        );
        $this->ids['positionday'] = $this->insert(
            "INSERT INTO matchday (season_id, matchday_number, date, matchday_type, is_played) VALUES ($season, 2, '2025-09-17', 'positionday', 0)"
        );

        // Matchday 1: Adler 150 gegen Falken 140, Eulen 140 gegen GhostTeam 190.
        $this->fixture($this->ids['adler'], 150, $this->ids['falken'], 140);
        $this->fixture($this->ids['eulen'], 140, $this->ids['ghosts'], 190);
    }

    private function fixture(int $home, int $homeNet, int $away, int $awayNet): void
    {
        $tp = $this->insert(
            "INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, completion_status)
             VALUES ({$this->ids['matchday1']}, $home, $away, 'complete')"
        );
        foreach ([[$home, $homeNet], [$away, $awayNet]] as [$team, $net]) {
            for ($position = 1; $position <= 4; $position++) {
                $player = 'NULL';
                if ($team !== $this->ids['ghosts']) {
                    $player = (string) $this->insert("INSERT INTO player (name, gender) VALUES ('S$team-$position', 'male')");
                }
                $lineup = $this->insert(
                    "INSERT INTO lineup (fixture_id, team_season_id, sheet_row, player_id) VALUES ($tp, $team, $position, $player)"
                );
                for ($num = 1; $num <= 3; $num++) {
                    $this->insert("INSERT INTO game_result (lineup_id, game_number, net, is_blind) VALUES ($lineup, $num, $net, 0)");
                }
            }
        }
    }

    private function insert(string $sql): int
    {
        $this->pdo->exec($sql);

        return (int) $this->pdo->lastInsertId();
    }

    private function cleanUp(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
