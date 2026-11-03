<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Handicap;

/**
 * Pure calculation of handicap and gross (handicap module).
 *
 * This class contains only side-effect-free calculations. Freezing the
 * handicap (R12.9/R12.12) and the chain recalculation (R12.17–19) are
 * deliberately not part of this class and are implemented separately.
 *
 * @see R12.1, R12.8, R12.10, R12.11, R12.13, R12.14, R12.15, R12.16, R13.3, R20.2, R27.7
 */
final class HandicapModule
{
    /** Lower bound of a valid handicap (R12.15). */
    private const HANDICAP_MIN = 0;

    /** Upper bound of a valid formula result (R12.16). */
    private const FORMULA_RESULT_MAX = 300;

    /**
     * Calculates a player's handicap.
     *
     * Flow according to R12:
     * - Without a formula neither handicap nor gross is calculated and an
     *   error message is returned (R12.13).
     * - Without an average, and if it is not the first matchday special case,
     *   the player is marked "without handicap" (R12.14).
     * - Otherwise the formula is applied to the exact average (default
     *   `HDC = (target score − average) × 0.8`) and the result is rounded half
     *   up to an integer (R12.8), as in the league's spreadsheet
     *   (decision of 17.10.2026)
     *   and limited to the range `[0, maxHandicap]` (R12.10/R12.15).
     * - If the formula yields no valid integer result in the range 0–300, the
     *   player is marked "without handicap" with an error message (R12.16).
     *
     * @param float|null           $average         Exact net game average or null if there is none.
     * @param int                  $targetScore     Globally defined target score (R12.2).
     * @param HandicapFormula|null $formula         Formula configured per season or null if none is stored (R12.13).
     * @param int                  $maxHandicap     Maximum handicap of the season (R12.3/R12.10).
     * @param bool                 $isFirstMatchday Marks the first matchday special case (R12.9). It only affects
     *                                              which average state is passed in when freezing (task 8.4) and
     *                                              is passed here as context.
     */
    public function calculateHandicap(
        ?float $average,
        int $targetScore,
        ?HandicapFormula $formula,
        int $maxHandicap,
        bool $isFirstMatchday = false,
    ): HandicapResult {
        // R12.13: without a configured formula no handicap/gross + error message.
        if ($formula === null) {
            return HandicapResult::missingFormula();
        }

        // R12.14: without an average no handicap can be calculated;
        // the player is marked "without handicap". The first matchday
        // special case (R12.9) only concerns WHICH average state
        // is passed in when freezing (task 8.4) and changes nothing here:
        // an actually missing average yields no handicap in either case.
        // The flag exists to pass on the context clearly.
        if ($average === null) {
            return HandicapResult::withoutHandicap();
        }

        // The formula works on the exact average; only the result is rounded
        // (league spreadsheet: SUM(200-D6)*0.8 with the number format "0").
        $raw = $formula->apply($targetScore, $average);

        // R12.16: non-finite formula results are invalid → "without handicap" + error message.
        if (!is_finite($raw)) {
            return HandicapResult::invalidFormulaResult();
        }

        // R12.8: round half up to an integer; the first rounding removes
        // binary floating point artefacts (e.g. 14.4999999 instead of 14.5).
        $rounded = (int) round(round($raw, 9));

        // R12.16: a valid formula result must be in the range 0–300.
        if ($rounded < self::HANDICAP_MIN || $rounded > self::FORMULA_RESULT_MAX) {
            // A negative raw result is no error but is set to 0 (R12.15);
            // only exceeding 300 counts as an invalid formula result.
            if ($rounded < self::HANDICAP_MIN) {
                $rounded = self::HANDICAP_MIN;
            } else {
                return HandicapResult::invalidFormulaResult();
            }
        }

        // R12.10/R12.15: limit to [0, maxHandicap].
        $limited = $this->limit($rounded, $maxHandicap);

        return HandicapResult::calculated($limited);
    }

    /**
     * Calculates the gross result of a game as the sum of net and handicap
     * (R12.11, R13.3).
     *
     * Also used for derived blind gross values (R27.7) and for the ghost team
     * (R20.2, net is also gross → handicap 0).
     *
     * @param int $net      Net result of the game.
     * @param int $handicap The player's handicap calculated for the season.
     */
    public function calculateGross(int $net, int $handicap): int
    {
        return $net + $handicap;
    }

    /**
     * Limits a handicap to the valid range `[0, maxHandicap]` (R12.10/R12.15).
     */
    private function limit(int $handicap, int $maxHandicap): int
    {
        if ($handicap < self::HANDICAP_MIN) {
            return self::HANDICAP_MIN;
        }

        if ($handicap > $maxHandicap) {
            return $maxHandicap;
        }

        return $handicap;
    }
}
