<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\TeamEntry;

use Bowlingliga\Application\ResultEntry\BlindFilling;
use Bowlingliga\Application\ResultEntry\BlindPlayerOrder;
use Bowlingliga\Application\ResultEntry\EnteredGame;
use Bowlingliga\Application\ResultEntry\EnteredResults;
use Bowlingliga\Application\ResultEntry\ResultRecording;
use Bowlingliga\Application\TeamEntry\TeamEntryException;
use Bowlingliga\Application\TeamEntry\TeamEntryWriteAccess;
use Bowlingliga\Application\TeamEntry\TeamResultEntry;
use Bowlingliga\Cache\CacheManager;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\TeamEntryCodes;
use Bowlingliga\Security\Validator;
use Bowlingliga\Tests\Unit\Application\ImmediateTransaction;
use Bowlingliga\Tests\Unit\Application\InMemoryRosterRepository;
use Bowlingliga\Tests\Unit\Application\ResultEntry\FixedMatchdayContext;
use Bowlingliga\Tests\Unit\Application\ResultEntry\FixedPlayedGames;
use Bowlingliga\Tests\Unit\Application\ResultEntry\InMemoryResultStore;
use Bowlingliga\Tests\Unit\Application\ResultEntry\RecordingCacheStore;
use Bowlingliga\Tests\Unit\Application\ResultEntry\RecordingFlagStore;
use Bowlingliga\Tests\Unit\Application\ResultEntry\RecordingHandicapFreezing;
use Bowlingliga\Tests\Unit\Application\ResultEntry\RecordingStatusStore;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Result entry by the teams (R37, decision of 2026-10-25): each team enters
 * its own lineup and results game by game, sends them, the opponent confirms;
 * free positions are filled with derived blind values. Codes and their
 * validity, who may change what, checks, sending, confirming, sending back,
 * withdrawing and the final saving through the real {@see ResultRecording}.
 */
final class TeamResultEntryTest extends TestCase
{
    private const FIXTURE = 100;
    private const MATCHDAY = 7;
    private const SEASON = 1;
    private const HOME = 10;
    private const AWAY = 20;

    private InMemoryTeamEntryStore $store;
    private TeamEntryCodes $codes;
    private InMemoryResultStore $results;
    private RecordingStatusStore $status;
    private RecordingHandicapFreezing $handicaps;
    private FixedPlayedGames $playedGames;
    private SwitchableWriteAccess $adminAccess;
    private ResultRecording $teamRecording;
    private TeamResultEntry $entry;
    private string $now = '2026-09-02 20:00:00';

    protected function setUp(): void
    {
        $this->build(6);
    }

    // --- Codes ---------------------------------------------------------------

    public function testCodeOpensTheFixtureOnlyOnTheMatchdayUntilNoonOfTheNextDay(): void
    {
        $code = $this->code('home');
        self::assertSame('home', $this->entry->open($code)->side);

        $this->now = '2026-09-03 11:59:00';
        self::assertSame('Adler', $this->entry->open($code)->ownTeam()['team_name']);

        foreach (['2026-09-01 23:59:00', '2026-09-03 12:00:00'] as $time) {
            $this->now = $time;
            try {
                $this->entry->open($code);
                self::fail('Outside the matchday the code must not work: ' . $time);
            } catch (TeamEntryException $e) {
                self::assertSame(['date' => '2026-09-02'], $e->message()->params);
            }
        }
    }

    public function testAlteredRenewedOrUnavailableCodesAreRejected(): void
    {
        $code = $this->code('away');
        $altered = substr($code, 0, -1) . ($code[-1] === 'A' ? 'B' : 'A');
        $this->assertRejected($altered, 'team_entry.error.invalid_code');

        $this->entry->renewCodes(self::FIXTURE);
        $this->assertRejected($code, 'team_entry.error.invalid_code');
        self::assertSame('away', $this->entry->open($this->code('away', 2))->side, 'The new code works.');

        $this->store->enabled[self::SEASON] = false;
        $this->assertRejected($this->code('away', 2), 'team_entry.error.not_available');

        $this->store->enabled[self::SEASON] = true;
        $this->store->fixtures[self::FIXTURE]['away']['is_ghost_team'] = true;
        $this->assertRejected($this->code('away', 2), 'team_entry.error.invalid_code');
        self::assertNull($this->entry->codeFor(self::FIXTURE, 'away'), 'The ghost team gets no code.');
        self::assertNotNull($this->entry->codeFor(self::FIXTURE, 'home'), 'Its opponent does.');
    }

    // --- Entering game by game -------------------------------------------------

