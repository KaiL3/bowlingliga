<?php

declare(strict_types=1);

namespace Bowlingliga\Cache;

/**
 * Port for persisting the cache entries (R35.3, R35.4).
 *
 * Decouples the {@see CacheManager} from the concrete persistence technology.
 * The manager only knows the domain operations: read an entry, write it and
 * invalidate affected entries. The port is implemented by the
 * {@see \Bowlingliga\Persistence\CacheRepository} based on the cache tables
 * (`cache_*`); in tests a slim in-memory fake is enough.
 *
 * Since the cache only serves the response time (R35.3), implementations may
 * react fail-safe on a persistence error; domain correctness never depends on
 * the cache.
 */
interface CacheStore
{
    /**
     * Returns the stored entry of a category for a key, or null if there is no
     * entry (cache miss).
     */
    public function find(CacheCategory $category, string $key): ?CacheEntry;

    /**
     * Creates or updates a cache entry (upsert per key).
     */
    public function write(CacheCategory $category, CacheEntry $entry): void;

    /**
     * Removes all entries of all categories that are affected by the described
     * raw data change (R35.4).
     *
     * Affected are entries of the same season whose `from_matchday_number` is
     * greater than or equal to the invalidated matchday; if the invalidation
     * names no matchday, all entries of the season are removed.
     */
    public function invalidate(CacheInvalidation $invalidation): void;

    /**
     * Clears a single cache category completely (e.g. for maintenance/tests).
     */
    public function clear(CacheCategory $category): void;
}
