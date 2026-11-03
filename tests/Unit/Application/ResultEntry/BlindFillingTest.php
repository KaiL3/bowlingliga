<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\BlindFilling;
use Bowlingliga\Application\ResultEntry\BlindPlayerOrder;
use Bowlingliga\Application\ResultEntry\BlindPosition;
use Bowlingliga\Tests\Unit\Application\InMemoryRosterRepository;
use PHPUnit\Framework\TestCase;

/**
 * Filling the free positions of a team's games with derived blind values
 * (R21.3, R27.4–R27.7, R27.18; a blind slot plays like a ghost player).
 */
final class BlindFillingTest extends TestCase
{
    private const TEAM = 10;
    private const MATCHDAY = 7;
    private const GHOST_SCORE = 190;

    private InMemoryRosterRepository $roster;
    private FixedPlayedGames $played;
    private BlindFilling $filling;

    protected function setUp(): void
    {
        $this->roster = new InMemoryRosterRepository();
        $this->played = new FixedPlayedGames();
        $this->filling = new BlindFilling(new BlindPlayerOrder($this->roster, $this->played), $this->played);
    }

    public function testFourPlayersNeedNoBlindValue(): void
    {
        $this->rosterOf(6);

        self::assertSame([], $this->filling->fill(self::TEAM, self::MATCHDAY, $this->playing([1, 2, 3, 4]), self::GHOST_SCORE));
    }

    public function testTheRosterPlayerWithTheMostGamesServesAsBlindPlayer(): void
    {
        $this->rosterOf(5);
        $this->played->games = [14 => 10, 15 => 20];
        $this->played->averages = [14 => 160.0, 15 => 150.4];

        $positions = $this->filling->fill(self::TEAM, self::MATCHDAY, $this->playing([1, 2, 3]), self::GHOST_SCORE);

        self::assertSame(
            [[BlindPosition::SUBSTITUTE, 15, 1, 140], [BlindPosition::SUBSTITUTE, 15, 2, 140], [BlindPosition::SUBSTITUTE, 15, 3, 140]],
            $this->summary($positions),
            'Player 15 has more games; his average − 10, rounded.',
        );
    }

    public function testAPlayerDroppingOutKeepsHisPositionWithHisOwnAverage(): void
    {
        $this->rosterOf(6);
        $this->played->averages = [14 => 171.0, 15 => 200.0];
        $entries = $this->playing([1, 2, 3, 4]);
        $entries[14][2] = ['is_blind' => true, 'net' => null];
        $entries[14][3] = ['is_blind' => true, 'net' => null];

        self::assertSame(
            [[BlindPosition::OWN, 14, 2, 161], [BlindPosition::OWN, 14, 3, 161]],
            $this->summary($this->filling->fill(self::TEAM, self::MATCHDAY, $entries, self::GHOST_SCORE)),
        );
    }

    public function testALatecomerOnlyLeavesTheFirstGameToTheBlindPlayer(): void
    {
        $this->rosterOf(6);
        $this->played->averages = [15 => 150.0, 16 => 150.0];
        $entries = $this->playing([1, 2, 3]);
        $entries[14] = [2 => ['is_blind' => false, 'net' => 150], 3 => ['is_blind' => false, 'net' => 150]];

        self::assertSame(
            [[BlindPosition::SUBSTITUTE, 15, 1, 140]],
            $this->summary($this->filling->fill(self::TEAM, self::MATCHDAY, $entries, self::GHOST_SCORE)),
        );
    }

    /** R27.18: no roster player left – blind slots that play like a ghost player. */
    public function testWithoutAFreeRosterPlayerTheGapsPlayLikeGhostPlayers(): void
    {
        $this->rosterOf(2);
        $this->played->games = [11 => 5, 12 => 30];
        $this->played->averages = [11 => 150.0, 12 => 230.0];

        $positions = $this->filling->fill(self::TEAM, self::MATCHDAY, $this->playing([1, 2]), self::GHOST_SCORE);

        self::assertCount(6, $positions);
        foreach ($positions as $position) {
            self::assertSame(BlindPosition::SLOT, $position->kind);
            self::assertNull($position->playerId);
            self::assertSame(self::GHOST_SCORE, $position->net, 'Not the strong player\'s 220.');
        }
        self::assertSame([1, 1, 1, 2, 2, 2], array_map(static fn (BlindPosition $p): int => $p->slot, $positions));

        $game = $positions[0]->toEnteredGame(self::TEAM);
        self::assertNotNull($game);
        self::assertTrue($game->isBlindSlot());
        self::assertNull($game->playerId);
    }

