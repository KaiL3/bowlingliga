<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\TeamEntry;

use Bowlingliga\Application\ResultEntry\BlindFilling;
use Bowlingliga\Application\ResultEntry\BlindPlayerOrder;
use Bowlingliga\Application\ResultEntry\ResultRecording;
use Bowlingliga\Application\TeamEntry\TeamEntryException;
use Bowlingliga\Application\TeamEntry\TeamEntryWriteAccess;
use Bowlingliga\Application\TeamEntry\TeamResultEntry;
use Bowlingliga\Cache\CacheManager;
use Bowlingliga\Persistence\Aggregation\TableRawDataRepository;
use Bowlingliga\Persistence\CacheRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\FixtureRepository;
use Bowlingliga\Persistence\FrozenHandicapRepository;
use Bowlingliga\Persistence\MatchdayRepository;
use Bowlingliga\Persistence\MigrationRunner;
use Bowlingliga\Persistence\PdoTransaction;
use Bowlingliga\Persistence\ResultEntry\HandicapFreezingRepository;
use Bowlingliga\Persistence\ResultRepository;
use Bowlingliga\Persistence\RosterRepository;
use Bowlingliga\Persistence\TeamEntry\TeamEntryRepository;
use Bowlingliga\Security\TeamEntryCodes;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\WriteAccess;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Result entry by the teams against the test database (R37, migrations 011
 * and 014): the complete evening from the own entries to the final saving
 * with frozen handicaps, blind slots of a small roster, and the takeover by
 * the admin.
 */
final class TeamEntryIntegrationTest extends TestCase
{
    private const DATE = '2030-09-04';

    private PDO $pdo;
    private Database $database;
    private TeamEntryRepository $store;
    private TeamEntryCodes $codes;
    private TeamResultEntry $entry;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        if (! in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The PDO MySQL driver is not available.');
        }

        $this->database = new Database([
            'host'            => self::env('BOWLINGLIGA_TEST_DB_HOST', '127.0.0.1'),
            'port'            => (int) self::env('BOWLINGLIGA_TEST_DB_PORT', '3306'),
            'dbname'          => self::env('BOWLINGLIGA_TEST_DB_NAME', 'bowlingliga_test'),
            'user'            => self::env('BOWLINGLIGA_TEST_DB_USER', 'root'),
            'password'        => self::env('BOWLINGLIGA_TEST_DB_PASSWORD', ''),
            'charset'         => self::env('BOWLINGLIGA_TEST_DB_CHARSET', 'utf8mb4'),
            'connect_timeout' => 5,
        ]);
        try {
            $this->pdo = $this->database->pdo();
        } catch (Throwable $e) {
            self::markTestSkipped('No reachable test database: ' . $e->getMessage());
        }

        $this->dropSchema();
        (new MigrationRunner($this->pdo, dirname(__DIR__, 3) . '/db/migrations'))->run();
        $this->createLeague();