    public function testEachTeamEntersItsOwnLineupAndResultsGameByGame(): void
    {
        $home = $this->code('home');
        $this->entry->saveGame($home, 1, $this->marks(self::HOME, [1, 2, 3, 4]), $this->nets(self::HOME, [1, 2, 3, 4], 170));

        $games = $this->entry->open($home)->participation(self::HOME);
        self::assertCount(4, $games);
        self::assertSame(['is_blind' => false, 'net' => 170], $games[self::HOME + 1][1]);

        // History: mark and net of every player on its own.
        self::assertCount(8, $this->store->changes);
        self::assertSame(
            ['fixture' => self::FIXTURE, 'side' => 'home', 'action' => 'lineup', 'player' => self::HOME + 1, 'game' => 1, 'before' => null, 'after' => 'spielt'],
            $this->store->changes[0],
        );
        $this->entry->saveGame($home, 1, $this->marks(self::HOME, [1, 2, 3, 4]), [self::HOME + 1 => '175'] + $this->nets(self::HOME, [2, 3, 4], 170));
        self::assertSame(['home', 'net', '170', '175'], [$this->store->changes[8]['side'], $this->store->changes[8]['action'], $this->store->changes[8]['before'], $this->store->changes[8]['after']]);

        // A player without a mark does not play, even with a net in the form.
        $this->entry->saveGame($home, 1, $this->marks(self::HOME, [1, 2, 3]), $this->nets(self::HOME, [1, 2, 3, 4], 170));
        self::assertArrayNotHasKey(self::HOME + 4, $this->entry->open($home)->participation(self::HOME));

        // The opponent's players are not part of the own entry.
        $this->expectExceptionMessage('team_entry.error.unknown_player');
        $this->entry->saveGame($this->code('away'), 1, $this->marks(self::HOME, [1]), []);
    }

    public function testNetsMustBeWithinRangeAndAtMostFourPlay(): void
    {
        $home = $this->code('home');
        try {
            $this->entry->saveGame($home, 1, $this->marks(self::HOME, [1]), [self::HOME + 1 => '301']);
            self::fail('301 is out of range.');
        } catch (TeamEntryException $e) {
            self::assertSame('team_entry.error.invalid_net', $e->message()->key);
        }

        $this->expectExceptionMessage('team_entry.error.too_many_players');
        $this->entry->saveGame($home, 1, $this->marks(self::HOME, [1, 2, 3, 4, 5]), []);
    }

    public function testMovingOnChecksTheGame(): void
    {
        $home = $this->code('home');
        $this->assertGameRejected($home, 1, 'team_entry.error.game_empty');

        $this->entry->saveGame($home, 1, $this->marks(self::HOME, [1, 2, 3, 4]), $this->nets(self::HOME, [1, 2, 3], 170));
        $this->assertGameRejected($home, 1, 'team_entry.error.missing_net');

        $this->entry->saveGame($home, 1, $this->marks(self::HOME, [1, 2, 3, 4]), $this->nets(self::HOME, [1, 2, 3, 4], 170));
        $this->entry->checkGame($home, 1);

        // "blind" only for a player who played the game before.
        $this->entry->saveGame($home, 2, $this->marks(self::HOME, [1, 2, 3]) + [self::HOME + 5 => 'blind'], $this->nets(self::HOME, [1, 2, 3], 170));
        $this->assertGameRejected($home, 2, 'team_entry.error.blind_without_game');

        // Player 4 was substituted after game 1 and cannot come back in game 3.
        $this->entry->saveGame($home, 2, $this->marks(self::HOME, [1, 2, 3, 5]), $this->nets(self::HOME, [1, 2, 3, 5], 170));
        $this->entry->checkGame($home, 2);
        $this->entry->saveGame($home, 3, $this->marks(self::HOME, [1, 2, 3, 4]), $this->nets(self::HOME, [1, 2, 3, 4], 170));
        $this->assertGameRejected($home, 3, 'team_entry.error.reentry');
    }

