<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * Read port for a player's net average immediately before a matchday (R11,
 * R27.6), the basis of the derived blind value.
 *
 * In production the
 * {@see \Bowlingliga\Persistence\ResultEntry\HandicapFreezingRepository}
 * implements it with the same rules as the handicap.
 */
interface PlayerAverageReader
{
    /** Exact net average before the matchday, null without one (new player, R27.16). */
    public function averageBefore(int $matchdayId, int $playerId): ?float;
}
