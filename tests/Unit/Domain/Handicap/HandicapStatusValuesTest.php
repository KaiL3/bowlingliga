<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\Handicap;

use Bowlingliga\Domain\Handicap\HandicapFormula;
use Bowlingliga\Domain\Handicap\HandicapModule;
use Bowlingliga\Domain\Handicap\HandicapStatus;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the status values of the handicap calculation and the first
 * matchday special case (task 8.8).
 *
 * Validates: R12.9, R12.14, R12.16
 *
 * R12.9: for a player's very first matchday (neither a played game
 * nor a previous season record before the matchday) the handicap is
 * determined from the average state at the end of this matchday. In this case
 * the average passed in equals this end state.
 *
 * R12.14: if there is no game average and it is not the first
 * matchday special case, the player is marked "without handicap" and no
 * handicap is calculated.
 *
 * R12.16: if applying the handicap formula yields no valid integer
 * result in the range 0–300, the player is marked "without handicap" and an
 * error message is returned.
 *
 * In addition the regular case (CALCULATED) and the case of the missing
 * formula (MISSING_FORMULA, R12.13 context) are checked, to cover the complete
 * set of status values. The real {@see HandicapModule} is used (no mock
 * calculation).
 */
final class HandicapStatusValuesTest extends TestCase
{
    private const TARGET_SCORE     = 200;
    private const MAX_HANDICAP = 64;

    private HandicapModule $module;

    protected function setUp(): void
    {
        $this->module = new HandicapModule();
    }

    /**
     * R12.14: no average (not the first matchday) => "without handicap".
     */
    public function testMissingAverageReturnsWithoutHandicapWithoutMessage(): void
    {
        $result = $this->module->calculateHandicap(
            average: null,
            targetScore: self::TARGET_SCORE,
            formula: HandicapFormula::standard(),
            maxHandicap: self::MAX_HANDICAP,
        );

        self::assertSame(HandicapStatus::WITHOUT_HANDICAP, $result->status());
        self::assertFalse($result->hasHandicap());
        self::assertNull($result->handicap());
        self::assertNull($result->message());
    }

    /**
     * R12.13 context: without a configured formula no handicap is calculated
     * and an error message is returned.
     */
    public function testMissingFormulaReturnsMissingFormulaWithMessage(): void
    {
        $result = $this->module->calculateHandicap(
            average: 150.0,
            targetScore: self::TARGET_SCORE,
            formula: null,
            maxHandicap: self::MAX_HANDICAP,
        );

        self::assertSame(HandicapStatus::MISSING_FORMULA, $result->status());
        self::assertFalse($result->hasHandicap());
        self::assertNull($result->handicap());
        self::assertNotNull($result->message());
        self::assertNotSame('', $result->message());
    }

    /**
     * R12.16: a formula result rounded to above 300 is invalid => "without
     * handicap" with an error message.
     *
     * With target score 300, average 0 and factor 2 the formula yields
     * (300 − 0) × 2 = 600, which exceeds the valid range 0–300.
     */
    public function testFormulaResultAbove300ReturnsInvalidFormulaResultWithMessage(): void
    {
        $result = $this->module->calculateHandicap(
            average: 0.0,
            targetScore: 300,
            formula: HandicapFormula::withFactor(2.0),
            maxHandicap: self::MAX_HANDICAP,
        );

        self::assertSame(HandicapStatus::INVALID_FORMULA_RESULT, $result->status());
        self::assertFalse($result->hasHandicap());
        self::assertNull($result->handicap());
        self::assertNotNull($result->message());
        self::assertNotSame('', $result->message());
    }

    /**
     * Regular case: a valid average yields a calculated handicap.
     *
     * HDC = (200 − 150) × 0.8 = 40 (R12.8).
     */
    public function testValidAverageReturnsCalculatedHandicap(): void
    {
        $result = $this->module->calculateHandicap(
            average: 150.0,
            targetScore: self::TARGET_SCORE,
            formula: HandicapFormula::standard(),
            maxHandicap: self::MAX_HANDICAP,
        );

        self::assertSame(HandicapStatus::CALCULATED, $result->status());
        self::assertTrue($result->hasHandicap());
        self::assertSame(40, $result->handicap());
        self::assertNull($result->message());
    }

    /**
     * R12.9: first matchday special case. For the very first matchday the
     * handicap is determined from the average state at the end of the
     * matchday; the average passed in (here 130) equals this end state.
     *
     * HDC = (200 − 130) × 0.8 = 56.
     */
    public function testFirstMatchdayWithFinalStandingReturnsCalculatedHandicap(): void
    {
        $result = $this->module->calculateHandicap(
            average: 130.0,
            targetScore: self::TARGET_SCORE,
            formula: HandicapFormula::standard(),
            maxHandicap: self::MAX_HANDICAP,
            isFirstMatchday: true,
        );

        self::assertSame(HandicapStatus::CALCULATED, $result->status());
        self::assertTrue($result->hasHandicap());
        self::assertSame(56, $result->handicap());
        self::assertNull($result->message());
    }

    /**
     * R12.9/R12.14: first matchday without a previous season. If there is no
     * average state even on the first matchday (e.g. before the games of this
     * matchday are entered, so that no end state can be derived), no handicap
     * can be calculated. The first matchday flag changes nothing about that: an
     * actually missing average still leads to "without handicap".
     */
    public function testFirstMatchdayWithoutAverageReturnsWithoutHandicap(): void
    {
        $result = $this->module->calculateHandicap(
            average: null,
            targetScore: self::TARGET_SCORE,
            formula: HandicapFormula::standard(),
            maxHandicap: self::MAX_HANDICAP,
            isFirstMatchday: true,
        );

        self::assertSame(HandicapStatus::WITHOUT_HANDICAP, $result->status());
        self::assertFalse($result->hasHandicap());
        self::assertNull($result->handicap());
        self::assertNull($result->message());
    }

    /**
     * Delimitation R12.15 vs. R12.16: a negative raw formula result is NO
     * invalid formula result but is set to 0 (R12.15). Only exceeding 300
     * counts as invalid (R12.16).
     *
     * Average 300 > target score 200 => (200 − 300) × 0.8 = −80 => 0.
     */
    public function testNegativeFormulaResultIsSetToZeroInsteadOfInvalid(): void
    {
        $result = $this->module->calculateHandicap(
            average: 300.0,
            targetScore: self::TARGET_SCORE,
            formula: HandicapFormula::standard(),
            maxHandicap: self::MAX_HANDICAP,
        );

        self::assertSame(HandicapStatus::CALCULATED, $result->status());
        self::assertTrue($result->hasHandicap());
        self::assertSame(0, $result->handicap());
        self::assertNull($result->message());
    }
}