    public function testFreePositionsAreFilledWithTheDerivedBlindValues(): void
    {
        $this->playedGames->games = [self::HOME + 5 => 12, self::HOME + 6 => 30];
        $this->playedGames->averages = [self::HOME + 4 => 181.0, self::HOME + 5 => 160.0, self::HOME + 6 => 150.0];
        $home = $this->code('home');

        // Player 4 drops out after game 1; only three players are there at all.
        $this->entry->saveGame($home, 1, $this->marks(self::HOME, [1, 2, 3, 4]), $this->nets(self::HOME, [1, 2, 3, 4], 170));
        $this->entry->saveGame($home, 2, $this->marks(self::HOME, [1, 2, 3]) + [self::HOME + 4 => 'blind'], $this->nets(self::HOME, [1, 2, 3], 170));
        $this->entry->saveGame($home, 3, $this->marks(self::HOME, [1, 2, 3]), $this->nets(self::HOME, [1, 2, 3], 170));
        foreach ([1, 2, 3] as $game) {
            $this->entry->checkGame($home, $game);
        }

        $blind = array_values(array_filter($this->homeGames($this->submitBoth()), static fn (EnteredGame $g): bool => $g->isBlind));
        self::assertSame(
            [[self::HOME + 4, 2, 171], [self::HOME + 6, 3, 140]],
            array_map(static fn (EnteredGame $g): array => [$g->playerId, $g->gameNumber, $g->net], $blind),
            'Own average − 10 for the player dropping out, then the roster player with the most games.',
        );
    }

    /** R27.18: a roster of two – the free positions play like ghost players. */
    public function testATwoPlayerRosterIsFilledWithBlindSlots(): void
    {
        $this->build(2);
        $this->playedGames->games = [self::HOME + 2 => 9];
        $this->playedGames->averages = [self::HOME + 1 => 140.0, self::HOME + 2 => 230.0];
        $home = $this->code('home');
        foreach ([1, 2, 3] as $game) {
            $this->entry->saveGame($home, $game, $this->marks(self::HOME, [1, 2]), $this->nets(self::HOME, [1, 2], 170));
        }

        $games = $this->homeGames($this->submitBoth());
        self::assertCount(12, $games);
        $slots = array_values(array_filter($games, static fn (EnteredGame $g): bool => $g->isBlindSlot()));
        self::assertCount(6, $slots);
        self::assertSame([null], array_values(array_unique(array_map(static fn (EnteredGame $g): ?int => $g->playerId, $slots))));
        self::assertSame([190], array_values(array_unique(array_map(static fn (EnteredGame $g): int => $g->net, $slots))), 'The ghost score, not 230 − 10.');
    }

    // --- Sending, confirming, sending back ---------------------------------------

    public function testSendingChecksAllThreeGames(): void
    {
        $home = $this->code('home');
        $this->entry->saveGame($home, 1, $this->marks(self::HOME, [1, 2, 3, 4]), $this->nets(self::HOME, [1, 2, 3, 4], 170));
        $this->entry->saveGame($home, 2, $this->marks(self::HOME, [1, 2, 3, 4]), $this->nets(self::HOME, [1, 2, 3, 4], 170));

        $this->expectExceptionMessage('team_entry.error.game_empty');
        $this->entry->submit($home);
    }

    public function testBothSendAndConfirmEachOtherInAnyOrder(): void
    {
        $home = $this->code('home');
        $away = $this->code('away');
        $this->enterAll($home, self::HOME, 170);

        self::assertFalse($this->entry->submit($home));
        self::assertTrue($this->entry->open($away)->canConfirm(), 'The opponent may confirm before sending his own entry.');
        self::assertFalse($this->entry->confirm($away));
        self::assertSame([], $this->results->saved, 'Nothing is saved before both are confirmed.');

        $this->enterAll($away, self::AWAY, 160);
        self::assertFalse($this->entry->submit($away));
        self::assertTrue($this->entry->confirm($home));

        $saved = $this->results->saved[self::FIXTURE] ?? null;
        self::assertInstanceOf(EnteredResults::class, $saved);
        self::assertSame(ResultRecording::COMPLETE_COUNT, $saved->quantity());
        self::assertSame('complete', $this->status->status[self::FIXTURE]);
        self::assertSame([self::MATCHDAY], $this->handicaps->frozen);
        self::assertContains('final', $this->store->actions());

        self::assertTrue($this->entry->open($home)->finished);
        $this->expectExceptionMessage('team_entry.error.finished');
        $this->entry->saveGame($home, 1, [], []);
    }

    public function testASentEntryIsLockedAndCanBeWithdrawnUntilTheOpponentConfirms(): void
    {
        $home = $this->code('home');
        $away = $this->code('away');
        $this->enterAll($home, self::HOME, 170);
        $this->entry->submit($home);

        try {
            $this->entry->saveGame($home, 1, [], []);
            self::fail('A sent entry cannot be changed.');
        } catch (TeamEntryException $e) {
            self::assertSame('team_entry.error.already_submitted', $e->message()->key);
        }

        $this->entry->withdraw($home);
        self::assertFalse($this->entry->open($home)->own->submitted);
        self::assertFalse($this->entry->open($away)->canConfirm());
        self::assertContains('withdrawn', $this->store->actions());

        $this->entry->submit($home);
        $this->entry->confirm($away);
        $this->expectExceptionMessage('team_entry.error.already_confirmed');
        $this->entry->withdraw($home);
    }

