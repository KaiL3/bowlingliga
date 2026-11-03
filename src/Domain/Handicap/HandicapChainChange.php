<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Handicap;

/**
 * Single change within the preview of a handicap chain recalculation (diff
 * old/new for one matchday/player, R12.18).
 *
 * An entry contrasts which handicap was frozen so far for a player on a
 * matchday ({@see $previousHandicap}) with the one the recalculation yields
 * ({@see $newHandicap}). The associated status ({@see $status}) carries over
 * the error and special cases from {@see HandicapModule} (missing formula,
 * without handicap, invalid formula result).
 *
 * The preview is explicitly free of side effects: an entry describes a
 * *proposed* change that is only persisted after explicit confirmation
 * (R12.18/R12.19).
 *
 * @see R12.18, R12.19
 */
final class HandicapChainChange
{
    /**
     * @param int            $matchdayId       Id of the affected matchday.
     * @param int            $playerId         Id of the affected player.
     * @param HandicapStatus $status           Recalculation status of this
     *                                         entry.
     * @param int|null       $previousHandicap Handicap frozen so far (0–300) or
     *                                         null if none was frozen.
     * @param int|null       $newHandicap      Recalculated handicap (0–300) or
     *                                         null if the recalculation yields
     *                                         no handicap
     *                                         (R12.13/R12.14/R12.16).
     */
    public function __construct(
        public readonly int $matchdayId,
        public readonly int $playerId,
        public readonly HandicapStatus $status,
        public readonly ?int $previousHandicap,
        public readonly ?int $newHandicap,
    ) {
    }

    /**
     * Tells whether the frozen value changes through the recalculation
     * (different handicap compared to the previous state, R12.18).
     *
     * A transition from/to "no handicap" (null) also counts as a change.
     */
    public function isChange(): bool
    {
        return $this->previousHandicap !== $this->newHandicap;
    }
}
