<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

/**
 * Read port for displaying the master data in the admin area (task 35.1).
 *
 * Provides the {@see MasterDataController} only with the reads needed for the
 * overview (players, team season assignments, seasons, matchdays, admin
 * accounts), decoupled from the concrete raw data repositories. In production
 * the {@see \Bowlingliga\Persistence\MasterData\RepositoryMasterDataReader}
 * implements this port via the existing repositories; in tests a slim
 * in-memory fake is enough.
 *
 * All return values are the persisted records unchanged (R4.5).
 */
interface MasterDataReader
{
    /**
     * Returns all players (each entry at least `id`, `name`, `gender`).
     *
     * @return list<array<string, mixed>>
     */
    public function allPlayers(): array;

    /**
     * Returns the team season assignments of a season (each entry at least
     * `id`, `team_name`, `season_id`).
     *
     * @return list<array<string, mixed>>
     */
    public function seasonTeams(int $seasonId): array;

    /**
     * Returns all seasons (each entry at least `id`, `name`).
     *
     * @return list<array<string, mixed>>
     */
    public function allSeasons(): array;

    /**
     * Returns the matchdays of a season (each entry at least `id`,
     * `matchday_number`, `date`, `matchday_type`).
     *
     * @return list<array<string, mixed>>
     */
    public function seasonMatchdays(int $seasonId): array;

    /**
     * Returns all admin accounts (each entry at least `id`, `username`).
     *
     * @return list<array<string, mixed>>
     */
    public function allAccounts(): array;

    /**
     * Returns the roster entries of all teams of a season (each entry
     * `roster_id`, `team_season_id`, `player_id`, `name`, R21.1).
     *
     * @return list<array<string, mixed>>
     */
    public function seasonRoster(int $seasonId): array;
}
