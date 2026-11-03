<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Domain\Message;

/**
 * Read port for displaying the season planning in the admin area (task 35.2).
 *
 * Provides the {@see SeasonPlanningController} with the season including its
 * planning basis, the team names and the stored schedule including the lane
 * assignment (R9.14). In production the
 * {@see \Bowlingliga\Persistence\SeasonPlanning\SeasonPlanningDataRepository}
 * implements this port; in tests a fake is enough.
 */
interface SeasonPlanningReader
{
    /**
     * Returns the season with its planning basis, or null if it does not
     * exist.
     *
     * @return array{id: int, name: string, start_date: string, end_date: string, team_count: int,
     *               matchday_count: int|null, lane_count: int|null, positionday_interval?: int, started: bool}|null
     *         `team_count` only counts real teams (without the ghost team);
     *         `matchday_count` is the number relevant for planning (defined
     *         or derived from the end date, R9.10).
     */
    public function season(int $seasonId): ?array;

    /**
     * Returns the names of all teams of the season, indexed by team season id;
     * the ghost team additionally appears under the key 0, which stands for
     * the ghost team in the planning preview.
     *
     * @return array<int, string>
     */
    public function teamNames(int $seasonId): array;

    /**
     * Returns the season's stored schedule, chronologically by matchday number;
     * empty if no plan has been saved yet.
     *
     * @return list<array{id: int, matchday_number: int, date: string, matchday_type: string,
     *                    is_played: bool,
     *                    pairings: list<array{home: string, away: string, lanes: string|null, status: string}>}>
     */
    public function storedPlan(int $seasonId): array;

    /**
     * Returns the reason why an existing plan may no longer be replaced, or
     * null if (re)planning is allowed.
     */
    public function reasonAgainstReplanning(int $seasonId): ?Message;
}