        $this->store = new TeamEntryRepository($this->database);
        $this->codes = new TeamEntryCodes('integration');
        $this->entry = $this->buildEntry(new SwitchableWriteAccess(true));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
    }

    public function testBothTeamsEnterSendAndConfirmAndTheResultsAreFinal(): void
    {
        $home = $this->codes->create($this->ids['fixture'], 'home', 1);
        $away = $this->codes->create($this->ids['fixture'], 'away', 1);

        $this->enterAll($home, 'h', 170);
        $this->enterAll($away, 'a', 160);
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM game_result'), 'Nothing counts during the entry.');
        self::assertCount(1, $this->store->activeFixtures($this->ids['season']));

        $this->entry->submit($home);
        self::assertFalse($this->entry->confirm($away), 'The away team confirms before sending its own entry.');
        $this->entry->submit($away);
        self::assertTrue($this->entry->confirm($home));

        self::assertSame(24, $this->numeral('SELECT COUNT(*) FROM game_result'));
        self::assertSame(12 * 170, $this->numeral(
            "SELECT SUM(net) FROM game_result gr JOIN lineup l ON l.id = gr.lineup_id WHERE l.team_season_id = {$this->ids['h']}"
        ));
        self::assertSame('complete', $this->pdo->query("SELECT completion_status FROM fixture WHERE id = {$this->ids['fixture']}")->fetchColumn());
        self::assertSame(1, $this->numeral('SELECT is_played FROM matchday'));
        self::assertSame(8, $this->numeral('SELECT COUNT(*) FROM frozen_handicap'), 'Handicaps of the 8 players are frozen.');
        self::assertSame(2, $this->numeral('SELECT COUNT(*) FROM team_entry WHERE closed_at IS NOT NULL'));
        self::assertSame([], $this->store->activeFixtures($this->ids['season']));

        // History: one row per changed value, the steps without a value in order.
        $steps = $this->pdo->query('SELECT action FROM fixture_log WHERE player_id IS NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['submitted', 'confirmed', 'submitted', 'confirmed', 'final'], $steps);
        self::assertSame(24, $this->numeral("SELECT COUNT(*) FROM fixture_log WHERE action = 'lineup' AND value_after = 'spielt'"), 'Every lineup mark on its own.');
        self::assertSame(24, $this->numeral("SELECT COUNT(*) FROM fixture_log WHERE action = 'net' AND value_before IS NULL"));
        $entry = $this->pdo->query("SELECT side, actor, value_after FROM fixture_log WHERE action = 'net' AND player_id = {$this->ids['h1']} AND game_number = 2")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['side' => 'home', 'actor' => null, 'value_after' => '170'], $entry, 'The home team entered its own net.');
    }

    /** R27.18: a roster of two – the free positions play like ghost players (ghost score, HDC 0). */
    public function testATwoPlayerRosterIsSavedWithBlindSlots(): void
    {
        $this->pdo->exec("DELETE FROM roster WHERE player_id IN ({$this->ids['h3']}, {$this->ids['h4']}, {$this->ids['h5']})");
        $home = $this->codes->create($this->ids['fixture'], 'home', 1);
        $away = $this->codes->create($this->ids['fixture'], 'away', 1);
        foreach ([1, 2, 3] as $game) {
            $this->entry->saveGame($home, $game, [$this->ids['h1'] => 'plays', $this->ids['h2'] => 'plays'], [$this->ids['h1'] => '170', $this->ids['h2'] => '150']);
        }
        $this->enterAll($away, 'a', 160);
        $this->entry->submit($home);
        $this->entry->submit($away);
        $this->entry->confirm($away);
        self::assertTrue($this->entry->confirm($home));

        $slots = $this->pdo->query(
            "SELECT gr.net FROM lineup l JOIN game_result gr ON gr.lineup_id = l.id
             WHERE l.team_season_id = {$this->ids['h']} AND l.player_id IS NULL AND l.is_blind = 1"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(6, $slots);
        self::assertSame([190], array_values(array_unique(array_map('intval', $slots))), 'The season\'s ghost score.');

        // The table counts the slots without handicap, the players with theirs.
        self::assertGreaterThan(0, $this->numeral("SELECT handicap FROM frozen_handicap WHERE player_id = {$this->ids['h1']}"));
        $fixture = (new TableRawDataRepository($this->database))->findFixturesForSeason($this->ids['season'])[0];
        $hdc = array_map(static fn ($row): int => $row->handicap, $fixture->home->rows);
        self::assertCount(4, $hdc, 'Two players and two blind slots.');
        self::assertSame([0, 0], array_slice($hdc, 2));
    }

    /** R37.10: against the ghost team sending makes the results final. */
    public function testAgainstTheGhostTeamSendingMakesTheResultsFinal(): void
    {
        $this->pdo->exec("UPDATE team_season SET is_ghost_team = 1 WHERE id = {$this->ids['a']}");
        $this->pdo->exec("UPDATE season_config SET ghost_score = 185");
        self::assertNull($this->entry->codeFor($this->ids['fixture'], 'away'));
        $home = (string) $this->entry->codeFor($this->ids['fixture'], 'home');

        $this->enterAll($home, 'h', 170);
        self::assertTrue($this->entry->submit($home));

        self::assertSame(24, $this->numeral('SELECT COUNT(*) FROM game_result'));
        self::assertSame(12 * 185, $this->numeral(
            "SELECT SUM(net) FROM game_result gr JOIN lineup l ON l.id = gr.lineup_id WHERE l.team_season_id = {$this->ids['a']} AND l.player_id IS NULL"
        ), 'The ghost team plays its fixed score with four ghost players.');
        self::assertSame('complete', $this->pdo->query("SELECT completion_status FROM fixture WHERE id = {$this->ids['fixture']}")->fetchColumn());
        self::assertSame(4, $this->numeral('SELECT COUNT(*) FROM frozen_handicap'), 'Handicaps of the 4 real players.');
    }

    /**
     * R30.20: with an incomplete earlier matchday the confirmation is rejected
     * with a note; the sent entries stay for the admin.
     */
    public function testAnIncompleteEarlierMatchdayRejectsTheConfirmation(): void
    {
        $this->pdo->exec('UPDATE matchday SET matchday_number = 2');
        $this->pdo->exec("INSERT INTO matchday (season_id, matchday_number, date) VALUES ({$this->ids['season']}, 1, DATE_SUB('" . self::DATE . "', INTERVAL 7 DAY))");
        $home = $this->codes->create($this->ids['fixture'], 'home', 1);
        $away = $this->codes->create($this->ids['fixture'], 'away', 1);
        $this->enterAll($home, 'h', 170);
        $this->enterAll($away, 'a', 160);
        $this->entry->submit($home);
        $this->entry->submit($away);
        $this->entry->confirm($away);

        try {
            $this->entry->confirm($home);
            self::fail('An incomplete earlier matchday must reject the confirmation (R30.20).');
        } catch (TeamEntryException $e) {
            self::assertSame('team_entry.error.earlier_matchday_incomplete', $e->message()->key);
        }

        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM game_result'), 'Nothing is final.');
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM frozen_handicap'));
        self::assertSame(2, $this->numeral('SELECT COUNT(*) FROM team_entry WHERE closed_at IS NULL'), 'The entries stay for the admin.');
    }

    public function testAdminTakesTheEntryOverIntoTheVirtualScoreSheet(): void
    {
        $this->enterAll($this->codes->create($this->ids['fixture'], 'away', 1), 'a', 150);
        $this->entry->saveGame($this->codes->create($this->ids['fixture'], 'home', 1), 1, [$this->ids['h1'] => 'plays'], [$this->ids['h1'] => '170']);

        self::assertSame(16, $this->entry->takeOver($this->ids['fixture']), '12 of the away team, 1 + 3 blind of the home team.');

        self::assertSame(16, $this->numeral('SELECT COUNT(*) FROM game_result'));
        self::assertSame('in_progress', $this->pdo->query("SELECT completion_status FROM fixture WHERE id = {$this->ids['fixture']}")->fetchColumn());
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM frozen_handicap'), 'A draft freezes nothing.');
        self::assertSame([], $this->store->activeFixtures($this->ids['season']));
    }

    public function testRenewedCodesAndSwitchArePersisted(): void
    {
        $this->store->renewCodes($this->ids['fixture']);
        $this->store->renewCodes($this->ids['fixture']);
        self::assertSame(3, $this->store->sides($this->ids['fixture'])['away']->codeVersion);

        $this->store->setEnabled($this->ids['season'], false);
        self::assertFalse($this->store->isEnabled($this->ids['season']));
        self::assertNull($this->entry->codeFor($this->ids['fixture'], 'home'));
    }

    public function testDeletingTheFixtureRemovesItsTeamEntry(): void
    {
        $this->entry->saveGame($this->codes->create($this->ids['fixture'], 'home', 1), 1, [$this->ids['h1'] => 'plays'], []);
        $this->pdo->exec("DELETE FROM fixture WHERE id = {$this->ids['fixture']}");

        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM team_entry_game'));
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM fixture_log'));
    }

    private function buildEntry(WriteAccess $admin): TeamResultEntry
    {
        $matchdays = new MatchdayRepository($this->database);
        $roster    = new RosterRepository($this->database);
        $handicaps = new HandicapFreezingRepository($this->database, new FrozenHandicapRepository($this->database));
        $recording = fn (WriteAccess $access): ResultRecording => new ResultRecording(
            new ResultRepository($this->database),
            new FixtureRepository($this->database),
            $matchdays,
            $matchdays,
            $handicaps,
            new CacheManager(new CacheRepository($this->database)),
            new Validator(),
            $access,
            $roster,
            $handicaps,
            new PdoTransaction($this->database),
        );
        $teamAccess = new TeamEntryWriteAccess();
        $order = new BlindPlayerOrder($roster, $handicaps);

        return new TeamResultEntry(
            $this->store,
            $this->codes,
            $recording($teamAccess),
            $teamAccess,
            $recording($admin),
            $admin,
            $order,
            new BlindFilling($order, $handicaps),
            new PdoTransaction($this->database),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::DATE . ' 21:00:00'),
        );
    }

    private function createLeague(): void
    {
        $this->pdo->exec("INSERT INTO season (name, start_date, end_date, team_entry) VALUES ('2030/31', '2030-09-01', '2031-05-31', 1)");
        $this->ids['season'] = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO season_config (season_id, lane_count, handicap_formula, target_score) VALUES ({$this->ids['season']}, 2, 'standard', 200)");
        $this->pdo->exec("INSERT INTO lane_pair (season_id, lane_a, lane_b) VALUES ({$this->ids['season']}, 1, 2)");
        $lanePair = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO matchday (season_id, matchday_number, date) VALUES ({$this->ids['season']}, 1, '" . self::DATE . "')");
        $matchday = (int) $this->pdo->lastInsertId();

        foreach (['h' => 'Adler', 'a' => 'Falken'] as $key => $name) {
            $this->pdo->exec('INSERT INTO team () VALUES ()');
            $team = (int) $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO team_season (team_id, season_id, team_name) VALUES ($team, {$this->ids['season']}, '$name')");
            $this->ids[$key] = (int) $this->pdo->lastInsertId();
            for ($i = 1; $i <= 5; $i++) {
                $this->pdo->exec("INSERT INTO player (name, gender) VALUES ('$name $i', 'female')");
                $this->ids[$key . $i] = (int) $this->pdo->lastInsertId();
                $this->pdo->exec("INSERT INTO roster (team_season_id, player_id) VALUES ({$this->ids[$key]}, {$this->ids[$key . $i]})");
            }
        }

        $this->pdo->exec("INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, lane_pair_id)
            VALUES ($matchday, {$this->ids['h']}, {$this->ids['a']}, $lanePair)");
        $this->ids['fixture'] = (int) $this->pdo->lastInsertId();
    }

    /** Players 1–4 of a team play all three games with this net. */
    private function enterAll(string $code, string $team, int $net): void
    {
        $lineup = [];
        $nets = [];
        for ($i = 1; $i <= 4; $i++) {
            $lineup[$this->ids[$team . $i]] = 'plays';
            $nets[$this->ids[$team . $i]] = (string) $net;
        }
        foreach ([1, 2, 3] as $game) {
            $this->entry->saveGame($code, $game, $lineup, $nets);
        }
    }

    private function numeral(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
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
