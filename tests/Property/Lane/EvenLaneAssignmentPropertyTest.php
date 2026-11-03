<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Lane;

use Bowlingliga\Domain\Lane\Fixture;
use Bowlingliga\Domain\Lane\LaneAssignmentService;
use Bowlingliga\Domain\Lane\ScheduleMatchday;
use Bowlingliga\Tests\GermanText;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the evenly distributed, conflict-free lane assignment.
 *
 * Feature: bowlingliga-website, Property 19: Evenly distributed lane assignment
 *
 * Validates: R18.1, R18.2, R18.3, R18.9, R18.10
 *
 * Domain background: the {@see LaneAssignmentService} assigns exactly one
 * lane pair to every fixture of every matchday (R18.1), uses every
 * lane pair at most once per matchday (R18.3) and distributes a
 * team's matchdays as evenly as possible over the lane pairs (requirement
 * 18.2): if there is a valid assignment with a spread (max−min of the lane
 * pair usage, lane pairs never used count as 0) of at most one matchday per
 * team, such an assignment is chosen; otherwise an assignment with the
 * smallest possible maximum spread, together with a warning (requirement
 * 18.9) and a deviation overview per affected team (R18.10).
 *
 * The generator produces the family coming from the season planner
 * (R9): one or more complete round robin passes (circle method)
 * over an even number of teams (2, 4, 6, 8) with lane count = number of teams,
 * so that all lane pairs are taken on every matchday (lane pairs = number of
 * teams / 2 = fixtures per matchday).
 *
 * Feasibility of spread ≤ 1: for 4 teams over 2 lane pairs, reachability
 * depends on the number of complete round robin passes. Every team plays 3
 * matchdays per pass; with an odd number of passes the total number of
 * matchdays per team is odd, so it cannot be spread over two lane pairs with
 * spread ≤ 1 – the smallest possible maximum spread is then 3 (a known,
 * proven result, since a team would have to play all 3 matchdays of a pass on
 * the same lane pair). With an even number of passes spread ≤ 1 (even 0) is
 * reachable. For 2, 6 and 8 teams spread ≤ 1 is always reachable. The test
 * therefore checks case by case: where spread ≤ 1 is reachable, exactly that
 * without warning; otherwise (4 teams, odd number of passes) a success with
 * warning with the smallest possible maximum spread 3 and a non-empty
 * deviation overview.
 */
