<?php

declare(strict_types=1);

namespace Bowlingliga\Application\SeasonPlanning;

use Bowlingliga\Domain\Lane\Fixture as LaneFixture;
use Bowlingliga\Domain\Lane\LaneAssignmentService;
use Bowlingliga\Domain\Lane\ScheduleMatchday as LaneMatchday;
use Bowlingliga\Domain\Message;
use Bowlingliga\Domain\SeasonPlanner\Fixture as PlannerFixture;
use Bowlingliga\Domain\SeasonPlanner\Matchday as PlannerMatchday;
use Bowlingliga\Domain\SeasonPlanner\Schedule;
use Bowlingliga\Domain\SeasonPlanner\SeasonLength;
use Bowlingliga\Domain\SeasonPlanner\SeasonPlanner;
use Bowlingliga\Domain\SeasonPlanner\Team as PlannerTeam;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\WriteAccess;

/**
 * Writing use case: wiring the season planning including the lane assignment
 * as a whole (R9.3, R9.4, R9.9, R9.14, R18.4, R18.7).
 *
 * The use case connects the two pure domain services {@see SeasonPlanner} and
 * {@see LaneAssignmentService} with the persistence:
 *
 * - {@see planPreview()} reads the real teams and the configuration of the
 *   season, generates the schedule (R9.1) and the lane assignment (R18) and
 *   returns a {@see PlanningPreview} without persisting. Warnings from an
 *   uneven distribution (R9.12) or a not fully even lane assignment (R18.9)
 *   are collected so that the administrator sees them before applying
 *   (R9.14). The administrator can set the type of every matchday in the
 *   preview; the plan is then generated again. The lane assignment is only
 *   generated for matchdays with fixed fixtures; positiondays and lot draws
 *   are skipped according to R9.9/R18.7.
 * - {@see applyPlan()} saves the preview transactionally (R9.3). If the
 *   preview carries a warning, it is only applied with explicit confirmation
 *   (R9.12). If saving fails, the data stays unchanged (R9.4, R18.4).
 *
 * Every call is protected by {@see WriteAccess} (R5.1).
 */
final class SeasonPlanningManagement
{
    public function __construct(
        private readonly SeasonTeamsReader $teamsReader,
        private readonly SeasonPlanStore $store,
        private readonly SeasonPlanner $seasonPlanner,
        private readonly LaneAssignmentService $laneAssignment,
        private readonly WriteAccess $writeAccess,
    ) {
    }

    /**
     * Generates a planning preview (schedule + lane assignment) without
     * persisting (R9.1, R9.9, R9.14, R18).
     *
     * @param array<int, string> $types Matchday types chosen in the preview per
     *        matchday number (regular, positionday, lot_draw); the others
     *        follow the positionday interval of the season.
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws SeasonPlanningException if no plan can be generated (R9.5) or the
     *         lane assignment fails (R18.5/18.6).
     */
    public function planPreview(int $seasonId, array $types = []): PlanningPreview
    {
        $this->ensureWriteAccess();

        $teams = $this->teamsReader->findTeams($seasonId);
        $matchdayCount = $this->teamsReader->findMatchdayCount($seasonId) ?? 0;
        $laneCount = $this->teamsReader->findLaneCount($seasonId) ?? 0;

        // Schedule erzeugen (R9.1, R9.5, R9.12).
        $plannerTeams = $this->buildPlannerTeams($teams);
        $result = $this->seasonPlanner->generateSchedule(
            $plannerTeams,
            SeasonLength::fromMatchdayCount(max(1, $matchdayCount)),
            $this->teamsReader->findPositiondayInterval($seasonId),
            $types,
        );

        if (! $result->successful || $result->schedule === null) {
            throw SeasonPlanningException::planCannotBeGenerated(
                $result->errorCause ?? new Message('planning.error.failed')
            );
        }

        $warnings = $result->warnings;
        $schedule = $result->schedule;

        // Generate the lane assignment only for matchdays with fixed fixtures;
        // positiondays are skipped (R9.9, R18.7).
        $laneDeviations = [];
        $laneAssignmentByMatchday = $this->generateLaneAssignment($schedule, $laneCount, $warnings, $laneDeviations);

        $matchdays = $this->buildPlannedMatchdays($schedule, $laneAssignmentByMatchday);

        return new PlanningPreview($seasonId, $matchdays, array_values($warnings), $laneDeviations);
    }

    /**
     * Applies a previously generated planning preview and persists it
     * transactionally (R9.3, R9.4).
     *
     * If the preview carries a warning (R9.12/R18.9), it is only applied with
     * `$confirmed = true`; otherwise applying is rejected and nothing is saved.
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws SeasonPlanningException if a confirmation is missing (R9.12) or
     *         saving fails (R9.4).
     */
    public function applyPlan(PlanningPreview $preview, bool $confirmed): void
    {
        $this->ensureWriteAccess();

        if ($preview->hasWarnings() && ! $confirmed) {
            throw SeasonPlanningException::confirmationRequired();
        }

        try {
            $this->store->saveSchedule($preview->seasonId, $preview->matchdays);
        } catch (SeasonPlanningException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            // R9.4/R18.4: on error the data stays unchanged.
            throw SeasonPlanningException::saveFailed($throwable);
        }
    }

