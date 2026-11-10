<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\MasterData;

use Bowlingliga\Application\Import\PlayerCsvParser;
use Bowlingliga\I18n\CatalogImportVocabulary;
use Bowlingliga\Application\Import\PlayerImport;
use Bowlingliga\Application\Import\PlayerImportException;
use Bowlingliga\Application\PlayerManagement;
use Bowlingliga\Application\PreviousSeasonRecordManagement;
use Bowlingliga\Application\PreviousSeasonRecordStore;
use Bowlingliga\Application\TeamManagement;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\Import\PlayerImportRepository;
use Bowlingliga\Persistence\PdoTransaction;
use Bowlingliga\Persistence\PlayerRepository;
use Bowlingliga\Persistence\PreviousSeasonContextRepository;
use Bowlingliga\Persistence\PreviousSeasonRecordRepository;
use Bowlingliga\Persistence\RosterRepository;
use Bowlingliga\Persistence\SeasonRepository;
use Bowlingliga\Persistence\TeamRepository;
use Bowlingliga\Persistence\TeamSeasonRepository;
use Bowlingliga\Security\Validator;
use Bowlingliga\Tests\GermanText;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Integration test of the player import against the test database: teams,
 * players, roster and previous season records in one transaction, and the
 * previous season context from the raw data.
 */
final class PlayerImportIntegrationTest extends TestCase
{
    private const FILE = "Team;Name;Geschlecht;Vorjahr Schnitt;Vorjahr Spiele\n"
        . "Pin Busters;Kurt;m;143,64;99\n"
        . "Pin Busters;Conrad;m;160;96\n"
        . "Jolly Rollers;Alma;w;;\n";

    private Database $database;
    private PDO $pdo;
    private int $seasonId;

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
        $this->pdo->exec("INSERT INTO season (name, start_date, end_date) VALUES ('Saison 2099', '2099-09-02', '2100-05-26')");
        $this->seasonId = (int) $this->pdo->lastInsertId();
        $team = $this->insert('INSERT INTO team () VALUES ()');
        $this->insert("INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team) VALUES ($team, {$this->seasonId}, 'Pin Busters', 0)");
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

    public function testImportStoresTeamsPlayersRosterAndPreviousSeason(): void
    {
        $result = $this->import()->import($this->seasonId, self::FILE);

        self::assertSame(['players' => 3, 'new_teams' => 1, 'records' => 2], $result);
        self::assertSame(
            [['name' => 'Alma', 'gender' => 'female', 'team_name' => 'Jolly Rollers'],
             ['name' => 'Conrad', 'gender' => 'male', 'team_name' => 'Pin Busters'],
             ['name' => 'Kurt', 'gender' => 'male', 'team_name' => 'Pin Busters']],
            $this->pdo->query(
                'SELECT p.name, p.gender, ts.team_name FROM player p
                 JOIN roster r ON r.player_id = p.id JOIN team_season ts ON ts.id = r.team_season_id ORDER BY p.name'
            )->fetchAll(PDO::FETCH_ASSOC)
        );
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM team_season')->fetchColumn(), 'Pin Busters reused.');
        self::assertSame(
            [['name' => 'Conrad', 'game_count' => 96, 'net_pins_total' => 15360], ['name' => 'Kurt', 'game_count' => 99, 'net_pins_total' => 14220]],
            array_map(
                static fn (array $row): array => ['name' => $row['name'], 'game_count' => (int) $row['game_count'], 'net_pins_total' => (int) $row['net_pins_total']],
                $this->pdo->query(
                    'SELECT p.name, v.game_count, v.net_pins_total FROM previous_season_record v JOIN player p ON p.id = v.player_id WHERE v.season_id = ' . $this->seasonId . ' ORDER BY p.name'
                )->fetchAll(PDO::FETCH_ASSOC)
            )
        );
    }

    public function testSecondImportOfTheSameFileIsRejected(): void
    {
        $this->import()->import($this->seasonId, self::FILE);

        try {
            $this->import()->import($this->seasonId, self::FILE);
            self::fail('Existing names must abort the import.');
        } catch (PlayerImportException $e) {
            self::assertContains('Zeile 2: Einen Spieler „Kurt“ gibt es schon.', GermanText::all($e->errors));
        }
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM player')->fetchColumn());
    }

