<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Cache;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Cache\CacheInvalidation;
use Bowlingliga\Cache\CacheCategory;
use Bowlingliga\Cache\CacheManager;
use Bowlingliga\Tests\Unit\Cache\InMemoryCacheStore;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the reproducibility of derived values and the cache
 * consistency.
 *
 * Feature: bowlingliga-website, Property 23: Reproducibility of derived values and cache consistency
 *
 * Validates: R35.2, R35.4
 *
 * Domain background: derived values (average, tables, honours) can be
 * calculated reproducibly from the current raw data at any time (requirement
 * 35.2). A valid cache value must therefore match a recalculation from the
 * current raw data; if the raw data changes, a subsequent read after cache
 * invalidation must again match the recalculation (R35.4).
 *
 * The {@see CacheManager} identifies the raw data state via a `raw_hash`.
 * This test simulates raw data as a varying integer per matchday; the
 * "recalculation" is a pure function of this state. Over many generated raw
 * data states and changes it is checked that the value returned via
 * {@see CacheManager::readOrCalculate()} always equals the pure recalculation
 * – on a cache miss, on a cache hit, with a changed raw data state and after
 * {@see CacheManager::invalidate()}.
 *
 * The pure cache mechanism is tested without a database (in-memory store).
 */
final class CacheReproducibilityPropertyTest extends TestCase
{
    use TestTrait;

    private const SEASON = 1;

    /**
     * Property 23 (reproducibility): for every raw data state
     * {@see CacheManager::readOrCalculate()} returns exactly the same result as
     * the pure recalculation from this state – regardless of whether the value
     * comes from the cache or was recalculated (R35.2).
     *
     * Feature: bowlingliga-website, Property 23: Reproducibility of derived values and cache consistency
     *
     * Validates: R35.2
     *
     * At least 100 inputs are generated (limitTo(100)).
     */
    public function testValidCacheMatchesRecalculation(): void
    {
        $this
            ->limitTo(100)
            ->forAll(
                Generators::choose(1, 20),   // Matchday counter
                Generators::choose(0, 10000) // raw data state
            )
            ->then(function (int $matchday, int $rawStanding): void {
                $store = new InMemoryCacheStore();
                $manager  = new CacheManager($store);

                $key = 'liga-' . self::SEASON;
                $rawHash    = $this->hash($rawStanding);
                $calculation = fn (): array => $this->recalculation($rawStanding);

                // First access (cache miss): the value is calculated and stored.
                $firstValue = $manager->readOrCalculate(
                    CacheCategory::LEAGUE_TABLE,
                    $key,
                    $rawHash,
                    $calculation,
                    self::SEASON,
                    $matchday,
                );

                // Second access (cache hit) with the identical raw state.
                $secondValue = $manager->readOrCalculate(
                    CacheCategory::LEAGUE_TABLE,
                    $key,
                    $rawHash,
                    // This calculation must not be called any more; if it
                    // were, it would return the same value (determinism).
                    $calculation,
                    self::SEASON,
                    $matchday,
                );

                // Both match the direct recalculation (R35.2).
                self::assertSame($this->recalculation($rawStanding), $firstValue);
                self::assertSame($this->recalculation($rawStanding), $secondValue);
                self::assertSame($firstValue, $secondValue, 'Cache hit and recalculation must agree (R35.2).');
            });
    }

    /**
     * Property 23 (cache consistency after a change): if the raw data state
     * changes and the cache is invalidated from the affected matchday onwards,
     * the subsequent read matches the recalculation from the new state
     * (R35.4).
     *
     * Feature: bowlingliga-website, Property 23: Reproducibility of derived values and cache consistency
     *
     * Validates: R35.4
     *
     * At least 100 inputs are generated (limitTo(100)).
     */
    public function testAfterInvalidationReadAccessMatchesRecalculation(): void
    {
        $this
            ->limitTo(100)
            ->forAll(
                Generators::choose(1, 20),   // Matchday counter
                Generators::choose(0, 10000), // old raw data state
                Generators::choose(0, 10000)  // new raw data state
            )
            ->then(function (int $matchday, int $old, int $fresh): void {
                $store = new InMemoryCacheStore();
                $manager  = new CacheManager($store);

                $key = 'liga-' . self::SEASON;

                // Ausgangszustand cachen.
                $manager->readOrCalculate(
                    CacheCategory::LEAGUE_TABLE,
                    $key,
                    $this->hash($old),
                    fn (): array => $this->recalculation($old),
                    self::SEASON,
                    $matchday,
                );

                // Raw data change: invalidate the cache from this matchday onwards (R35.4).
                $manager->invalidate(CacheInvalidation::fromMatchday(self::SEASON, $matchday));

                // Subsequent read with the new state.
                $valueAfterChange = $manager->readOrCalculate(
                    CacheCategory::LEAGUE_TABLE,
                    $key,
                    $this->hash($fresh),
                    fn (): array => $this->recalculation($fresh),
                    self::SEASON,
                    $matchday,
                );

                self::assertSame(
                    $this->recalculation($fresh),
                    $valueAfterChange,
                    'After invalidation the read must equal the recalculation from the new raw data (R35.4).'
                );
            });
    }

    /**
     * Pure, deterministic "recalculation" of a derived value from the simulated
     * raw data state: equal raw data always gives the same result (R35.2).
     *
     * @return array<string, mixed>
     */
    private function recalculation(int $rawStanding): array
    {
        return [
            'sum'       => $rawStanding,
            'average' => round($rawStanding / 3, 2),
            'marker'  => $rawStanding % 2 === 0 ? 'even' : 'odd',
        ];
    }

    /**
     * Forms the raw data hash by which the CacheManager detects whether an
     * entry is valid.
     */
    private function hash(int $rawStanding): string
    {
        return hash('sha256', (string) $rawStanding);
    }
}
