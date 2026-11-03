<?php

declare(strict_types=1);

namespace Bowlingliga\Domain;

/**
 * A roster player of a team together with the games played as of the
 * matchday (R27.4, R27.14).
 *
 * Serves as input for {@see BlindPlayerService::determineSubstitutePlayer()}.
 * The games played are counted outside the pure service (raw data) and only
 * passed in as values here, so that the service stays free of side effects.
 */
final class RosterPlayer
{
    /**
     * @param int  $playerId         unique player id
     * @param int  $gamesPlayed      number of games played as of the matchday (R27.4)
     * @param bool $regularlyLinedUp true if the player is lined up regularly
     *        (for real) on this evening (R27.4)
     */
    public function __construct(
        public readonly int $playerId,
        public readonly int $gamesPlayed,
        public readonly bool $regularlyLinedUp
    ) {
    }
}
