<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\EnteredGame;
use Bowlingliga\Application\ResultEntry\EnteredResults;
use Bowlingliga\Application\ResultEntry\ResultEntryException;
use Bowlingliga\Application\ResultEntry\ResultLog;
use Bowlingliga\Application\ResultEntry\ResultRecording;
use Bowlingliga\Cache\CacheManager;
use Bowlingliga\Domain\Handicap\HandicapChainChange;
use Bowlingliga\Domain\Handicap\HandicapChainPreview;
use Bowlingliga\Domain\Handicap\HandicapStatus;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\Validator;
use Bowlingliga\Tests\GermanText;
use Bowlingliga\Tests\Unit\Application\InMemoryRosterRepository;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the use case {@see ResultRecording} (task 24.2).
 *
 * Validates: R22.6, R22.7, R24.1, R24.4, R30.14, R30.15, R30.18, R35.4, R35.5, R5.1
 *
 * Checks draft (status "in Bearbeitung", no played matchday), confirmation
 * (complete results -> save raw data, invalidate the cache from the matchday,
 * freeze handicaps, status "vollständig", played matchday), the fallback on
 * removal, correction with optional chain recalculation, the access
 * protection, the roster check and the order of the blind players. All dependencies are in-memory fakes; the validation uses the
 * real {@see Validator}.
 */
final class ResultRecordingTest extends TestCase
{
    private const FIXTURE = 100;
    private const MATCHDAY = 7;
    private const SEASON = 1;
    private const COUNTER = 3;
    private const HOME = 10;
    private const AWAY = 20;

    private InMemoryResultStore $results;
    private RecordingStatusStore $status;
    private RecordingFlagStore $flag;
    private FixedMatchdayContext $context;
    private RecordingHandicapFreezing $handicaps;
    private RecordingCacheStore $cacheStore;
    private SwitchableWriteAccess $access;
    private InMemoryRosterRepository $roster;
    private FixedPlayedGames $playedGames;
    private ResultRecording $management;

    protected function setUp(): void
    {
        $this->results   = new InMemoryResultStore();
        $this->status       = new RecordingStatusStore();
        $this->flag  = new RecordingFlagStore();
        $this->context      = new FixedMatchdayContext();
        $this->context->contexts[self::MATCHDAY] = ['season_id' => self::SEASON, 'matchday_number' => self::COUNTER];
        $this->handicaps    = new RecordingHandicapFreezing();
        $this->cacheStore = new RecordingCacheStore();
        $this->access      = new SwitchableWriteAccess(true);
        $this->roster      = new InMemoryRosterRepository();
        $this->playedGames = new FixedPlayedGames();
        foreach ([self::HOME, self::AWAY] as $team) {
            for ($i = 1; $i <= 6; $i++) {
                $this->roster->create($team, $team + $i);
            }
        }

        $this->management = new ResultRecording(
            $this->results,
            $this->status,
            $this->flag,
            $this->context,
            $this->handicaps,
            new CacheManager($this->cacheStore),
            new Validator(),
            $this->access,
            $this->roster,
            $this->playedGames,
        );
    }

    // --- R30.15: Entwurf ---

    public function testDraftSetsStatusInProgressWithoutPlayedAndWithoutCacheInvalidation(): void
    {
        $this->management->saveDraft($this->partialResults());

        self::assertSame('in_progress', $this->status->status[self::FIXTURE]);
        self::assertArrayNotHasKey(self::MATCHDAY, $this->flag->isPlayed, 'A draft does not mark the matchday as played (R30.16).');
        self::assertSame([], $this->cacheStore->invalidations, 'A draft changes no public derived values and invalidates no cache.');
        self::assertSame([], $this->handicaps->frozen, 'A draft freezes no handicaps.');
    }

    // --- R22.7 / R24.1 / R30.18 / R35.4 / R35.5: confirmation ---

