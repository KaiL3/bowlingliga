<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Positionday;

use Bowlingliga\Application\ScoreSheetFileCleanup;
use Bowlingliga\Domain\Lane\Fixture as LaneFixture;
use Bowlingliga\Domain\Lane\LaneAssignmentService;
use Bowlingliga\Domain\Lane\ScheduleMatchday as LaneMatchday;
use Bowlingliga\Domain\Message;
use Bowlingliga\Domain\Positionday\PositiondayFixture;
use Bowlingliga\Domain\Positionday\PositiondayService;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\WriteAccess;

/**
 * Writing use case: wiring the positionday formation as a whole (R31.2, R31.5,
 * R31.7).
 *
 * The use case triggers the formation of the positionday fixtures as soon as
 * all previous matchdays are "complete" (R31.2/R31.4), then generates the
 * positionday's lane assignment (R31.5) and saves fixtures and lane
 * assignment transactionally. As long as the positionday itself is not
 * "complete" yet, the automatically formed fixtures may be (re)formed or
 * corrected (R31.7); once it is complete, the formation is rejected.
 *
 * The fixture formation itself lives in the pure {@see PositiondayService};
 * the lane assignment in the pure {@see LaneAssignmentService}. This use case
 * only orchestrates reading, calling and saving transactionally. Every call is
 * protected by {@see WriteAccess} (R5.1).
 */
final class PositiondayManagement
{
    public function __construct(
        private readonly PositiondayContextReader $context,
        private readonly PositiondayFixtureStore $store,
        private readonly PositiondayService $positiondayService,
        private readonly LaneAssignmentService $laneAssignment,
        private readonly WriteAccess $writeAccess,
        private readonly ?ScoreSheetFileCleanup $scoreSheetFiles = null,
    ) {
    }

    /**
     * Forms the fixtures of a positionday from the standings, generates the
     * lane assignment and saves both transactionally (R31.2, R31.5).
     *
     * If not all previous matchdays are "complete" yet, nothing is fixed and a
     * note is returned (R31.4). If the positionday is already "complete", the
     * (re)formation is rejected (R31.7).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws PositiondayException if the positionday is already complete
     *         (R31.7), the lane assignment fails (R31.5) or saving fails.
     */
    public function formAndSave(int $matchdayId): PositiondayFormationResult
    {
        $this->ensureWriteAccess();

        // R31.7: an already complete positionday is not changed any more.
        if ($this->context->positiondayComplete($matchdayId)) {
            throw PositiondayException::alreadyComplete();
        }

        $allComplete = $this->context->allPreviousMatchdaysComplete($matchdayId);
        $standings = $this->context->standingsBefore($matchdayId);

        // Form the fixtures via the pure service (R31.2, R31.3, R31.4).
        $result = $this->positiondayService->formPositiondayFixtures($allComplete, $standings);

        if (! $result->isReady()) {
            // R31.4: not all previous matchdays complete yet – fix nothing.
            return PositiondayFormationResult::notReady(
                $result->note ?? new Message('positionday.not_ready')
            );
        }

        // Generate the lane assignment of the positionday (R31.5).
        $teamSeasonIds = $this->context->teamSeasonIds($matchdayId);
        $laneCount = $this->context->laneCount($matchdayId);

        [$fixtures, $warnings] = $this->assignLanes($matchdayId, $result->fixtures, $teamSeasonIds, $laneCount);

        try {
            $this->store->saveFixtures($matchdayId, $fixtures);
        } catch (\Throwable $throwable) {
            throw PositiondayException::saveFailed($throwable);
        }

        // Replacing the fixtures deletes the previous ones including their
        // score sheet records; their files go as well (R3.15).
        $this->scoreSheetFiles?->removeOrphanedFiles();

        return PositiondayFormationResult::formed($warnings);
    }

    /**
     * Generates the lane assignment for the one positionday matchday and forms
     * the fixtures to save from it (R31.5, R18).
     *
     * @param list<PositiondayFixture> $positiondayFixtures
     * @param array<string, int>       $teamSeasonIds
     *
     * @return array{0: list<SeededPositiondayFixture>, 1: list<string>} Fixtures and warnings
     *
     * @throws PositiondayException for a failed lane assignment (R31.5).
     */
    private function assignLanes(int $matchdayNumber, array $positiondayFixtures, array $teamSeasonIds, int $laneCount): array
    {
        $laneFixtures = [];
        foreach ($positiondayFixtures as $fixture) {
            $laneFixtures[] = new LaneFixture(
                $this->laneTeamId($fixture->homeTeamId, $fixture->homeIsGhostTeam, $teamSeasonIds),
                $this->laneTeamId($fixture->awayTeamId, $fixture->awayIsGhostTeam, $teamSeasonIds),
                $fixture->homeIsGhostTeam,
                $fixture->awayIsGhostTeam,
            );
        }

        $schedule = [new LaneMatchday($matchdayNumber, $laneFixtures)];
        $result = $this->laneAssignment->assignLanes($schedule, $laneCount);

        if (! $result->isSuccessful()) {
            throw PositiondayException::laneAssignmentFailed(
                $result->error ?? new Message('lanes.error.failed')
            );
        }

        // LanePair-Index je Paarungsindex.
        $lanePairByIndex = [];
        foreach ($result->assignments as $assignment) {
            $lanePairByIndex[$assignment->fixtureIndex] = $assignment->lanePair->index;
        }

        $isSet = [];
        foreach ($positiondayFixtures as $index => $fixture) {
            $isSet[] = new SeededPositiondayFixture(
                $this->teamSeasonId($fixture->homeTeamId, $fixture->homeIsGhostTeam, $teamSeasonIds),
                $this->teamSeasonId($fixture->awayTeamId, $fixture->awayIsGhostTeam, $teamSeasonIds),
                $lanePairByIndex[$index] ?? null,
            );
        }

        $warnings = $result->hasWarning() && $result->warning !== null ? [$result->warning] : [];

        return [$isSet, $warnings];
    }

    /**
     * Integer team id for the lane assignment: team season id of real teams or
     * 0 for the ghost team (R18.8).
     *
     * @param array<string, int> $teamSeasonIds
     */
    private function laneTeamId(string $teamId, bool $isGhost, array $teamSeasonIds): int
    {
        return $isGhost ? 0 : ($teamSeasonIds[$teamId] ?? (int) $teamId);
    }

    /**
     * Team season id of a team. The ghost team gets its flagged team season
     * row if the context supplies it (as the season planning creates it);
     * without such a row it stays null.
     *
     * @param array<string, int> $teamSeasonIds
     */
    private function teamSeasonId(string $teamId, bool $isGhost, array $teamSeasonIds): ?int
    {
        return $teamSeasonIds[$teamId] ?? ($isGhost ? null : (int) $teamId);
    }

    private function ensureWriteAccess(): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
    }
}
