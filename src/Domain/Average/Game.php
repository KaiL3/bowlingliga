<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Average;

/**
 * Immutable value object for a single net game of a player as input to the
 * average module (R11).
 *
 * It is pure input raw data without side effects: the object bundles the net
 * result of a game with the domain flags that decide whether it is included
 * in the average calculation.
 *
 * - {$blind}: the game is marked blind according to R27. Games
 *   marked blind are not included in the average (R11.1, R27.13).
 * - {$ghostTeam}: the result comes from a ghost team player with a fixed net
 *   result. Ghost team results are not included in the average (R20.4).
 * - {$previousSeason}: the game was actually played in the previous season.
 *   Such games are only included during the first five matchdays of the
 *   current season (R11.3, R11.5).
 *
 * A game actually played by the player is one that is neither marked blind
 * nor comes from a ghost team player (R11.1).
 */
final class Game
{
    /**
     * @param int  $netResult      Net result in the range 0 to 300.
     * @param bool $blind          True if the game is marked blind according
     *                             to R27.
     * @param bool $ghostTeam      True if the result comes from a ghost team
     *                             player (R20).
     * @param bool $previousSeason True if the game was played in the previous
     *                             season (R11.3).
     *
     * @throws \InvalidArgumentException If the net result is outside the valid
     *                                   range 0 to 300.
     */
    public function __construct(
        public readonly int $netResult,
        public readonly bool $blind = false,
        public readonly bool $ghostTeam = false,
        public readonly bool $previousSeason = false,
    ) {
        if ($netResult < 0 || $netResult > 300) {
            throw new \InvalidArgumentException(
                'The net result of a single game must be between 0 and 300, given: ' . $netResult
            );
        }
    }

    /**
     * Tells whether this game was actually played by the player and may
     * therefore in principle be included in the average (R11.1).
     *
     * Games marked blind (R27.13) and ghost team results (R20.4) do not count
     * as actually played.
     */
    public function isActuallyPlayed(): bool
    {
        return !$this->blind && !$this->ghostTeam;
    }
}
