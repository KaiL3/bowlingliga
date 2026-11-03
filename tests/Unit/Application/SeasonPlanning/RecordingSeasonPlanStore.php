<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\SeasonPlanning;

use Bowlingliga\Application\SeasonPlanning\PlannedMatchday;
use Bowlingliga\Application\SeasonPlanning\SeasonPlanStore;

/**
 * Fake of the {@see SeasonPlanStore} port for the unit tests, without a
 * database.
 *
 * Records the saved matchdays and can optionally simulate a store error (for
 * the error path R9.4).
 */
final class RecordingSeasonPlanStore implements SeasonPlanStore
{
    /** @var list<PlannedMatchday>|null zuletzt gespeicherte Spieltage */
    public ?array $saved = null;

    public ?int $savedSeasonId = null;

    public bool $throwsError = false;

    public function saveSchedule(int $seasonId, array $matchdays): void
    {
        if ($this->throwsError) {
            throw new \RuntimeException('Simulierter Speicherfehler.');
        }

        $this->savedSeasonId = $seasonId;
        $this->saved = $matchdays;
    }
}