    /**
     * Translates the team season entries read into season planner teams; the
     * team season id serves as stable string id (R9.1).
     *
     * @param list<array{team_season_id: int, team_name: string}> $teams
     *
     * @return list<PlannerTeam>
     */
    private function buildPlannerTeams(array $teams): array
    {
        return array_map(
            static fn (array $t): PlannerTeam => new PlannerTeam((string) $t['team_season_id'], $t['team_name']),
            $teams
        );
    }

    /**
     * Generates the lane assignment for the matchdays with fixed fixtures
     * (without positiondays, R9.9/R18.7) and indexes it by matchday number and
     * fixture index. Collects a possible lane warning (R18.9).
     *
     * @param array<int, string> $warnings gets a possible lane warning added (by reference)
     * @param array<int, array{usage: array<int, int>, spread: int}> $deviations
     *        receives the deviation overview per team (R18.10, by reference)
     *
     * @return array<int, array<int, int>> [matchdayNumber][fixtureIndex] => lane pair index
     *
     * @throws SeasonPlanningException for a failed lane assignment (R18.5/18.6)
     */
    private function generateLaneAssignment(Schedule $schedule, int $laneCount, array &$warnings, array &$deviations): array
    {
        $laneMatchdays = [];
        foreach ($schedule->matchdays as $matchday) {
            if ($matchday->isSpecialDay()) {
                // Positionday or lot draw: fixtures are not fixed yet → skip (R9.9, R18.7).
                continue;
            }

            $laneMatchdays[] = new LaneMatchday($matchday->number, $this->buildLaneFixtures($matchday));
        }

        if ($laneMatchdays === []) {
            // No fixed fixtures (only special days): no
            // lane assignment needed.
            return [];
        }

        $result = $this->laneAssignment->assignLanes($laneMatchdays, $laneCount);

        if (! $result->isSuccessful()) {
            throw SeasonPlanningException::laneAssignmentFailed(
                $result->error ?? new Message('lanes.error.failed')
            );
        }

        if ($result->hasWarning() && $result->warning !== null) {
            $warnings[] = $result->warning;
            $deviations = $result->deviations;
        }

        $isIndexed = [];
        foreach ($result->assignments as $assignment) {
            $isIndexed[$assignment->matchdayNumber][$assignment->fixtureIndex] = $assignment->lanePair->index;
        }

        return $isIndexed;
    }

    /**
     * Translates the fixtures of a planner matchday into lane fixtures; the
     * ghost team is kept with a special id (0) and marked as ghost team
     * (R18.8).
     *
     * @return list<LaneFixture>
     */
    private function buildLaneFixtures(PlannerMatchday $matchday): array
    {
        $fixtures = [];
        foreach ($matchday->fixtures as $fixture) {
            $fixtures[] = new LaneFixture(
                $this->laneTeamId($fixture->home->id, $fixture->home->isGhostTeam),
                $this->laneTeamId($fixture->away->id, $fixture->away->isGhostTeam),
                $fixture->home->isGhostTeam,
                $fixture->away->isGhostTeam,
            );
        }

        return $fixtures;
    }

    /**
     * Forms the integer team id for the lane assignment: the team season id of
     * real teams or 0 for the ghost team (which has no team season row).
     */
    private function laneTeamId(string $plannerTeamId, bool $isGhost): int
    {
        return $isGhost ? 0 : (int) $plannerTeamId;
    }

    /**
     * Builds the matchdays to save from the schedule and the indexed lane
     * assignment (R9.3). Positiondays carry no fixed fixtures and no lane
     * assignment (R9.9, R18.7).
     *
     * @param array<int, array<int, int>> $laneAssignment [matchdayNumber][fixtureIndex] => lane pair index
     *
     * @return list<PlannedMatchday>
     */
    private function buildPlannedMatchdays(Schedule $schedule, array $laneAssignment): array
    {
        $matchdays = [];
        foreach ($schedule->matchdays as $matchday) {
            if ($matchday->isSpecialDay()) {
                // Positionday or lot draw: save without fixed fixtures (R9.9, R32).
                $matchdays[] = new PlannedMatchday($matchday->number, $matchday->type, []);
                continue;
            }

            $fixtures = [];
            foreach ($matchday->fixtures as $index => $fixture) {
                $fixtures[] = new PlannedFixture(
                    $this->teamSeasonId($fixture->home->id, $fixture->home->isGhostTeam),
                    $this->teamSeasonId($fixture->away->id, $fixture->away->isGhostTeam),
                    $laneAssignment[$matchday->number][$index] ?? null,
                );
            }

            $matchdays[] = new PlannedMatchday($matchday->number, 'regular', $fixtures);
        }

        return $matchdays;
    }

    /**
     * Team season id of a real team; null for the ghost team (no team season
     * row of its own).
     */
    private function teamSeasonId(string $plannerTeamId, bool $isGhost): ?int
    {
        return $isGhost ? null : (int) $plannerTeamId;
    }

    private function ensureWriteAccess(): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
    }
}
