<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

use Bowlingliga\Domain\Handicap\HandicapChainPreview;

/**
 * Port for freezing the handicaps of a matchday and for optionally
 * recalculating the handicap chain on a correction (R12.12, R12.17–19, R22.6,
 * R30.18).
 *
 * Freezing the handicap needs the player-specific average states immediately
 * before the matchday (or at the end for the first matchday, R12.9).
 * Preparing these from the raw data deliberately lives behind this port, so
 * that the use case {@see ResultRecording} only orchestrates the domain flow.
 * The actual calculation and persisting happen in the adapter via the
 * {@see \Bowlingliga\Domain\Handicap\HandicapFreezeService}.
 *
 * The frozen handicap is raw data and is never changed by the cache
 * invalidation (R35.5).
 */
interface HandicapFreezing
{
    /**
     * Freezes the handicaps of all players involved on a matchday as raw data
     * (R12.12, R30.18).
     *
     * Called when a complete score sheet is confirmed, after the net results
     * have been saved as raw data.
     */
    public function freezeHandicaps(int $matchdayId): void;

    /**
     * Recalculates the handicap chain from a matchday and returns a preview
     * (old/new diff) without persisting (R12.18).
     *
     * Used for later corrections: the previously frozen handicaps stay
     * unchanged until the preview is applied explicitly (R12.19).
     */
    public function recalculateChain(int $seasonId, int $fromMatchdayCounter): HandicapChainPreview;

    /**
     * Applies a previously calculated chain preview and persists the
     * recalculated handicaps as raw data – only after explicit confirmation
     * (R12.18, R12.19).
     */
    public function applyChain(HandicapChainPreview $preview): void;
}
