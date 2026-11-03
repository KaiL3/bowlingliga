<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Points;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\Points\PointsService;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the scale rule of the bonus points with cap.
 *
 * Feature: bowlingliga-website, Property 7: Bonus points scale with cap
 *
 * Validates: R17.6, R17.7, R17.8
 *
 * Domain background: the bonus points of an evening are derived solely from
 * the gross total (R17.5) and follow a fixed scale rule:
 *   - gross total ≤ 2100 pins → exactly 1 bonus point (R17.6)
 *   - from 2101 pins          → 2 bonus points, then one more bonus point for
 *                               every further full 100 pins (R17.7)
 *   - from 2501 pins          → at most 6 bonus points (cap, no more)
 *                               (R17.8)
 *
 * The pure calculation service
 * {@see \Bowlingliga\Domain\Points\PointsService::bonusPoints()} is tested.
 */
final class BonusPointsScalePropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 7 (scale rule): for every valid gross total the bonus points
     * follow the scale rule exactly (R17.6, R17.7, R17.8), always lie
     * in the range [1, 6] and never decrease with a growing gross total
     * (monotonicity).
     *
     * Feature: bowlingliga-website, Property 7: Bonus points scale with cap
     *
     * Validates: R17.6, R17.7, R17.8
     *
     * At least 100 inputs are generated (limitTo(100)). The input range covers
     * realistic gross totals including all scale boundaries (0 to 4000 pins).
     */
    public function testBonusPointsFollowTheScaleRuleWithCap(): void
    {
        $service = new PointsService();

        $this
            ->limitTo(100)
            ->forAll(Generators::choose(0, 4000))
            ->then(function (int $grossTotal) use ($service): void {
                $bonusPoints = $service->bonusPoints($grossTotal);

                // Expected value derived independently from the scale rule
                // (R17.6, R17.7, R17.8).
                $expected = $this->expectedBonusPoints($grossTotal);
                self::assertSame(
                    $expected,
                    $bonusPoints,
                    sprintf(
                        'Bonus points for a gross total of %d must follow the scale (R17.6/17.7/17.8).',
                        $grossTotal
                    )
                );

                // Value range: always between 1 and 6 (R17.6 lower bound, R17.8 cap).
                self::assertGreaterThanOrEqual(1, $bonusPoints, 'Bonus points must not fall below 1 (R17.6).');
                self::assertLessThanOrEqual(6, $bonusPoints, 'Bonus points must not exceed 6 (R17.8).');

                // Monotonicity: a gross total 1 pin higher must
                // never give fewer bonus points (R17.7/17.8).
                $bonusPointsOneHigher = $service->bonusPoints($grossTotal + 1);
                self::assertGreaterThanOrEqual(
                    $bonusPoints,
                    $bonusPointsOneHigher,
                    sprintf(
                        'Bonus points must not fall as the gross total rises (monotony; %d → %d).',
                        $grossTotal,
                        $grossTotal + 1
                    )
                );
            });
    }

    /**
     * Independent reference implementation (oracle) of the scale rule for
     * verifying {@see PointsService::bonusPoints()}.
     *
     * R17.6: ≤ 2100 pins → 1 bonus point.
     * R17.7: from 2101 pins → 2 bonus points plus one more for every
     *                   further full 100 pins above 2101.
     * R17.8: from 2501 pins → cap at no more than 6 bonus points.
     */
    private function expectedBonusPoints(int $grossTotal): int
    {
        if ($grossTotal <= 2100) {
            return 1;
        }

        if ($grossTotal >= 2501) {
            return 6;
        }

        // 2101..2500: 2 bonus points plus one more per full 100 pins from 2101.
        return 2 + intdiv($grossTotal - 2101, 100);
    }
}
