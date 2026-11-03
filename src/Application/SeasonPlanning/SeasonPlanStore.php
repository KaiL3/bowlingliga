<?php

declare(strict_types=1);

namespace Bowlingliga\Application\SeasonPlanning;

/**
 * Port for transactionally persisting the generated schedule including the
 * lane assignment (R9.3, R9.4, R18.4).
 *
 * Decouples {@see SeasonPlanningManagement} from the concrete persistence
 * technology. For each matchday the implementation creates the matchday row,
 * its fixtures (with team season assignment and lane pair index) and – if
 * present – the lane assignment. The whole operation runs in one
 * transaction: if a step fails, the existing data stays unchanged (R9.4,
 * R18.4).
 *
 * The port is implemented by an adapter based on the matchday, fixture and
 * lane pair repositories; in tests a fake is enough.
 */
interface SeasonPlanStore
{
    /**
     * Saves a season's complete schedule including the lane assignment
     * transactionally (R9.3, R18.4).
     *
     * @param list<PlannedMatchday> $matchdays
     *
     * @throws SeasonPlanningException if saving fails; the data then stays
     *         unchanged (R9.4).
     */
    public function saveSchedule(int $seasonId, array $matchdays): void;
}