    /** No unused roster player is left out: a new one serves with the ghost score, no blind slot. */
    public function testANewRosterPlayerWithoutAverageServesWithTheGhostScore(): void
    {
        $this->rosterOf(4);
        $this->played->games = [11 => 3];
        $this->played->averages = [11 => 160.0];

        $positions = $this->filling->fill(self::TEAM, self::MATCHDAY, $this->playing([1, 2, 3]), self::GHOST_SCORE);

        self::assertSame([[BlindPosition::SUBSTITUTE, 14, 1, 190], [BlindPosition::SUBSTITUTE, 14, 2, 190], [BlindPosition::SUBSTITUTE, 14, 3, 190]], $this->summary($positions));
        self::assertTrue($positions[0]->ghostValue);
        self::assertTrue($positions[0]->toEnteredGame(self::TEAM)?->isPlayerRow(), 'In his own row.');
    }

    public function testBlindSlotsOnlyOnceTheRosterIsUsedUp(): void
    {
        $this->rosterOf(3);
        $this->played->averages = [11 => 160.0];

        $positions = $this->filling->fill(self::TEAM, self::MATCHDAY, $this->playing([1, 2]), self::GHOST_SCORE);

        self::assertSame(
            [[BlindPosition::SUBSTITUTE, 13, 1, 190], [BlindPosition::SUBSTITUTE, 13, 2, 190], [BlindPosition::SUBSTITUTE, 13, 3, 190],
             [BlindPosition::SLOT, null, 1, 190], [BlindPosition::SLOT, null, 2, 190], [BlindPosition::SLOT, null, 3, 190]],
            $this->summary($positions),
            'Player 13 first, a slot only for the fourth position.',
        );
    }

    /** A new player without an average: the average of his games this evening, until the end. */
    public function testANewPlayerDroppingOutGetsTheAverageOfHisGamesOfThisEvening(): void
    {
        $this->rosterOf(4);
        $entries = $this->playing([2, 3, 4]);
        $entries[11] = [
            1 => ['is_blind' => false, 'net' => 150],
            2 => ['is_blind' => false, 'net' => 171],
            3 => ['is_blind' => true, 'net' => null],
        ];

        $positions = $this->filling->fill(self::TEAM, self::MATCHDAY, $entries, self::GHOST_SCORE);

        self::assertSame([[BlindPosition::OWN, 11, 3, 151]], $this->summary($positions), '(150 + 171) / 2 − 10, rounded.');
        self::assertTrue($positions[0]->fromEvening);
    }

    public function testAGameWithoutAnyRealPlayerIsNotFilled(): void
    {
        $this->rosterOf(5);
        $this->played->averages = [15 => 150.0];
        $this->played->games = [15 => 8];
        $entries = [];
        foreach ([11, 12, 13] as $player) {
            $entries[$player] = [1 => ['is_blind' => false, 'net' => 150]];
        }

        self::assertSame([[BlindPosition::SUBSTITUTE, 15, 1, 140]], $this->summary($this->filling->fill(self::TEAM, self::MATCHDAY, $entries, self::GHOST_SCORE)));
    }

    private function rosterOf(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->roster->create(self::TEAM, self::TEAM + $i);
        }
    }

    /**
     * Players (by number) playing all three games with net 150.
     *
     * @param list<int> $numbers
     *
     * @return array<int, array<int, array{is_blind: bool, net: int|null}>>
     */
    private function playing(array $numbers): array
    {
        $entries = [];
        foreach ($numbers as $number) {
            foreach ([1, 2, 3] as $game) {
                $entries[self::TEAM + $number][$game] = ['is_blind' => false, 'net' => 150];
            }
        }

        return $entries;
    }

    /**
     * @param list<BlindPosition> $positions
     *
     * @return list<array{0: string, 1: int|null, 2: int, 3: int|null}>
     */
    private function summary(array $positions): array
    {
        return array_map(static fn (BlindPosition $p): array => [$p->kind, $p->playerId, $p->gameNumber, $p->net], $positions);
    }
}
