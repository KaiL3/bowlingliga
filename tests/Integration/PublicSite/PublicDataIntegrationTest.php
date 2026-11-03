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
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Integration test of the production {@see PublicDataRepository} adapter
 * against a local MySQL/MariaDB test database (task 34.2).
 *
 * Validates: R1.3, R2.1, R14.2, R15.1, R15.3, R15.4, R15.5, R16.1
 *
 * Checks that the adapter orchestrates a season's raw data correctly into the
 * public display data structures: matchday list with played status, detail
 * view (team names, results, average lists, original score sheet), league
 * table and the null cases (unknown season/matchday).
 *
 * The real schema (migrations 001 and 003) is applied per test and removed
 * afterwards. If the PDO MySQL driver is missing or no database is reachable,
 * the test is skipped in a controlled way.
 */
final class PublicDataIntegrationTest extends TestCase
{
    private Database $database;
    private PDO $pdo;
    private PublicDataRepository $reader;

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

        $this->reader = new PublicDataRepository(
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
     * R14.2/R1.3: the matchday list contains the matchday with team names and
     * played status.
     */
    public function testMatchdayListFromRawData(): void
    {
        $this->createSampleData();

        $items = $this->reader->matchdayList(1);

        self::assertCount(1, $items);
        self::assertSame('Die Adler', $items[0]->homeName);
        self::assertSame('Geisterteam', $items[0]->awayName);
        self::assertTrue($items[0]->isPlayed);
    }

    /**
     * R15.1/R15.3/R15.4: the detail view returns per fixture the team names and
     * the digital score sheet, the average list of the real team and the link
     * to the original score sheet.
     */
    public function testMatchdayDetailFromRawData(): void
    {
        $this->createSampleData();

        $detail = $this->reader->matchdayDetail(1);

        self::assertNotNull($detail);
        self::assertCount(1, $detail->fixtures, 'The matchday has one fixture.');

        $fixture = $detail->fixtures[0];
        self::assertSame('Die Adler', $fixture->home->teamName);
        self::assertSame('Geisterteam', $fixture->away->teamName);
        self::assertCount(4, $fixture->home->rows, 'The score sheet lists the 4 rows of the evening.');
        self::assertFalse($fixture->averageListHome->isEmpty(), 'The average list of the real team must not be empty.');
        self::assertTrue($fixture->hasOriginalScoreSheet(), 'The uploaded original score sheet must be linked (R15.4).');
    }

    /**
     * R15.5: for an unknown matchday the adapter returns no detail.
     */
    public function testUnknownMatchdayReturnsNoDetail(): void
    {
        $this->createSampleData();

        self::assertNull($this->reader->matchdayDetail(999));
        self::assertFalse($this->reader->isMatchdayPlayed(999));
    }

    /**
     * R16.1: the league table is returned; null for an unknown season.
     */
    public function testLeagueTableAndUnknownSeason(): void
    {
        $this->createSampleData();

        self::assertNotNull($this->reader->leagueTable(1));
        self::assertNull($this->reader->leagueTable(999));
    }

    // --- Sample data (like in the raw data reader integration test) ---

    /**
     * R14.8: the next matchday is the first one not played yet from today on,
     * with its fixtures sorted by lane pair.
     */
    public function testNextMatchdayWithFixturesSortedByLane(): void
    {
        $this->createSampleData();
        $this->insert('INSERT INTO team (id) VALUES (3)', []);
        $this->insert('INSERT INTO team_season (id, team_id, season_id, team_name, is_ghost_team) VALUES (3, 3, 1, :n, 0)', ['n' => 'Die Falken']);
        $this->insert('INSERT INTO lane_pair (id, season_id, lane_a, lane_b) VALUES (1, 1, 3, 4), (2, 1, 1, 2)', []);
        // Matchday 2 lies in the past without results, matchday 3 is next.
        $this->insert('INSERT INTO matchday (id, season_id, matchday_number, date) VALUES (2, 1, 2, :d)', ['d' => '2025-09-15']);
        $this->insert('INSERT INTO matchday (id, season_id, matchday_number, date) VALUES (3, 1, 3, :d)', ['d' => '2025-09-22']);
        $this->insert("INSERT INTO matchday (id, season_id, matchday_number, date, matchday_type) VALUES (4, 1, 4, :d, 'lot_draw')", ['d' => '2025-09-29']);
        $this->insert('INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, lane_pair_id) VALUES (3, 1, 2, 1), (3, 3, 1, 2)', []);

        $next = $this->reader->nextMatchday(1, '2025-09-16');

        self::assertNotNull($next);
        self::assertSame(3, $next->number);
        self::assertSame([
            ['home' => 'Die Falken', 'away' => 'Die Adler', 'home_lane' => 1, 'away_lane' => 2],
            ['home' => 'Die Adler', 'away' => 'Geisterteam', 'home_lane' => 3, 'away_lane' => 4],
        ], $next->fixtures);

        $lotDraw = $this->reader->nextMatchday(1, '2025-09-23');
        self::assertSame('lot_draw', $lotDraw?->type);
        self::assertSame([], $lotDraw->fixtures);
        self::assertNull($this->reader->nextMatchday(1, '2025-10-01'), 'No matchday left.');
    }

    private function createSampleData(): void
    {
        $this->insert('INSERT INTO season (id, name, start_date, end_date) VALUES (1, :n, :s, :e)', ['n' => 'Testsaison', 's' => '2025-09-01', 'e' => '2026-06-30']);
        $this->insert('INSERT INTO season_config (season_id, lane_count, target_score) VALUES (1, 4, 190)', []);

        $this->insert('INSERT INTO matchday (id, season_id, matchday_number, date, is_played) VALUES (1, 1, 1, :d, 1)', ['d' => '2025-09-08']);

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

        $this->createPositionWithResult(1, 1, 1, 1, [150, 160, 170], false);
        $this->createPositionWithResult(2, 1, 1, 2, [140, 150, 155], false);
        $this->createPositionWithResult(3, 1, 1, null, [120, 120, 120], true);
        $this->createPositionWithResult(4, 1, 1, null, [120, 120, 120], true);

        for ($pos = 1; $pos <= 4; $pos++) {
            $this->createPositionWithResult($pos, 1, 2, null, [190, 190, 190], false);
        }

        // Original score sheet for the fixture.
        $stmt = $this->pdo->prepare('INSERT INTO original_score_sheet (fixture_id, content_hash, size_bytes, mime_type) VALUES (1, :d, 64, :m)');
        $stmt->bindValue(':d', hash('sha256', random_bytes(64)), PDO::PARAM_STR);
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
        foreach (['db/migrations/001_schema.sql'] as $file) {
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
