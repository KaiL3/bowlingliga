<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\Handicap;

use Bowlingliga\Domain\Handicap\HandicapFormula;
use Bowlingliga\Domain\Handicap\HandicapModule;
use Bowlingliga\Domain\Handicap\HandicapTableService;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the player-independent handicap table of the rules page.
 *
 * The table lists the handicap per whole-pin average from the largest
 * average with the maximum handicap up to the smallest average with
 * handicap 0, and every value must match the real handicap calculation.
 */
final class HandicapTableServiceTest extends TestCase
{
    private HandicapTableService $service;

    protected function setUp(): void
    {
        $this->service = new HandicapTableService();
    }

    public function testDefaultConfigurationRunsFromMaximumToZero(): void
    {
        $table = $this->service->build(HandicapFormula::standard(), 190, 64);

        self::assertCount(81, $table->rows, 'Schnitt 110 bis 190.');
        self::assertSame([110, 64], [$table->rows[0]->average, $table->rows[0]->handicap]);
        self::assertSame([111, 63], [$table->rows[1]->average, $table->rows[1]->handicap]);
        self::assertSame([113, 62], [$table->rows[3]->average, $table->rows[3]->handicap], '61.6 is rounded up.');
        self::assertSame([189, 1], [$table->rows[79]->average, $table->rows[79]->handicap]);
        self::assertSame([190, 0], [$table->rows[80]->average, $table->rows[80]->handicap]);
        self::assertTrue($table->isOpenBelow());
        self::assertTrue($table->isOpenAbove());
        self::assertSame(190, $table->targetScore);
        self::assertSame(0.8, $table->factor);
        self::assertSame(64, $table->maxHandicap);
    }

    public function testEveryRowMatchesTheHandicapCalculation(): void
    {
        $formula = HandicapFormula::withFactor(0.9);
        $table = $this->service->build($formula, 200, 50);
        $module = new HandicapModule();

        $previous = PHP_INT_MAX;
        foreach ($table->rows as $row) {
            $expected = $module->calculateHandicap((float) $row->average, 200, $formula, 50)->handicap();
            self::assertSame($expected, $row->handicap, "Schnitt {$row->average}");
            self::assertLessThanOrEqual($previous, $row->handicap, 'The HDC falls as the average rises.');
            $previous = $row->handicap;
        }
        self::assertSame(50, $table->rows[0]->handicap);
        self::assertSame(0, $table->rows[array_key_last($table->rows)]->handicap);
    }

    public function testLowerMaximumShortensTheTable(): void
    {
        $table = $this->service->build(HandicapFormula::withFactor(1.0), 200, 30);

        self::assertCount(31, $table->rows);
        self::assertSame([170, 30], [$table->rows[0]->average, $table->rows[0]->handicap]);
        self::assertSame([200, 0], [$table->rows[30]->average, $table->rows[30]->handicap]);
    }

    public function testFactorZeroGivesASingleRowWithoutHandicap(): void
    {
        $table = $this->service->build(HandicapFormula::withFactor(0.0), 190, 64);

        self::assertCount(1, $table->rows);
        self::assertSame([0, 0], [$table->rows[0]->average, $table->rows[0]->handicap]);
        self::assertFalse($table->isOpenBelow());
        self::assertTrue($table->isOpenAbove());
    }
}
