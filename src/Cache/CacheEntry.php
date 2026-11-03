<?php

declare(strict_types=1);

namespace Bowlingliga\Cache;

/**
 * Immutable value object of a stored cache entry (R35.2–4).
 *
 * An entry carries a derived value that can be calculated reproducibly from
 * the raw data ({@see $contentData}) together with its invalidation
 * reference:
 * - {@see $rawHash} binds the entry to the state of the raw data it was
 *   calculated from; if the current hash differs, the entry counts as
 *   outdated.
 * - {@see $seasonId} and {@see $fromMatchdayCounter} model the domain
 *   dependency, i.e. from which matchday on the entry is affected by a result
 *   change (R35.4).
 *
 * The entry contains only derived values and never raw data (R35.3).
 */
final class CacheEntry
{
    /**
     * @param string               $key                 unique cache key
     * @param array<string, mixed> $contentData         derived value (JSON serialisable)
     * @param string               $rawHash             hash of the raw data state (R35.4)
     * @param int|null             $seasonId            affected season (invalidation)
     * @param int|null             $fromMatchdayCounter from which matchday on affected (R35.4)
     * @param \DateTimeImmutable   $calculatedAt        point in time of the calculation
     */
    public function __construct(
        public readonly string $key,
        public readonly array $contentData,
        public readonly string $rawHash,
        public readonly ?int $seasonId,
        public readonly ?int $fromMatchdayCounter,
        public readonly \DateTimeImmutable $calculatedAt,
    ) {
    }
}
