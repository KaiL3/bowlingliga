<?php

declare(strict_types=1);

namespace Bowlingliga\Application\SeasonPlanning;

/**
 * Read port for the real teams taking part in a season and the configuration
 * needed for planning (R9.1, R9.10, R8.11).
 *
 * Returns the real teams (team season assignment: id + team name), the
 * defined season length (matchday count) and the configured lane count. The
 * port is implemented by an adapter based on the season, team season and
 * season config repositories; in tests a fake is enough.
 */
interface SeasonTeamsReader
{
    /**
     * Returns the real teams of the season as a list of team season entries.
     *
     * @return list<array{team_season_id: int, team_name: string}>
     */
    public function findTeams(int $seasonId): array;

    /**
     * Returns the defined number of matchdays of the season (R9.10), or null
     * if it cannot be determined.
     */
    public function findMatchdayCount(int $seasonId): ?int;

    /**
     * Returns the configured lane count of the season (even ≥ 2, R8.11), or
     * null if none is configured.
     */
    public function findLaneCount(int $seasonId): ?int;

    /**
     * Every n-th matchday of the season is a positionday (0 = none); without a
     * configuration the default 10.
     */
    public function findPositiondayInterval(int $seasonId): int;
}
