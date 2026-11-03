<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Cache;

use Bowlingliga\Cache\CacheInvalidation;
use Bowlingliga\Cache\CacheCategory;
use Bowlingliga\Cache\CacheManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see CacheManager} (task 5).
 *
 * Validates: R35.2, R35.3, R35.4
 *
 * Checks the core behaviour: a valid cache hit avoids recalculation, a cache
 * miss and an outdated raw_hash trigger a recalculation (R35.2), invalidation
 * removes affected entries from a matchday onwards (R35.4) and a persistence
 * error of the cache does not endanger correctness (fail-safe, R35.3).
 * Persistence is replaced without a database by an in-memory fake.
 */
final class CacheManagerTest extends TestCase
{
    private InMemoryCacheStore $store;
    private CacheManager $manager;

    protected function setUp(): void
    {
        $this->store = new InMemoryCacheStore();
        $this->manager  = new CacheManager(
            $this->store,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-01-01 12:00:00'),
        );
    }

    public function testCacheMissCalculatesAndStores(): void
    {
        $calls = 0;

        $value = $this->manager->readOrCalculate(
            CacheCategory::AVERAGE,
            'spieler-1-saison-1',
            'hash-a',
            function () use (&$calls): array {
                ++$calls;

                return ['average' => 180.5];
            },
            seasonId: 1,
            fromMatchdayCounter: 3,
        );

        self::assertSame(['average' => 180.5], $value);
        self::assertSame(1, $calls, 'On a cache miss it must be calculated exactly once (R35.2).');
        self::assertSame(1, $this->store->writeCounter, 'The result must be stored in the cache.');
    }

    public function testValidCacheHitAvoidsRecalculation(): void
    {
        $calculation = fn (): array => ['average' => 180.5];

        // First call: miss -> calculate and store.
        $this->manager->readOrCalculate(CacheCategory::AVERAGE, 'k', 'hash-a', $calculation, 1, 3);

        $calls = 0;
        $value = $this->manager->readOrCalculate(
            CacheCategory::AVERAGE,
            'k',
            'hash-a',
            function () use (&$calls): array {
                ++$calls;

                return ['average' => 999.0];
            },
            1,
            3,
        );

        self::assertSame(['average' => 180.5], $value, 'A valid cache hit returns the stored value.');
        self::assertSame(0, $calls, 'A valid hit must not recalculate.');
    }

    public function testOutdatedRawHashTriggersRecalculation(): void
    {
        $this->manager->readOrCalculate(
            CacheCategory::LEAGUE_TABLE,
            'liga-1',
            'hash-alt',
            fn (): array => ['state' => 'alt'],
            1,
            1,
        );

        $value = $this->manager->readOrCalculate(
            CacheCategory::LEAGUE_TABLE,
            'liga-1',
            'hash-neu',
            fn (): array => ['state' => 'neu'],
            1,
            1,
        );

        self::assertSame(['state' => 'neu'], $value, 'A different raw hash must recalculate (R35.2).');
    }

    public function testInvalidationFromMatchdayRemovesAffectedEntries(): void
    {
        // Two average entries: from matchday 2 and from matchday 5.
        $this->manager->readOrCalculate(CacheCategory::AVERAGE, 'sp2', 'h', fn (): array => ['s' => 2], 1, 2);
        $this->manager->readOrCalculate(CacheCategory::AVERAGE, 'sp5', 'h', fn (): array => ['s' => 5], 1, 5);

        // Result change on matchday 4 -> invalidates everything from matchday 4 (i.e. sp5).
        $this->manager->invalidate(CacheInvalidation::fromMatchday(1, 4));

        // sp2 stays valid -> no new call.
        $callsSp2 = 0;
        $this->manager->readOrCalculate(CacheCategory::AVERAGE, 'sp2', 'h', function () use (&$callsSp2): array {
            ++$callsSp2;

            return ['s' => 2];
        }, 1, 2);

        // sp5 was invalidated -> recalculation.
        $callsSp5 = 0;
        $this->manager->readOrCalculate(CacheCategory::AVERAGE, 'sp5', 'h', function () use (&$callsSp5): array {
            ++$callsSp5;

            return ['s' => 5];
        }, 1, 5);

        self::assertSame(0, $callsSp2, 'An entry before the invalidated matchday stays valid (R35.4).');
        self::assertSame(1, $callsSp5, 'An entry from the invalidated matchday on must be recalculated (R35.4).');
    }

    public function testInvalidationOnlyForAffectedSeason(): void
    {
        $this->manager->readOrCalculate(CacheCategory::HONOURS, 's1', 'h', fn (): array => ['x' => 1], 1, 1);
        $this->manager->readOrCalculate(CacheCategory::HONOURS, 's2', 'h', fn (): array => ['x' => 2], 2, 1);

        $this->manager->invalidate(CacheInvalidation::wholeSeason(1));

        $callsS2 = 0;
        $this->manager->readOrCalculate(CacheCategory::HONOURS, 's2', 'h', function () use (&$callsS2): array {
            ++$callsS2;

            return ['x' => 2];
        }, 2, 1);

        self::assertSame(0, $callsS2, 'Another season must not be affected by the invalidation (R35.4).');
    }

    public function testFailSafeOnCachePersistenceError(): void
    {
        $this->store->throwsError = true;
        $calls = 0;

        $value = $this->manager->readOrCalculate(
            CacheCategory::MATCHDAY_TABLE,
            'k',
            'h',
            function () use (&$calls): array {
                ++$calls;

                return ['ok' => true];
            },
            1,
            1,
        );

        self::assertSame(['ok' => true], $value, 'On a cache error the value must still be calculated correctly (R35.3).');
        self::assertSame(1, $calls);

        // The invalidation must not fail through on an error either.
        $this->manager->invalidate(CacheInvalidation::wholeSeason(1));
        $this->addToAssertionCount(1);
    }
}