    public function testSendingBackReopensTheEntryAndSendingAgainAnswersIt(): void
    {
        $home = $this->code('home');
        $away = $this->code('away');
        $this->enterAll($home, self::HOME, 170);
        $this->entry->submit($home);

        try {
            $this->entry->reject($away, '   ');
            self::fail('A reason is required.');
        } catch (TeamEntryException) {
        }

        $this->entry->reject($away, 'Spiel 2 von Adler 1 war 180');
        $sheet = $this->entry->open($home);
        self::assertFalse($sheet->own->submitted);
        self::assertTrue($sheet->canEdit());
        self::assertSame('Spiel 2 von Adler 1 war 180', $sheet->opponent->rejectionNote);

        $this->entry->saveGame($home, 2, $this->marks(self::HOME, [1, 2, 3, 4]), [self::HOME + 1 => '180'] + $this->nets(self::HOME, [2, 3, 4], 170));
        $this->entry->submit($home);
        self::assertNull($this->entry->open($home)->opponent->rejectionNote);
    }

    public function testConfirmingNeedsASentEntry(): void
    {
        $this->expectExceptionMessage('team_entry.error.not_submitted');
        $this->entry->confirm($this->code('away'));
    }

    /** R37.10: against the ghost team sending is the confirmation. */
    public function testAgainstTheGhostTeamSendingSavesTheResultsAtOnce(): void
    {
        $this->store->fixtures[self::FIXTURE]['away']['is_ghost_team'] = true;
        $home = $this->code('home');

        $sheet = $this->entry->open($home);
        self::assertTrue($sheet->ghostOpponent());
        self::assertTrue($sheet->canEdit());
        self::assertFalse($sheet->canConfirm());

        $this->enterAll($home, self::HOME, 170);
        self::assertSame([], $this->results->saved, 'Nothing counts before sending.');
        self::assertTrue($this->entry->submit($home));

        $saved = $this->results->saved[self::FIXTURE];
        self::assertSame(ResultRecording::COMPLETE_COUNT, $saved->quantity(), '12 own and 12 ghost team results.');
        self::assertSame([self::AWAY], $saved->ghostTeamIds);
        self::assertSame('complete', $this->status->status[self::FIXTURE]);
        self::assertTrue($this->entry->open($home)->finished);
    }

    public function testTeamAccessIsOnlyGrantedDuringTheFinalSaving(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->teamRecording->saveDraft(new EnteredResults(self::FIXTURE, self::MATCHDAY, []));
    }

    // --- Admin -----------------------------------------------------------------

    public function testAdminTakesTheValuesOverAsDraftWithTheBlindValues(): void
    {
        $this->playedGames->averages = [self::HOME + 5 => 160.0];
        $home = $this->code('home');
        $this->entry->saveGame($home, 1, $this->marks(self::HOME, [1, 2, 3]), $this->nets(self::HOME, [1, 2, 3], 170));
        $this->entry->saveGame($this->code('away'), 1, $this->marks(self::AWAY, [1, 2, 3, 4]), $this->nets(self::AWAY, [1, 2, 3, 4], 150));

        self::assertSame(8, $this->entry->takeOver(self::FIXTURE), '3 + 1 blind for the home team, 4 for the away team.');
        self::assertSame('in_progress', $this->status->status[self::FIXTURE]);
        self::assertSame(8, $this->results->saved[self::FIXTURE]->quantity());

        $this->expectExceptionMessage('team_entry.error.finished');
        $this->entry->saveGame($home, 1, [], []);
    }

    public function testAdminActionsNeedTheAdminLogin(): void
    {
        $this->adminAccess->allowed = false;

        $this->expectException(AccessDeniedException::class);
        $this->entry->takeOver(self::FIXTURE);
    }

    // --- Helpers ---------------------------------------------------------------

