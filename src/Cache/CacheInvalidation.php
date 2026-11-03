<?php

declare(strict_types=1);

namespace Bowlingliga\Cache;

/**
 * Describes the cache dependency affected by a raw data change (R35.4).
 *
 * Models explicitly which derived values a change invalidates: a result
 * change on matchday t of a season invalidates the average, table and honours
 * cache of this season from matchday t onwards.
 *
 * Pure, immutable value object.
 */
final class CacheInvalidation
{
    /**
     * @param int      $seasonId            affected season
     * @param int|null $fromMatchdayCounter from which matchday counter on the
     *        cache is invalidated; null invalidates the whole season regardless
     *        of the matchday
     */
    public function __construct(
        public readonly int $seasonId,
        public readonly ?int $fromMatchdayCounter = null,
    ) {
    }

    /**
     * Invalidation from a specific matchday of a season (R35.4).
     */
    public static function fromMatchday(int $seasonId, int $fromMatchdayCounter): self
    {
        return new self($seasonId, $fromMatchdayCounter);
    }

    /**
     * Invalidation of the whole season, regardless of the matchday.
     */
    public static function wholeSeason(int $seasonId): self
    {
        return new self($seasonId, null);
    }
}
