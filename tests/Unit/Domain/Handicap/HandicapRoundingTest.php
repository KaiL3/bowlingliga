<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\Handicap;

use Bowlingliga\Domain\Handicap\HandicapFormula;
use Bowlingliga\Domain\Handicap\HandicapModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the rounding of the handicap.
 *
 * The league applies the formula to the exact average and only rounds the
 * result (half up), as in its spreadsheet: `SUM(200-D6)*0.8` with the number
 * format "0" (decision of 17.10.2026):
 * HDC = round((target score − average) × factor).
 */
final class HandicapRoundingTest extends TestCase
{
    /**
     * @return iterable<string, array{float, int}>
     */
    public static function averages(): iterable
    {
        // One team of a matchday sheet of the league spreadsheet (target score 200).
        yield 'Player A 181,5625 → 14,75' => [181.5625, 15];
        yield 'Player B 180,8952 → 15,28' => [180.89523809523808, 15];
        yield 'Player C 178,9825 → 16,81' => [178.98245614035088, 17];
        yield 'Player D 188,825 → 8,94' => [188.825, 9];
        yield 'Player E 148,00 → 41,6' => [148.0, 42];
        // Exactly half a pin of handicap rounds up, despite floating point artefacts.
        yield '181,875 → 14,5' => [181.875, 15];
        yield '183,125 → 13,5' => [183.125, 14];
        // Just below half: rounds down.
        yield '181,876 → 14,4992' => [181.876, 14];
    }

    #[DataProvider('averages')]
    public function testFormulaUsesTheExactAverage(float $average, int $expected): void
    {
        $result = (new HandicapModule())->calculateHandicap($average, 200, HandicapFormula::standard(), 64);

        self::assertSame($expected, $result->handicap());
    }

    public function testMaximumHandicapLimitsTheResult(): void
    {
        // Player E in the spreadsheet: 41.6 → 40 with a maximum handicap of 40.
        $result = (new HandicapModule())->calculateHandicap(148.0, 200, HandicapFormula::standard(), 40);

        self::assertSame(40, $result->handicap());
    }

    public function testAverageAboveTargetScoreGivesZero(): void
    {
        $result = (new HandicapModule())->calculateHandicap(200.4, 200, HandicapFormula::standard(), 64);

        self::assertSame(0, $result->handicap());
    }
}
