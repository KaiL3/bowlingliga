<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\PublicSite;

use Bowlingliga\Application\Aggregation\TableAggregator;
use Bowlingliga\Application\TeamProfile\TeamFixtureResult;
use Bowlingliga\Application\TeamProfile\TeamProfiles;
use Bowlingliga\Persistence\Aggregation\TableRawDataRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\DatabaseConnectionException;
use Bowlingliga\Persistence\MigrationRunner;
use Bowlingliga\Persistence\TeamProfile\TeamProfileRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration test of the team page against MariaDB: the same team across
 * seasons under another name, matchdays with status and blind games read
 * from the raw data of the table.
 *
 * Validates: R41.1, R41.2, R41.5, R41.6, R27.12
 *
 * "Adler" of the earlier season plays on as "Adler II" (same team id). On
 * matchday 1 of the later season Anna plays three games of 150 next to a
 * blind slot with one game of 140 against Ben's three games of 140 for
 * "Raben"; matchday 2 is cancelled, matchday 3 not played yet.
 */
final class TeamProfileIntegrationTest extends TestCase
{
    private PDO $pdo;
    private Database $database;
    private TeamProfileRepository $repository;
    private int $earlier;
    private int $later;
    private int $adler;
    private int $adlerII;
    private int $raben;
    private int $ghosts;
    private int $first;

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
        $this->repository = new TeamProfileRepository($this->database);

        $this->earlier = $this->season('2025/26', '2025-09-03');
        $this->later   = $this->season('2026/27', '2026-09-02');
        $team          = $this->insert('INSERT INTO team () VALUES ()');
        $this->adler   = $this->teamSeason($team, $this->earlier, 'Adler');
        $this->adlerII = $this->teamSeason($team, $this->later, 'Adler II');
        $this->raben   = $this->teamSeason($this->insert('INSERT INTO team () VALUES ()'), $this->later, 'Raben');
        $this->ghosts  = $this->insert('INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team) VALUES ('
            . $this->insert('INSERT INTO team () VALUES ()') . ", {$this->later}, 'Geisterteam', 1)");

        $anna = $this->insert("INSERT INTO player (name, gender) VALUES ('Anna', 'female')");
        $ben  = $this->insert("INSERT INTO player (name, gender) VALUES ('Ben', 'male')");

        $this->first = $this->matchday($this->later, 1, '2026-09-02', 1, 0);
        $fixture     = $this->insert("INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, completion_status) VALUES ({$this->first}, {$this->adlerII}, {$this->raben}, 'complete')");
        $this->games($fixture, $this->adlerII, 1, (string) $anna, 0, [150, 150, 150]);
        $this->games($fixture, $this->adlerII, 2, 'NULL', 1, [140]);
        $this->games($fixture, $this->raben, 1, (string) $ben, 0, [140, 140, 140]);

        $this->matchday($this->later, 2, '2026-09-09', 0, 1);
        $this->matchday($this->later, 3, '2026-09-16', 0, 0);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
    }

    public function testTheTeamAndItsSeasonsUnderEveryName(): void
    {
        $team = $this->repository->team($this->adlerII);
        self::assertSame('Adler II', $team['team_name'] ?? null);
        self::assertSame('2026/27', $team['season_name'] ?? null);
        self::assertNull($this->repository->team($this->ghosts), 'The ghost team has no page.');
        self::assertNull($this->repository->team(999));

        self::assertSame(
            [[$this->adler, '2025/26'], [$this->adlerII, '2026/27']],
            array_map(static fn (array $s): array => [$s['team_season_id'], $s['season_name']], $this->repository->seasons((int) $team['team_id'])),
        );
    }

    public function testMatchdaysCarryTheirStatus(): void
    {
        self::assertSame(
            [[1, true, false], [2, false, true], [3, false, false]],
            array_map(static fn (array $m): array => [$m['number'], $m['played'], $m['cancelled']], $this->repository->matchdays($this->later)),
        );
    }

    public function testTheProfileLeavesBlindGamesOutOfTheAverageWithoutBlind(): void
    {
        $raw     = new TableRawDataRepository($this->database);
        $profile = (new TeamProfiles($this->repository, $raw, new TableAggregator($raw)))->profile($this->adlerII);
        self::assertNotNull($profile);

        self::assertSame([1, 2], array_map(static fn ($m): int => $m->matchdayNumber, $profile->matchdays));
        self::assertTrue($profile->matchdays[1]->cancelled);

        $result = $profile->matchdays[0]->result;
        self::assertSame($this->first, $profile->matchdays[0]->matchdayId);
        self::assertSame($this->raben, $result?->opponentTeamSeasonId);
        self::assertSame(3, $result->count(TeamFixtureResult::WIN));
        self::assertSame(590, $result->netPins);
        self::assertSame(4, $result->games);
        self::assertSame(450, $result->playedNetPins);
        self::assertSame(3, $result->playedGames);
        self::assertSame(1, $profile->blindGames());
        self::assertEqualsWithDelta(147.5, $profile->averageNet(), 0.001);
        self::assertEqualsWithDelta(150.0, $profile->playedAverageNet(), 0.001);
        self::assertSame(1, $profile->rank);
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

    private function teamSeason(int $teamId, int $seasonId, string $name): int
    {
        return $this->insert("INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team) VALUES ($teamId, $seasonId, '$name', 0)");
    }

    private function matchday(int $seasonId, int $number, string $date, int $played, int $cancelled): int
    {
        return $this->insert("INSERT INTO matchday (season_id, matchday_number, date, matchday_type, is_played, is_cancelled) VALUES ($seasonId, $number, '$date', 'regular', $played, $cancelled)");
    }

    /**
     * @param string    $playerId SQL value, 'NULL' for a blind slot
     * @param list<int> $nets
     */
    private function games(int $fixtureId, int $teamSeasonId, int $row, string $playerId, int $blind, array $nets): void
    {
        $lineup = $this->insert("INSERT INTO lineup (fixture_id, team_season_id, sheet_row, player_id, is_blind) VALUES ($fixtureId, $teamSeasonId, $row, $playerId, $blind)");
        foreach ($nets as $i => $net) {
            $this->insert('INSERT INTO game_result (lineup_id, game_number, net) VALUES (' . $lineup . ', ' . ($i + 1) . ', ' . $net . ')');
        }
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

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }
}
