<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\SeasonPlanning;

use Bowlingliga\Application\Aggregation\TableAggregator;
use Bowlingliga\Application\LotDraw\LotDrawException;
use Bowlingliga\Application\LotDraw\LotDrawManagement;
use Bowlingliga\Application\SeasonPlanning\SeasonPlanningException;
use Bowlingliga\Application\SeasonPlanning\SeasonPlanningManagement;
use Bowlingliga\Domain\Lane\LaneAssignmentService;
use Bowlingliga\Domain\SeasonPlanner\SeasonPlanner;
use Bowlingliga\Persistence\Aggregation\TableRawDataRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\LotDraw\LotDrawRepository;
use Bowlingliga\Persistence\PositiondayFixtureRepository;
use Bowlingliga\Persistence\SeasonPlanning\SeasonPlanningDataRepository;
use Bowlingliga\Persistence\SeasonPlanRepository;
use Bowlingliga\Tests\GermanText;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Integration test of the season planning against a local MySQL/MariaDB test
 * database (task 35.2).
 *
 * Validates: R9.3, R9.6, R9.10, R9.13, R9.14, R18.1, R18.4, R20, R28.11
 *
 * Checks the production read adapter ({@see SeasonPlanningDataRepository})
 * and the store adapter ({@see SeasonPlanRepository}) together with the real
 * use case: saving including the lane assignment, the ghost team as a team
 * season row of its own, weekly dates, replacing a plan not started yet and
 * the lock as soon as results exist.
 *
 * The schema (migrations 001 and 003) is applied per test and removed again;
 * without a reachable database the test is skipped.
 */
final class SeasonPlanningIntegrationTest extends TestCase
{
    private Database $database;
    private PDO $pdo;
    private SeasonPlanRepository $store;
    private SeasonPlanningDataRepository $data;
    private SeasonPlanningManagement $management;

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

        $this->store   = new SeasonPlanRepository($this->database);
        $this->data      = new SeasonPlanningDataRepository($this->database, $this->store);
        $this->management = new SeasonPlanningManagement(
            $this->data,
            $this->store,
            new SeasonPlanner(),
            new LaneAssignmentService(),
            new SwitchableWriteAccess(true),
        );
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
     * R9.3/R9.6/R18.1/R20: the plan is saved including the lane assignment; the
     * ghost team appears as a flagged team season row, no fixture stays
     * without a team.
     */
    public function testPlanIsSavedWithGhostTeamAndLaneAssignment(): void
    {
        $seasonId = $this->createSeason(['Adler', 'Falken', 'Eulen'], matchdayCount: 6);

        $this->management->applyPlan($this->management->planPreview($seasonId), false);

        self::assertSame(6, $this->numeral('SELECT COUNT(*) FROM matchday WHERE season_id = ?', [$seasonId]));
        self::assertSame(12, $this->numeral(
            'SELECT COUNT(*) FROM fixture fx JOIN matchday md ON md.id = fx.matchday_id WHERE md.season_id = ?',
            [$seasonId]
        ));
        self::assertSame(0, $this->numeral(
            'SELECT COUNT(*) FROM fixture WHERE home_team_season_id IS NULL OR away_team_season_id IS NULL OR lane_pair_id IS NULL',
            []
        ));
        self::assertSame(1, $this->numeral(
            'SELECT COUNT(*) FROM team_season WHERE season_id = ? AND is_ghost_team = 1',
            [$seasonId]
        ));
        self::assertSame(2, $this->numeral('SELECT COUNT(*) FROM lane_pair WHERE season_id = ?', [$seasonId]));

        // Every real team plays equally often against the flagged
        // ghost team row (R9.7) – with 6 matchdays and 3 teams twice each.
        self::assertSame(
            [2, 2, 2],
            array_map('intval', $this->pdo->query(
                'SELECT COUNT(*) FROM fixture fx
                 JOIN team_season g ON g.is_ghost_team = 1
                   AND g.id IN (fx.home_team_season_id, fx.away_team_season_id)
                 JOIN team_season r ON r.is_ghost_team = 0
                   AND r.id IN (fx.home_team_season_id, fx.away_team_season_id)
                 GROUP BY r.id'
            )->fetchAll(PDO::FETCH_COLUMN))
        );

        // The league table can be formed from the planned data; before
        // the first played matchday it contains the real teams with zero values (R16.9).
        $table = (new TableAggregator(new TableRawDataRepository($this->database)))->leagueTable($seasonId);
        self::assertCount(3, $table->rows);
    }

