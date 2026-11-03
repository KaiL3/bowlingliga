<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\SeasonPlanner;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\SeasonPlanner\SeasonLength;
use Bowlingliga\Domain\SeasonPlanner\SeasonPlanner;
use Bowlingliga\Domain\SeasonPlanner\Schedule;
use Bowlingliga\Domain\SeasonPlanner\Team;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the structural correctness of the generated schedule in
 * round robin mode with an even number of teams.
 *
 * Feature: bowlingliga-website, Property 17: Schedule structure (round robin)
 *
 * Validates: R9.1, R9.2, R9.11
 *
 * Domain background: the season planner generates the schedule over one or
 * more complete round robin rounds (R9.11). With an even number of teams n a
 * complete round comprises exactly n − 1 matchdays in which every pair of
 * teams meets exactly once (R9.1); over k complete rounds every pair of teams
 * therefore meets exactly k times (R9.1, R9.11). No team is scheduled more
 * than once on any matchday (R9.2).
 *
 * An even number of teams (2..8) is tested, so that the season planner adds
 * no ghost team (the odd case is the subject of a separate test). The season
 * length is always chosen as an exact multiple of the round length (n − 1),
 * so that the fixtures spread evenly over complete rounds and no
 * distribution warning (R9.12) arises.
 */
final class ScheduleStructurePropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 17 (schedule structure): for an even number of teams and a
     * season length that is an exact multiple of the round length (n − 1), the
     * season planner generates a schedule in which
     *   - no team is scheduled more than once on any matchday (R9.2),
     *   - every pair of teams meets exactly k times over k complete rounds
     *     (R9.1, R9.11),
     *   - the total number of matchdays equals the defined season length.
     *
     * Feature: bowlingliga-website, Property 17: Schedule structure (round robin)
     *
     * Validates: R9.1, R9.2, R9.11
     *
     * At least 100 inputs are generated (limitTo(100)). The input range covers
     * even numbers of teams from 2 to 8 and 1 to 6 complete rounds.
     */
    public function testScheduleStructureRoundRobin(): void
    {
        $planner = new SeasonPlanner();

        $this
            ->limitTo(100)
            ->forAll(
                // Gerade Teamzahl 2..8: pairCount 1..4 → n = 2 * pairCount.
                Generators::choose(1, 4),
                // Number of complete rounds 1..6.
                Generators::choose(1, 6)
            )
            ->then(function (int $pairCount, int $rounds) use ($planner): void {
                $numberOfTeams = 2 * $pairCount;
                $roundLength = $numberOfTeams - 1;
                $matchdayCount = $rounds * $roundLength;

                $teams = $this->generateTeams($numberOfTeams);

                $result = $planner->generateSchedule(
                    $teams,
                    SeasonLength::fromMatchdayCount($matchdayCount),
                    0, // without positiondays: pure round robin (special days: SpecialDaysPropertyTest)
                );

                // With an even number of teams and an exact multiple of the round length
                // planning succeeds without a distribution warning (R9.1, R9.11, R9.12).
                self::assertTrue(
                    $result->successful,
                    sprintf('Spielplan muss erzeugt werden (n=%d, Spieltage=%d).', $numberOfTeams, $matchdayCount)
                );
                self::assertFalse(
                    $result->hasWarnings(),
                    sprintf(
                        'Ein Vielfaches der Rundenlänge darf keine Verteilungswarnung ergeben (n=%d, Spieltage=%d).',
                        $numberOfTeams,
                        $matchdayCount
                    )
                );

                $schedule = $result->schedule;
                self::assertInstanceOf(Schedule::class, $schedule);

                // Even number of teams → no ghost team (R9.8).
                self::assertFalse(
                    $schedule->containsGhostTeam,
                    'With an even number of teams no ghost team may be added.'
                );

                // The total number of matchdays equals the defined length (R9.10, R9.11).
                self::assertSame(
                    $matchdayCount,
                    $schedule->matchdayCount(),
                    'The number of matchdays must equal the set season length.'
                );

                // R9.2: no team is scheduled more than once on any matchday.
                // At the same time we count the encounters per pair of teams across the plan.
                $encounters = [];
                foreach ($schedule->matchdays as $matchday) {
                    $seen = [];
                    foreach ($matchday->fixtures as $fixture) {
                        foreach ([$fixture->home->id, $fixture->away->id] as $teamId) {
                            self::assertArrayNotHasKey(
                                $teamId,
                                $seen,
                                sprintf(
                                    'Team %s is scheduled more than once on matchday %d (R9.2).',
                                    $teamId,
                                    $matchday->number
                                )
                            );
                            $seen[$teamId] = true;
                        }

                        $key = $this->pairKey($fixture->home->id, $fixture->away->id);
                        $encounters[$key] = ($encounters[$key] ?? 0) + 1;
                    }
                }

                // R9.1, R9.11: every possible pair of teams meets exactly k times over k
                // complete rounds.
                $expectedPairs = $this->allPairs($teams);
                self::assertCount(
                    count($expectedPairs),
                    $encounters,
                    sprintf(
                        'Exactly %d different team pairs must occur (n=%d).',
                        count($expectedPairs),
                        $numberOfTeams
                    )
                );

                foreach ($expectedPairs as $key) {
                    self::assertSame(
                        $rounds,
                        $encounters[$key] ?? 0,
                        sprintf(
                            'Team pair %s must occur exactly %d times over %d complete rounds (R9.1, R9.11).',
                            $key,
                            $rounds,
                            $rounds
                        )
                    );
                }
            });
    }

    /**
     * Creates a list of real teams with stable, unique ids.
     *
     * @return list<Team>
     */
    private function generateTeams(int $quantity): array
    {
        $teams = [];
        for ($i = 1; $i <= $quantity; $i++) {
            $teams[] = new Team('team-' . $i, 'Team ' . $i);
        }

        return $teams;
    }

    /**
     * Order-independent key of an unordered pair of teams.
     */
    private function pairKey(string $a, string $b): string
    {
        return $a < $b ? $a . '|' . $b : $b . '|' . $a;
    }

    /**
     * Determines all unordered pairs of the given teams as order-independent
     * keys.
     *
     * @param list<Team> $teams
     *
     * @return list<string>
     */
    private function allPairs(array $teams): array
    {
        $pairs = [];
        $quantity = count($teams);
        for ($i = 0; $i < $quantity; $i++) {
            for ($j = $i + 1; $j < $quantity; $j++) {
                $pairs[] = $this->pairKey($teams[$i]->id, $teams[$j]->id);
            }
        }

        return $pairs;
    }
}