    public function testFailureRollsBackTheWholeImport(): void
    {
        $failingRecords = new class implements PreviousSeasonRecordStore {
            public function save(int $seasonId, int $playerId, int $numberOfGames, int $netPinsTotal): void
            {
                throw new \RuntimeException('Speichern fehlgeschlagen.');
            }

            public function delete(int $seasonId, int $playerId): void
            {
            }

            public function find(int $playerId): ?array
            {
                return null;
            }
        };

        try {
            $this->import($failingRecords)->import($this->seasonId, self::FILE);
            self::fail('The error must be passed on.');
        } catch (\RuntimeException $e) {
            self::assertSame('Speichern fehlgeschlagen.', $e->getMessage());
        }

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM player')->fetchColumn(), 'No players saved.');
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM team_season')->fetchColumn(), 'No team created.');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM roster')->fetchColumn());
    }

    public function testPreviousSeasonContextFromRawData(): void
    {
        $before = new PreviousSeasonContextRepository($this->database, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2099-09-01'));
        self::assertFalse($before->isSeasonStarted($this->seasonId));

        $started = new PreviousSeasonContextRepository($this->database, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2099-09-02'));
        self::assertTrue($started->isSeasonStarted($this->seasonId), 'From its start date the season counts as started (R29.9).');
        self::assertFalse($started->isSeasonStarted($this->seasonId + 99), 'An unknown season has not started.');

        // Only the season itself counts: an earlier season that has started does not block a later one.
        $this->pdo->exec("INSERT INTO season (name, start_date, end_date) VALUES ('Saison 2100', '2100-09-01', '2101-05-27')");
        self::assertFalse($started->isSeasonStarted((int) $this->pdo->lastInsertId()));
    }

    private function import(?PreviousSeasonRecordStore $records = null): PlayerImport
    {
        $access = new SwitchableWriteAccess(true);
        $validator = new Validator();
        $rosters = new RosterRepository($this->database);
        $teamSeasons = new TeamSeasonRepository($this->database);
        $context = new PreviousSeasonContextRepository($this->database, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2099-08-01'));

        return new PlayerImport(
            new PlayerCsvParser(CatalogImportVocabulary::all()),
            new PlayerImportRepository($this->database),
            $context,
            new PlayerManagement(new PlayerRepository($this->database), $rosters, $validator, $access),
            new TeamManagement(new TeamRepository($this->database), $teamSeasons, new SeasonRepository($this->database), $validator, $access),
            new PreviousSeasonRecordManagement($records ?? new PreviousSeasonRecordRepository($this->database), $context, $validator, $access),
            new PdoTransaction($this->database),
            $validator,
            $access,
        );
    }

    private function insert(string $sql): int
    {
        $this->pdo->exec($sql);

        return (int) $this->pdo->lastInsertId();
    }

    private function applySchema(): void
    {
        $this->dropSchema();

        $root = dirname(__DIR__, 3);
        foreach ([
            'db/migrations/001_schema.sql',
            'db/migrations/002_points_rules.sql',
            'db/migrations/003_players_public.sql',
            'db/migrations/004_matchday_cancelled.sql',
            'db/migrations/005_trusted_device.sql',
        ] as $file) {
            $this->pdo->exec((string) file_get_contents($root . '/' . $file));
        }
    }

    private function dropSchema(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'fixture_log', 'team_entry_game', 'team_entry', 'game_result', 'lineup', 'original_score_sheet', 'fixture', 'lane_pair',
            'frozen_handicap', 'roster', 'team_season', 'team', 'matchday', 'season_config', 'season',
            'player', 'previous_season_record', 'admin_account', 'login_attempt', 'trusted_device', 'home_page_post',
            'rules', 'privacy_policy', 'legal_notice', 'league_setting',
            'cache_average', 'cache_league_table', 'cache_matchday_table', 'cache_honours',
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
