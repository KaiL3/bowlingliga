<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\SeasonPlanner;

/**
 * Immutable result of the schedule generation – the complete schedule of a
 * season (R9.1, R9.2, R9.11).
 *
 * The schedule consists of the matchdays stored in order of their number;
 * every matchday carries its fixtures and its type (regular or positionday,
 * R9.13). The fixtures arise in round robin mode over one or more complete
 * rounds, so that every pair of teams meets equally often across the plan
 * (R9.1, R9.11) and no team is scheduled more than once on a matchday (R9.2).
 *
 * If the plan contains the ghost team added by the season planner (R9.6),
 * this is visible via {@see containsGhostTeam}; every real team is then
 * assigned to the ghost team equally often across the season (R9.7).
 *
 * The schedule is a pure calculation result without side effects; persisting
 * (R9.3) and the lane assignment (R9.9, R18) happen in later layers.
 */
final class Schedule
{
    /**
     * @param list<Matchday> $matchdays         The matchdays in order of their
     *                                          number (R9.2, R9.13).
     * @param bool           $containsGhostTeam True if the season planner added a
     *                                          ghost team (R9.6).
     */
    public function __construct(
        public readonly array $matchdays,
        public readonly bool $containsGhostTeam,
    ) {
    }

    /**
     * Number of matchdays of the schedule (R9.10, R9.11).
     */
    public function matchdayCount(): int
    {
        return count($this->matchdays);
    }

    /**
     * Returns the matchdays marked as positionday (every tenth matchday,
     * R9.13).
     *
     * @return list<Matchday>
     */
    public function positiondays(): array
    {
        return array_values(array_filter(
            $this->matchdays,
            static fn (Matchday $matchday): bool => $matchday->isPositionday()
        ));
    }
}
