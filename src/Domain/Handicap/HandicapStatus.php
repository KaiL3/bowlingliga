<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Handicap;

/**
 * Status values of a handicap calculation.
 *
 * Distinguishes the successful calculation from the error and special cases
 * defined in R12. Based on the status the calling layers can
 * decide whether a handicap/gross exists or whether the player must be marked
 * "without handicap".
 *
 * @see R12.13, R12.14, R12.16
 */
enum HandicapStatus: string
{
    /** The handicap was calculated regularly and exists. */
    case CALCULATED = 'calculated';

    /**
     * No handicap formula is configured for the season. Neither handicap nor
     * gross is calculated; the net stays unchanged (R12.13).
     */
    case MISSING_FORMULA = 'missing_formula';

    /**
     * There is no game average and it is not the first matchday special case.
     * The player is marked "without handicap" (R12.14).
     */
    case WITHOUT_HANDICAP = 'without_handicap';

    /**
     * Applying the handicap formula yields no valid integer result in the
     * range 0–300. The player is marked "without handicap"
     * (R12.16).
     */
    case INVALID_FORMULA_RESULT = 'invalid_formula_result';
}
