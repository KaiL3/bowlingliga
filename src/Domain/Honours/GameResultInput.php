<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Honours;

/**
 * Immutable input value object (raw data excerpt) for a single net game of a
 * player as input to the {@see HonoursModule} (R13).
 *
 * The object bundles exactly the raw data from which the honours are
 * calculated reproducibly (R35) and without side effects. The honours module
 * does not access the database; all raw games already collected are passed in
 * from outside, and the inclusion, exclusion and series logic is handled
 * solely by the {@see HonoursModule}.
 *
 * To form a countable series, a player's games are grouped by {$playerId} and
 * {$eveningId}: a countable series only exists if a real player has exactly 3
 * regular, personally played games on a single evening (R13.2).
 *
 * - {$blind}: the game is marked blind according to R27. Games
 *   marked blind do not count towards the honours (R13.4, R27.13).
 * - {$ghostTeam}: the result comes from a ghost team player with a fixed net
 *   result. Ghost team results are excluded from the honours (R13.4, R20.5).
 *
 * Only a game actually played by the player – neither marked blind nor from
 * the ghost team – is considered in the honours (R13.4).
 */
final class GameResultInput
{
    /**
     * @param int         $playerId   Unique player id; groups a player's games
     *                                (R13.2).
     * @param Gender      $gender     Gender of the player; controls the
     *                                separate scoring (R13.1).
     * @param int         $eveningId  Unique id of the evening; groups the games
     *                                into a possible series (R13.2).
     * @param int         $netResult  Net result of the game in the range 0 to
     *                                300.
     * @param int         $handicap   The player's handicap at the time of the
     *                                evening; basis of the gross scoring
     *                                gross = net + handicap (R13.3). Not
     *                                negative.
     * @param bool        $blind      True if the game is marked blind according
     *                                to R27 (R13.4, R27.13).
     * @param bool        $ghostTeam  True if the result comes from a ghost team
     *                                player (R13.4, R20.5).
     * @param string|null $playerName Optional display name of the player for
     *                                showing the honour; no domain meaning for
     *                                the calculation.
     *
     * @throws \InvalidArgumentException If the net result is outside the range
     *                                   0 to 300 or the handicap is negative.
     */
    public function __construct(
        public readonly int $playerId,
        public readonly Gender $gender,
        public readonly int $eveningId,
        public readonly int $netResult,
        public readonly int $handicap,
        public readonly bool $blind = false,
        public readonly bool $ghostTeam = false,
        public readonly ?string $playerName = null,
    ) {
        if ($netResult < 0 || $netResult > 300) {
            throw new \InvalidArgumentException(
                'The net result of a single game must be between 0 and 300, given: ' . $netResult
            );
        }

        if ($handicap < 0) {
            throw new \InvalidArgumentException(
                'The handicap of a player must not be negative, given: ' . $handicap
            );
        }
    }

    /**
     * Tells whether this game was played regularly by the player personally
     * and may therefore in principle be included in the honours (R13.4).
     *
     * Games marked blind (R13.4, R27.13) and ghost team results (R13.4, R20.5)
     * do not count as personally played.
     */
    public function isRegularSelfPlayed(): bool
    {
        return !$this->blind && !$this->ghostTeam;
    }

    /**
     * Returns the gross value of this game as the sum of the net value and the
     * player's handicap (R13.3).
     */
    public function grossResult(): int
    {
        return $this->netResult + $this->handicap;
    }
}