final class EvenLaneAssignmentPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * For 4 teams over 2 lane pairs no spread ≤ 1 is reachable with an odd
     * number of complete round robin passes; the smallest possible maximum
     * spread is then 3 (known, proven result). For 2, 6 and 8 teams and for 4
     * teams with an even number of passes spread ≤ 1 is reachable.
     */
    private const MINIMUM_SPREAD_FOUR_TEAMS_ODD = 3;

    /**
     * Property 19: for every round robin schedule over an even number of teams
     * the lane assignment returns a successful result in which (a) every
     * fixture of every matchday gets exactly one lane pair (R18.1), (b) the
     * assigned lane pairs differ pairwise per matchday (R18.3) and (c) per team
     * the spread max−min of the lane pair usage over all lane pairs is as small
     * as possible: at most 1 where reachable (R18.2, first case), otherwise the
     * smallest possible maximum spread with warning and deviation overview
     * (R18.2 second case, R18.9, R18.10).
     *
     * Feature: bowlingliga-website, Property 19: Evenly distributed lane assignment
     *
     * Validates: R18.1, R18.2, R18.3, R18.9, R18.10
     *
     * At least 100 inputs are generated (limitTo(100)). The input range covers
     * 2 to 8 teams (even, ⇒ lane count 2..8) and 1 to 4 complete round robin
     * passes.
     */
    public function testEvenAndConflictFreeLaneAssignment(): void
    {
        $service = new LaneAssignmentService();

        $this
            ->limitTo(100)
            ->forAll(
                Generators::choose(1, 4), // Anzahl halber Teamanzahl → Teams = 2 * dieser Wert (2..8)
                Generators::choose(1, 4), // Number of complete round robin passes
            )
            ->then(function (int $halfTeamCount, int $runs) use ($service): void {
                $teamTotal = 2 * $halfTeamCount;
                $laneCount = $teamTotal; // lane pairs = number of teams / 2 = fixtures per matchday
                $lanePairCount = intdiv($laneCount, 2);

                $schedule = $this->buildRoundRobinSchedule($teamTotal, $runs);

                $totalFixtures = 0;
                foreach ($schedule as $matchday) {
                    $totalFixtures += $matchday->fixtureCount();
                }

                $result = $service->assignLanes($schedule, $laneCount);

                self::assertTrue(
                    $result->isSuccessful(),
                    sprintf(
                        'Für einen gültigen Round-Robin-Spielplan (%d Teams, %d Durchläufe, Bahnanzahl %d) muss die Bahnzuordnung erfolgreich sein.',
                        $teamTotal,
                        $runs,
                        $laneCount
                    )
                );

                // ----- R18.1: exactly one lane pair per fixture of every matchday. -----
                self::assertCount(
                    $totalFixtures,
                    $result->assignments,
                    'Every fixture of every matchday must get exactly one lane pair (R18.1).'
                );

                // ----- R18.3: pairwise different lane pairs per matchday. -----
                /** @var array<int, list<int>> $lanePairsPerMatchday [matchdayNumber] => Liste LanePair-Indizes */
                $lanePairsPerMatchday = [];
                /** @var array<int, array<int, int>> $usage [teamId][lanePairIndex] => Anzahl Spieltage */
                $usage = [];

                foreach ($result->assignments as $assignment) {
                    $lanePairIndex = $assignment->lanePair->index;

                    self::assertGreaterThanOrEqual(
                        1,
                        $lanePairIndex,
                        'The lane pair index must be at least 1 (R18.1).'
                    );
                    self::assertLessThanOrEqual(
                        $lanePairCount,
                        $lanePairIndex,
                        'The lane pair index must not exceed the number of available lane pairs (R18.1).'
                    );

                    $lanePairsPerMatchday[$assignment->matchdayNumber][] = $lanePairIndex;

                    foreach ($assignment->fixture->teamIds() as $teamId) {
                        $usage[$teamId][$lanePairIndex] = ($usage[$teamId][$lanePairIndex] ?? 0) + 1;
                    }
                }

                foreach ($lanePairsPerMatchday as $matchdayNumber => $indices) {
                    self::assertSame(
                        count($indices),
                        count(array_unique($indices)),
                        sprintf(
                            'On matchday %d every lane pair must belong to at most one fixture (R18.3).',
                            $matchdayNumber
                        )
                    );
                }

                // ----- Calculate the spread per team (max−min over ALL lane pairs). -----
                /** @var array<int, int> $spreadPerTeam */
                $spreadPerTeam = [];
                foreach ($usage as $teamId => $perLanePair) {
                    $maximum = 0;
                    $minimum = null;
                    for ($lanePair = 1; $lanePair <= $lanePairCount; $lanePair++) {
                        $value = $perLanePair[$lanePair] ?? 0; // lane pairs never used count as 0
                        if ($value > $maximum) {
                            $maximum = $value;
                        }
                        if ($minimum === null || $value < $minimum) {
                            $minimum = $value;
                        }
                    }
                    $spreadPerTeam[$teamId] = $maximum - ($minimum ?? 0);
                }

                $maximumSpread = $spreadPerTeam === [] ? 0 : max($spreadPerTeam);

                // For 4 teams spread ≤ 1 is only reachable with an even number of passes
                // (3 matchdays per team per pass; an odd sum
                // cannot be spread over 2 lane pairs with spread ≤ 1).
                $spreadOneUnreachable = $teamTotal === 4 && ($runs % 2 === 1);

                if ($spreadOneUnreachable) {
                    // ----- R18.2 (second case) / R18.9 / R18.10: spread ≤ 1
                    // is not reachable here; the smallest possible maximum
                    // spread is 3. The assignment is still returned,
                    // with warning and deviation overview.
                    self::assertTrue(
                        $result->hasWarning(),
                        sprintf(
                            'Bei 4 Teams und %d (ungeraden) Durchläufen ist keine Spannweite ≤ 1 erreichbar; das Ergebnis muss eine Warnung tragen (R18.9).',
                            $runs
                        )
                    );
                    self::assertNotSame(
                        '',
                        GermanText::of($result->warning),
                        'The warning must point out the not fully even distribution (R18.9).'
                    );

                    self::assertSame(
                        self::MINIMUM_SPREAD_FOUR_TEAMS_ODD,
                        $maximumSpread,
                        'With 4 teams and an odd number of rounds the assignment must reach the smallest possible maximum spread (3) (R18.2, second case).'
                    );

                    // R18.10: the deviation overview lists the affected teams
                    // with their usage per lane pair and the spread.
                    self::assertNotEmpty(
                        $result->deviations,
                        'The deviation overview must list the teams concerned (R18.10).'
                    );

                    foreach ($result->deviations as $teamId => $deviation) {
                        self::assertArrayHasKey('usage', $deviation, 'Every deviation must show the use per lane pair (R18.10).');
                        self::assertArrayHasKey('spread', $deviation, 'Every deviation must show the spread (R18.10).');
                        self::assertGreaterThan(
                            1,
                            $deviation['spread'],
                            'The deviation overview lists only teams with a spread > 1 (R18.10).'
                        );
                        self::assertCount(
                            $lanePairCount,
                            $deviation['usage'],
                            'The use shown must cover all lane pairs (R18.10).'
                        );
                        // The spread shown must match the usage shown.
                        $values = array_values($deviation['usage']);
                        self::assertSame(
                            max($values) - min($values),
                            $deviation['spread'],
                            'The spread shown must match the use per lane pair (R18.10).'
                        );
                        // Consistency with the spread actually observed.
                        self::assertSame(
                            $spreadPerTeam[$teamId] ?? null,
                            $deviation['spread'],
                            'The spread shown must match the one actually assigned (R18.10).'
                        );
                    }
                } else {
                    // ----- R18.2 (first case): spread ≤ 1 per team, without warning. -----
                    foreach ($spreadPerTeam as $teamId => $spread) {
                        self::assertLessThanOrEqual(
                            1,
                            $spread,
                            sprintf(
                                'Team %d: the spread of the lane pair use (max−min) must be at most 1 (R18.2).',
                                $teamId
                            )
                        );
                    }

                    self::assertFalse(
                        $result->hasWarning(),
                        sprintf(
                            'Bei %d Teams ist Spannweite ≤ 1 erreichbar; das Ergebnis darf keine Warnung tragen (R18.2, erster Fall).',
                            $teamTotal
                        )
                    );
                    self::assertSame(
                        [],
                        $result->deviations,
                        'Without a warning there must be no deviation overview (R18.2, first case).'
                    );
                }
            });
    }

    /**
     * Generates a round robin schedule over an even number of teams using the
     * circle method. A complete pass consists of (number of teams − 1)
     * matchdays with (number of teams / 2) fixtures each; every team plays
     * exactly once on every matchday and no team twice. Several passes repeat
     * the procedure, so that the fixtures repeat over the season according to
     * R9.11.
     *
     * Team ids are 0 .. number of teams − 1.
     *
     * @return list<ScheduleMatchday>
     */
    private function buildRoundRobinSchedule(int $teamTotal, int $runs): array
    {
        /** @var list<ScheduleMatchday> $schedule */
        $schedule = [];
        $matchdayNumber = 1;

        for ($pass = 0; $pass < $runs; $pass++) {
            // Fixed circle method: team 0 stays fixed, the others rotate.
            $rotating = range(1, $teamTotal - 1);

            for ($round = 0; $round < $teamTotal - 1; $round++) {
                $sequence = array_merge([0], $rotating);

                /** @var list<Fixture> $fixtures */
                $fixtures = [];
                for ($i = 0; $i < intdiv($teamTotal, 2); $i++) {
                    $home = $sequence[$i];
                    $away = $sequence[$teamTotal - 1 - $i];
                    $fixtures[] = new Fixture($home, $away);
                }

                $schedule[] = new ScheduleMatchday($matchdayNumber, $fixtures);
                $matchdayNumber++;

                // Rotation: last element to the front (team 0 stays fixed).
                $lastItem = array_pop($rotating);
                array_unshift($rotating, $lastItem);
            }
        }

        return $schedule;
    }
}
