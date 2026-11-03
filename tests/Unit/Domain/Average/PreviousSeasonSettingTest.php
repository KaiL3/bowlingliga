<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\Average;

use Bowlingliga\Domain\Average\AverageInput;
use Bowlingliga\Domain\Average\AverageModule;
use Bowlingliga\Domain\Average\Game;
use Bowlingliga\Domain\Average\PreviousSeasonRecord;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the configurable previous-season limit and the exact average.
 *
 * The average after matchday k includes the previous season while k is at
 * most the configured matchday; 0 switches the previous season off. Counter 0
 * is the state before the first matchday.
 */
final class PreviousSeasonSettingTest extends TestCase
{
    private AverageModule $module;
    private AverageInput $input;

    protected function setUp(): void
    {
        $this->module = new AverageModule();
        // Current season 3 × 200, previous season record 30 games with 4800 pins.
        $this->input = new AverageInput(
            [new Game(200), new Game(200), new Game(200)],
            new PreviousSeasonRecord(30, 4800),
        );
    }

    public function testPreviousSeasonCountsUpToTheConfiguredMatchday(): void
    {
        $withPrevious = (4800 + 600) / 33;

        self::assertEqualsWithDelta($withPrevious, $this->module->calculateExactAverage(5, $this->input, 5), 1e-9);
        self::assertSame(200.0, $this->module->calculateExactAverage(6, $this->input, 5));
        self::assertEqualsWithDelta($withPrevious, $this->module->calculateExactAverage(6, $this->input, 6), 1e-9);
        self::assertSame(200.0, $this->module->calculateExactAverage(1, $this->input, 0), '0 = Vorjahr nie.');
    }

    public function testStateBeforeTheFirstMatchdayIncludesThePreviousSeason(): void
    {
        self::assertSame(160.0, $this->module->calculateExactAverage(0, new AverageInput([], new PreviousSeasonRecord(30, 4800)), 5));
        self::assertNull($this->module->calculateExactAverage(0, new AverageInput([], new PreviousSeasonRecord(30, 4800)), 0));
    }

    public function testExactAverageIsNotRounded(): void
    {
        $input = new AverageInput([new Game(165), new Game(166), new Game(166)]);

        self::assertEqualsWithDelta(165.6666666, $this->module->calculateExactAverage(1, $input), 1e-6);
        self::assertSame(165.67, $this->module->calculateAverage(1, 1, 1, $input));
    }

    public function testNegativeCounterIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->module->calculateExactAverage(-1, $this->input);
    }
}
