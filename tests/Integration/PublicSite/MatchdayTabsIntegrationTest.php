<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\PublicSite;

use Bowlingliga\Application\Aggregation\HonoursAggregator;
use Bowlingliga\Application\Aggregation\AverageListAggregator;
use Bowlingliga\Application\Aggregation\TableAggregator;
use Bowlingliga\Persistence\Aggregation\HonoursRawDataRepository;
use Bowlingliga\Persistence\Aggregation\AverageListRawDataRepository;
use Bowlingliga\Persistence\Aggregation\TableRawDataRepository;
use Bowlingliga\Persistence\LanePairRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\PublicSite\PublicDataRepository;
use Bowlingliga\Persistence\PublicSite\ScoreSheetDisplayRepository;
use Bowlingliga\Persistence\OriginalScoreSheetRepository;
use Bowlingliga\Persistence\SeasonRepository;
use Bowlingliga\Persistence\MatchdayRepository;
use Bowlingliga\Persistence\FixtureRepository;
use Bowlingliga\Persistence\TeamSeasonRepository;
use Bowlingliga\Presentation\PublicController;
use Bowlingliga\Presentation\PublicView;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Integration test of the matchday detail view with fixture tabs by lane pair
 * and digital score sheet (task 28.6).
 *
 * Validates: R2.1, R15.1, R15.3, R22.1
 *
 * Creates a matchday with two fixtures (with lane pairs) and checks the
 * rendered HTML: two tabs with "Bahn x/y", per fixture the game results
 * (player names and net/gross values) and one matchday table below the tabs.
 *
 * The real schema (migrations 001 and 003) is applied per test and removed
 * afterwards. If the PDO MySQL driver is missing or no database is reachable,
 * the test is skipped in a controlled way.
 */
final class MatchdayTabsIntegrationTest extends TestCase
{
    private Database $database;
    private PDO $pdo;
    private PublicController $controller;

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

        $reader = new PublicDataRepository(
            new SeasonRepository($this->database),
            new MatchdayRepository($this->database),
            new FixtureRepository($this->database),
            new TeamSeasonRepository($this->database),
            new OriginalScoreSheetRepository($this->database, new \Bowlingliga\Tests\Unit\Application\InMemoryBlobStore()),
            new LanePairRepository($this->database),
            new ScoreSheetDisplayRepository($this->database),
            new TableAggregator(new TableRawDataRepository($this->database)),
            new AverageListAggregator(new AverageListRawDataRepository($this->database)),
            new HonoursAggregator(new HonoursRawDataRepository($this->database)),
        );

        $this->controller = new PublicController($reader, new PublicView());
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
     * R15.1/R18: both fixtures of the matchday appear as tabs, named after
     * their lane pair.
     */
    public function testBothFixturesAsTabsByLanePair(): void
    {
        $this->createMatchdayWithTwoFixtures();

        $html = $this->controller->matchdayDetail(1);

        self::assertStringContainsString('role="tab"', $html, 'The fixtures are shown as tabs.');
        self::assertStringContainsString('Bahn 1/2', $html, 'First tab named after its lane pair.');
        self::assertStringContainsString('Bahn 3/4', $html, 'Second tab named after its lane pair.');
        self::assertSame(2, substr_count($html, 'role="tab"'), 'There are exactly two tabs.');
    }

    /**
     * R2.1/R22.1: the digital score sheet shows the player names and their game
     * results.
     */
    public function testScoreSheetShowsPlayersAndResults(): void
    {
        $this->createMatchdayWithTwoFixtures();

        $html = $this->controller->matchdayDetail(1);

        self::assertStringContainsString('Anna Berg', $html);
        self::assertStringContainsString('Carla Daum', $html);
        self::assertStringContainsString('Spielzettel', $html);
        // A concrete net result of Anna (160) must be on the sheet.
        self::assertStringContainsString('160', $html);
    }

    /**
     * R15.3: the matchday table appears exactly once (for the whole matchday),
     * not per tab.
     */
    public function testMatchdayTableExactlyOnce(): void
    {
        $this->createMatchdayWithTwoFixtures();

        $html = $this->controller->matchdayDetail(1);

        self::assertSame(1, substr_count($html, '<h2>Tagestabelle</h2>'), 'The day table appears once below the tabs.');
    }

    // --- Beispieldaten ---

