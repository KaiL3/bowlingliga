<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\PublicSite;

use Bowlingliga\Application\Aggregation\HonoursAggregator;
use Bowlingliga\Application\Aggregation\AverageListAggregator;
use Bowlingliga\Application\Aggregation\TableAggregator;
use Bowlingliga\Persistence\Aggregation\HonoursRawDataRepository;
use Bowlingliga\Persistence\Aggregation\AverageListRawDataRepository;
use Bowlingliga\Persistence\Aggregation\TableRawDataRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\PublicSite\PublicDataRepository;
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
 * End-to-end integration test of the public views against a local
 * MySQL/MariaDB test database (task 34.4).
 *
 * Validates: R1.1, R2.1, R14.1, R15.1, R15.4, R15.5, R16.1, R35.2
 *
 * Checks the complete read chain from the real raw data schema via the
 * production adapters and aggregators up to the rendered HTML of the
 * {@see PublicView} (through the {@see PublicController}). Unlike the
 * {@see PublicDataIntegrationTest}, which checks the data structure, this
 * test verifies the delivered markup: matchday list, detail view
 * (played/unplayed, original score sheet present/missing), league table and
 * the empty season.
 *
 * The real schema (migrations 001 and 003) is applied per test and removed
 * afterwards. If the PDO MySQL driver is missing or no database is reachable,
 * the test is skipped in a controlled way.
 */