    public function testConfirmationSavesInvalidatesFreezesAndFlags(): void
    {
        $this->management->confirmAndSave($this->completeResults());

        // Raw data saved (R22.6).
        self::assertArrayHasKey(self::FIXTURE, $this->results->saved);

        // Cache invalidated from the matchday onwards (R35.4).
        self::assertCount(1, $this->cacheStore->invalidations);
        self::assertSame(self::SEASON, $this->cacheStore->invalidations[0]->seasonId);
        self::assertSame(self::COUNTER, $this->cacheStore->invalidations[0]->fromMatchdayCounter);

        // Handicaps frozen (R30.18) – as a separate step, independent of the cache (R35.5).
        self::assertSame([self::MATCHDAY], $this->handicaps->frozen);

        // Status complete + played matchday (R24.1, R30.18).
        self::assertSame('complete', $this->status->status[self::FIXTURE]);
        self::assertTrue($this->flag->isPlayed[self::MATCHDAY]);
    }

    /**
     * R24.1/R24.2: as long as another fixture of the matchday is not complete,
     * the matchday does not count as played; only the last confirmation flags
     * it.
     */
    public function testMatchdayIsPlayedOnlyAfterAllFixtures(): void
    {
        $this->status->status[self::FIXTURE + 1] = 'open';

        $this->management->confirmAndSave($this->completeResults());

        self::assertSame('complete', $this->status->status[self::FIXTURE]);
        self::assertFalse($this->flag->isPlayed[self::MATCHDAY], 'An open fixture prevents the played matchday.');

        $this->status->status[self::FIXTURE + 1] = 'complete';
        $this->management->confirmAndSave($this->completeResults());

        self::assertTrue($this->flag->isPlayed[self::MATCHDAY]);
    }

    /**
     * R24.4: if a fixture of a played matchday is saved again as draft, the
     * matchday loses its flag and the dependent caches are invalidated.
     */
    public function testDraftRevokesPlayedMatchday(): void
    {
        $this->status->status[self::FIXTURE] = 'complete';

        $this->management->saveDraft($this->partialResults());

        self::assertSame('in_progress', $this->status->status[self::FIXTURE]);
        self::assertFalse($this->flag->isPlayed[self::MATCHDAY]);
        self::assertCount(1, $this->cacheStore->invalidations);
    }

    public function testIncompleteConfirmationIsRejected(): void
    {
        try {
            $this->management->confirmAndSave($this->partialResults());
            self::fail('Incomplete results must not be confirmed as complete (R30.4/R30.18).');
        } catch (ResultEntryException) {
            // expected
        }

        self::assertSame([], $this->cacheStore->invalidations);
        self::assertSame([], $this->handicaps->frozen);
        self::assertArrayNotHasKey(self::MATCHDAY, $this->flag->isPlayed);
    }

    public function testInvalidNetValueIsRejected(): void
    {
        $games = $this->completeGames();
        $games[0] = EnteredGame::player(self::HOME, self::HOME + 1, 1, 301);
        $results = new EnteredResults(self::FIXTURE, self::MATCHDAY, $games);

        $this->expectException(ValidationException::class);
        $this->management->confirmAndSave($results);
    }

    // --- R21.2 / R27.17: substitution between games ---

    public function testSubstitutionBetweenGamesIsConfirmed(): void
    {
        // Player 1 plays game 1, is replaced by player 5 for games 2 and 3.
        $this->management->confirmAndSave($this->withHome($this->homeGames([
            1 => [1], 2 => [1, 2, 3], 3 => [1, 2, 3], 4 => [1, 2, 3], 5 => [2, 3],
        ])));

        self::assertSame('complete', $this->status->status[self::FIXTURE]);
        self::assertCount(24, $this->results->saved[self::FIXTURE]->games);
    }

    public function testPlayerOutsideTheRosterIsRejected(): void
    {
        $games = $this->homeGames([1 => [1, 2, 3], 2 => [1, 2, 3], 3 => [1, 2, 3]]);
        foreach ([1, 2, 3] as $num) {
            $games[] = EnteredGame::player(self::HOME, 999, $num, 150);
        }

        $this->expectException(ResultEntryException::class);
        $this->expectExceptionMessage('error.result.not_in_roster');
        $this->management->confirmAndSave($this->withHome($games));
    }

    public function testFiveResultsInOneGameAreRejectedAlreadyForTheDraft(): void
    {
        try {
            $this->management->saveDraft(new EnteredResults(self::FIXTURE, self::MATCHDAY, $this->homeGames([
                1 => [1], 2 => [1], 3 => [1], 4 => [1], 5 => [1],
            ])));
            self::fail('More than 4 players at the same time must be rejected (R21.4).');
        } catch (ResultEntryException $e) {
            self::assertStringContainsString('höchstens 4 Spieler gleichzeitig', GermanText::of($e));
        }

        self::assertSame([], $this->results->saved);
    }

