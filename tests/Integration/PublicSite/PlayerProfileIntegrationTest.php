<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\PublicSite;

use Bowlingliga\Application\PlayerProfile\PlayerProfiles;
use Bowlingliga\Domain\PlayerProfile\PlayerHistoryPoint;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\DatabaseConnectionException;
use Bowlingliga\Persistence\MigrationRunner;
use Bowlingliga\Persistence\PlayerProfile\PlayerProfileRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration test of the player page against MariaDB: seasons with team,
 * played matchdays, frozen handicaps and the previous season.
 *
 * Validates: R39.2, R39.3, R39.4, R39.5, R39.6, R29.2
 *
 * Anna played three games of 150 for "Adler" in the earlier season. In the
 * later season she is on the roster of "Falken": she misses matchday 1,
 * plays three games of 180 on matchday 2 (handicap 30 frozen, one more game
 * marked blind) and has results in a fixture of matchday 3 that is not
 * complete yet. Ben has only played for a team, without a roster.
 */
final class PlayerProfileIntegrationTest extends TestCase
{
    private PDO $pdo;
    private PlayerProfileRepository $repository;
    private int $earlier;
    private int $later;
    private int $anna;
    private int $ben;
    private int $falken;

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
            $database  = new Database($config);
            $this->pdo = $database->pdo();
        } catch (DatabaseConnectionException $e) {
            self::markTestSkipped('No reachable MySQL test database: ' . $e->getMessage());
        }

        $this->dropSchema();
        (new MigrationRunner($this->pdo, dirname(__DIR__, 3) . '/db/migrations'))->run();
        $this->repository = new PlayerProfileRepository($database);

        $this->earlier = $this->season('2025/26', '2025-09-03');
        $this->later   = $this->season('2026/27', '2026-09-02');
        $this->anna    = $this->insert("INSERT INTO player (name, gender) VALUES ('Anna', 'female')");
        $this->ben     = $this->insert("INSERT INTO player (name, gender) VALUES ('Ben', 'male')");

        $adler = $this->teamSeason($this->earlier, 'Adler');
        $eulen = $this->teamSeason($this->earlier, 'Eulen');
        $this->insert("INSERT INTO roster (team_season_id, player_id) VALUES ($adler, {$this->anna})");
        $old = $this->fixture($this->matchday($this->earlier, 1, '2025-09-03'), $adler, $eulen, 'complete');
        $this->games($old, $adler, 1, $this->anna, [150, 150, 150]);

        $this->falken = $this->teamSeason($this->later, 'Falken');
        $raben        = $this->teamSeason($this->later, 'Raben');
        $this->insert("INSERT INTO roster (team_season_id, player_id) VALUES ({$this->falken}, {$this->anna})");

        $first = $this->fixture($this->matchday($this->later, 1, '2026-09-02'), $this->falken, $raben, 'complete');
        $this->games($first, $raben, 1, $this->ben, [140, 140, 140]);

        $second   = $this->matchday($this->later, 2, '2026-09-09');
        $fixture2 = $this->fixture($second, $this->falken, $raben, 'complete');
        $this->games($fixture2, $this->falken, 1, $this->anna, [180, 180, 180]);
        $this->insert("INSERT INTO frozen_handicap (matchday_id, player_id, handicap) VALUES ($second, {$this->anna}, 30)");
        $blindRow = $this->insert("INSERT INTO lineup (fixture_id, team_season_id, sheet_row, player_id, is_blind) VALUES ($fixture2, {$this->falken}, 2, {$this->anna}, 1)");
        $this->insert("INSERT INTO game_result (lineup_id, game_number, net, is_blind) VALUES ($blindRow, 1, 100, 1)");

        $third = $this->fixture($this->matchday($this->later, 3, '2026-09-16'), $this->falken, $raben, 'in_progress');
        $this->games($third, $this->falken, 1, $this->anna, [100, 100, 100]);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
    }

    public function testThePlayerAndTheirSeasonsWithTeam(): void
    {
        self::assertSame(['id' => $this->anna, 'name' => 'Anna', 'gender' => 'female'], $this->repository->player($this->anna));
        self::assertNull($this->repository->player(999));

        self::assertSame(
            [[$this->earlier, 'Adler'], [$this->later, 'Falken']],
            array_map(static fn (array $s): array => [$s['season_id'], $s['team_name']], $this->repository->seasons($this->anna)),
        );
        self::assertSame(
            [[$this->later, 'Raben']],
            array_map(static fn (array $s): array => [$s['season_id'], $s['team_name']], $this->repository->seasons($this->ben)),
            'A lineup without a roster counts as well.',
        );
    }

    public function testRawDataOfASeason(): void
    {
        $raw = $this->repository->seasonRawData($this->anna, $this->later);

        self::assertSame([1, 2], array_map(static fn ($m): int => $m->matchdayNumber, $raw->matchdays), 'Only matchdays with a complete fixture.');
        self::assertSame([], $raw->matchdays[0]->games);
        self::assertCount(4, $raw->matchdays[1]->games, 'Three games and the blind one.');
        self::assertSame(30, $raw->matchdays[1]->handicap);
        self::assertNull($raw->matchdays[0]->handicap);
        self::assertCount(3, $raw->previousSeasonGames, 'Without a record the games of the preceding season count.');
        self::assertNull($raw->record);
        self::assertSame(5, $raw->previousSeasonMatchdays);
    }

    public function testARecordOfTheSeasonReplacesThePrecedingSeason(): void
    {
        $this->insert("INSERT INTO previous_season_record (season_id, player_id, game_count, net_pins_total) VALUES ({$this->later}, {$this->anna}, 30, 5400)");

        $raw = $this->repository->seasonRawData($this->anna, $this->later);

        self::assertSame([], $raw->previousSeasonGames);
        self::assertSame(30, $raw->record?->numberOfGames);
    }

    public function testTheProfileStartsOnTheFirstMatchdayWithThePreviousSeason(): void
    {
        $profile = (new PlayerProfiles($this->repository))->profile($this->anna, null, $this->later);

        self::assertNotNull($profile);
        self::assertSame($this->later, $profile->selectedSeasonId);
        self::assertSame(
            [[1, 150.0, null, 0], [2, 165.0, 30, 3]],
            array_map(
                static fn (PlayerHistoryPoint $p): array => [$p->matchdayNumber, round((float) $p->average, 2), $p->handicap, $p->games],
                $profile->history->points,
            ),
            'Matchday 1 with the previous season value, matchday 2 with (450 + 540) / 6.',
        );
        self::assertSame(['Falken', 'Adler'], array_map(static fn ($s): string => $s->teamName, $profile->seasons));
        self::assertSame($this->falken, $profile->seasons[0]->teamSeasonId);
        self::assertSame(1, $profile->seasons[0]->matchdaysPlayed);
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

    private function teamSeason(int $seasonId, string $name): int
    {
        $team = $this->insert('INSERT INTO team () VALUES ()');

        return $this->insert("INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team) VALUES ($team, $seasonId, '$name', 0)");
    }

    private function matchday(int $seasonId, int $number, string $date): int
    {
        return $this->insert("INSERT INTO matchday (season_id, matchday_number, date, matchday_type, is_played) VALUES ($seasonId, $number, '$date', 'regular', 1)");
    }

    private function fixture(int $matchdayId, int $home, int $away, string $status): int
    {
        return $this->insert("INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, completion_status) VALUES ($matchdayId, $home, $away, '$status')");
    }

    /**
     * @param list<int> $nets
     */
    private function games(int $fixtureId, int $teamSeasonId, int $row, int $playerId, array $nets): void
    {
        $lineup = $this->insert("INSERT INTO lineup (fixture_id, team_season_id, sheet_row, player_id) VALUES ($fixtureId, $teamSeasonId, $row, $playerId)");
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