final class PublicPagesIntegrationTest extends TestCase
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
            new \Bowlingliga\Persistence\LanePairRepository($this->database),
            new \Bowlingliga\Persistence\PublicSite\ScoreSheetDisplayRepository($this->database),
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
     * R14.1: the rendered matchday list contains the played matchday as link
     * and the unplayed matchday with "ohne Ergebnisse".
     */
    public function testMatchdayListHtml(): void
    {
        $this->createSampleData();

        $html = $this->controller->matchdayList(1);

        self::assertStringContainsString('Spieltage', $html);
        self::assertStringContainsString('/matchday/1', $html, 'The played matchday must be linked (R14.4).');
        self::assertStringContainsString('ohne Ergebnisse', $html, 'The matchday not played must be marked (R1.3).');
    }

    /**
     * R15.1/R15.4: the rendered detail view of a played matchday shows the team
     * names, the average list and the link to the original score sheet.
     */
    public function testMatchdayDetailHtmlWithScoreSheet(): void
    {
        $this->createSampleData();
        $this->createOriginalScoreSheet(1);

        $html = $this->controller->matchdayDetail(1);

        self::assertStringContainsString('Die Adler', $html);
        self::assertStringContainsString('Geisterteam', $html);
        self::assertStringContainsString('Anna', $html, 'The average list of the real team must be rendered.');
        self::assertStringContainsString('/score-sheet/', $html, 'The original score sheet must be linked (R15.4).');
    }

    /**
     * R15.4: without an uploaded score sheet the corresponding message appears.
     */
    public function testMatchdayDetailHtmlWithoutScoreSheet(): void
    {
        $this->createSampleData();

        $html = $this->controller->matchdayDetail(1);

        self::assertStringContainsString('kein hochgeladener Spielzettel', $html);
    }

    /**
     * R15.5: for a matchday not played yet the view shows the message instead
     * of the detail data.
     */
    public function testUnplayedMatchdayShowsMessage(): void
    {
        $this->createSampleData();

        $html = $this->controller->matchdayDetail(2);

        self::assertStringContainsString('noch nicht gespielt', $html);
        self::assertStringNotContainsString('Schnittliste', $html);
    }

    /**
     * R16.1: the rendered league table contains both teams.
     */
    public function testLeagueTableHtml(): void
    {
        $this->createSampleData();

        $html = $this->controller->leagueTable(1);

        self::assertStringContainsString('Ligatabelle', $html);
        self::assertStringContainsString('Die Adler', $html);
        self::assertStringContainsString('Geisterteam', $html);
    }

    /**
     * R2: the team names of the league table lead to the team page; the ghost
     * team is not linked.
     */
    public function testLeagueTableLinksTeams(): void
    {
        $this->createSampleData();

        $html = $this->controller->leagueTable(1);

        self::assertStringContainsString('<a class="table__team-link" href="/team/1">Die Adler</a>', $html);
        self::assertStringNotContainsString('href="/team/2"', $html);
    }

    /**
     * R2.1: the team page shows the average list after the last played
     * matchday; blind games do not count (R27.13).
     */
    public function testTeamPageShowsCurrentAverageList(): void
    {
        $this->createSampleData();

        $html = $this->controller->teamAverageList(1);

        self::assertStringContainsString('<h1>Die Adler</h1>', $html);
        self::assertStringContainsString('Stand nach Spieltag 1 · Mo, 08.09.2025', $html);
        self::assertStringContainsString('Anna', $html);
        self::assertStringContainsString('<td>3</td><td>160,00</td>', $html, 'Anna: (150 + 160 + 170) / 3, with two decimals.');
        self::assertStringContainsString('Bernd', $html);
        self::assertStringContainsString('href="/table?season=1"', $html);
    }

    public function testTeamPageGhostTeamAndUnknownTeam(): void
    {
        $this->createSampleData();

        self::assertStringContainsString('nicht gefunden', $this->controller->teamAverageList(2));
        self::assertStringContainsString('nicht gefunden', $this->controller->teamAverageList(99));
    }

    /**
     * R14.7: a season without matchdays returns the empty message.
     */
    public function testEmptySeasonMatchdayList(): void
    {
        // Season without matchdays.
        $this->insert('INSERT INTO season (id, name, start_date, end_date) VALUES (5, :n, :s, :e)', ['n' => 'Leer', 's' => '2025-09-01', 'e' => '2026-06-30']);

        $html = $this->controller->matchdayList(5);

        self::assertStringContainsString('keine Spieltage', $html);
    }

    // --- Beispieldaten ---

    private function createSampleData(): void
    {
        $this->insert('INSERT INTO season (id, name, start_date, end_date) VALUES (1, :n, :s, :e)', ['n' => 'Testsaison', 's' => '2025-09-01', 'e' => '2026-06-30']);
        $this->insert('INSERT INTO season_config (season_id, lane_count, target_score) VALUES (1, 4, 190)', []);

        $this->insert('INSERT INTO matchday (id, season_id, matchday_number, date, is_played) VALUES (1, 1, 1, :d, 1)', ['d' => '2025-09-08']);
        $this->insert('INSERT INTO matchday (id, season_id, matchday_number, date, is_played) VALUES (2, 1, 2, :d, 0)', ['d' => '2025-09-15']);

        $this->insert('INSERT INTO team (id) VALUES (1)', []);
        $this->insert('INSERT INTO team_season (id, team_id, season_id, team_name, is_ghost_team) VALUES (1, 1, 1, :n, 0)', ['n' => 'Die Adler']);
        $this->insert('INSERT INTO player (id, name, gender) VALUES (1, :n, :g)', ['n' => 'Anna', 'g' => 'female']);
        $this->insert('INSERT INTO player (id, name, gender) VALUES (2, :n, :g)', ['n' => 'Bernd', 'g' => 'male']);
        $this->insert('INSERT INTO roster (team_season_id, player_id) VALUES (1, 1)', []);
        $this->insert('INSERT INTO roster (team_season_id, player_id) VALUES (1, 2)', []);

        $this->insert('INSERT INTO team (id) VALUES (2)', []);
        $this->insert('INSERT INTO team_season (id, team_id, season_id, team_name, is_ghost_team) VALUES (2, 2, 1, :n, 1)', ['n' => 'Geisterteam']);

        $this->insert('INSERT INTO fixture (id, matchday_id, home_team_season_id, away_team_season_id) VALUES (1, 1, 1, 2)', []);

        $this->insert('INSERT INTO frozen_handicap (matchday_id, player_id, handicap) VALUES (1, 1, 20)', []);
        $this->insert('INSERT INTO frozen_handicap (matchday_id, player_id, handicap) VALUES (1, 2, 10)', []);

        // Real team: 4 complete positions (two real players, two blind).
        $this->createPositionWithResult(1, 1, 1, 1, [150, 160, 170], false);
        $this->createPositionWithResult(2, 1, 1, 2, [140, 150, 155], false);
        $this->createPositionWithResult(3, 1, 1, null, [120, 120, 120], true);
        $this->createPositionWithResult(4, 1, 1, null, [120, 120, 120], true);

        // Ghost team: 4 complete positions (fixed net = target score).
        for ($pos = 1; $pos <= 4; $pos++) {
            $this->createPositionWithResult($pos, 1, 2, null, [190, 190, 190], false);
        }
    }

    private function createOriginalScoreSheet(int $fixtureId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO original_score_sheet (fixture_id, content_hash, size_bytes, mime_type) VALUES (:fx, :d, 32, :m)');
        $stmt->bindValue(':fx', $fixtureId, PDO::PARAM_INT);
        $stmt->bindValue(':d', hash('sha256', random_bytes(32)), PDO::PARAM_STR);
        $stmt->bindValue(':m', 'image/jpeg', PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * @param array{0: int, 1: int, 2: int} $net
     */
    private function createPositionWithResult(int $position, int $fixtureId, int $teamSeasonId, ?int $playerId, array $net, bool $isBlind): void
    {
        $lineupId = $this->insert(
            'INSERT INTO lineup (fixture_id, team_season_id, sheet_row, player_id) VALUES (:fx, :ts, :pos, :sp)',
            ['fx' => $fixtureId, 'ts' => $teamSeasonId, 'pos' => $position, 'sp' => $playerId]
        );

        foreach ($net as $index => $value) {
            $this->insert(
                'INSERT INTO game_result (lineup_id, game_number, net, is_blind) VALUES (:lineup, :number, :net, :blind)',
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
        foreach (['db/migrations/001_schema.sql', 'db/migrations/002_points_rules.sql'] as $file) {
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
