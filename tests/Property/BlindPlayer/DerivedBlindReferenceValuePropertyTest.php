<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\BlindPlayer;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\BlindPlayerService;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the derived blind reference value (net/gross) limited
 * (clamped) to the range 0–300.
 *
 * Feature: bowlingliga-website, Property 13: Derived blind reference value with clamping
 *
 * Validates: R27.6, R27.7, R27.8, R27.9
 *
 * Domain background: for a game marked blind the system determines a derived
 * blind value purely as plausibility reference:
 *   - blind net   = net average − 10             (R27.6)
 *   - blind gross = net average − 10 + handicap  (R27.7)
 * Both reference values are then limited to the allowed range:
 *   - values less than 0      → 0   (R27.8)
 *   - values greater than 300 → 300 (R27.9)
 *
 * The pure calculation service
 * {@see \Bowlingliga\Domain\BlindPlayerService::derivedBlindNet()} and
 * {@see \Bowlingliga\Domain\BlindPlayerService::derivedBlindGross()} is
 * tested.
 */
final class DerivedBlindReferenceValuePropertyTest extends TestCase
{
    use TestTrait;

    /** Fixed deduction for deriving the blind reference value (R27.6–7). */
    private const BLIND_DEDUCTION = 10;

    /** Lower clamp bound for net/gross (R27.8). */
    private const MIN_VALUE = 0;

    /** Upper clamp bound for net/gross (R27.9). */
    private const MAX_VALUE = 300;

    /**
     * Property 13 (clamping): for every net average and every handicap of the
     * substitute player, the derived blind net equals the value
     * round(net average − 10) limited to 0–300 and the derived blind gross
     * equals the value round(net average − 10 + handicap) limited to 0–300;
     * both always lie in the range [0, 300].
     *
     * Feature: bowlingliga-website, Property 13: Derived blind reference value with clamping
     *
     * Validates: R27.6, R27.7, R27.8, R27.9
     *
     * At least 100 inputs are generated (limitTo(100)). The input range for the
     * net average deliberately goes beyond the target range (−30 to 330 pins in
     * steps of a tenth), so that clamping is forced at both ends and the
     * round-half-up of the floating point net averages is checked at the same
     * time. The handicap is generated over the realistic range 0 to the maximum
     * handicap (default 64).
     */
    public function testDerivedBlindReferenceValuesAreClampedToZeroToThreeHundred(): void
    {
        $service = new BlindPlayerService();

        // Net average as floating point value in steps of a tenth over an
        // extended range that forces clamping at both ends
        // (below 0 and above 300) and supplies fractional values for rounding.
        $netAverageGenerator = Generators::map(
            static fn (int $tenths): float => $tenths / 10.0,
            Generators::choose(-300, 3300)
        );

        // Handicap as integer over the realistic range [0, 64]
        // (maximum handicap default 64).
        $handicapGenerator = Generators::choose(0, 64);

        $this
            ->limitTo(100)
            ->forAll($netAverageGenerator, $handicapGenerator)
            ->then(function (float $netAverage, int $handicap) use ($service): void {
                $blindNet  = $service->derivedBlindNet($netAverage);
                $blindGross = $service->derivedBlindGross($netAverage, $handicap);

                // Expected values derived independently from the formula + clamping
                // (R27.6, R27.7, R27.8, R27.9).
                $expectedNet = $this->clamp(
                    (int) round($netAverage - self::BLIND_DEDUCTION)
                );
                $expectedGross = $this->clamp(
                    (int) round($netAverage - self::BLIND_DEDUCTION + $handicap)
                );

                self::assertSame(
                    $expectedNet,
                    $blindNet,
                    sprintf(
                        'Blind net for net average %.1f must be clamp(round(average − 10), 0, 300) (R27.6/27.8/27.9).',
                        $netAverage
                    )
                );

                self::assertSame(
                    $expectedGross,
                    $blindGross,
                    sprintf(
                        'Blind gross for net average %.1f and handicap %d must be clamp(round(average − 10 + handicap), 0, 300) (R27.7/27.8/27.9).',
                        $netAverage,
                        $handicap
                    )
                );

                // Range: both reference values always lie in [0, 300]
                // (R27.8 Untergrenze, R27.9 Obergrenze).
                self::assertGreaterThanOrEqual(
                    self::MIN_VALUE,
                    $blindNet,
                    'Blind net must not fall below 0 (R27.8).'
                );
                self::assertLessThanOrEqual(
                    self::MAX_VALUE,
                    $blindNet,
                    'Blind net must not exceed 300 (R27.9).'
                );
                self::assertGreaterThanOrEqual(
                    self::MIN_VALUE,
                    $blindGross,
                    'Blind gross must not fall below 0 (R27.8).'
                );
                self::assertLessThanOrEqual(
                    self::MAX_VALUE,
                    $blindGross,
                    'Blind gross must not exceed 300 (R27.9).'
                );
            });
    }

    /**
     * Independent reference implementation (oracle) of clamping to the allowed
     * range 0–300 (R27.8, R27.9).
     */
    private function clamp(int $value): int
    {
        return max(self::MIN_VALUE, min(self::MAX_VALUE, $value));
    }
}
