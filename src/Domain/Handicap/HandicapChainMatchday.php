<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Handicap;

/**
 * Input for a single matchday/player within the handicap chain recalculation
 * (R12.18).
 *
 * An element describes exactly one handicap state to freeze: for a player on
 * a matchday the relevant net average (state according to R12.8/R12.9) and
 * the season configuration values needed for the calculation. The previously
 * frozen handicap value ({@see $previousHandicap}) is carried along so that
 * the preview can form the old/new difference without additional reads.
 *
 * This structure is a pure value holder without side effects. Choosing the
 * relevant average state (and thus the chronological order) is up to the
 * calling use case, since only there the temporal context of the matchdays
 * and the players' raw data are available.
 *
 * @see R12.9, R12.18
 */
final class HandicapChainMatchday
{
    /**
     * @param int                  $matchdayId       Id of the matchday the
     *                                               handicap is frozen for.
     * @param int                  $matchdayCounter  Sequential number of the
     *                                               matchday within the season
     *                                               (determines the
     *                                               chronological order of the
     *                                               chain, R12.18).
     * @param int                  $playerId         Player id.
     * @param float|null           $average          Relevant net average (state
     *                                               according to R12.8/R12.9)
     *                                               or null if there is none.
     * @param int                  $targetScore      Globally defined target
     *                                               score (R12.2).
     * @param HandicapFormula|null $formula          Formula configured per
     *                                               season or null (R12.13).
     * @param int                  $maxHandicap      Maximum handicap of the
     *                                               season (R12.3/R12.10).
     * @param bool                 $isFirstMatchday  Marks the player's first
     *                                               matchday special case
     *                                               (R12.9).
     * @param int|null             $previousHandicap The handicap currently
     *                                               frozen for this
     *                                               matchday/player (0–300) or
     *                                               null if none is frozen yet;
     *                                               used for the preview diff
     *                                               (R12.18).
     */
    public function __construct(
        public readonly int $matchdayId,
        public readonly int $matchdayCounter,
        public readonly int $playerId,
        public readonly ?float $average,
        public readonly int $targetScore,
        public readonly ?HandicapFormula $formula,
        public readonly int $maxHandicap,
        public readonly bool $isFirstMatchday = false,
        public readonly ?int $previousHandicap = null,
    ) {
        if ($previousHandicap !== null && ($previousHandicap < 0 || $previousHandicap > 300)) {
            throw new \InvalidArgumentException(
                'The previous frozen handicap must be between 0 and 300, given: ' . $previousHandicap
            );
        }
    }
}
