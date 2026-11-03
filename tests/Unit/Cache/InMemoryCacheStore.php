<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Cache;

use Bowlingliga\Cache\CacheEntry;
use Bowlingliga\Cache\CacheInvalidation;
use Bowlingliga\Cache\CacheCategory;
use Bowlingliga\Cache\CacheStore;

/**
 * In-memory fake of the {@see CacheStore} port for the CacheManager unit
 * tests, without a database.
 *
 * Reproduces the matchday-dependent invalidation (R35.4) and an optionally
 * switchable error case, to check the fail-safe behaviour of the manager
 * (R35.3).
 */
final class InMemoryCacheStore implements CacheStore
{
    /** @var array<string, array<string, CacheEntry>> category value => key => entry */
    private array $entries = [];

    /** Counts writes, to prove recalculations. */
    public int $writeCounter = 0;

    /** If true, all operations throw an exception (fail-safe test). */
    public bool $throwsError = false;

    public function find(CacheCategory $category, string $key): ?CacheEntry
    {
        $this->ensureError();

        return $this->entries[$category->value][$key] ?? null;
    }

    public function write(CacheCategory $category, CacheEntry $entry): void
    {
        $this->ensureError();

        ++$this->writeCounter;
        $this->entries[$category->value][$entry->key] = $entry;
    }

    public function invalidate(CacheInvalidation $invalidation): void
    {
        $this->ensureError();

        foreach ($this->entries as $categoryValue => $entries) {
            foreach ($entries as $key => $entry) {
                if ($entry->seasonId !== $invalidation->seasonId) {
                    continue;
                }

                if (
                    $invalidation->fromMatchdayCounter === null
                    || $entry->fromMatchdayCounter === null
                    || $entry->fromMatchdayCounter >= $invalidation->fromMatchdayCounter
                ) {
                    unset($this->entries[$categoryValue][$key]);
                }
            }
        }
    }

    public function clear(CacheCategory $category): void
    {
        $this->ensureError();

        unset($this->entries[$category->value]);
    }

    private function ensureError(): void
    {
        if ($this->throwsError) {
            throw new \RuntimeException('Simulierter Cache-Persistenzfehler.');
        }
    }
}
