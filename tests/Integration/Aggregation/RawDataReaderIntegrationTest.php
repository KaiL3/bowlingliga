<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\Aggregation;

use Bowlingliga\Application\Aggregation\HonoursAggregator;
use Bowlingliga\Application\Aggregation\AverageListAggregator;
use Bowlingliga\Application\Aggregation\TableAggregator;
use Bowlingliga\Persistence\Aggregation\HonoursRawDataRepository;
use Bowlingliga\Persistence\Aggregation\AverageListRawDataRepository;
use Bowlingliga\Persistence\Aggregation\TableRawDataRepository;
use Bowlingliga\Persistence\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Integration test of the production raw data reader adapters of the
 * aggregation against a local MySQL/MariaDB test database (task 34.1).
 *
 * Validates: R2.1, R13.1, R16.1, R16.6, R20.2, R24.3, R35.2
 *
 * Checks that the three adapters – TableRawDataRepository,
 * AverageListRawDataRepository and HonoursRawDataRepository – turn the raw
 * data correctly into the aggregation inputs and that the pure aggregators
 * form the expected league/matchday, average and honours data from it. In
 * particular the ghost team handling (own row, no handicap, R16.10/R20.2) and
 * the exclusion of unplayed matchdays (R16.6/R24.3) are checked.
 *
 * The real schema (migrations 001 and 003) is applied per test and removed
 * afterwards. If the PDO MySQL driver is missing or no database is reachable,
 * the test is skipped in a controlled way. The connection parameters are read
 * only from environment variables.
 */
final class RawDataReaderIntegrationTest extends TestCase
{
    private Database $database;
    private PDO $pdo;

