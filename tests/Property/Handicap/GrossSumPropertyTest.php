<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Handicap;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\Handicap\HandicapModule;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the gross calculation as the sum of net and handicap.
 *
 * Feature: bowlingliga-website, Property 4: Gross as sum of net and handicap
 *
 * Validates: R12.11, R13.3
 *
 * Domain background: the gross result of a game is defined as net plus
 * handicap (gross = net + HDC; R12.11 and R13.3). The
 * pure calculation service
 * {@see \Bowlingliga\Domain\Handicap\HandicapModule::calculateGross()} is
 * tested.
 */
final class GrossSumPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 4: for every valid net result (0..300) and every valid handicap
     * (0..100) calculateGross() returns exactly the sum of net and handicap
     * (R12.11, R13.3).
     *
     * Feature: bowlingliga-website, Property 4: Gross as sum of net and handicap
     *
     * Validates: R12.11, R13.3
     *
     * At least 100 inputs are generated (limitTo(100)). The input range covers
     * realistic net results (0 to 300 pins) and handicaps (0 to 100).
     */
    public function testGrossIsExactlyTheSumOfNetAndHandicap(): void
    {
        $module = new HandicapModule();

        $this
            ->limitTo(100)
            ->forAll(
                Generators::choose(0, 300),
                Generators::choose(0, 100)
            )
            ->then(function (int $net, int $handicap) use ($module): void {
                $gross = $module->calculateGross($net, $handicap);

                self::assertSame(
                    $net + $handicap,
                    $gross,
                    sprintf(
                        'Gross must be exactly net + handicap (R12.11/R13.3); net=%d, handicap=%d.',
                        $net,
                        $handicap
                    )
                );
            });
    }
}
