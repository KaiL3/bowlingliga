<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Positionday;

use Bowlingliga\Domain\Table\TeamStatistics;

/**
 * Read port for the context of the positionday formation (R31.2, R31.4,
 * R31.7).
 *
 * Returns the information needed for the wiring of a positionday matchday:
 * whether all previous matchdays are "complete" (R31.2/R31.4), the current
 * standings as a {@see TeamStatistics} list (R31.3), the completion status of
 * the positionday itself (for correctability, R31.7), the configured lane
 * count (R31.5) and the mapping team id → team season id for persisting.
 *
 * The port is implemented by an adapter based on the matchday, fixture, team
 * season and table repositories; in tests a fake is enough.
 */
interface PositiondayContextReader
{
    /**
     * Tells whether all matchdays before the positionday have the completion
     * status "complete" (R31.2, R31.4).
     */
    public function allPreviousMatchdaysComplete(int $matchdayId): bool;

    /**
     * Returns the current league table standings over all matchdays before the
     * positionday as {@see TeamStatistics} aggregated per team (R31.3).
     *
     * @return list<TeamStatistics>
     */
    public function standingsBefore(int $matchdayId): array;

    /**
     * Tells whether the positionday itself is already "complete"; only as long
     * as it is not may the fixtures be (re)formed/corrected (R31.7).
     */
    public function positiondayComplete(int $matchdayId): bool;

    /**
     * Returns the configured lane count of the positionday's season (R31.5).
     */
    public function laneCount(int $matchdayId): int;

    /**
     * Returns the mapping team id (as in {@see TeamStatistics::$teamId}) → team
     * season id for persisting the formed fixtures. The ghost team is not
     * included (no team season row).
     *
     * @return array<string, int>
     */
    public function teamSeasonIds(int $matchdayId): array;
}