    // --- R27.4: blind players in their own row, by games played ---

    /**
     * Players 1–3 play; \$blind serve as blind players in all three games.
     *
     * @param list<int> $blind player numbers (4–6)
     */
    private function withBlindPlayers(array $blind, array $players = [1, 2, 3]): EnteredResults
    {
        $games = $this->homeGames(array_fill_keys($players, [1, 2, 3]));
        foreach ($blind as $player) {
            foreach ([1, 2, 3] as $num) {
                $games[] = EnteredGame::player(self::HOME, self::HOME + $player, $num, 140, true);
            }
        }

        return $this->withHome($games);
    }

    public function testBlindPlayerMustBeTheNonPlayingRosterPlayerWithMostGames(): void
    {
        $this->playedGames->games = [self::HOME + 4 => 30, self::HOME + 5 => 9, self::HOME + 6 => 12];

        try {
            $this->management->confirmAndSave($this->withBlindPlayers([6]));
            self::fail('Player 4 has more games and does not play (R27.4).');
        } catch (ResultEntryException $e) {
            self::assertStringContainsString('Als Blindspieler ist zuerst Spieler 14 heranzuziehen (30 Spiele), nicht Spieler 16 (12 Spiele)', GermanText::of($e));
        }
        self::assertSame([], $this->results->saved);

        $this->management->confirmAndSave($this->withBlindPlayers([4]));
        self::assertSame('complete', $this->status->status[self::FIXTURE]);
    }

    public function testSecondBlindPlayerIsTheNextOne(): void
    {
        $this->playedGames->games = [self::HOME + 3 => 5, self::HOME + 4 => 30, self::HOME + 5 => 9, self::HOME + 6 => 12];

        try {
            $this->management->confirmAndSave($this->withBlindPlayers([4, 5], [1, 2]));
            self::fail('Player 6 has more games than player 5.');
        } catch (ResultEntryException $e) {
            self::assertStringContainsString('zuerst Spieler 16 heranzuziehen (12 Spiele), nicht Spieler 15 (9 Spiele)', GermanText::of($e));
        }

        $this->management->confirmAndSave($this->withBlindPlayers([4, 6], [1, 2]));
        self::assertSame('complete', $this->status->status[self::FIXTURE]);
    }

    public function testStoredBlindPlayersAreNotCheckedAgain(): void
    {
        // Since saving, player 4 has more games (later matchdays); the correction stays possible (R35).
        $this->playedGames->games = [self::HOME + 4 => 30];
        $this->results->storedBlindPlayers[self::FIXTURE . '-' . self::HOME] = [self::HOME + 6];

        $this->management->confirmAndSave($this->withBlindPlayers([6]));

        self::assertSame('complete', $this->status->status[self::FIXTURE]);
    }

    public function testDraftDoesNotCheckTheBlindPlayers(): void
    {
        $this->playedGames->games = [self::HOME + 4 => 30];

        $this->management->saveDraft($this->withBlindPlayers([6]));

        self::assertSame('in_progress', $this->status->status[self::FIXTURE]);
    }
    // --- R24.4 / R30.15/16: Entfernen ---

    public function testRemoveSetsStatusOpenWhenNoResultsRemain(): void
    {
        $this->results->remainingResultsExist = false;

        $this->management->removeResults(self::FIXTURE, self::MATCHDAY);

        self::assertContains(self::FIXTURE, $this->results->removed);
        self::assertFalse($this->flag->isPlayed[self::MATCHDAY], 'After removing, the matchday is no longer played (R24.4).');
        self::assertSame('open', $this->status->status[self::FIXTURE]);
        self::assertCount(1, $this->cacheStore->invalidations, 'Removing invalidates the dependent caches (R35.4).');
    }

    public function testRemoveSetsStatusInProgressWhenResultsRemain(): void
    {
        $this->results->remainingResultsExist = true;

        $this->management->removeResults(self::FIXTURE, self::MATCHDAY);

        self::assertSame('in_progress', $this->status->status[self::FIXTURE]);
    }

