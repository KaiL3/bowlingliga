<?php

declare(strict_types=1);

namespace Bowlingliga\Application\SeasonPlanning;

use Bowlingliga\Domain\Message;

/**
 * Preview of a season planning before it is applied permanently (R9.12,
 * R9.14, R18.9).
 *
 * Bundles the matchdays to save including the lane assignment and any
 * warnings from the schedule generation (uneven distribution, R9.12) and the
 * lane assignment (not fully even, R18.9). The preview is presented to the
 * administrator for review and confirmation (R9.14); only applying it via
 * {@see SeasonPlanningManagement::applyPlan()} persists it (R9.3).
 *
 * Pure, immutable value object without side effects.
 */
final class PlanningPreview
{
    /**
     * @param int                   $seasonId  affected season
     * @param list<PlannedMatchday> $matchdays matchdays to save including the
     *        lane assignment (positiondays without assignment, R18.7)
     * @param list<Message>         $warnings  distribution/lane warnings (R9.12, R18.9)
     * @param array<int, array{usage: array<int, int>, spread: int}> $laneDeviations
     *        Deviation overview of the lane assignment per affected team (R18.10),
     *        indexed by team season id (0 = ghost team); empty without a lane warning
     */
    public function __construct(
        public readonly int $seasonId,
        public readonly array $matchdays,
        public readonly array $warnings,
        public readonly array $laneDeviations = [],
    ) {
    }

    /**
     * Tells whether the preview carries a warning and applying it therefore
     * requires explicit confirmation (R9.12, R18.9).
     */
    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