    /** Fixture Adler (home) – Falken (away), both with the given roster size. */
    private function build(int $players): void
    {
        $this->store = new InMemoryTeamEntryStore();
        $this->store->enabled[self::SEASON] = true;
        $this->store->fixtures[self::FIXTURE] = [
            'id' => self::FIXTURE, 'matchday_id' => self::MATCHDAY, 'season_id' => self::SEASON,
            'matchday_number' => 3, 'date' => '2026-09-02', 'lanes' => '1/2', 'status' => 'open', 'ghost_score' => 190,
            'home' => $this->team(self::HOME, 'Adler', $players),
            'away' => $this->team(self::AWAY, 'Falken', 6),
        ];

        $roster = new InMemoryRosterRepository();
        for ($i = 1; $i <= $players; $i++) {
            $roster->create(self::HOME, self::HOME + $i);
        }
        for ($i = 1; $i <= 6; $i++) {
            $roster->create(self::AWAY, self::AWAY + $i);
        }

        $this->codes = new TeamEntryCodes('test-key');
        $this->results = new InMemoryResultStore();
        $this->status = new RecordingStatusStore();
        $this->handicaps = new RecordingHandicapFreezing();
        $this->playedGames = new FixedPlayedGames();
        $this->adminAccess = new SwitchableWriteAccess(true);
        $context = new FixedMatchdayContext();
        $context->contexts[self::MATCHDAY] = ['season_id' => self::SEASON, 'matchday_number' => 3];

        $teamAccess = new TeamEntryWriteAccess();
        $recording = fn ($access): ResultRecording => new ResultRecording(
            $this->results,
            $this->status,
            new RecordingFlagStore(),
            $context,
            $this->handicaps,
            new CacheManager(new RecordingCacheStore()),
            new Validator(),
            $access,
            $roster,
            $this->playedGames,
        );
        $this->teamRecording = $recording($teamAccess);

        $order = new BlindPlayerOrder($roster, $this->playedGames);
        $this->entry = new TeamResultEntry(
            $this->store,
            $this->codes,
            $this->teamRecording,
            $teamAccess,
            $recording($this->adminAccess),
            $this->adminAccess,
            $order,
            new BlindFilling($order, $this->playedGames),
            new ImmediateTransaction(),
            fn (): \DateTimeImmutable => new \DateTimeImmutable($this->now),
        );
    }

    /** @return array<string, mixed> */
    private function team(int $id, string $name, int $players): array
    {
        $names = [];
        for ($i = 1; $i <= $players; $i++) {
            $names[$id + $i] = $name . ' ' . $i;
        }

        return ['team_season_id' => $id, 'team_name' => $name, 'is_ghost_team' => false, 'players' => $names];
    }

    private function code(string $side, int $version = 1): string
    {
        return $this->codes->create(self::FIXTURE, $side, $version);
    }

    /**
     * Marks "plays" for the players with these numbers.
     *
     * @param list<int> $numbers
     *
     * @return array<int, string>
     */
    private function marks(int $team, array $numbers): array
    {
        $marks = [];
        foreach ($numbers as $number) {
            $marks[$team + $number] = 'plays';
        }

        return $marks;
    }

    /**
     * @param list<int> $numbers
     *
     * @return array<int, string>
     */
    private function nets(int $team, array $numbers, int $net): array
    {
        $nets = [];
        foreach ($numbers as $number) {
            $nets[$team + $number] = (string) $net;
        }

        return $nets;
    }

    /** Players 1–4 of a team play all three games. */
    private function enterAll(string $code, int $team, int $net): void
    {
        foreach ([1, 2, 3] as $game) {
            $this->entry->saveGame($code, $game, $this->marks($team, [1, 2, 3, 4]), $this->nets($team, [1, 2, 3, 4], $net));
        }
    }

    /** Away team enters completely, both send and confirm; returns the saved results. */
    private function submitBoth(): EnteredResults
    {
        $home = $this->code('home');
        $away = $this->code('away');
        $this->enterAll($away, self::AWAY, 160);
        $this->entry->submit($home);
        $this->entry->submit($away);
        $this->entry->confirm($away);
        $this->entry->confirm($home);

        return $this->results->saved[self::FIXTURE];
    }

    /** @return list<EnteredGame> */
    private function homeGames(EnteredResults $results): array
    {
        return array_values(array_filter($results->games, static fn (EnteredGame $g): bool => $g->teamSeasonId === self::HOME));
    }

    private function assertGameRejected(string $code, int $game, string $key): void
    {
        try {
            $this->entry->checkGame($code, $game);
            self::fail('Game ' . $game . ' must be rejected: ' . $key);
        } catch (TeamEntryException $e) {
            self::assertSame($key, $e->message()->key);
        }
    }

    private function assertRejected(string $code, string $key): void
    {
        try {
            $this->entry->open($code);
            self::fail('The code must be rejected.');
        } catch (TeamEntryException $e) {
            self::assertSame($key, $e->message()->key);
        }
    }
}