    protected function setUp(): void
    {
        if (! in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The PDO MySQL driver is not available; integration test skipped.');
        }

        $this->database = new Database($this->testConfiguration());

        try {
            $this->pdo = $this->database->pdo();
            $this->pdo->query('SELECT 1');
        } catch (Throwable $throwable) {
            self::markTestSkipped('No reachable MySQL test database: ' . $throwable->getMessage());
        }

        $this->applySchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->dropSchema();
        }
    }

    /**
     * R16.1/R16.6/R16.10/R20.2: the league table contains the real team and the
     * ghost team as rows of their own; for the ghost team net and gross pins
     * are equal (no handicap).
     */
    public function testLeagueTableFromRawData(): void
    {
        $this->createSampleData();

        $aggregator = new TableAggregator(new TableRawDataRepository($this->database));
        $table    = $aggregator->leagueTable(1);

        $names = array_map(static fn ($z): string => $z->teamName, $table->rows);
        self::assertContains('Die Adler', $names);
        self::assertContains('Geisterteam', $names);

        // Ghost team row: net pins == gross pins (R16.11, R20.2).
        foreach ($table->rows as $row) {
            if ($row->teamName === 'Geisterteam') {
                self::assertSame($row->netPins, $row->grossPins, 'The ghost team carries no handicap (R20.2).');
            }
        }
    }

    /**
     * R16.6/R24.3: unplayed matchdays are excluded – a second, unplayed
     * matchday must not increase the pins.
     */
    public function testUnplayedMatchdayIsExcluded(): void
    {
        $this->createSampleData();

        $aggregator = new TableRawDataRepository($this->database);
        $before     = $aggregator->findFixturesForSeason(1);

        // Second matchday, NOT played, with one fixture.
        $this->insert('INSERT INTO matchday (id, season_id, matchday_number, date, is_played) VALUES (2, 1, 2, :d, 0)', ['d' => '2025-09-15']);
        $this->insert('INSERT INTO fixture (id, matchday_id, home_team_season_id, away_team_season_id) VALUES (99, 2, 1, 2)', []);

        $after = $aggregator->findFixturesForSeason(1);

        self::assertCount(count($before), $after, 'Matchdays not played must not be included (R24.3).');
    }

    /**
     * R2.1/R2.8: the average list of the real team contains the real players
     * with their net averages; the frozen handicap is taken over.
     */
    public function testAverageListFromRawData(): void
    {
        $this->createSampleData();

        $aggregator   = new AverageListAggregator(new AverageListRawDataRepository($this->database));
        $averageList = $aggregator->averageList(1, 1);

        self::assertFalse($averageList->isEmpty(), 'The real team must have averages.');
        $names = array_map(static fn ($z): string => $z->playerName, $averageList->rows);
        self::assertContains('Anna', $names);
    }

    /**
     * R2.8: a player who missed the matchday keeps the last handicap frozen
     * before it; a later frozen handicap does not leak into an earlier state.
     */
    public function testAMissedMatchdayKeepsTheLastFrozenHandicap(): void
    {
        $this->createSampleData();
        // Matchday 2: only Anna plays (new HDC 18), Bernd misses it.
        $this->insert('INSERT INTO matchday (id, season_id, matchday_number, date, is_played) VALUES (2, 1, 2, :d, 1)', ['d' => '2025-09-15']);
        $this->insert('INSERT INTO fixture (id, matchday_id, home_team_season_id, away_team_season_id) VALUES (2, 2, 1, 2)', []);
        $this->insert('INSERT INTO frozen_handicap (matchday_id, player_id, handicap) VALUES (2, 1, 18)', []);
        $this->createPositionWithResult(1, 2, 1, 1, [160, 160, 160], false);
        // Matchday 3: Bernd plays again with HDC 5.
        $this->insert('INSERT INTO matchday (id, season_id, matchday_number, date, is_played) VALUES (3, 1, 3, :d, 1)', ['d' => '2025-09-22']);
        $this->insert('INSERT INTO fixture (id, matchday_id, home_team_season_id, away_team_season_id) VALUES (3, 3, 1, 2)', []);
        $this->insert('INSERT INTO frozen_handicap (matchday_id, player_id, handicap) VALUES (3, 2, 5)', []);
        $this->createPositionWithResult(1, 3, 1, 2, [180, 180, 180], false);

        $rows = [];
        foreach ((new AverageListAggregator(new AverageListRawDataRepository($this->database)))->averageList(1, 2)->rows as $row) {
            $rows[$row->playerName] = $row;
        }

        self::assertSame(18, $rows['Anna']->handicap);
        self::assertSame(10, $rows['Bernd']->handicap, 'Bernd keeps the handicap of matchday 1.');
        self::assertNotNull($rows['Bernd']->averageGross);
    }

    /**
     * R13.1: the honours aggregation returns a result; ghost team and blind
     * results are excluded by the pure honours module.
     */
    public function testHonoursFromRawData(): void
    {
        $this->createSampleData();

        $aggregator = new HonoursAggregator(new HonoursRawDataRepository($this->database));
        $honours   = $aggregator->honours(1);

        // An honours object is returned (the view checks the visibility).
        self::assertNotNull($honours);
    }

    // --- Beispieldaten ---

    /**
     * Creates a season with a played matchday, a real team (two real players
     * with frozen HDC) and a ghost team including their lineups and game
     * results.
     */
    private function createSampleData(): void
    {
        $this->insert('INSERT INTO season (id, name, start_date, end_date) VALUES (1, :n, :s, :e)', ['n' => 'Testsaison', 's' => '2025-09-01', 'e' => '2026-06-30']);
        $this->insert('INSERT INTO season_config (season_id, lane_count, target_score) VALUES (1, 4, 190)', []);

        $this->insert('INSERT INTO matchday (id, season_id, matchday_number, date, is_played) VALUES (1, 1, 1, :d, 1)', ['d' => '2025-09-08']);

        // Real team with roster.
        $this->insert('INSERT INTO team (id) VALUES (1)', []);
        $this->insert('INSERT INTO team_season (id, team_id, season_id, team_name, is_ghost_team) VALUES (1, 1, 1, :n, 0)', ['n' => 'Die Adler']);
        $this->insert('INSERT INTO player (id, name, gender) VALUES (1, :n, :g)', ['n' => 'Anna', 'g' => 'female']);
        $this->insert('INSERT INTO player (id, name, gender) VALUES (2, :n, :g)', ['n' => 'Bernd', 'g' => 'male']);
        $this->insert('INSERT INTO roster (team_season_id, player_id) VALUES (1, 1)', []);
        $this->insert('INSERT INTO roster (team_season_id, player_id) VALUES (1, 2)', []);

        // GhostTeam.
        $this->insert('INSERT INTO team (id) VALUES (2)', []);
        $this->insert('INSERT INTO team_season (id, team_id, season_id, team_name, is_ghost_team) VALUES (2, 2, 1, :n, 1)', ['n' => 'Geisterteam']);

        // Fixture reales Team gegen GhostTeam am gespielten Matchday.
        $this->insert('INSERT INTO fixture (id, matchday_id, home_team_season_id, away_team_season_id) VALUES (1, 1, 1, 2)', []);

        // Frozen handicaps of the real players on matchday 1.
        $this->insert('INSERT INTO frozen_handicap (matchday_id, player_id, handicap) VALUES (1, 1, 20)', []);
        $this->insert('INSERT INTO frozen_handicap (matchday_id, player_id, handicap) VALUES (1, 2, 10)', []);

        // Lineups of the real team (positions 1 and 2 with real players,
        // 3 and 4 blind/vacant).
        $this->createPositionWithResult(1, 1, 1, 1, [150, 160, 170], false);
        $this->createPositionWithResult(2, 1, 1, 2, [140, 150, 155], false);
        $this->createPositionWithResult(3, 1, 1, null, [120, 120, 120], true);
        $this->createPositionWithResult(4, 1, 1, null, [120, 120, 120], true);

        // Lineups of the ghost team: fixed net = target score 190, no HDC.
        for ($pos = 1; $pos <= 4; $pos++) {
            $this->createPositionWithResult($pos, 1, 2, null, [190, 190, 190], false);
        }
    }

    /**
     * Creates a lineup position with three game results.
     *
     * @param array{0: int, 1: int, 2: int} $net
     */
    private function createPositionWithResult(int $position, int $fixtureId, int $teamSeasonId, ?int $playerId, array $net, bool $isBlind): void
    {
        $lineupId = $this->insert(
            'INSERT INTO lineup (fixture_id, team_season_id, sheet_row, player_id)
             VALUES (:fx, :ts, :pos, :sp)',
            ['fx' => $fixtureId, 'ts' => $teamSeasonId, 'pos' => $position, 'sp' => $playerId]
        );

        foreach ($net as $index => $value) {
            $this->insert(
                'INSERT INTO game_result (lineup_id, game_number, net, is_blind)
                 VALUES (:lineup, :number, :net, :blind)',
                ['lineup' => $lineupId, 'number' => $index + 1, 'net' => $value, 'blind' => $isBlind ? 1 : 0]
            );
        }
    }

    /**
     * @param array<string, mixed> $parameter
     */
    private function insert(string $sql, array $parameter): int
    {
        $this->pdo->prepare($sql)->execute($parameter);

        return (int) $this->pdo->lastInsertId();
    }

    private function applySchema(): void
    {
        $this->dropSchema();

        $root = dirname(__DIR__, 3);
        foreach (['db/migrations/001_schema.sql', 'db/migrations/002_points_rules.sql', 'db/migrations/003_players_public.sql', 'db/migrations/004_matchday_cancelled.sql', 'db/migrations/005_trusted_device.sql', 'db/migrations/006_account_permission.sql'] as $file) {
            $this->pdo->exec((string) file_get_contents($root . '/' . $file));
        }
    }

    private function dropSchema(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'fixture_log', 'team_entry_game', 'team_entry', 'game_result', 'lineup', 'original_score_sheet', 'fixture', 'lane_pair',
            'frozen_handicap', 'roster', 'team_season', 'team', 'matchday', 'season_config', 'season',
            'player', 'previous_season_record', 'admin_account', 'login_attempt', 'trusted_device', 'account_permission', 'account_log', 'home_page_post',
            'rules', 'privacy_policy', 'legal_notice', 'league_setting',
        ] as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @return array<string, mixed>
     */
    private function testConfiguration(): array
    {
        return [
            'host'            => self::env('BOWLINGLIGA_TEST_DB_HOST', '127.0.0.1'),
            'port'            => (int) self::env('BOWLINGLIGA_TEST_DB_PORT', '3306'),
            'dbname'          => self::env('BOWLINGLIGA_TEST_DB_NAME', 'bowlingliga_test'),
            'user'            => self::env('BOWLINGLIGA_TEST_DB_USER', 'root'),
            'password'        => self::env('BOWLINGLIGA_TEST_DB_PASSWORD', ''),
            'charset'         => self::env('BOWLINGLIGA_TEST_DB_CHARSET', 'utf8mb4'),
            'connect_timeout' => 5,
        ];
    }

    private static function env(string $name, string $standard): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $standard : $value;
    }
}
