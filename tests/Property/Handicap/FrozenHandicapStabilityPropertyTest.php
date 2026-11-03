<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Handicap;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\Handicap\FrozenHandicapStore;
use Bowlingliga\Domain\Handicap\HandicapFreezeService;
use Bowlingliga\Domain\Handicap\HandicapFormula;
use Bowlingliga\Domain\Handicap\HandicapChainRecalculation;
use Bowlingliga\Domain\Handicap\HandicapChainMatchday;
use Bowlingliga\Domain\Handicap\HandicapModule;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the stability of the frozen handicap.
 *
 * Feature: bowlingliga-website, Property 5: Stability of the frozen handicap
 *
 * Validates: R12.12, R12.17, R12.19
 *
 * Domain background: a frozen handicap is raw data that preserves the average
 * state immediately before the respective matchday (R12.12). If a past
 * matchday is corrected later (a raw data change), the frozen handicaps stay
 * unchanged as long as the administrator does not explicitly trigger and
 * confirm a chain recalculation (R12.17). If an offered recalculation is not
 * confirmed, the previously frozen handicaps also stay unchanged (R12.19).
 *
 * The interplay of the pure
 * {@see \Bowlingliga\Domain\Handicap\HandicapChainRecalculation} (creates a
 * side-effect-free preview) and the
 * {@see \Bowlingliga\Domain\Handicap\HandicapFreezeService} (only persists
 * on explicit apply) is tested. Persistence is replaced by an in-memory
 * implementation of the {@see FrozenHandicapStore} port, so that the test runs
 * without a database and still uses the real domain services.
 */
final class FrozenHandicapStabilityPropertyTest extends TestCase
{
    use TestTrait;

    private const TARGET_SCORE     = 200;
    private const MAX_HANDICAP = 100;

    /**
     * Property 5 (stability): for a previously frozen handicap, every
     * subsequent raw data change (here: a changed average resulting in a
     * different preview) that is not explicitly confirmed by applying leaves
     * the frozen value unchanged (R12.12, R12.17, R12.19).
     *
     * Feature: bowlingliga-website, Property 5: Stability of the frozen handicap
     *
     * Validates: R12.12, R12.17, R12.19
     *
     * At least 100 inputs are generated (limitTo(100)).
     */
    public function testFrozenHandicapStaysStableWithoutConfirmedApply(): void
    {
        $module   = new HandicapModule();
        $formula  = HandicapFormula::standard();

        $this
            ->limitTo(100)
            ->forAll(
                // Original average 0.00–300.00 (state when freezing).
                Generators::map(
                    static fn (int $h): float => $h / 100.0,
                    Generators::choose(0, 30000)
                ),
                // Changed average after the raw data correction (0.00–300.00).
                Generators::map(
                    static fn (int $h): float => $h / 100.0,
                    Generators::choose(0, 30000)
                ),
                // Matchday counter of the frozen matchday (>= 1).
                Generators::choose(1, 30)
            )
            ->then(function (float $originalAverage, float $newAverage, int $matchdayCounter) use ($module, $formula): void {
                $store = new CollectingHandicapStore();
                $service  = new HandicapFreezeService($module, $store);
                $chains   = new HandicapChainRecalculation($module);

                $playerId  = 7;
                $matchdayId = 100 + $matchdayCounter;

                // Step 1: original freezing from the old average.
                $freeze = $service->freezeHandicap(
                    playerId: $playerId,
                    matchdayId: $matchdayId,
                    average: $originalAverage,
                    targetScore: self::TARGET_SCORE,
                    formula: $formula,
                    maxHandicap: self::MAX_HANDICAP,
                );

                // In the chosen input range there is always a handicap.
                self::assertTrue($freeze->hasHandicap());
                $frozen = $store->value($matchdayId, $playerId);
                self::assertNotNull($frozen);

                // Step 2: raw data correction -> calculate a mere preview. By
                // definition the preview must persist NOTHING (R12.18).
                $preview = $chains->recalculateHandicapChain($matchdayCounter, [
                    new HandicapChainMatchday(
                        matchdayId: $matchdayId,
                        matchdayCounter: $matchdayCounter,
                        playerId: $playerId,
                        average: $newAverage,
                        targetScore: self::TARGET_SCORE,
                        formula: $formula,
                        maxHandicap: self::MAX_HANDICAP,
                        isFirstMatchday: false,
                        previousHandicap: $frozen,
                    ),
                ]);

                // R12.17/R12.19: without an explicit apply the previously
                // frozen value stays unchanged – regardless of whether the
                // preview shows a deviation.
                self::assertSame(
                    $frozen,
                    $store->value($matchdayId, $playerId),
                    'Merely calculating a preview must not change the frozen handicap (R12.17, R12.19).'
                );
                self::assertCount(
                    1,
                    $store->entries,
                    'Without a confirmed apply no additional raw data record may be created (R12.19).'
                );

                // Check: only the explicit apply writes the new
                // value (R12.18) – and only if the value actually changes.
                $service->applyHandicapChainPreview($preview);
                $expectedAfterApply = $preview->hasChanges()
                    ? $preview->changesOnly()[0]->newHandicap
                    : $frozen;
                self::assertSame(
                    $expectedAfterApply,
                    $store->value($matchdayId, $playerId),
                    'After an explicit apply the recalculated value applies (R12.18).'
                );
            });
    }
}
