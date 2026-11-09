<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\ResultEntry;

use Bowlingliga\Application\Aggregation\AverageListAggregator;
use Bowlingliga\Application\Aggregation\TableAggregator;
use Bowlingliga\Application\ResultEntry\BlindPlayerOrder;
use Bowlingliga\Application\ResultEntry\ResultRecording;
use Bowlingliga\Application\ResultEntry\HandicapFreezing;
use Bowlingliga\Application\ResultEntry\OriginalScoreSheetStorage;
use Bowlingliga\Application\ResultEntry\ScoreSheetOverview;
use Bowlingliga\Application\ResultEntry\VirtualScoreSheetService;
use Bowlingliga\Application\Schedule\MatchdayRescheduling;
use Bowlingliga\Application\Schedule\MatchdayScheduleException;
use Bowlingliga\Cache\CacheManager;
use Bowlingliga\Domain\Handicap\HandicapChainPreview;
use Bowlingliga\Persistence\Aggregation\AverageListRawDataRepository;
use Bowlingliga\Persistence\Aggregation\TableRawDataRepository;
use Bowlingliga\Persistence\CacheRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\FrozenHandicapRepository;
use Bowlingliga\Persistence\ResultRepository;
use Bowlingliga\Persistence\ResultEntry\EntryDataRepository;
use Bowlingliga\Persistence\ResultEntry\HandicapFreezingRepository;
use Bowlingliga\Persistence\RosterRepository;
use Bowlingliga\Persistence\OriginalScoreSheetRepository;
use Bowlingliga\Persistence\PdoTransaction;
use Bowlingliga\Persistence\Schedule\MatchdayScheduleRepository;
use Bowlingliga\Persistence\ScoreSheetRepository;
use Bowlingliga\Persistence\MatchdayRepository;
use Bowlingliga\Persistence\FixtureRepository;
use Bowlingliga\Persistence\OverviewRepository;
use Bowlingliga\Presentation\ResultEntryController;
use Bowlingliga\Presentation\ResultEntryView;
use Bowlingliga\Security\Validator;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Integration test of the result entry in the admin area against a local
 * MySQL/MariaDB test database (task 35.3).
 *
 * Validates: R28.12, R21.2, R21.4, R21.5, R20.1, R20.6, R22.6, R24.1,
 *            24.4, 27.1–27.7, 27.17, 30.14–30.18, 12.9, 12.12, 3.2, 3.15, 3.16
 *
 * Runs through the production controller with real repositories: score sheet
 * with a row per roster player and blind slots, substitution between games,
 * draft, confirmation including automatic ghost team results and handicap
 * freezing, removal and the upload of the original score sheet as evidence.
 *
 * Schema: migrations 001–004 per test; skipped without a reachable database.
 */
final class ResultEntryIntegrationTest extends TestCase
{
    private const CSRF = '<input type="hidden" name="csrf_token" value="t">';

    private Database $database;
    private PDO $pdo;
    private ResultEntryController $controller;

    /** @var array<string, int> */
    private array $ids = [];

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
        $this->createSampleData();