    private function createMatchdayWithTwoFixtures(): void
    {
        $this->insert('INSERT INTO season (id, name, start_date, end_date) VALUES (1, :n, :s, :e)', ['n' => 'Testsaison', 's' => '2025-09-01', 'e' => '2026-06-30']);
        $this->insert('INSERT INTO season_config (season_id, lane_count, target_score) VALUES (1, 4, 190)', []);

        $this->insert('INSERT INTO lane_pair (id, season_id, lane_a, lane_b) VALUES (1, 1, 1, 2)', []);
        $this->insert('INSERT INTO lane_pair (id, season_id, lane_a, lane_b) VALUES (2, 1, 3, 4)', []);

        $this->insert('INSERT INTO matchday (id, season_id, matchday_number, date, is_played) VALUES (1, 1, 1, :d, 1)', ['d' => '2025-09-08']);

        // Vier reale Teams.
        $teams = [];
        $names = ['Die Adler', 'Falken', 'Eulen', 'Raben'];
        $playerNames = [
            ['Anna Berg', 'Bernd Clausen'],
            ['Carla Daum', 'Dirk Engel'],
            ['Eva Funk', 'Frank Groß'],
            ['Gina Hahn', 'Hans Imker'],
        ];
        foreach ($names as $i => $name) {
            $teamId = $this->insert('INSERT INTO team () VALUES ()', []);
            $ts = $this->insert('INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team) VALUES (:t, 1, :n, 0)', ['t' => $teamId, 'n' => $name]);
            $sp1 = $this->insert('INSERT INTO player (name, gender) VALUES (:n, :g)', ['n' => $playerNames[$i][0], 'g' => 'female']);
            $sp2 = $this->insert('INSERT INTO player (name, gender) VALUES (:n, :g)', ['n' => $playerNames[$i][1], 'g' => 'male']);
            $this->insert('INSERT INTO roster (team_season_id, player_id) VALUES (:ts, :s)', ['ts' => $ts, 's' => $sp1]);
            $this->insert('INSERT INTO roster (team_season_id, player_id) VALUES (:ts, :s)', ['ts' => $ts, 's' => $sp2]);
            $teams[] = ['ts' => $ts, 'player' => [$sp1, $sp2]];
        }

        // Two fixtures on the same matchday with lane pairs.
        $this->createFixture(1, 1, $teams[0], $teams[1], [[160, 170, 180], [150, 155, 165]], [[150, 160, 155], [140, 145, 150]]);
        $this->createFixture(2, 2, $teams[2], $teams[3], [[170, 175, 180], [160, 165, 170]], [[155, 160, 165], [145, 150, 155]]);

        // Frozen handicaps of all real players.
        foreach ($teams as $team) {
            foreach ($team['player'] as $playerId) {
                $this->insert('INSERT INTO frozen_handicap (matchday_id, player_id, handicap) VALUES (1, :s, 20)', ['s' => $playerId]);
            }
        }
    }

    /**
     * Creates a fixture with complete lineups of both teams (positions 1/2 real
     * players, 3/4 blind).
     *
     * @param array{ts: int, player: array{0: int, 1: int}} $home
     * @param array{ts: int, player: array{0: int, 1: int}} $away
     * @param array{0: array{0:int,1:int,2:int}, 1: array{0:int,1:int,2:int}} $homeNet
     * @param array{0: array{0:int,1:int,2:int}, 1: array{0:int,1:int,2:int}} $awayNet
     */
    private function createFixture(int $fixtureId, int $lanePairId, array $home, array $away, array $homeNet, array $awayNet): void
    {
        $this->insert(
            'INSERT INTO fixture (id, matchday_id, home_team_season_id, away_team_season_id, lane_pair_id) VALUES (:id, 1, :h, :g, :lp)',
            ['id' => $fixtureId, 'h' => $home['ts'], 'g' => $away['ts'], 'lp' => $lanePairId]
        );

        $this->createTeamLineup($fixtureId, $home, $homeNet);
        $this->createTeamLineup($fixtureId, $away, $awayNet);
    }

    /**
     * @param array{ts: int, player: array{0: int, 1: int}} $team
     * @param array{0: array{0:int,1:int,2:int}, 1: array{0:int,1:int,2:int}} $net
     */
    private function createTeamLineup(int $fixtureId, array $team, array $net): void
    {
        for ($pos = 1; $pos <= 4; $pos++) {
            $playerId = $pos <= 2 ? $team['player'][$pos - 1] : null;
            $isBlind  = $pos > 2;
            $values     = $pos <= 2 ? $net[$pos - 1] : [120, 120, 120];

            $lineupId = $this->insert(
                'INSERT INTO lineup (fixture_id, team_season_id, sheet_row, player_id) VALUES (:fx, :ts, :pos, :sp)',
                ['fx' => $fixtureId, 'ts' => $team['ts'], 'pos' => $pos, 'sp' => $playerId]
            );

            foreach ($values as $i => $value) {
                $this->insert(
                    'INSERT INTO game_result (lineup_id, game_number, net, is_blind) VALUES (:a, :number, :n, :b)',
                    ['a' => $lineupId, 'number' => $i + 1, 'n' => $value, 'b' => $isBlind ? 1 : 0]
                );
            }
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
        foreach (['db/migrations/001_schema.sql', 'db/migrations/002_points_rules.sql', 'db/migrations/003_players_public.sql'] as $file) {
            $this->pdo->exec((string) file_get_contents($root . '/' . $file));
        }
    }

    private function dropSchema(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'fixture_log', 'team_entry_game', 'team_entry', 'game_result', 'lineup', 'original_score_sheet', 'fixture', 'lane_pair',
            'frozen_handicap', 'roster', 'team_season', 'team', 'matchday', 'season_config', 'season',
            'player', 'previous_season_record', 'admin_account', 'login_attempt', 'home_page_post',
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
