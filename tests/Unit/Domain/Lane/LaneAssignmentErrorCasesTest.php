<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\Lane;

use Bowlingliga\Domain\Lane\Fixture;
use Bowlingliga\Domain\Lane\LaneAssignmentService;
use Bowlingliga\Domain\Lane\ScheduleMatchday;
use Bowlingliga\Tests\GermanText;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the error cases of the automatic lane assignment (task 16.3).
 *
 * Validates: R18.5, R18.6
 *
 * R18.5: if no schedule can be generated or no lane count is
 * configured, the system calculates no lane assignment and reports the missing
 * prerequisite. In these cases the {@see LaneAssignmentService} returns a
 * failure without a partial assignment.
 *
 * R18.6: if the lane pairs are not enough to assign all fixtures of
 * a matchday at the same time, no lane assignment is saved and the
 * insufficient lane count is reported. Here too no partial assignment arises
 * on purpose, so that the caller saves nothing.
 *
 * In all error cases it is checked that isSuccessful() === false holds, a
 * non-empty error message exists and no assignments at all (assignments ===
 * []) are produced.
 */
final class LaneAssignmentErrorCasesTest extends TestCase
{
    private LaneAssignmentService $service;

    protected function setUp(): void
    {
        $this->service = new LaneAssignmentService();
    }

    /**
     * R18.5: a lane count below 2 is no valid configuration – no assignment,
     * error message, no partial assignment.
     */
    public function testLaneCountBelowTwoReturnsErrorWithoutAssignment(): void
    {
        $schedule = [
            new ScheduleMatchday(1, [new Fixture(1, 2)]),
        ];

        $result = $this->service->assignLanes($schedule, 0);

        self::assertFalse($result->isSuccessful());
        self::assertNotSame('', GermanText::of($result->error));
        self::assertSame([], $result->assignments);
    }

    /**
     * R18.5: an odd lane count cannot form complete lane pairs and is therefore
     * no valid lane count – no assignment, error message.
     */
    public function testOddLaneCountReturnsErrorWithoutAssignment(): void
    {
        $schedule = [
            new ScheduleMatchday(1, [new Fixture(1, 2)]),
        ];

        $result = $this->service->assignLanes($schedule, 3);

        self::assertFalse($result->isSuccessful());
        self::assertNotSame('', GermanText::of($result->error));
        self::assertSame([], $result->assignments);
    }

    /**
     * R18.5: without a schedule (empty matchday list) no assignment can be
     * calculated – error message, no partial assignment.
     */
    public function testEmptyScheduleReturnsErrorWithoutAssignment(): void
    {
        $result = $this->service->assignLanes([], 4);

        self::assertFalse($result->isSuccessful());
        self::assertNotSame('', GermanText::of($result->error));
        self::assertSame([], $result->assignments);
    }

    /**
     * R18.6: a matchday has more fixtures than available lane pairs (here 2
     * fixtures with only 1 lane pair of 2 lanes) – no assignment is saved,
     * error message, no partial assignment.
     */
    public function testMoreFixturesThanLanePairsReturnsErrorWithoutAssignment(): void
    {
        $schedule = [
            new ScheduleMatchday(1, [
                new Fixture(1, 2),
                new Fixture(3, 4),
            ]),
        ];

        // 2 lanes give exactly 1 lane pair, but there are 2 fixtures.
        $result = $this->service->assignLanes($schedule, 2);

        self::assertFalse($result->isSuccessful());
        self::assertNotSame('', GermanText::of($result->error));
        self::assertSame([], $result->assignments);
    }

    /**
     * R18.6: even if only a later matchday has too many fixtures for the
     * available lane pairs, no assignment is produced at all – deliberately no
     * partial assignment of the valid matchdays is created.
     */
    public function testInsufficientLanePairsOnALaterMatchdayReturnsNoPartialAssignment(): void
    {
        $schedule = [
            // Matchday 1 would be valid with 2 lane pairs (4 lanes) ...
            new ScheduleMatchday(1, [
                new Fixture(1, 2),
                new Fixture(3, 4),
            ]),
            // ... matchday 2, however, has 3 fixtures with only 2 lane pairs.
            new ScheduleMatchday(2, [
                new Fixture(1, 3),
                new Fixture(2, 5),
                new Fixture(4, 6),
            ]),
        ];

        $result = $this->service->assignLanes($schedule, 4);

        self::assertFalse($result->isSuccessful());
        self::assertNotSame('', GermanText::of($result->error));
        self::assertSame([], $result->assignments);
    }
}
