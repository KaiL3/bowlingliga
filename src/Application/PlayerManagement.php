<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Security\WriteAccess;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\AccessDeniedException;

/**
 * Writing use case: managing players while keeping data minimisation
 * (R6.1–6.7, R25.1–25.4).
 *
 * Bundles creating, changing and removing players and the per-season
 * assignment of a player to the roster of a team season. The following
 * applies:
 * - Only name and gender are stored (data minimisation, R25.1); a pseudonym is
 *   allowed, since only the length of the name is checked (R25.3).
 * - Every input is checked server-side via the {@see Validator} before
 *   persisting (R6.2/R6.3); for invalid values nothing is saved and a
 *   field-related exception is thrown.
 * - Every writing call is protected by {@see WriteAccess} (R5.1): without a
 *   valid authenticated session the action is not executed and the data stays
 *   unchanged.
 * - Persisting happens transactionally in the raw data repositories
 *   (R4.1/R4.3).
 *
 * When a player is removed, their per-season team memberships (roster
 * entries) are removed as well (R6.5); this is ensured by the
 * `ON DELETE CASCADE` of the foreign key `roster.player_id`, which deletes the
 * personal assignments together with the player (R25.4).
 */
final class PlayerManagement
{
    public function __construct(
        private readonly PlayerStore $player,
        private readonly RosterStore $roster,
        private readonly Validator $validator,
        private readonly WriteAccess $writeAccess,
    ) {
    }

    /**
     * Creates a player with name and gender and returns its id (R6.1).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\ValidationException for an invalid name
     *         (R6.2) or gender (R6.3).
     */
    public function createPlayer(string $name, string $gender): int
    {
        $this->ensureWriteAccess();

        $name       = $this->validator->checkName($name);
        $gender = $this->validator->checkGender($gender);

        return $this->player->create($name, $gender);
    }

    /**
     * Changes name and gender of a player (R6.4).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\ValidationException for an invalid name
     *         (R6.2) or gender (R6.3).
     */
    public function updatePlayer(int $playerId, string $name, string $gender): void
    {
        $this->ensureWriteAccess();

        $name       = $this->validator->checkName($name);
        $gender = $this->validator->checkGender($gender);

        $this->player->update($playerId, $name, $gender);
    }

    /**
     * Removes a player and with it their per-season team memberships (R6.5,
     * R25.4).
     *
     * Deleting the player also removes all of their roster entries via the
     * `ON DELETE CASCADE` of the foreign key `roster.player_id`, so that no
     * personal assignments remain.
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     */
    public function removePlayer(int $playerId): void
    {
        $this->ensureWriteAccess();

        $this->player->delete($playerId);
    }

    /**
     * Assigns a player to the roster of a team season and returns the id of the
     * assignment (R6.6, R21.1).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     */
    public function assignPlayerToTeamSeason(int $teamSeasonId, int $playerId): int
    {
        $this->ensureWriteAccess();

        // A player belongs to at most one roster per season; changing teams
        // within the season is not intended (R6.7, R6.8).
        $existing = $this->roster->findAssignmentInSeason($teamSeasonId, $playerId);
        if ($existing !== null) {
            throw $existing['team_season_id'] === $teamSeasonId
                ? PlayerManagementException::alreadyInRoster($existing['player_name'])
                : PlayerManagementException::playsForOtherTeam($existing['player_name'], $existing['team_name']);
        }

        return $this->roster->create($teamSeasonId, $playerId);
    }

    /**
     * Removes a single per-season team membership (roster entry) again (R6.7).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     */
    public function removeTeamSeasonAssignment(int $rosterId): void
    {
        $this->ensureWriteAccess();

        $this->roster->delete($rosterId);
    }

    /**
     * Ensures that write access is currently allowed (R5.1).
     *
     * @throws AccessDeniedException if there is no valid write access.
     */
    private function ensureWriteAccess(): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
    }
}
