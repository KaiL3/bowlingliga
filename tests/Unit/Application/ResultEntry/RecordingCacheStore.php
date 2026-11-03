<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Cache\CacheEntry;
use Bowlingliga\Cache\CacheInvalidation;
use Bowlingliga\Cache\CacheCategory;
use Bowlingliga\Cache\CacheStore;

/**
 * In-memory fake of the {@see CacheStore} port that only records the
 * invalidation calls.
 */
final class RecordingCacheStore implements CacheStore
{
    /** @var list<CacheInvalidation> */
    public array $invalidations = [];

    public function find(CacheCategory $category, string $key): ?CacheEntry
    {
        return null;
    }

    public function write(CacheCategory $category, CacheEntry $entry): void
    {
    }

    public function invalidate(CacheInvalidation $invalidation): void
    {
        $this->invalidations[] = $invalidation;
    }

    public function clear(CacheCategory $category): void
    {
    }
}