    // --- R12.18 / R12.19: correction with optional chain recalculation ---

    public function testCorrectionWithoutChainRecalculationReturnsNoPreview(): void
    {
        $preview = $this->management->correctWithOptionalChainRecalculation(
            $this->completeResults(),
            false,
        );

        self::assertNull($preview);
        self::assertArrayHasKey(self::FIXTURE, $this->results->saved);
        self::assertCount(1, $this->cacheStore->invalidations, 'A correction invalidates the dependent caches as well (R22.6).');
        self::assertSame([], $this->handicaps->applied, 'Without a recalculation no chain is applied (R12.19).');
    }

    public function testCorrectionWithChainRecalculationReturnsPreviewWithoutPersisting(): void
    {
        $preview = $this->management->correctWithOptionalChainRecalculation(
            $this->completeResults(),
            true,
        );

        self::assertNotNull($preview, 'The recalculation gives a preview (R12.18).');
        self::assertSame(self::COUNTER, $preview->fromMatchdayCounter);
        self::assertSame([], $this->handicaps->applied, 'The preview alone persists nothing (R12.19).');
    }

    public function testApplyPersistsOnlyAfterConfirmation(): void
    {
        $preview = $this->management->correctWithOptionalChainRecalculation(
            $this->completeResults(),
            true,
        );
        self::assertNotNull($preview);

        $this->management->applyChainRecalculation($preview);

        self::assertSame([$preview], $this->handicaps->applied, 'Only applying persists the recalculated chain (R12.18).');
    }

    // --- R12.18/R12.19: handicap chain from the score sheet ---

    public function testPreviewHandicapChainFromMatchdayWithoutPersisting(): void
    {
        $preview = $this->management->previewHandicapChain(self::MATCHDAY);

        self::assertSame(self::COUNTER, $preview->fromMatchdayCounter);
        self::assertSame([], $this->handicaps->applied);
        self::assertSame([], $this->cacheStore->invalidations);
    }

    public function testApplyWithMatchingFingerprintPersistsAndInvalidates(): void
    {
        $this->handicaps->preview = $this->previewWithChange();
        $fingerprint = $this->management->previewHandicapChain(self::MATCHDAY)->fingerprint();

        $this->management->applyHandicapChain(self::MATCHDAY, $fingerprint);

        self::assertCount(1, $this->handicaps->applied);
        self::assertCount(1, $this->cacheStore->invalidations, 'Gross and tables from the matchday on are recalculated (R35.4).');
        self::assertSame(self::SEASON, $this->cacheStore->invalidations[0]->seasonId);
    }

    public function testApplyWithOutdatedFingerprintChangesNothing(): void
    {
        $this->handicaps->preview = $this->previewWithChange();

        try {
            $this->management->applyHandicapChain(self::MATCHDAY, 'veraltet');
            self::fail('An outdated preview must not be applied (R12.19).');
        } catch (ResultEntryException) {
        }

        self::assertSame([], $this->handicaps->applied);
        self::assertSame([], $this->cacheStore->invalidations);
    }

    public function testWithoutWriteAccessNoChainPreview(): void
    {
        $this->access->allowed = false;

        $this->expectException(AccessDeniedException::class);
        $this->management->previewHandicapChain(self::MATCHDAY);
    }

    private function previewWithChange(): HandicapChainPreview
    {
        return new HandicapChainPreview(self::COUNTER, [
            new HandicapChainChange(self::MATCHDAY, 5, HandicapStatus::CALCULATED, 24, 22),
        ]);
    }

    // --- R5.1: Zugriffsschutz ---

    public function testWithoutWriteAccessNoConfirmation(): void
    {
        $this->access->allowed = false;

        $this->expectException(AccessDeniedException::class);
        $this->management->confirmAndSave($this->completeResults());
    }

    // --- History of the admin's changes ---------------------------------------

