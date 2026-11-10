<?php

declare(strict_types=1);

namespace Bowlingliga\Application\TeamProfile;

/**
 * Read port of the team page (R41): the team, its seasons and the matchdays
 * of a season. Results and points come from the raw data of the table
 * ({@see \Bowlingliga\Application\Aggregation\TableRawDataReader}).
 */
interface TeamProfileReader
{
    /**
     * The team in one season, or null for an unknown team season or the ghost
     * team, which has no page (R41.1).
     *
     * @return array{team_season_id: int, team_id: int, season_id: int, season_name: string, team_name: string}|null
     */
    public function team(int $teamSeasonId): ?array;

    /**
     * Every season of the same team (same team id, whatever its name),
     * oldest first (R41.2).
     *
     * @return list<array{team_season_id: int, season_id: int, season_name: string}>
     */
    public function seasons(int $teamId): array;

    /**
     * The matchdays of a season by number.
     *
     * @return list<array{id: int, number: int, date: string, played: bool, cancelled: bool}>
     */
    public function matchdays(int $seasonId): array;
}
