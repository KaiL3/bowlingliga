<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * Read port for the season/matchday context of a matchday (R35.4).
 *
 * Returns the reference values needed for the targeted cache invalidation:
 * the season and the matchday counter of a matchday. A result change on
 * matchday t invalidates the average, table and honours cache of this season
 * from t onwards (R35.4).
 *
 * The port is implemented by an adapter based on the
 * {@see \Bowlingliga\Persistence\MatchdayRepository}; in tests a slim fake
 * is enough.
 */
interface MatchdayContext
{
    /**
     * Returns season id and matchday counter of a matchday, or null if the
     * matchday does not exist.
     *
     * @return array{season_id: int, matchday_number: int}|null
     */
    public function findSeasonAndCounter(int $matchdayId): ?array;
}
