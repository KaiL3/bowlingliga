<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

/**
 * Read port for the state question that determines whether previous season
 * records of a season can still be entered (R29.9).
 *
 * The records of a season can be entered and changed as long as that season
 * has not reached its start date. The port is implemented by an adapter based
 * on the raw data repositories; in tests a slim fake is enough.
 */
interface PreviousSeasonContext
{
    /**
     * Tells whether the season has already reached or passed its start date
     * (R29.9). An unknown season counts as "not started".
     */
    public function isSeasonStarted(int $seasonId): bool;
}