    public function testEveryChangedValueIsLoggedWithBeforeAndAfter(): void
    {
        $log = new RecordingResultLog();
        $recording = $this->recordingWithLog($log);

        $recording->saveDraft($this->partialResults());
        self::assertSame([
            [self::HOME + 1, 1, null, '150'], [self::HOME + 1, 2, null, '160'], [self::HOME + 1, 3, null, '170'],
        ], $log->changes);
        self::assertSame(['draft_saved'], $log->steps);

        // A correction logs only what really changed, the blind mark included.
        $log->changes = [];
        $recording->saveDraft(new EnteredResults(self::FIXTURE, self::MATCHDAY, [
            EnteredGame::player(self::HOME, self::HOME + 1, 1, 150),
            EnteredGame::player(self::HOME, self::HOME + 1, 2, 165),
            EnteredGame::player(self::HOME, self::HOME + 2, 3, 140, true),
        ]));
        self::assertSame([
            [self::HOME + 1, 2, '160', '165'], [self::HOME + 1, 3, '170', null], [self::HOME + 2, 3, null, '140 blind'],
        ], $log->changes);
    }

    public function testRemovingLogsEveryValueAsRemoved(): void
    {
        $log = new RecordingResultLog();
        $recording = $this->recordingWithLog($log);
        $recording->saveDraft($this->partialResults());
        $log->changes = [];

        $recording->removeResults(self::FIXTURE, self::MATCHDAY);

        self::assertCount(3, $log->changes);
        self::assertSame(['150', null], [$log->changes[0][2], $log->changes[0][3]]);
        self::assertSame(['draft_saved', 'removed'], $log->steps);
    }

    private function recordingWithLog(ResultLog $log): ResultRecording
    {
        return new ResultRecording(
            $this->results,
            $this->status,
            $this->flag,
            $this->context,
            $this->handicaps,
            new CacheManager($this->cacheStore),
            new Validator(),
            $this->access,
            $this->roster,
            $this->playedGames,
            log: $log,
        );
    }

    // --- Testdaten-Helfer ---

    private function partialResults(): EnteredResults
    {
        // Only 3 games -> incomplete.
        return new EnteredResults(self::FIXTURE, self::MATCHDAY, [
            EnteredGame::player(self::HOME, self::HOME + 1, 1, 150),
            EnteredGame::player(self::HOME, self::HOME + 1, 2, 160),
            EnteredGame::player(self::HOME, self::HOME + 1, 3, 170),
        ]);
    }

    private function completeResults(): EnteredResults
    {
        return new EnteredResults(self::FIXTURE, self::MATCHDAY, $this->completeGames());
    }

    /**
     * Creates 24 games: 2 teams × 4 players (roster players 1–4) × 3 games.
     *
     * @return list<EnteredGame>
     */
    private function completeGames(): array
    {
        $games = [];
        foreach ([self::HOME, self::AWAY] as $team) {
            for ($player = 1; $player <= 4; $player++) {
                for ($num = 1; $num <= 3; $num++) {
                    $games[] = EnteredGame::player($team, $team + $player, $num, 150);
                }
            }
        }

        return $games;
    }

    /**
     * Complete games in which the home team lines up $homeGames instead of
     * players 1–4; the away team is complete.
     *
     * @param list<EnteredGame> $homeGames
     */
    private function withHome(array $homeGames): EnteredResults
    {
        $away = array_values(array_filter($this->completeGames(), static fn (EnteredGame $g): bool => $g->teamSeasonId === self::AWAY));

        return new EnteredResults(self::FIXTURE, self::MATCHDAY, [...$homeGames, ...$away]);
    }

    /**
     * Home games of the players with the given game numbers.
     *
     * @param array<int, list<int>> $games player number (1–6) → game numbers
     *
     * @return list<EnteredGame>
     */
    private function homeGames(array $games): array
    {
        $list = [];
        foreach ($games as $player => $numbers) {
            foreach ($numbers as $num) {
                $list[] = EnteredGame::player(self::HOME, self::HOME + $player, $num, 150);
            }
        }

        return $list;
    }
}

/**
 * Records the history entries of {@see ResultRecording}.
 */
final class RecordingResultLog implements ResultLog
{
    /** @var list<array{0: int, 1: int, 2: string|null, 3: string|null}> */
    public array $changes = [];

    /** @var list<string> */
    public array $steps = [];

    public function changed(int $fixtureId, int $playerId, int $gameNumber, ?string $before, ?string $after): void
    {
        $this->changes[] = [$playerId, $gameNumber, $before, $after];
    }

    public function noted(int $fixtureId, string $action, string $detail = ''): void
    {
        $this->steps[] = $action;
    }
}