    /**
     * Weekly dates from the season start, at most until the end date; every
     * tenth matchday is a positionday without fixtures (R9.13).
     */
    public function testDatesWeeklyAndPositionday(): void
    {
        $seasonId = $this->createSeason(['Adler', 'Falken'], matchdayCount: 10, endDate: '2030-10-23');

        $this->management->applyPlan($this->management->planPreview($seasonId), true);

        $plan = $this->data->storedPlan($seasonId);
        self::assertCount(10, $plan);
        self::assertSame('2030-09-04', $plan[0]['date']);
        self::assertSame('2030-09-11', $plan[1]['date']);
        self::assertSame('2030-10-23', $plan[9]['date'], 'Dates are limited to the end date.');
        self::assertSame('positionday', $plan[9]['matchday_type']);
        self::assertSame([], $plan[9]['pairings']);
        self::assertSame('1/2', $plan[0]['pairings'][0]['lanes']);
    }

    /**
     * The positionday interval of the season configuration controls the
     * planning: every fourth matchday, or none with 0.
     */
    public function testPositiondayIntervalOfTheSeason(): void
    {
        $seasonId = $this->createSeason(['Adler', 'Falken'], matchdayCount: 10, endDate: '2030-11-30');
        $types = fn (): array => array_map(static fn (array $matchday): string => $matchday['matchday_type'], $this->data->storedPlan($seasonId));

        $this->pdo->exec("UPDATE season_config SET positionday_interval = 4 WHERE season_id = $seasonId");
        self::assertSame(4, $this->data->season($seasonId)['positionday_interval']);
        $this->management->applyPlan($this->management->planPreview($seasonId), true);
        self::assertSame([3, 7], array_keys(array_filter($types(), static fn (string $type): bool => $type === 'positionday')));

        $this->pdo->exec("UPDATE season_config SET positionday_interval = 0 WHERE season_id = $seasonId");
        $this->management->applyPlan($this->management->planPreview($seasonId), true);
        self::assertNotContains('positionday', $types(), '0 = no positiondays.');
    }