        $this->controller = $this->buildController();
    }

    /**
     * Builds the production controller with real repositories; optionally with
     * a different handicap freezing (e.g. one that fails).
     */
    private function buildController(?HandicapFreezing $freezing = null): ResultEntryController
    {
        $access       = new SwitchableWriteAccess(true);
        $matchdays     = new MatchdayRepository($this->database);
        $roster       = new RosterRepository($this->database);
        $handicaps     = new HandicapFreezingRepository($this->database, new FrozenHandicapRepository($this->database));

        return new ResultEntryController(
            new ScoreSheetOverview(new OverviewRepository($this->database)),
            new VirtualScoreSheetService(new ScoreSheetRepository($this->database)),
            new ResultRecording(
                new ResultRepository($this->database),
                new FixtureRepository($this->database),
                $matchdays,
                $matchdays,
                $freezing ?? $handicaps,
                new CacheManager(new CacheRepository($this->database)),
                new Validator(),
                $access,
                $roster,
                $handicaps,
                new PdoTransaction($this->database),
            ),
            new BlindPlayerOrder($roster, $handicaps),
            new OriginalScoreSheetStorage(new OriginalScoreSheetRepository($this->database, new \Bowlingliga\Tests\Unit\Application\InMemoryBlobStore()), $access),
            new EntryDataRepository($this->database),
            new ResultEntryView(),
            $handicaps,
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
     * R21.2/R27.4: the score sheet has a row for every roster player with a
     * blind switch per game and no separate blind rows.
     */
    public function testScoreSheetListsTheWholeRoster(): void
    {
        $html = $this->controller->scoreSheetPage(self::CSRF, $this->ids['fx']);

        foreach (['anna', 'bernd', 'carla', 'dirk', 'eva', 'frank'] as $player) {
            self::assertStringContainsString('name="net[home][' . $this->ids[$player] . '][1]"', $html);
            self::assertStringContainsString('name="blind[home][' . $this->ids[$player] . '][3]" value="1"', $html);
        }
        self::assertSame(18, substr_count($html, 'class="js-blind"'), 'Blind switches in the 6 player rows.');
        self::assertSame(6, substr_count($html, 'class="js-row" data-player='), 'One row per roster player, no separate blind rows.');
        self::assertSame(10, substr_count($html, 'class="js-row"'), '6 player rows and 4 ghost team rows.');
        self::assertStringContainsString('wird der nicht spielende Kaderspieler mit den meisten Spielen in seiner eigenen Zeile blind eingetragen', $html);
        // Data for checking a blind player directly when ticking, and the message dialog.
        self::assertStringContainsString('<tr class="js-row" data-player="' . $this->ids['eva'] . '" data-name="Eva" data-games="0"', $html);
        self::assertStringContainsString('<dialog class="dialog js-blind-dialog"', $html);
        self::assertStringContainsString('Ergebnisse je Spiel', $html);
        self::assertStringContainsString('feste Werte: 190 Pins je Spiel', $html);
        self::assertStringNotContainsString('/admin/results/aufstellung', $html, 'There is no separate lineup step any more.');
    }

    /**
     * R27.18: a roster of two – the score sheet adds two blind slots that play
     * like ghost players, prefilled with the ghost score (editable); saved,
     * they count without handicap.
     */
    public function testBlindSlotsForARosterOfTwo(): void
    {
        $this->pdo->exec('DELETE FROM roster WHERE player_id NOT IN (' . $this->ids['anna'] . ', ' . $this->ids['bernd'] . ')');

        $html = $this->controller->scoreSheetPage(self::CSRF, $this->ids['fx']);
        self::assertSame(2, substr_count($html, 'Blindplatz <small>wie ein Geisterspieler, ohne HDC</small>'));
        self::assertStringContainsString('name="slot[home][2][3]" value="190"', $html, 'Prefilled with the ghost score.');

        $input = $this->inputWith('confirm', ['anna' => [1, 2, 3], 'bernd' => [1, 2, 3]]);
        foreach ([1, 2] as $slot) {
            $input['slot']['home'][$slot] = [1 => '190', 2 => '190', 3 => $slot === 2 ? '185' : '190'];
        }
        $result = $this->controller->saveResults($input);
        self::assertTrue($result->successful, $result->message);
        self::assertSame('complete', $this->fixtureStatus());
        self::assertSame(6, $this->numeral(
            'SELECT COUNT(*) FROM game_result e JOIN lineup a ON a.id = e.lineup_id
             WHERE a.team_season_id = ' . $this->ids['adler'] . ' AND a.player_id IS NULL AND a.is_blind = 1'
        ));

        // Stored slots come back with their value; a deviation from the ghost score is highlighted.
        $html = $this->controller->scoreSheetPage(self::CSRF, $this->ids['fx']);
        self::assertStringContainsString('name="slot[home][2][3]" value="185"', $html);
        self::assertStringContainsString('score-sheet__field--check score-sheet__field--blind" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" data-number="3" name="slot[home][2][3]"', $html);
    }

    /**
     * R30.14/R30.15: a draft may be incomplete, sets the status
     * "in Bearbeitung" and does not mark the matchday as played.
     */
    public function testDraftSavesPartialResults(): void
    {
        $result = $this->controller->saveResults([
            'fixture_id' => (string) $this->ids['fx'],
            'action'     => 'draft',
            'net'        => ['home' => [$this->ids['anna'] => [1 => '150', 2 => '160']]],
        ]);

        self::assertTrue($result->successful, $result->message);
        self::assertSame('in_progress', $this->fixtureStatus());
        self::assertSame(0, $this->numeral('SELECT is_played FROM matchday'));
        self::assertSame(14, $this->numeral('SELECT COUNT(*) FROM game_result'), '2 eigene + 12 Geisterteam-Ergebnisse.');

        $html = $this->controller->scoreSheetPage(self::CSRF, $this->ids['fx']);
        self::assertStringContainsString('name="net[home][' . $this->ids['anna'] . '][2]" value="160"', $html, 'The draft is restored (R30.17).');
        self::assertStringContainsString('data-number="1">1/4</td>', $html);
    }

    /**
     * R22.6/R24.1/R30.18/R20.1/R12.9: confirming saves all 24 results (ghost
     * team with target score), sets "vollständig" and played and freezes the
     * handicaps – on the first matchday from the state at the end of the
     * matchday.
     */
    public function testConfirmFreezesHandicaps(): void
    {
        $result = $this->controller->saveResults($this->fullInput('confirm'));

        self::assertTrue($result->successful, $result->message);
        self::assertSame('complete', $this->fixtureStatus());
        self::assertSame(1, $this->numeral('SELECT is_played FROM matchday'));
        self::assertSame(24, $this->numeral('SELECT COUNT(*) FROM game_result'));
        self::assertSame(12, $this->numeral(
            'SELECT COUNT(*) FROM game_result e JOIN lineup a ON a.id = e.lineup_id
             WHERE a.team_season_id = ' . $this->ids['ghosts'] . ' AND e.net = 190 AND a.player_id IS NULL'
        ));

        // Anna plays 150/160/170 → average 160 → HDC = (190 − 160) × 0.8 = 24.
        self::assertSame(24, $this->numeral('SELECT handicap FROM frozen_handicap WHERE player_id = ' . $this->ids['anna']));
        self::assertSame(4, $this->numeral('SELECT COUNT(*) FROM frozen_handicap'), 'Only players who played get a handicap.');
    }

    /**
     * R21.2/R27.17: Anna has to stop after game 1, Eva plays games 2 and 3.
     * Both get their own handicap, the average list counts their own games,
     * the team still has 4 results per game.
     */
    public function testSubstitutionBetweenGames(): void
    {
        $input = $this->fullInput('confirm');
        unset($input['net']['home'][$this->ids['anna']][2], $input['net']['home'][$this->ids['anna']][3]);
        $input['net']['home'][$this->ids['eva']] = [2 => '180', 3 => '200'];

        $preview = $this->controller->scoreSheetPage(self::CSRF, $this->ids['fx'], $input);
        self::assertSame(3, substr_count($preview, '4/4</td>'), 'Every game has exactly 4 results.');

        $result = $this->controller->saveResults($input);
        self::assertTrue($result->successful, $result->message);
        self::assertSame('complete', $this->fixtureStatus());

        self::assertSame(5, $this->numeral('SELECT COUNT(*) FROM lineup WHERE team_season_id = ' . $this->ids['adler']));
        self::assertSame(24, $this->numeral('SELECT COUNT(*) FROM game_result'));
        // First matchday: Anna 150 → HDC (190 − 150) × 0.8 = 32; Eva 190 → 0.
        self::assertSame(32, $this->numeral('SELECT handicap FROM frozen_handicap WHERE player_id = ' . $this->ids['anna']));
        self::assertSame(0, $this->numeral('SELECT handicap FROM frozen_handicap WHERE player_id = ' . $this->ids['eva']));

        $items = (new AverageListAggregator(new AverageListRawDataRepository($this->database)))->averageList($this->ids['adler'], 1);
        $games = [];
        foreach ($items->rows as $row) {
            $games[$row->playerName] = $row->numberOfGames;
        }
        self::assertSame(1, $games['Anna']);
        self::assertSame(2, $games['Eva']);

        // The league table counts 12 games and the pins of all five players.
        $table = (new TableAggregator(new TableRawDataRepository($this->database)))->leagueTable($this->ids['season']);
        foreach ($table->rows as $row) {
            if ($row->teamName === 'Adler') {
                self::assertSame(12, $row->numberOfGames);
            }
        }

        // The score sheet shows Eva's row with her two games.
        $html = $this->controller->scoreSheetPage(self::CSRF, $this->ids['fx']);
        self::assertStringContainsString('name="net[home][' . $this->ids['eva'] . '][1]" value=""', $html);
        self::assertStringContainsString('name="net[home][' . $this->ids['eva'] . '][3]" value="200"', $html);
    }

    /**
     * R4.3: if a later step fails on confirmation (here freezing the
     * handicaps), the whole confirmation is rolled back – no results, no status
     * "vollständig", no played matchday.
     */
    public function testErrorOnConfirmRollsEverythingBack(): void
    {
        $failing = new class () implements HandicapFreezing {
            public function freezeHandicaps(int $matchdayId): void
            {
                throw new \RuntimeException('Einfrieren fehlgeschlagen.');
            }

            public function recalculateChain(int $seasonId, int $fromMatchdayCounter): HandicapChainPreview
            {
                throw new \LogicException('nicht benötigt');
            }

            public function applyChain(HandicapChainPreview $preview): void
            {
            }
        };

        $result = $this->buildController($failing)->saveResults($this->fullInput('confirm'));

        self::assertFalse($result->successful);
        self::assertSame('Einfrieren fehlgeschlagen.', $result->message);
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM game_result'), 'No partial write of the results.');
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM lineup'), 'No lineup rows either.');
        self::assertSame('open', $this->fixtureStatus());
        self::assertSame(0, $this->numeral('SELECT is_played FROM matchday'));
        self::assertFalse($this->pdo->inTransaction());
    }

    /**
     * R24.1/R24.2/R24.4: with two fixtures on the matchday the matchday only
     * counts as played once both are complete; until then the league table
     * counts nothing. A later draft revokes the flag again.
     */
    public function testMatchdayIsPlayedOnlyAfterAllFixtures(): void
    {
        $team = $this->insert('INSERT INTO team () VALUES ()');
        $falcons = $this->insert(
            "INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team) VALUES ($team, {$this->ids['season']}, 'Falken', 0)"
        );
        $second = $this->insert(
            "INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, completion_status)
             SELECT matchday_id, $falcons, {$this->ids['ghosts']}, 'open' FROM fixture WHERE id = {$this->ids['fx']}"
        );

        $result = $this->controller->saveResults($this->fullInput('confirm'));

        self::assertTrue($result->successful, $result->message);
        self::assertSame('complete', $this->fixtureStatus());
        self::assertSame(0, $this->numeral('SELECT is_played FROM matchday'), 'The second fixture is still open.');
        $table = (new TableAggregator(new TableRawDataRepository($this->database)))->leagueTable($this->ids['season']);
        foreach ($table->rows as $row) {
            self::assertSame(0, $row->points, $row->teamName . ' has no points without a played matchday.');
            self::assertSame(0, $row->numberOfGames, $row->teamName . ' has no games without a played matchday.');
        }

        // Second fixture complete → another confirmation flags the matchday.
        $this->pdo->exec("UPDATE fixture SET completion_status = 'complete' WHERE id = $second");
        $this->controller->saveResults($this->fullInput('confirm'));
        self::assertSame(1, $this->numeral('SELECT is_played FROM matchday'));

        // A draft of one fixture revokes the played matchday again.
        $this->controller->saveResults($this->fullInput('draft'));
        self::assertSame('in_progress', $this->fixtureStatus());
        self::assertSame(0, $this->numeral('SELECT is_played FROM matchday'));
    }

    /**
     * R27.4/R27.7/R27.12/R17: with three players Dirk – the roster player who
     * does not play and has the most games (tie: the smallest id) – serves as
     * blind player in his own row. He gets a frozen handicap that is added to
     * the blind results; the score sheet shows reference, gross, sums and
     * points like the paper score sheet.
     */
    public function testScoreSheetCalculatesBlindGrossAndPoints(): void
    {
        // Dirk: previous season average 160 → HDC (190 − 160) × 0.8 = 24, reference 150.
        $this->insert("INSERT INTO previous_season_record (season_id, player_id, game_count, net_pins_total) VALUES ({$this->ids['season']}, {$this->ids['dirk']}, 30, 4800)");

        $dirk  = $this->ids['dirk'];
        $input = $this->fullInput('confirm');
        $input['net']['home'][$dirk]   = [1 => '150', 2 => '150', 3 => '150'];
        $input['blind']['home'][$dirk] = [1 => '1', 2 => '1', 3 => '1'];

        $before = $this->controller->scoreSheetPage(self::CSRF, $this->ids['fx'], $input);
        self::assertStringContainsString('Blindspieler in dieser Reihenfolge: Dirk (30 Spiele), Eva (0 Spiele), Frank (0 Spiele)', $before, 'Games played so far, as in column G (incl. previous season).');
        self::assertStringContainsString('Dirk <small class="js-reference">Ref. 150</small></th>'
            . '<td class="sheet__number st-avg" data-label="AVG">160,00</td><td class="sheet__number js-hdc st-hdc" data-label="HDC">24</td>', $before);
        self::assertStringContainsString('<span class="badge badge--blind" title="Blindspieler">Blind</span>', $before);

        $result = $this->controller->saveResults($input);
        self::assertTrue($result->successful, $result->message);

        self::assertSame(24, $this->numeral('SELECT handicap FROM frozen_handicap WHERE player_id = ' . $this->ids['dirk']));
        self::assertSame(1, $this->numeral('SELECT is_blind FROM lineup WHERE player_id = ' . $this->ids['dirk']));
        self::assertSame(3, $this->numeral(
            'SELECT COUNT(*) FROM game_result e JOIN lineup a ON a.id = e.lineup_id WHERE a.player_id = ' . $this->ids['dirk'] . ' AND e.is_blind = 1'
        ));

        // Gross per game: 702 / 732 / 762 = 2196 (bonus 2); ghost team 760 per game = 2280 (bonus 3).
        // Points home 0/0/2 + 2 = 4, ghost team 2/2/0 + 3 = 7.
        $html = $this->controller->scoreSheetPage(self::CSRF, $this->ids['fx']);
        self::assertStringContainsString('name="blind[home][' . $dirk . '][2]" value="1" checked', $html, 'The blind player row is restored.');
        self::assertStringContainsString('<td class="sheet__number js-gross-total st-gross" data-label="Brutto">522</td>', $html, 'Blind-Brutto 3 × (150 + 24).');
        self::assertStringContainsString('<td class="sheet__number js-sum-gross-total">2196</td>', $html);
        self::assertStringContainsString('<td class="sheet__number js-sum-gross-total">2280</td>', $html);
        self::assertSame(2, substr_count($html, 'class="sheet__number js-total"'));
        self::assertStringContainsString('<td class="sheet__number js-total">4</td>', $html);
        self::assertStringContainsString('<td class="sheet__number js-total">7</td>', $html);
        self::assertStringNotContainsString('score-sheet__field--check', $html, 'Sheet value = reference: no review needed.');
    }

    /**
     * R27.5, R27.13: blind per game. Anna and Dirk cannot continue after game
     * 2 and nobody replaces them: game 3 is blind in their own rows. Only the
     * real games count for handicap and average list, the blind games do
     * count for the team pins. For Dirk his own average − 10 is the reference
     * (decision of 13.10.2026); only his deviating blind game needs review.
     */
    public function testBlindPerGame(): void
    {
        // Dirk: previous season average 160 → reference 150, HDC 24.
        $this->insert("INSERT INTO previous_season_record (season_id, player_id, game_count, net_pins_total) VALUES ({$this->ids['season']}, {$this->ids['dirk']}, 30, 4800)");

        $anna = $this->ids['anna'];
        $dirk = $this->ids['dirk'];
        $input = $this->fullInput('confirm');
        $input['blind']['home'][$anna] = [3 => '1'];     // Anna 150/160 real, 170 blind
        $input['blind']['home'][$dirk] = [3 => '1'];     // Dirk 180/190 real, game 3 blind
        $input['net']['home'][$dirk][3] = '170';         // weicht von Referenz 150 ab

        // The deviation is only marked for Dirk's blind game (R27.11).
        $preview = $this->controller->scoreSheetPage(self::CSRF, $this->ids['fx'], $input);
        self::assertSame(1, substr_count($preview, 'score-sheet__field--check'));
        self::assertStringContainsString('name="net[home][' . $dirk . '][3]" value="170" aria-label="Adler Dirk Spiel 3" aria-describedby="review-note"', $preview);
        self::assertStringContainsString('name="blind[home][' . $anna . '][3]" value="1" checked', $preview);

        $result = $this->controller->saveResults($input);
        self::assertTrue($result->successful, $result->message);

        $blind = $this->pdo->query(
            'SELECT a.player_id, e.game_number FROM game_result e JOIN lineup a ON a.id = e.lineup_id
             WHERE e.is_blind = 1 AND a.team_season_id = ' . $this->ids['adler'] . ' ORDER BY a.sheet_row, e.game_number'
        )->fetchAll(PDO::FETCH_NUM);
        self::assertSame([[(string) $anna, '3'], [(string) $dirk, '3']], array_map(static fn (array $z): array => array_map('strval', $z), $blind));

        // First matchday HDC only from the real games: average (150 + 160) / 2 = 155 → (190 − 155) × 0.8 = 28.
        self::assertSame(28, $this->numeral('SELECT handicap FROM frozen_handicap WHERE player_id = ' . $anna));
        self::assertSame(24, $this->numeral('SELECT handicap FROM frozen_handicap WHERE player_id = ' . $dirk));

        // Average list: Anna with 2 games (155.00), Dirk with 2 games.
        $items = (new AverageListAggregator(new AverageListRawDataRepository($this->database)))->averageList($this->ids['adler'], 1);
        $games = [];
        foreach ($items->rows as $row) {
            $games[$row->playerName] = [$row->numberOfGames, $row->averageNet];
        }
        self::assertSame([2, 155.0], $games['Anna']);
        self::assertSame(2, $games['Dirk'][0]);

        // Team pins include the blind games: net sum of game 3 = 170 + 180 + 190 + 170.
        $html = $this->controller->scoreSheetPage(self::CSRF, $this->ids['fx']);
        self::assertStringContainsString('<td class="sheet__number js-sum-net" data-number="3">710</td>', $html);
    }

    /**
     * R12.12: confirming again (correction) does not overwrite frozen
     * handicaps.
     */
    public function testCorrectionLeavesFrozenHandicapsUnchanged(): void
    {
        $this->controller->saveResults($this->fullInput('confirm'));

        $input = $this->fullInput('confirm');
        $input['net']['home'][$this->ids['anna']] = [1 => '250', 2 => '250', 3 => '250'];
        $result = $this->controller->saveResults($input);

        self::assertTrue($result->successful, $result->message);
        self::assertSame(250, $this->numeral(
            'SELECT e.net FROM game_result e JOIN lineup a ON a.id = e.lineup_id
             WHERE a.player_id = ' . $this->ids['anna'] . ' AND e.game_number = 1'
        ));
        self::assertSame(24, $this->numeral('SELECT handicap FROM frozen_handicap WHERE player_id = ' . $this->ids['anna']));
    }

    /**
     * R12.17–R12.19: a correction of matchday 1 with "Handicap-Kette neu
     * berechnen" leads to the preview; it is only applied after confirmation,
     * then also for the following matchday.
     */
    public function testCorrectionWithHandicapChainPreviewAndApply(): void
    {
        // Matchday 1: Anna 150/160/170 → first matchday, average 160 → HDC 24.
        $this->controller->saveResults($this->fullInput('confirm'));
        $tp1 = $this->ids['fx'];

        // Matchday 2 with the same lineup: HDC from the average before the matchday (160) → 24.
        $matchday2 = $this->insert(
            "INSERT INTO matchday (season_id, matchday_number, date, matchday_type, is_played)
             VALUES ({$this->ids['season']}, 2, '2030-09-11', 'regular', 0)"
        );
        $this->ids['fx'] = $this->insert(
            "INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, completion_status)
             VALUES ($matchday2, {$this->ids['adler']}, {$this->ids['ghosts']}, 'open')"
        );
        $this->controller->saveResults($this->fullInput('confirm'));
        $this->ids['fx'] = $tp1;

        $hdcAnna = fn (): string => implode(',', $this->pdo->query(
            'SELECT handicap FROM frozen_handicap WHERE player_id = ' . $this->ids['anna'] . ' ORDER BY matchday_id'
        )->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame('24,24', $hdcAnna());

        // Correction of matchday 1: Anna 250/250/250 with recalculation.
        $input = $this->fullInput('confirm');
        $input['net']['home'][$this->ids['anna']] = [1 => '250', 2 => '250', 3 => '250'];
        $input['recalculate_chain'] = '1';
        $result = $this->controller->saveResults($input);

        self::assertTrue($result->successful, $result->message);
        self::assertSame('/admin/results/handicap-chain?fixture=' . $tp1, $result->redirect);
        self::assertSame('24,24', $hdcAnna(), 'Before applying, everything stays unchanged (R12.19).');

        // Preview: average 250 → HDC 0 on both matchdays.
        $html = $this->controller->handicapChainPage(self::CSRF, $tp1);
        self::assertStringContainsString('2 Änderungen ab Spieltag 1', $html);
        self::assertSame(2, substr_count($html, '<td class="text">Anna</td><td>24</td><td><strong>0</strong></td>'));
        self::assertMatchesRegularExpression('/name="fingerprint" value="([0-9a-f]{64})"/', $html);
        preg_match('/name="fingerprint" value="([0-9a-f]{64})"/', $html, $matches);

        // Without confirmation and with an outdated fingerprint: nothing applied.
        $without = $this->controller->applyHandicapChain(['fixture_id' => (string) $tp1, 'fingerprint' => $matches[1]]);
        $old  = $this->controller->applyHandicapChain(['fixture_id' => (string) $tp1, 'fingerprint' => str_repeat('0', 64), 'confirmed' => '1']);
        self::assertFalse($without->successful);
        self::assertFalse($old->successful);
        self::assertSame('24,24', $hdcAnna());

        $takeover = $this->controller->applyHandicapChain(
            ['fixture_id' => (string) $tp1, 'fingerprint' => $matches[1], 'confirmed' => '1']
        );
        self::assertTrue($takeover->successful, $takeover->message);
        self::assertStringContainsString('2 Handicaps wurden übernommen', $takeover->message);
        self::assertSame('0,0', $hdcAnna());

        self::assertStringContainsString('Keine Änderungen', $this->controller->handicapChainPage(self::CSRF, $tp1));
    }

    /**
     * The checkbox for the recalculation only appears for complete fixtures.
     */
    public function testChainCheckboxOnlyForCompleteFixture(): void
    {
        self::assertStringNotContainsString('name="recalculate_chain"', $this->controller->scoreSheetPage(self::CSRF, $this->ids['fx']));

        $this->controller->saveResults($this->fullInput('confirm'));
        self::assertStringContainsString('name="recalculate_chain"', $this->controller->scoreSheetPage(self::CSRF, $this->ids['fx']));
    }

    /**
     * R30.16: incomplete results cannot be confirmed; invalid values are
     * rejected; nothing is saved.
     */
    public function testIncompleteOrInvalidIsRejected(): void
    {
        $incomplete = $this->fullInput('confirm');
        unset($incomplete['net']['home'][$this->ids['dirk']][3]);
        self::assertFalse($this->controller->saveResults($incomplete)->successful);

        $invalid = $this->fullInput('confirm');
        $invalid['net']['home'][$this->ids['bernd']][2] = '3o1';
        $result = $this->controller->saveResults($invalid);
        self::assertFalse($result->successful);
        self::assertStringContainsString('Adler, Bernd, Spiel 2', $result->message);

        $tooHigh = $this->fullInput('confirm');
        $tooHigh['net']['home'][$this->ids['bernd']][2] = '301';
        self::assertFalse($this->controller->saveResults($tooHigh)->successful);

        self::assertSame('open', $this->fixtureStatus());
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM frozen_handicap'));
    }

    /**
     * R21.2: a substituted player does not come back, and at most 4 players
     * play at the same time – already for a draft (R21.4).
     */
    public function testReentryAndFivePlayersAtOnceAreRejected(): void
    {
        $reentry = $this->inputWith('confirm', [
            'anna' => [1, 3], 'eva' => [2], 'bernd' => [1, 2, 3], 'carla' => [1, 2, 3], 'dirk' => [1, 2, 3],
        ]);
        $result = $this->controller->saveResults($reentry);
        self::assertFalse($result->successful);
        self::assertStringContainsString('Anna (Adler) hat nach einer Pause wieder gespielt', $result->message);

        $five = $this->inputWith('draft', ['anna' => [1], 'bernd' => [1], 'carla' => [1], 'dirk' => [1], 'eva' => [1]]);
        $result = $this->controller->saveResults($five);
        self::assertFalse($result->successful);
        self::assertStringContainsString('Adler: In Spiel 1 sind 5 Ergebnisse eingetragen', $result->message);

        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM game_result'));
        self::assertSame('open', $this->fixtureStatus());
    }

    /**
     * R27.4: a player with fewer games played so far may not serve as blind
     * player while a player with more games does not play. Games after this
     * matchday do not count.
     */
    public function testWrongBlindPlayerIsRejected(): void
    {
        // Dirk: 30 games in the previous season (counted as in column G);
        // Eva: 3 games on a later matchday, which do not count yet.
        $this->insert("INSERT INTO previous_season_record (season_id, player_id, game_count, net_pins_total) VALUES ({$this->ids['season']}, {$this->ids['dirk']}, 30, 4800)");
        $later = $this->insert(
            "INSERT INTO matchday (season_id, matchday_number, date, matchday_type, is_played)
             VALUES ({$this->ids['season']}, 2, '2030-09-11', 'regular', 1)"
        );
        $laterFixture = $this->insert(
            "INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, completion_status)
             VALUES ($later, {$this->ids['adler']}, {$this->ids['ghosts']}, 'complete')"
        );
        $lineup = $this->insert(
            "INSERT INTO lineup (fixture_id, team_season_id, sheet_row, player_id) VALUES ($laterFixture, {$this->ids['adler']}, 1, {$this->ids['frank']})"
        );
        foreach ([1, 2, 3] as $game) {
            $this->insert("INSERT INTO game_result (lineup_id, game_number, net) VALUES ($lineup, $game, 200)");
        }

        $eva   = $this->ids['eva'];
        $input = $this->fullInput('confirm');
        unset($input['net']['home'][$this->ids['dirk']]);
        $input['net']['home'][$eva]   = [1 => '150', 2 => '150', 3 => '150'];
        $input['blind']['home'][$eva] = [1 => '1', 2 => '1', 3 => '1'];

        $result = $this->controller->saveResults($input);
        self::assertFalse($result->successful);
        self::assertStringContainsString('Adler: Als Blindspieler ist zuerst Dirk heranzuziehen (30 Spiele), nicht Eva (0 Spiele)', $result->message);
        self::assertSame('open', $this->fixtureStatus());

        // Dirk plays instead of Anna: Eva may serve as blind player, since
        // Frank's 3 games on matchday 2 do not count yet (all have 0 so far).
        unset($input['net']['home'][$this->ids['anna']]);
        $input['net']['home'][$this->ids['dirk']] = [1 => '180', 2 => '190', 3 => '200'];
        $result = $this->controller->saveResults($input);
        self::assertTrue($result->successful, $result->message);
    }

    /**
     * R24.4: removing the results also removes the lineup rows; the fixture is
     * open again and not played.
     */
    public function testRemovingResultsResetsTheFixture(): void
    {
        $this->controller->saveResults($this->fullInput('confirm'));
        self::assertSame(8, $this->numeral('SELECT COUNT(*) FROM lineup'));

        $removed = $this->controller->removeResults(['fixture_id' => (string) $this->ids['fx']]);
        self::assertTrue($removed->successful, $removed->message);
        self::assertSame('open', $this->fixtureStatus());
        self::assertSame(0, $this->numeral('SELECT is_played FROM matchday'));
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM game_result'));
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM lineup'));
    }

    /**
     * R3.15/R3.16/R3.20: the upload stores the original as evidence (file type
     * determined from the content, photos as JPEG), without creating results.
     */
    public function testUploadStoresOriginalAsEvidence(): void
    {
        $upload = $this->pngUpload();
        $upload['type'] = 'application/octet-stream'; // What the browser claims is ignored.

        $result = $this->controller->saveOriginal(['fixture_id' => (string) $this->ids['fx']], $upload);

        self::assertTrue($result->successful, $result->message);
        self::assertSame(1, $this->numeral('SELECT COUNT(*) FROM original_score_sheet'));
        self::assertSame('image/jpeg', (string) $this->pdo->query('SELECT mime_type FROM original_score_sheet')->fetchColumn());
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM game_result'));

        $html = $this->controller->scoreSheetPage(self::CSRF, $this->ids['fx']);
        self::assertStringContainsString('href="/score-sheet/', $html);
        self::assertStringNotContainsString('auslesen', $html);
    }

    /**
     * R3.2: unsupported formats and missing files are rejected.
     */
    public function testInvalidUploadIsRejected(): void
    {
        $pathName = (string) tempnam(sys_get_temp_dir(), 'szt');
        file_put_contents($pathName, 'Kein Bild');
        $text = ['name' => 'zettel.png', 'type' => 'image/png', 'tmp_name' => $pathName, 'error' => UPLOAD_ERR_OK, 'size' => 9];

        $format = $this->controller->saveOriginal(['fixture_id' => (string) $this->ids['fx']], $text);
        $isMissing  = $this->controller->saveOriginal(['fixture_id' => (string) $this->ids['fx']], ['error' => UPLOAD_ERR_NO_FILE]);

        self::assertFalse($format->successful);
        self::assertFalse($isMissing->successful);
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM original_score_sheet'));
    }

    /**
     * The overview lists the fixture as to be filled in.
     */
    public function testOverview(): void
    {
        $html = $this->controller->overview($this->ids['season']);

        self::assertStringContainsString('1 von 1 Paarungen sind noch auszufüllen', $html);
        self::assertStringContainsString('Adler – Geisterteam', $html);
        self::assertStringContainsString('/admin/results/score-sheet?fixture=' . $this->ids['fx'], $html);
    }

    /**
     * The ghost team plays the ghost score of the season, independent of the
     * target score.
     */
    public function testGhostTeamPlaysGhostScoreOfSeason(): void
    {
        $this->pdo->exec("UPDATE season_config SET ghost_score = 185, target_score = 200 WHERE season_id = {$this->ids['season']}");

        $result = $this->controller->saveResults($this->fullInput('confirm'));

        self::assertTrue($result->successful, $result->message);
        self::assertSame(12, $this->numeral(
            'SELECT COUNT(*) FROM game_result e JOIN lineup a ON a.id = e.lineup_id
             WHERE a.team_season_id = ' . $this->ids['ghosts'] . ' AND e.net = 185 AND a.player_id IS NULL'
        ));
    }

    /**
     * The handicap of a matchday uses the average after the previous matchday:
     * with the default setting 5 the previous season still counts on matchday
     * 6, no longer on matchday 7. With the setting 4 it already stops on
     * matchday 6.
     */
    public function testHandicapOfMatchdaySixStillIncludesPreviousSeason(): void
    {
        // Dirk: previous season 30 games with 4800 pins, then 3 × 200 per matchday.
        $this->insert("INSERT INTO previous_season_record (season_id, player_id, game_count, net_pins_total) VALUES ({$this->ids['season']}, {$this->ids['dirk']}, 30, 4800)");
        $matchdays = [];
        for ($number = 1; $number <= 7; $number++) {
            $matchdays[$number] = $this->playedMatchdayOfDirk($number, 200);
        }
        $freezing = new HandicapFreezingRepository($this->database, new FrozenHandicapRepository($this->database));
        $handicap = fn (int $number): int => $this->numeral(
            "SELECT handicap FROM frozen_handicap WHERE player_id = {$this->ids['dirk']} AND matchday_id = {$matchdays[$number]}"
        );

        foreach ([5, 6, 7] as $number) {
            $freezing->freezeHandicaps($matchdays[$number]);
        }
        // After matchday 4: (4800 + 2400) / 42 = 171,43 → (190 − 171,43) × 0,8 = 14,86 → 15.
        self::assertSame(15, $handicap(5));
        // After matchday 5: (4800 + 3000) / 45 = 173,33 → (190 − 173,33) × 0,8 = 13,33 → 13.
        self::assertSame(13, $handicap(6), 'Matchday 6 still uses the previous season.');
        // After matchday 6 only the current season: 200 → HDC 0.
        self::assertSame(0, $handicap(7));

        $this->pdo->exec('DELETE FROM frozen_handicap');
        $this->pdo->exec("UPDATE season_config SET previous_season_matchdays = 4 WHERE season_id = {$this->ids['season']}");
        foreach ([5, 6] as $number) {
            $freezing->freezeHandicaps($matchdays[$number]);
        }
        self::assertSame(15, $handicap(5), 'Matchday 5 uses the state after matchday 4, still with the previous season.');
        self::assertSame(0, $handicap(6), 'With setting 4 the previous season no longer counts from matchday 6.');
    }

    /**
     * R30.20: a fixture can only be confirmed once every earlier matchday is
     * complete; a matchday without fixtures counts as incomplete. A draft
     * stays possible.
     */
    public function testAnIncompleteEarlierMatchdayBlocksTheConfirmation(): void
    {
        $matchday1 = $this->ids['fx'];
        $matchday2 = $this->insert(
            "INSERT INTO matchday (season_id, matchday_number, date, matchday_type, is_played)
             VALUES ({$this->ids['season']}, 2, '2030-09-11', 'positionday', 0)"
        );
        $matchday3 = $this->insert(
            "INSERT INTO matchday (season_id, matchday_number, date, matchday_type, is_played)
             VALUES ({$this->ids['season']}, 3, '2030-09-18', 'regular', 0)"
        );
        $this->ids['fx'] = $this->insert(
            "INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, completion_status)
             VALUES ($matchday3, {$this->ids['adler']}, {$this->ids['ghosts']}, 'open')"
        );

        $result = $this->controller->saveResults($this->fullInput('confirm'));
        self::assertFalse($result->successful);
        self::assertStringContainsString('Spieltag 1 ist noch nicht vollständig erfasst', $result->message);
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM frozen_handicap'));

        self::assertTrue($this->controller->saveResults($this->fullInput('draft'))->successful, 'A draft stays possible.');
        self::assertSame('in_progress', $this->statusOf($this->ids["fx"]));

        // Matchday 1 complete; matchday 2 has no fixtures yet and still blocks.
        $fixture3 = $this->ids['fx'];
        $this->ids['fx'] = $matchday1;
        self::assertTrue($this->controller->saveResults($this->fullInput('confirm'))->successful);
        $this->ids['fx'] = $fixture3;
        self::assertStringContainsString('Spieltag 2 ist', $this->controller->saveResults($this->fullInput('confirm'))->message);

        $this->insert(
            "INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, completion_status)
             VALUES ($matchday2, {$this->ids['adler']}, {$this->ids['ghosts']}, 'complete')"
        );
        $result = $this->controller->saveResults($this->fullInput('confirm'));
        self::assertTrue($result->successful, $result->message);
        self::assertSame('complete', $this->statusOf($this->ids["fx"]));
    }

    /**
     * R8.20/R8.21: a cancelled matchday takes no results, does not block the
     * later ones and is missing from the overview; postponing moves the
     * matchday, the later ones and the season end.
     */
    public function testCancelledAndPostponedMatchdays(): void
    {
        $schedule = new MatchdayRescheduling(new MatchdayScheduleRepository($this->database), new SwitchableWriteAccess(true), new PdoTransaction($this->database));
        $fixture1 = $this->ids['fx'];
        $matchday1 = $this->numeral("SELECT matchday_id FROM fixture WHERE id = $fixture1");
        $fixtures = [];
        foreach ([2 => '2030-09-11', 3 => '2030-09-18'] as $number => $date) {
            $matchday = $this->insert(
                "INSERT INTO matchday (season_id, matchday_number, date, matchday_type, is_played)
                 VALUES ({$this->ids['season']}, $number, '$date', 'regular', 0)"
            );
            $fixtures[$number] = $this->insert(
                "INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, completion_status)
                 VALUES ($matchday, {$this->ids['adler']}, {$this->ids['ghosts']}, 'open')"
            );
        }
        $matchday2 = $this->numeral("SELECT matchday_id FROM fixture WHERE id = {$fixtures[2]}");

        self::assertTrue($this->controller->saveResults($this->fullInput('draft'))->successful);
        $state = array_column($schedule->matchdays($this->ids['season']), null, 'number');
        self::assertTrue($state[1]['has_results'], 'A draft counts as results.');
        self::assertFalse($state[2]['has_results']);
        try {
            $schedule->cancel($matchday1);
            self::fail('A matchday with a draft cannot be cancelled.');
        } catch (MatchdayScheduleException) {
            // expected
        }

        self::assertTrue($this->controller->saveResults($this->fullInput('confirm'))->successful);
        self::assertTrue(array_column($schedule->matchdays($this->ids['season']), null, 'number')[1]['is_complete']);
        $schedule->cancel($matchday2);

        // The cancelled matchday 2 takes no results and does not block matchday 3.
        $this->ids['fx'] = $fixtures[2];
        self::assertStringContainsString('abgesagt', $this->controller->saveResults($this->fullInput('draft'))->message);
        $this->ids['fx'] = $fixtures[3];
        $result = $this->controller->saveResults($this->fullInput('confirm'));
        self::assertTrue($result->successful, $result->message);

        $overview = (new OverviewRepository($this->database))->findEntriesForSeason($this->ids['season']);
        self::assertSame([$fixture1, $fixtures[3]], array_map(static fn ($e): int => $e->fixtureId, $overview), 'The cancelled matchday is missing from the overview.');

        // Matchday 3 has results: taking the cancellation of matchday 2 back would
        // undermine the order of the result entry (R30.20).
        try {
            $schedule->reinstate($matchday2);
            self::fail('A later matchday with results prevents taking the cancellation back.');
        } catch (MatchdayScheduleException $e) {
            self::assertSame('error.schedule.later_results_entered', $e->message()->key);
        }

        // Without those results it can be taken back and postponed.
        $this->pdo->exec("DELETE gr FROM game_result gr JOIN lineup l ON l.id = gr.lineup_id WHERE l.fixture_id = {$fixtures[3]}");
        $this->pdo->exec("DELETE FROM lineup WHERE fixture_id = {$fixtures[3]}");
        $this->pdo->exec("UPDATE fixture SET completion_status = 'open' WHERE id = {$fixtures[3]}");
        $schedule->reinstate($matchday2);
        $end = (string) $this->pdo->query("SELECT end_date FROM season WHERE id = {$this->ids['season']}")->fetchColumn();
        $schedule->postpone($matchday2, '2030-09-25');

        $dates = $this->pdo->query("SELECT matchday_number, date FROM matchday WHERE season_id = {$this->ids['season']} ORDER BY matchday_number")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame([1 => '2030-09-04', 2 => '2030-09-25', 3 => '2030-10-02'], $dates);
        self::assertSame(
            (new \DateTimeImmutable($end))->modify('+14 days')->format('Y-m-d'),
            $this->pdo->query("SELECT end_date FROM season WHERE id = {$this->ids['season']}")->fetchColumn()
        );
    }

    /**
     * A complete fixture of the given matchday in which Dirk plays three games
     * with the given result (matchday 1 reuses the sample fixture).
     */
    private function playedMatchdayOfDirk(int $number, int $net): int
    {
        if ($number === 1) {
            $matchday = $this->numeral("SELECT matchday_id FROM fixture WHERE id = {$this->ids['fx']}");
            $fixture = $this->ids['fx'];
            $this->pdo->exec("UPDATE fixture SET completion_status = 'complete' WHERE id = $fixture");
        } else {
            $matchday = $this->insert(
                "INSERT INTO matchday (season_id, matchday_number, date, matchday_type, is_played)
                 VALUES ({$this->ids['season']}, $number, DATE_ADD('2030-09-04', INTERVAL " . (7 * ($number - 1)) . " DAY), 'regular', 1)"
            );
            $fixture = $this->insert(
                "INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, completion_status)
                 VALUES ($matchday, {$this->ids['adler']}, {$this->ids['ghosts']}, 'complete')"
            );
        }
        $lineup = $this->insert(
            "INSERT INTO lineup (fixture_id, team_season_id, sheet_row, player_id) VALUES ($fixture, {$this->ids['adler']}, 1, {$this->ids['dirk']})"
        );
        foreach ([1, 2, 3] as $game) {
            $this->insert("INSERT INTO game_result (lineup_id, game_number, net) VALUES ($lineup, $game, $net)");
        }

        return $matchday;
    }

    // --- Hilfen -----------------------------------------------------------

    /**
     * @return list<int>
     */
    private function fourPlayers(): array
    {
        return [$this->ids['anna'], $this->ids['bernd'], $this->ids['carla'], $this->ids['dirk']];
    }

    /**
     * Results of Anna, Bernd, Carla and Dirk (in this order 150/160/170,
     * 160/170/180, 170/180/190, 180/190/200), keyed by player id.
     *
     * @return array<string, mixed>
     */
    private function fullInput(string $action): array
    {
        $net = [];
        foreach ($this->fourPlayers() as $index => $playerId) {
            $base = 140 + 10 * ($index + 1);
            $net[$playerId] = [1 => (string) $base, 2 => (string) ($base + 10), 3 => (string) ($base + 20)];
        }

        return ['fixture_id' => (string) $this->ids['fx'], 'action' => $action, 'net' => ['home' => $net]];
    }

    /**
     * Input in which the given players play the given games (150 pins each).
     *
     * @param array<string, list<int>> $games player key → game numbers
     *
     * @return array<string, mixed>
     */
    private function inputWith(string $action, array $games): array
    {
        $net = [];
        foreach ($games as $player => $numbers) {
            foreach ($numbers as $num) {
                $net[$this->ids[$player]][$num] = '150';
            }
        }

        return ['fixture_id' => (string) $this->ids['fx'], 'action' => $action, 'net' => ['home' => $net]];
    }

    /**
     * @return array<string, mixed>
     */
    private function pngUpload(): array
    {
        $image = imagecreatetruecolor(4, 4);
        $pathName = (string) tempnam(sys_get_temp_dir(), 'szt');
        imagepng($image, $pathName);

        return ['name' => 'zettel.png', 'type' => 'image/png', 'tmp_name' => $pathName, 'error' => UPLOAD_ERR_OK, 'size' => filesize($pathName)];
    }

    private function statusOf(int $fixtureId): string
    {
        return (string) $this->pdo->query("SELECT completion_status FROM fixture WHERE id = $fixtureId")->fetchColumn();
    }

    private function fixtureStatus(): string
    {
        return (string) $this->pdo->query('SELECT completion_status FROM fixture')->fetchColumn();
    }

    private function numeral(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    private function createSampleData(): void
    {
        $this->ids['season'] = $this->insert(
            "INSERT INTO season (name, start_date, end_date) VALUES ('Testsaison', '2030-09-04', '2031-05-28')"
        );
        $this->insert(
            "INSERT INTO season_config (season_id, lane_count, max_handicap, handicap_formula, target_score)
             VALUES ({$this->ids['season']}, 2, 64, 'HDC = (Zielwert - Durchschnitt) * 0.8', 190)"
        );

        $teamA = $this->insert('INSERT INTO team () VALUES ()');
        $teamG = $this->insert('INSERT INTO team () VALUES ()');
        $this->ids['adler'] = $this->insert(
            "INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team) VALUES ($teamA, {$this->ids['season']}, 'Adler', 0)"
        );
        $this->ids['ghosts'] = $this->insert(
            "INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team) VALUES ($teamG, {$this->ids['season']}, 'Geisterteam', 1)"
        );

        foreach (['anna' => 'female', 'bernd' => 'male', 'carla' => 'female', 'dirk' => 'male', 'eva' => 'female', 'frank' => 'male'] as $name => $gender) {
            $this->ids[$name] = $this->insert(
                "INSERT INTO player (name, gender) VALUES ('" . ucfirst($name) . "', '$gender')"
            );
            $this->insert("INSERT INTO roster (team_season_id, player_id) VALUES ({$this->ids['adler']}, {$this->ids[$name]})");
        }

        $matchday = $this->insert(
            "INSERT INTO matchday (season_id, matchday_number, date, matchday_type, is_played)
             VALUES ({$this->ids['season']}, 1, '2030-09-04', 'regular', 0)"
        );
        $this->ids['fx'] = $this->insert(
            "INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, completion_status)
             VALUES ($matchday, {$this->ids['adler']}, {$this->ids['ghosts']}, 'open')"
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
            'player', 'previous_season_record', 'admin_account', 'login_attempt', 'home_page_post',
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

