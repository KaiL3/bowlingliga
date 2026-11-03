<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\Lane;

use Bowlingliga\Domain\Lane\LaneAssignmentService;
use Bowlingliga\Domain\Lane\ScheduleMatchday;
use Bowlingliga\Domain\Lane\Fixture;
use PHPUnit\Framework\TestCase;

/**
 * Regression test: lane assignment with more lane pairs than fixtures per
 * matchday over a whole season.
 *
 * Validates: R18.1, R18.2, R18.3
 *
 * The trigger was a timeout in the season planning with 3 teams plus ghost
 * team on 6 lanes (3 lane pairs, 2 fixtures per matchday) over around 40
 * matchdays: the pure depth-first search grew exponentially. The assignment
 * must be evenly distributed (spread ≤ 1) within a short time.
 */
final class LaneAssignmentRuntimeTest extends TestCase
{
    public function testFourTeamsOnThreeLanePairsOverWholeSeason(): void
    {
        $this->check(teamTotal: 4, laneCount: 6, matchdays: 40);
    }

    public function testSixTeamsOnFourLanePairsOverWholeSeason(): void
    {
        $this->check(teamTotal: 6, laneCount: 8, matchdays: 40);
    }

    private function check(int $teamTotal, int $laneCount, int $matchdays): void
    {
        $start = microtime(true);
        $result = (new LaneAssignmentService())->assignLanes($this->schedule($teamTotal, $matchdays), $laneCount);
        $duration = microtime(true) - $start;

        self::assertTrue($result->isSuccessful());
        self::assertLessThan(2.0, $duration, sprintf('The lane assignment must be quick (measured: %.2f s).', $duration));
        self::assertFalse($result->hasWarning(), 'A spread ≤ 1 is reachable and must be found (R18.2).');

        $usage = [];
        $perMatchday = [];
        foreach ($result->assignments as $assignment) {
            $perMatchday[$assignment->matchdayNumber][] = $assignment->lanePair->index;
            foreach ($assignment->fixture->teamIds() as $team) {
                $usage[$team][$assignment->lanePair->index] = ($usage[$team][$assignment->lanePair->index] ?? 0) + 1;
            }
        }

        foreach ($perMatchday as $indices) {
            self::assertSame(count($indices), count(array_unique($indices)), 'Different lane pairs per matchday (R18.3).');
        }

        $lanePairs = intdiv($laneCount, 2);
        foreach ($usage as $team => $perLanePair) {
            $values = [];
            for ($lanePair = 1; $lanePair <= $lanePairs; $lanePair++) {
                $values[] = $perLanePair[$lanePair] ?? 0;
            }
            self::assertLessThanOrEqual(1, max($values) - min($values), sprintf('Team %d: Spannweite ≤ 1 (R18.2).', $team));
        }
    }

    /**
     * Round robin using the circle method, repeated until the matchday count is
     * reached; the highest team is the ghost team.
     *
     * @return list<ScheduleMatchday>
     */
    private function schedule(int $teamTotal, int $matchdays): array
    {
        $teams = range(1, $teamTotal);
        $rounds = [];
        for ($round = 0; $round < $teamTotal - 1; $round++) {
            $fixtures = [];
            for ($i = 0; $i < intdiv($teamTotal, 2); $i++) {
                $home = $teams[$i];
                $away = $teams[$teamTotal - 1 - $i];
                $fixtures[] = new Fixture($home, $away, $home === $teamTotal, $away === $teamTotal);
            }
            $rounds[] = $fixtures;
            // Rotation: first team fixed, the others move on.
            $teams = array_merge([$teams[0]], [$teams[$teamTotal - 1]], array_slice($teams, 1, $teamTotal - 2));
        }

        $plan = [];
        for ($number = 1; $number <= $matchdays; $number++) {
            $plan[] = new ScheduleMatchday($number, $rounds[($number - 1) % count($rounds)]);
        }

        return $plan;
    }
}
