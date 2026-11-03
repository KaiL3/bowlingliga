<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Handicap;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\Handicap\HandicapFormula;
use Bowlingliga\Domain\Handicap\HandicapModule;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the handicap formula and its limit.
 *
 * Feature: bowlingliga-website, Property 3: Handicap formula and limit
 *
 * Validates: R12.8, R12.10, R12.15
 *
 * Domain background: the handicap module determines the handicap using the
 * default handicap formula `HDC = (target score − average) × 0.8`, rounds the
 * result to an integer (R12.8) and then limits it to the range
 * `[0, maximum handicap]`:
 *   - a result less than 0 is set to 0 (R12.15).
 *   - a result above the maximum handicap is limited to it (requirement
 *     12.10).
 *
 * The pure calculation service
 * {@see \Bowlingliga\Domain\Handicap\HandicapModule::calculateHandicap()} is
 * tested.
 *
 * The generator ranges (average 0–300, target score 100–250) are chosen so
 * that the rounded raw result always lies in the valid range 0–300 and the
 * default happy path (handicap is calculated) is taken; at the same time an
 * average above the target score produces a negative raw result and thus
 * exercises the limit to 0 (R12.15).
 */
final class HandicapFormulaLimitPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 3 (formula and limit): for every average, target score and
     * maximum handicap a calculated handicap equals exactly the formula result
     * rounded to an integer and limited to `[0, maximum handicap]`; the result
     * always lies within this range and a negative raw result is set to 0
     * (R12.8, R12.10, R12.15).
     *
     * Feature: bowlingliga-website, Property 3: Handicap formula and limit
     *
     * Validates: R12.8, R12.10, R12.15
     *
     * At least 100 inputs are generated (limitTo(100)).
     */
    public function testHandicapFollowsFormulaAndLimit(): void
    {
        $module = new HandicapModule();
        $formula = HandicapFormula::standard();

        $this
            ->limitTo(100)
            ->forAll(
                // Average 0.00–300.00 (to two decimals), including values
                // above the target score, so that the limit to 0 (R12.15) applies.
                Generators::map(
                    static fn (int $hundredths): float => $hundredths / 100.0,
                    Generators::choose(0, 30000)
                ),
                // Target score 100–250: keeps the rounded raw result always <= 300,
                // so that the happy path (handicap calculated) is taken.
                Generators::choose(100, 250),
                // Maximum handicap 0–100: exercises both the cap (R12.10) and
                // narrow limits.
                Generators::choose(0, 100)
            )
            ->then(function (float $average, int $targetScore, int $maxHandicap) use ($module, $formula): void {
                $result = $module->calculateHandicap($average, $targetScore, $formula, $maxHandicap);

                // Expected value derived independently: formula on the exact
                // average, rounded raw result (half up), then limited to
                // [0, maxHandicap] (R12.8/R12.10/R12.15).
                $raw = ((float) $targetScore - $average) * HandicapFormula::DEFAULT_FACTOR;
                $rounded = (int) round(round($raw, 9));
                $expected = max(0, min($rounded, $maxHandicap));

                // In the chosen input range there must always be a handicap.
                self::assertTrue(
                    $result->hasHandicap(),
                    sprintf(
                        'For average %.2f, target score %d, maximum handicap %d a handicap must be calculated.',
                        $average,
                        $targetScore,
                        $maxHandicap
                    )
                );

                $handicap = $result->handicap();

                // Equality with clamp(round((target score − average) × 0.8), 0, maxHandicap)
                // (R12.8, R12.10, R12.15).
                self::assertSame(
                    $expected,
                    $handicap,
                    sprintf(
                        'The handicap must equal the formula result rounded and limited to [0, %d] '
                        . '(average %.2f, target score %d).',
                        $maxHandicap,
                        $average,
                        $targetScore
                    )
                );

                // Range: the result always lies within [0, maxHandicap]
                // (R12.10 Obergrenze, R12.15 Untergrenze).
                self::assertGreaterThanOrEqual(
                    0,
                    $handicap,
                    'The handicap must not fall below 0 (R12.15).'
                );
                self::assertLessThanOrEqual(
                    $maxHandicap,
                    $handicap,
                    sprintf('The handicap must not exceed the maximum handicap (%d) (R12.10).', $maxHandicap)
                );

                // A negative raw result (average > target score) must lead to exactly 0
                // (R12.15).
                if ($rounded < 0) {
                    self::assertSame(
                        0,
                        $handicap,
                        sprintf(
                            'A negative formula result (%d) must be set to 0 (R12.15).',
                            $rounded
                        )
                    );
                }
            });
    }
}
