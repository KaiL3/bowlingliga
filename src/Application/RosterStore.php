<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

/**
 * Port for persisting the per-season roster assignments (R6.6, R6.7, R21.1).
 *
 * Decouples {@see PlayerManagement} from the concrete persistence technology:
 * the use case only knows the domain write operations for assigning a player
 * to the roster of a team season. The port is implemented by the
 * {@see \Bowlingliga\Persistence\RosterRepository}; in tests a slim
 * in-memory fake is enough.
 */
interface RosterStore
{
    /**
     * Assigns a player to the roster of a team season and returns the id of the
     * assignment (R6.6, R21.1).
     */
    public function create(int $teamSeasonId, int $playerId): int;

    /**
     * Removes a single roster assignment again (R6.7).
     */
    public function delete(int $id): void;

    /**
     * Existing roster assignment of the player in the season of the given team
     * season (with any team), or null (R6.7, R6.8).
     *
     * @return array{team_season_id: int, team_name: string, player_name: string}|null
     */
    public function findAssignmentInSeason(int $teamSeasonId, int $playerId): ?array;
}