    /**
     * A lot draw chosen in the preview is stored without fixtures, the round
     * robin runs over the regular matchdays; the fixtures drawn on site are
     * entered afterwards (ghost team included) and are fixed once results
     * exist.
     */
    public function testLotDrawIsPlannedWithoutFixturesAndPairingsAreEntered(): void
    {
        $seasonId = $this->createSeason(['Adler', 'Falken', 'Eulen'], matchdayCount: 4);

        $preview = $this->management->planPreview($seasonId, [2 => 'lot_draw']);
        foreach ($preview->warnings as $warning) {
            self::assertStringNotContainsString('regulären Spieltage', GermanText::of($warning), 'Three regular matchdays = one full round with four teams (including the ghost team).');
        }
        $this->management->applyPlan($preview, true);

        $plan = $this->data->storedPlan($seasonId);
        self::assertSame(['regular', 'lot_draw', 'regular', 'regular'], array_column($plan, 'matchday_type'));
        self::assertSame([], $plan[1]['pairings']);
        $pairs = [];
        foreach ([0, 2, 3] as $index) {
            foreach ($plan[$index]['pairings'] as $pairing) {
                $names = [$pairing['home'], $pairing['away']];
                sort($names);
                $pairs[] = implode('-', $names);
            }
        }
        self::assertCount(6, array_unique($pairs), 'Each of the six fixtures exactly once.');

        $teams = [];
        foreach ($this->pdo->query("SELECT id, team_name FROM team_season WHERE season_id = $seasonId")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $teams[(string) $row['team_name']] = (int) $row['id'];
        }
        self::assertCount(4, $teams, 'The ghost team is created with the plan.');
        $lotDrawId = (int) $plan[1]['id'];
        $lotDraw = new LotDrawManagement(
            new LotDrawRepository($this->database),
            new PositiondayFixtureRepository($this->database),
            new SwitchableWriteAccess(true),
        );
        $ghost = $teams[array_values(array_diff(array_keys($teams), ['Adler', 'Falken', 'Eulen']))[0]];

        $lotDraw->savePairings($lotDrawId, [[$teams['Eulen'], $teams['Adler']], [$ghost, $teams['Falken']]]);

        self::assertSame(
            [[$teams['Eulen'], $teams['Adler'], null], [$ghost, $teams['Falken'], null]],
            array_map(
                static fn (array $row): array => [(int) $row['home_team_season_id'], (int) $row['away_team_season_id'], $row['lane_pair_id']],
                $this->pdo->query("SELECT home_team_season_id, away_team_season_id, lane_pair_id FROM fixture WHERE matchday_id = $lotDrawId ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)
            )
        );

        // Changing is allowed until results exist.
        $lotDraw->savePairings($lotDrawId, [[$teams['Adler'], $teams['Falken']], [$teams['Eulen'], $ghost]]);
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM fixture WHERE matchday_id = $lotDrawId")->fetchColumn());
        $fixture = (int) $this->pdo->query("SELECT MIN(id) FROM fixture WHERE matchday_id = $lotDrawId")->fetchColumn();
        $this->pdo->exec("UPDATE fixture SET completion_status = 'complete' WHERE id = $fixture");

        $this->expectException(LotDrawException::class);
        $lotDraw->savePairings($lotDrawId, [[$teams['Eulen'], $teams['Adler']], [$ghost, $teams['Falken']]]);
    }

    /**
     * R9.10: without a defined matchday count the count follows from the weeks
     * between start and end date.
     */
    public function testMatchdayCountFromEndDate(): void
    {
        $seasonId = $this->createSeason(['Adler', 'Falken'], matchdayCount: null, endDate: '2030-10-02');

        // 04.09. to 02.10. = 4 weeks → 5 Wednesdays.
        self::assertSame(5, $this->data->findMatchdayCount($seasonId));
        self::assertSame(2, $this->data->season($seasonId)['team_count']);
    }

    /**
     * R9.14: a plan not started yet is replaced when applying again; the ghost
     * team is reused.
     */
    public function testNotStartedPlanIsReplaced(): void
    {
        $seasonId = $this->createSeason(['Adler', 'Falken', 'Eulen'], matchdayCount: 6);

        $this->management->applyPlan($this->management->planPreview($seasonId), false);
        $this->management->applyPlan($this->management->planPreview($seasonId), false);

        self::assertSame(6, $this->numeral('SELECT COUNT(*) FROM matchday WHERE season_id = ?', [$seasonId]));
        self::assertSame(1, $this->numeral(
            'SELECT COUNT(*) FROM team_season WHERE season_id = ? AND is_ghost_team = 1',
            [$seasonId]
        ));
    }

    /**
     * As soon as results are entered, the plan must no longer be replaced; the
     * data stays unchanged.
     */
    public function testPlanWithResultsIsLocked(): void
    {
        $seasonId = $this->createSeason(['Adler', 'Falken'], matchdayCount: 2);
        $this->management->applyPlan($this->management->planPreview($seasonId), false);

        $this->pdo->exec("UPDATE fixture SET completion_status = 'in_progress' LIMIT 1");
        $before = $this->numeral('SELECT MAX(id) FROM matchday', []);

        self::assertNotNull($this->data->reasonAgainstReplanning($seasonId));

        try {
            $this->management->applyPlan($this->management->planPreview($seasonId), false);
            self::fail('A plan whose result entry has started must not be replaced.');
        } catch (SeasonPlanningException $exception) {
            self::assertStringContainsString('Ergebnisse', GermanText::of($exception));
        }

        self::assertSame($before, $this->numeral('SELECT MAX(id) FROM matchday', []));
    }

    /**
     * A season that has already started must not replace its existing plan any
     * more (R9.14).
     */
    public function testStartedSeasonIsLocked(): void
    {
        $seasonId = $this->createSeason(['Adler', 'Falken'], matchdayCount: 2, startDate: '2020-01-01', endDate: '2020-06-30');
        $this->management->applyPlan($this->management->planPreview($seasonId), false);

        self::assertStringContainsString('begonnen', GermanText::of($this->data->reasonAgainstReplanning($seasonId)));
        self::assertTrue($this->data->season($seasonId)['started']);
    }

    /**
     * @param list<string> $teamNames
     */
    private function createSeason(
        array $teamNames,
        ?int $matchdayCount,
        string $startDate = '2030-09-04',
        string $endDate = '2031-05-28',
    ): int {
        $seasonId = $this->insert(
            'INSERT INTO season (name, start_date, end_date, matchday_count) VALUES (?, ?, ?, ?)',
            ['Testsaison', $startDate, $endDate, $matchdayCount]
        );
        $this->insert('INSERT INTO season_config (season_id, lane_count) VALUES (?, 4)', [$seasonId]);

        foreach ($teamNames as $name) {
            $teamId = $this->insert('INSERT INTO team () VALUES ()', []);
            $this->insert(
                'INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team) VALUES (?, ?, ?, 0)',
                [$teamId, $seasonId, $name]
            );
        }

        return $seasonId;
    }

    /**
     * @param list<mixed> $parameter
     */
    private function insert(string $sql, array $parameter): int
    {
        $this->pdo->prepare($sql)->execute($parameter);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<mixed> $parameter
     */
    private function numeral(string $sql, array $parameter): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameter);

        return (int) $stmt->fetchColumn();
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
