<?php

declare(strict_types=1);

namespace Bowlingliga\Cache;

/**
 * Central cache manager for derived values (R35.2, R35.3, R35.4, R35.5).
 *
 * The cache only serves to improve response times and is never required by
 * the domain (R35.3): every derived value can be calculated reproducibly from
 * the current raw data at any time (R35.2). This gives the central behaviour
 * of this manager:
 *
 * - {@see readOrCalculate()} returns a valid cache entry or transparently
 *   recalculates the value (fail-safe). An entry counts as valid if it exists
 *   and its stored `raw_hash` matches the current hash of the underlying raw
 *   data. Otherwise (cache miss or outdated hash) the value is recalculated
 *   and the entry updated. If the cache access itself fails, the value is
 *   recalculated as well – the cache must never endanger correctness.
 * - {@see invalidate()} removes all entries affected by a raw data change via
 *   explicit dependencies (R35.4): a result change on matchday t invalidates
 *   the average, table and honours cache from t onwards.
 *
 * The frozen handicap is raw data and is never touched by this manager
 * (R35.5); there is no cache category for it.
 */
final class CacheManager
{
    public function __construct(
        private readonly CacheStore $store,
        /** @var callable(): \DateTimeImmutable */
        private $clock = null,
    ) {
        $this->clock ??= static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Returns the derived value from the cache or transparently recalculates
     * it (R35.2, R35.4).
     *
     * The return value always matches a recalculation from the current raw
     * data: a valid cache entry is only used if its `raw_hash` equals the
     * current `$rawHash`. On a cache miss, an outdated hash or an error in the
     * cache access, `$calculation` is run, the result is stored in the cache
     * (fail-safe) and returned.
     *
     * @param string                           $key                 unique cache key
     * @param string                           $rawHash             hash of the current raw data state
     * @param callable(): array<string, mixed> $calculation         pure recalculation of the value
     * @param int|null                         $seasonId            invalidation reference (R35.4)
     * @param int|null                         $fromMatchdayCounter invalidation reference (R35.4)
     *
     * @return array<string, mixed> the derived value
     */
    public function readOrCalculate(
        CacheCategory $category,
        string $key,
        string $rawHash,
        callable $calculation,
        ?int $seasonId = null,
        ?int $fromMatchdayCounter = null,
    ): array {
        $presentEntry = $this->findOrFail($category, $key);

        if ($presentEntry !== null && $presentEntry->rawHash === $rawHash) {
            return $presentEntry->contentData;
        }

        // Cache miss or outdated state: recalculate (R35.2) and – fail-safe –
        // store in the cache. An error while writing must not prevent the
        // result (R35.3).
        $value = $calculation();

        $this->writeSafely($category, new CacheEntry(
            $key,
            $value,
            $rawHash,
            $seasonId,
            $fromMatchdayCounter,
            ($this->clock)(),
        ));

        return $value;
    }

    /**
     * Invalidates all cache entries affected by a raw data change (R35.4).
     *
     * Typically called after the transactional saving of changed net results
     * (R22.6): all entries of the affected season from the changed matchday
     * onwards are removed, so that a subsequent read recalculates from the
     * current raw data.
     */
    public function invalidate(CacheInvalidation $invalidation): void
    {
        try {
            $this->store->invalidate($invalidation);
        } catch (\Throwable) {
            // Fail-safe: if the invalidation fails, correctness is kept
            // by the raw_hash comparison in readOrCalculate() – an
            // outdated entry is discarded there anyway (R35.2).
        }
    }

    /**
     * Reads an entry defensively; an error in the cache access leads to a
     * handled cache miss (fail-safe, R35.3).
     */
    private function findOrFail(CacheCategory $category, string $key): ?CacheEntry
    {
        try {
            return $this->store->find($category, $key);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Writes an entry defensively; an error while writing is swallowed, since
     * the cache is not required by the domain (R35.3).
     */
    private function writeSafely(CacheCategory $category, CacheEntry $entry): void
    {
        try {
            $this->store->write($category, $entry);
        } catch (\Throwable) {
            // bewusst ignoriert (Fail-safe, R35.3)
        }
    }
}
