<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\SeasonPlanner;

use Bowlingliga\Domain\SeasonPlanner\SeasonLength;
use Bowlingliga\Domain\SeasonPlanner\SeasonPlanner;
use Bowlingliga\Domain\SeasonPlanner\Team;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the error cases of the season planning (task 15.4).
 *
 * Validates: R9.5
 *
 * R9.5: if the number of teams is less than 2 or does not allow a
 * balanced fixture distribution, the {@see SeasonPlanner} names the cause and
 * saves no schedule. In the result this means:
 * {@see ScheduleResult::successful} is false, {@see ScheduleResult::schedule}
 * is null and {@see ScheduleResult::errorCause} names the cause.
 *
 * R9.12: if the defined season length does not give an even round
 * robin distribution with an identical number of encounters per pair of
 * teams, the season planner still generates the plan but marks the result
 * with a warning; applying it requires explicit confirmation by the
 * administrator. The result is then successful but carries a non-empty
 * warning via {@see ScheduleResult::hasWarnings()}.
 */
final class SeasonPlannerErrorCasesTest extends TestCase
{
    private SeasonPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new SeasonPlanner();
    }

    /**
     * R9.5: without participating teams no schedule can be generated; the
     * result is not successful, carries no plan and names the cause.
     */
    public function testNoTeamsGivesErrorWithoutSchedule(): void
    {
        $result = $this->planner->generateSchedule([], SeasonLength::fromMatchdayCount(10));

        self::assertFalse($result->successful);
        self::assertNull($result->schedule);
        self::assertNotNull($result->errorCause);
        self::assertNotSame('', $result->errorCause);
    }

    /**
     * R9.5: a single team allows no fixture; the result is not successful,
     * carries no plan and names the cause.
     */
    public function testSingleTeamGivesErrorWithoutSchedule(): void
    {
        $result = $this->planner->generateSchedule(
            [new Team('t1', 'Team Eins')],
            SeasonLength::fromMatchdayCount(10)
        );

        self::assertFalse($result->successful);
        self::assertNull($result->schedule);
        self::assertNotNull($result->errorCause);
        self::assertNotSame('', $result->errorCause);
    }

    /**
     * R9.12: a valid even number of teams with a season length that is not a
     * multiple of the round length (n − 1) distributes the fixtures unevenly.
     * With 4 teams a round has 3 matchdays; 5 matchdays are no multiple of 3.
     * The plan is generated (successful), but the result gets a non-empty
     * warning.
     */
    public function testUnevenDistributionGivesSuccessWithWarning(): void
    {
        $teams = [
            new Team('t1', 'Team Eins'),
            new Team('t2', 'Team Zwei'),
            new Team('t3', 'Team Drei'),
            new Team('t4', 'Team Vier'),
        ];

        $result = $this->planner->generateSchedule($teams, SeasonLength::fromMatchdayCount(5));

        self::assertTrue($result->successful);
        self::assertNotNull($result->schedule);
        self::assertTrue($result->hasWarnings());
        self::assertNotEmpty($result->warnings);
        self::assertNotSame('', $result->warnings[0]);
        self::assertNull($result->errorCause);
    }
}
