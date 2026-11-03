<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

/**
 * Port for persisting a team's per-season participation including the team
 * name (R7.1, R7.3, R7.4, R7.6).
 *
 * Decouples {@see TeamManagement} from the concrete persistence technology.
 * The port is implemented by the
 * {@see \Bowlingliga\Persistence\TeamSeasonRepository}; in tests a slim
 * in-memory fake is enough.
 */
interface TeamSeasonStore
{
    /**
     * Assigns a team to a season with a team name and returns the id of the
     * assignment (R7.1).
     */
    public function create(int $teamId, int $seasonId, string $teamLabel): int;

    /**
     * Changes the per-season team name; the team identity stays unchanged
     * (R7.4).
     */
    public function updateTeamName(int $id, string $teamLabel): void;

    /**
     * Ends a team's participation in a season (R7.6).
     */
    public function delete(int $id): void;

    /**
     * Returns a team season assignment or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array;

    /**
     * Returns all team season assignments of a season; basis for the uniqueness
     * check of the team name per season (R7.3).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForSeason(int $seasonId): array;
}
