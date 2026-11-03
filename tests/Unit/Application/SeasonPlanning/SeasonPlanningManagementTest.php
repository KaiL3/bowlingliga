<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\SeasonPlanning;

use Bowlingliga\Application\SeasonPlanning\PlannedMatchday;
use Bowlingliga\Application\SeasonPlanning\PlanningPreview;
use Bowlingliga\Application\SeasonPlanning\SeasonPlanningException;
use Bowlingliga\Application\SeasonPlanning\SeasonPlanningManagement;
use Bowlingliga\Domain\Lane\LaneAssignmentService;
use Bowlingliga\Domain\SeasonPlanner\SeasonPlanner;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the wiring of the season planning including the lane
 * assignment (task 23).
 *
 * Validates: R9.3, R9.4, R9.9, R9.12, R18.4, R18.7, R5.1
 *
 * Checks the preview (schedule + lane assignment, positiondays skipped R9.9),
 * the two-phase apply with confirmation on a warning (R9.12), the
 * transactional saving (R9.3) including unchanged data on an error (R9.4) and
 * the access protection (R5.1). The pure domain services are used for real;
 * persistence and teams reader are in-memory fakes.
 */
final class SeasonPlanningManagementTest extends TestCase
{
    private SwitchableWriteAccess $access;

    protected function setUp(): void
    {
        $this->access = new SwitchableWriteAccess(true);
    }

    /**
     * @param list<array{team_season_id: int, team_name: string}> $teams
     */
    private function management(
        array $teams,
        ?int $matchdayCount,
        ?int $laneCount,
        ?RecordingSeasonPlanStore $store = null,
    ): SeasonPlanningManagement {
        return new SeasonPlanningManagement(
            new FixedSeasonTeamsReader($teams, $matchdayCount, $laneCount),
            $store ?? new RecordingSeasonPlanStore(),
            new SeasonPlanner(),
            new LaneAssignmentService(),
            $this->access,
        );
    }

    /**
     * @return list<array{team_season_id: int, team_name: string}>
     */
    private function teams(int $quantity): array
    {
        $teams = [];
        for ($i = 1; $i <= $quantity; $i++) {
            $teams[] = ['team_season_id' => $i, 'team_name' => 'Team ' . $i];
        }

        return $teams;
    }

    public function testPreviewGeneratesScheduleAndLaneAssignment(): void
    {
        // 4 teams, one full round = 3 matchdays: even schedule
        // distribution (no schedule warning). A lane pair warning can
        // still occur with such small schedules (spread per team) and
        // is allowed by the domain (R18.9); what is checked here is the complete
        // lane assignment (R18.1).
        $management = $this->management($this->teams(4), 3, 4);

        $preview = $management->planPreview(1);

        self::assertCount(3, $preview->matchdays);

        // Every regular fixture has a lane assignment (R18.1).
        foreach ($preview->matchdays as $matchday) {
            foreach ($matchday->fixtures as $fixture) {
                self::assertNotNull($fixture->lanePairIndex, 'Regular fixtures get a lane pair (R18.1).');
            }
        }
    }

    public function testPositiondayMatchdayWithoutFixedFixtures(): void
    {
        // 4 teams over 10 matchdays -> matchday 10 is a positionday (R9.13).
        $management = $this->management($this->teams(4), 10, 4);

        $preview = $management->planPreview(1);

        $positionday = null;
        foreach ($preview->matchdays as $matchday) {
            if ($matchday->number === 10) {
                $positionday = $matchday;
            }
        }

        self::assertNotNull($positionday);
        self::assertTrue($positionday->isPositionday());
        self::assertSame([], $positionday->fixtures, 'A positionday has no fixed fixtures (R9.9, R18.7).');
    }

    public function testOddTeamCountAddsGhostTeam(): void
    {
        // 3 real teams -> ghost team added -> 4 teams, round = 3 matchdays.
        $management = $this->management($this->teams(3), 3, 4);

        $preview = $management->planPreview(1);

        // In at least one fixture one team is the ghost team (home/away null).
        $hasGhost = false;
        foreach ($preview->matchdays as $matchday) {
            foreach ($matchday->fixtures as $fixture) {
                if ($fixture->homeTeamSeasonId === null || $fixture->awayTeamSeasonId === null) {
                    $hasGhost = true;
                }
            }
        }

        self::assertTrue($hasGhost, 'With an odd number of teams a ghost team is added (R9.6); it has no team season id.');
    }

    public function testFewerThanTwoTeamsThrowsException(): void
    {
        $management = $this->management($this->teams(1), 3, 4);

        $this->expectException(SeasonPlanningException::class);
        $management->planPreview(1);
    }

    public function testApplySavesTransactionally(): void
    {
        $store = new RecordingSeasonPlanStore();
        $management = $this->management($this->teams(4), 3, 4, $store);

        $preview = $management->planPreview(1);
        // With small schedules a lane pair warning can occur; applying
        // is therefore tested with explicit confirmation (R9.12).
        $management->applyPlan($preview, true);

        self::assertSame(1, $store->savedSeasonId);
        self::assertNotNull($store->saved);
        self::assertCount(3, $store->saved);
    }

    public function testWarningRequiresConfirmation(): void
    {
        // 4 teams over 4 matchdays: round = 3, 4 is no multiple -> warning (R9.12).
        $store = new RecordingSeasonPlanStore();
        $management = $this->management($this->teams(4), 4, 4, $store);

        $preview = $management->planPreview(1);
        self::assertTrue($preview->hasWarnings(), 'An uneven distribution creates a warning (R9.12).');

        // Without confirmation: rejected, nothing saved.
        try {
            $management->applyPlan($preview, false);
            self::fail('Without confirmation a preview with a warning must not be applied (R9.12).');
        } catch (SeasonPlanningException) {
            // expected
        }
        self::assertNull($store->saved);

        // With confirmation: applied.
        $management->applyPlan($preview, true);
        self::assertNotNull($store->saved);
    }

    public function testStoreErrorIsReportedAsSeasonPlanningException(): void
    {
        $store = new RecordingSeasonPlanStore();
        $store->throwsError = true;
        $management = $this->management($this->teams(4), 3, 4, $store);

        $preview = $management->planPreview(1);

        $this->expectException(SeasonPlanningException::class);
        $management->applyPlan($preview, true);
    }

    public function testWithoutWriteAccessNoPreview(): void
    {
        $this->access->allowed = false;
        $management = $this->management($this->teams(4), 3, 4);

        $this->expectException(AccessDeniedException::class);
        $management->planPreview(1);
    }
}
