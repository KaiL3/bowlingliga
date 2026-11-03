<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * Port for the number of games a player has played so far, as of a matchday
 * (R27.4).
 *
 * "So far" means before the matchday: the games of complete fixtures of
 * earlier matchdays, plus the previous season as long as it counts towards
 * the average (R11.3) – the same number the score sheet shows in column G.
 * Implemented by the
 * {@see \Bowlingliga\Persistence\ResultEntry\HandicapFreezingRepository}.
 */
interface PlayedGamesReader
{
    public function gamesPlayedBefore(int $matchdayId, int $playerId): int;
}
