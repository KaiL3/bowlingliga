<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Security\WriteAccess;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\AccessDeniedException;

/**
 * Writing use case: managing teams (R7.1–7.7).
 *
 * A team is an entity spanning seasons ({@see TeamStore}); its name applies
 * per season and is kept as a team season assignment
 * ({@see TeamSeasonStore}). This gives the domain rules:
 * - Creating produces a team identity and assigns it a team name for the
 *   season (R7.1). The name is checked for 1–100 characters via the
 *   {@see Validator} (R7.2) and must be unique within the season (R7.3).
 * - Changing only affects the per-season team name; the team identity stays
 *   unchanged (R7.4). The length and uniqueness rules apply here too.
 * - Removing ends a team's participation in a season, but only as long as the
 *   season has not started yet (R7.6); for an already started season the
 *   removal is rejected (R7.7). A non-existent team or assignment leads to an
 *   error message (R7.5).
 *
 * Every writing call is protected by {@see WriteAccess} (R5.1); without a
 * valid session the action is skipped. The "started" check uses the season's
 * start date ({@see SeasonReader}) against an injectable point in time
 * (deterministically testable): a season counts as started when its start
 * date is reached or passed on the check day.
 */
final class TeamManagement
{
    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /**
     * @param callable(): \DateTimeImmutable|null $clock Optional clock for
     *        deterministic tests; without it the current time applies.
     */
    public function __construct(
        private readonly TeamStore $teams,
        private readonly TeamSeasonStore $teamSeasons,
        private readonly SeasonReader $seasons,
        private readonly Validator $validator,
        private readonly WriteAccess $writeAccess,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Creates a new team and assigns it a team name for the season; returns
     * the id of the team season assignment (R7.1).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\ValidationException for an invalid team
     *         name (R7.2).
     * @throws TeamManagementException for a duplicate team name in the season
     *         (R7.3).
     */
    public function createTeam(int $seasonId, string $teamLabel): int
    {
        $this->ensureWriteAccess();

        $teamLabel = $this->validator->checkName($teamLabel, 'field.team_name');
        $this->ensureTeamNameUnique($seasonId, $teamLabel, null);

        $teamId = $this->teams->create();

        return $this->teamSeasons->create($teamId, $seasonId, $teamLabel);
    }

    /**
     * Changes the per-season team name of an existing team season assignment;
     * the team identity stays unchanged (R7.4).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\ValidationException for an invalid team
     *         name (R7.2).
     * @throws TeamManagementException if the assignment is missing or the team
     *         name is already taken in the season (R7.3).
     */
    public function renameTeam(int $teamSeasonId, string $teamLabel): void
    {
        $this->ensureWriteAccess();

        $assignment = $this->teamSeasons->find($teamSeasonId);
        if ($assignment === null) {
            throw TeamManagementException::assignmentNotFound();
        }

        $teamLabel = $this->validator->checkName($teamLabel, 'field.team_name');
        $this->ensureTeamNameUnique((int) $assignment['season_id'], $teamLabel, $teamSeasonId);

        $this->teamSeasons->updateTeamName($teamSeasonId, $teamLabel);
    }

    /**
     * Ends a team's participation in a season, provided the season has not
     * started yet (R7.6, R7.7).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws TeamManagementException if the assignment/team is missing (R7.5)
     *         or the season has already started (R7.7).
     */
    public function removeTeamFromSeason(int $teamSeasonId): void
    {
        $this->ensureWriteAccess();

        $assignment = $this->teamSeasons->find($teamSeasonId);
        if ($assignment === null) {
            throw TeamManagementException::teamNotFound();
        }

        if ($this->isSeasonStarted((int) $assignment['season_id'])) {
            throw TeamManagementException::seasonAlreadyStarted();
        }

        $this->teamSeasons->delete($teamSeasonId);
    }

    /**
     * Ensures that the team name is unique within the season (R7.3).
     *
     * When changing, the own assignment ({@see $ownAssignmentId}) is excluded
     * from the check, so that an unchanged name does not collide with itself.
     *
     * @throws TeamManagementException if the name is already assigned to
     *         another team of the season.
     */
    private function ensureTeamNameUnique(int $seasonId, string $teamLabel, ?int $ownAssignmentId): void
    {
        foreach ($this->teamSeasons->findAllForSeason($seasonId) as $existingItems) {
            if ($ownAssignmentId !== null && (int) $existingItems['id'] === $ownAssignmentId) {
                continue;
            }

            if ($this->normalise((string) $existingItems['team_name']) === $this->normalise($teamLabel)) {
                throw TeamManagementException::duplicateTeamName($teamLabel);
            }
        }
    }

    /**
     * Checks whether a season has already started on the current check day
     * (R7.7).
     *
     * If the season does not exist, it is treated as "not started", so that an
     * orphaned assignment can still be removed.
     */
    private function isSeasonStarted(int $seasonId): bool
    {
        $season = $this->seasons->find($seasonId);
        if ($season === null) {
            return false;
        }

        $startDate = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $season['start_date']);
        if ($startDate === false) {
            // Treat an unreadable start date defensively as "started", so as not to
            // allow removal in an unclear state.
            return true;
        }

        // Day-based comparison: start date reached or passed.
        $today = $this->currentTime()->setTime(0, 0);

        return $startDate->setTime(0, 0) <= $today;
    }

    private function ensureWriteAccess(): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
    }

    /**
     * Normalises a team name for the uniqueness comparison (trimmed, lower
     * case), so that names do not differ only in case or surrounding
     * whitespace.
     */
    private function normalise(string $teamLabel): string
    {
        return mb_strtolower(trim($teamLabel));
    }

    private function currentTime(): \DateTimeImmutable
    {
        return ($this->clock)();
    }
}
