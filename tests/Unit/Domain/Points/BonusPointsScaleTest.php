<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\Points;

use Bowlingliga\Domain\Points\BonusPointsScale;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Bonus points scale of a season (R17.6–R17.8, R17.11).
 */
final class BonusPointsScaleTest extends TestCase
{
    public function testDefaultScaleKeepsTheFormerFixedRule(): void
    {
        $scale = BonusPointsScale::standard();
        $expected = [0 => 1, 2100 => 1, 2101 => 2, 2200 => 2, 2201 => 3, 2400 => 4, 2401 => 5, 2500 => 5, 2501 => 6, 4000 => 6];

        foreach ($expected as $gross => $points) {
            self::assertSame($points, $scale->points($gross), "gross {$gross}");
        }
    }

    public function testCustomScale(): void
    {
        $scale = new BonusPointsScale(2000, 50, 4);

        self::assertSame(1, $scale->points(2000));
        self::assertSame(2, $scale->points(2050));
        self::assertSame(3, $scale->points(2051));
        self::assertSame(4, $scale->points(2101));
        self::assertSame(4, $scale->points(3000), 'At most the maximum.');
    }

    public function testMaximumZeroMeansNoBonusPoints(): void
    {
        $scale = new BonusPointsScale(2100, 100, 0);

        self::assertSame(0, $scale->points(1500));
        self::assertSame(0, $scale->points(2600));
        self::assertSame([], $scale->levels());
    }

    public function testLevelsOfTheDefaultScaleMatchThePrintedLegend(): void
    {
        self::assertSame([
            ['from' => null, 'to' => 2100, 'points' => 1],
            ['from' => 2101, 'to' => 2200, 'points' => 2],
            ['from' => 2201, 'to' => 2300, 'points' => 3],
            ['from' => 2301, 'to' => 2400, 'points' => 4],
            ['from' => 2401, 'to' => 2500, 'points' => 5],
            ['from' => 2501, 'to' => null, 'points' => 6],
        ], BonusPointsScale::standard()->levels());
    }

    public function testLevelsAgreeWithThePoints(): void
    {
        foreach ([new BonusPointsScale(), new BonusPointsScale(1800, 75, 9), new BonusPointsScale(0, 1, 2), new BonusPointsScale(500, 10, 1)] as $scale) {
            foreach ($scale->levels() as $level) {
                foreach ([$level['from'], $level['to']] as $gross) {
                    if ($gross !== null) {
                        self::assertSame($level['points'], $scale->points($gross));
                    }
                }
            }
        }
    }

    public function testInvalidValuesAreRejected(): void
    {
        foreach ([[-1, 100, 6], [10000, 100, 6], [2100, 0, 6], [2100, 1001, 6], [2100, 100, -1], [2100, 100, 21]] as [$threshold, $step, $maximum]) {
            try {
                new BonusPointsScale($threshold, $step, $maximum);
                self::fail("{$threshold}/{$step}/{$maximum} must be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNegativeGrossIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BonusPointsScale::standard()->points(-1);
    }
}
