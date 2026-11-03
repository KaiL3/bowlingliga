<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Positionday;

use Bowlingliga\Application\Positionday\PositiondayException;
use Bowlingliga\Application\Positionday\PositiondayManagement;
use Bowlingliga\Domain\Lane\LaneAssignmentService;
use Bowlingliga\Domain\Positionday\PositiondayService;
use Bowlingliga\Domain\Table\TeamStatistics;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the wiring of the positionday formation (task 26).
 *
 * Validates: R31.2, R31.4, R31.5, R31.7, R5.1
 *
 * Checks forming the fixtures from the standings including the lane
 * assignment (R31.2/R31.5), the note for incomplete previous matchdays
 * (R31.4), the rejection for an already complete positionday (R31.7), the
 * error handling when saving and the access protection (R5.1). The pure domain
 * services are used for real; context and store are in-memory fakes.
 */
final class PositiondayManagementTest extends TestCase
{
    private const MATCHDAY = 10;

    private SwitchableWriteAccess $access;

    protected function setUp(): void
    {
        $this->access = new SwitchableWriteAccess(true);
    }

    private function management(
        FixedPositiondayContext $context,
        RecordingPositiondayStore $store,
    ): PositiondayManagement {
        return new PositiondayManagement(
            $context,
            $store,
            new PositiondayService(),
            new LaneAssignmentService(),
            $this->access,
        );
    }

    /**
     * Four teams with a clear ranking (points descending).
     *
     * @return list<TeamStatistics>
     */
    private function fourTeams(): array
    {
        return [
            new TeamStatistics('1', 'Team 1', 20, 3000, 3200, 12),
            new TeamStatistics('2', 'Team 2', 16, 2900, 3100, 12),
            new TeamStatistics('3', 'Team 3', 12, 2800, 3000, 12),
            new TeamStatistics('4', 'Team 4', 8, 2700, 2900, 12),
        ];
    }

    public function testIncompletePreviousMatchdaysReturnNote(): void
    {
        $context = new FixedPositiondayContext(false, $this->fourTeams(), ['1' => 101, '2' => 102, '3' => 103, '4' => 104]);
        $store = new RecordingPositiondayStore();

        $result = $this->management($context, $store)->formAndSave(self::MATCHDAY);

        self::assertFalse($result->formed, 'With incomplete previous matchdays nothing is formed (R31.4).');
        self::assertNotNull($result->note);
        self::assertNull($store->saved, 'Nothing may be saved (R31.4).');
    }

    public function testReadyFormsFixturesWithLaneAssignment(): void
    {
        $context = new FixedPositiondayContext(true, $this->fourTeams(), ['1' => 101, '2' => 102, '3' => 103, '4' => 104]);
        $store = new RecordingPositiondayStore();

        $result = $this->management($context, $store)->formAndSave(self::MATCHDAY);

        self::assertTrue($result->formed, 'With complete previous matchdays the fixtures are formed (R31.2).');
        self::assertNotNull($store->saved);
        self::assertCount(2, $store->saved, '4 Teams ergeben 2 Paarungen (1v2, 3v4).');

        // 1v2 and 3v4 by team season ids.
        [$first, $second] = $store->saved;
        self::assertSame(101, $first->homeTeamSeasonId);
        self::assertSame(102, $first->awayTeamSeasonId);
        self::assertSame(103, $second->homeTeamSeasonId);
        self::assertSame(104, $second->awayTeamSeasonId);

        // LaneAssignment erzeugt (R31.5).
        self::assertNotNull($first->lanePairIndex);
        self::assertNotNull($second->lanePairIndex);
    }

    public function testReplacingFixturesRemovesUnreferencedScoreSheetFiles(): void
    {
        $context = new FixedPositiondayContext(true, $this->fourTeams(), ['1' => 101, '2' => 102, '3' => 103, '4' => 104]);
        $cleanup = new \Bowlingliga\Tests\Unit\Application\RecordingScoreSheetFileCleanup();
        $management = new PositiondayManagement(
            $context,
            new RecordingPositiondayStore(),
            new PositiondayService(),
            new LaneAssignmentService(),
            $this->access,
            $cleanup,
        );

        $management->formAndSave(self::MATCHDAY);

        self::assertSame(1, $cleanup->calls, 'The replaced fixtures may have had score sheets (R3.15).');
    }

    public function testAlreadyCompletePositiondayIsRejected(): void
    {
        $context = new FixedPositiondayContext(true, $this->fourTeams(), ['1' => 101, '2' => 102, '3' => 103, '4' => 104]);
        $context->positiondayComplete = true;
        $store = new RecordingPositiondayStore();

        $this->expectException(PositiondayException::class);
        $this->management($context, $store)->formAndSave(self::MATCHDAY);
    }

    public function testStoreErrorIsReported(): void
    {
        $context = new FixedPositiondayContext(true, $this->fourTeams(), ['1' => 101, '2' => 102, '3' => 103, '4' => 104]);
        $store = new RecordingPositiondayStore();
        $store->throwsError = true;

        $this->expectException(PositiondayException::class);
        $this->management($context, $store)->formAndSave(self::MATCHDAY);
    }

    public function testWithoutWriteAccessNoFormation(): void
    {
        $this->access->allowed = false;
        $context = new FixedPositiondayContext(true, $this->fourTeams(), ['1' => 101, '2' => 102, '3' => 103, '4' => 104]);
        $store = new RecordingPositiondayStore();

        $this->expectException(AccessDeniedException::class);
        $this->management($context, $store)->formAndSave(self::MATCHDAY);
    }
}
