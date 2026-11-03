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
 * Property test for adding and evenly assigning the ghost team in the season
 * planning.
 *
 * Feature: bowlingliga-website, Property 18: Ghost team in the season planning
 *
 * Validates: R9.6, R9.7, R9.8
 *
 * Domain background: with an odd number of real teams the season planner
 * automatically adds a ghost team, so that the total is even (requirement
 * 9.6), and adds no ghost team for an even number (R9.8). If a
 * season contains a ghost team, the planner assigns exactly one real team to
 * the ghost team on every matchday and assigns every real team to the ghost
 * team equally often across the season (R9.7).
 *
 * The pure calculation service
 * {@see \Bowlingliga\Domain\SeasonPlanner\SeasonPlanner::generateSchedule()}
 * is tested.
 */
final class GhostTeamPlanningPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 18 (odd number of teams): with an odd number of real teams the
     * season planner adds a ghost team (R9.6); over k complete rounds every
     * real team meets the ghost team exactly k times, i.e. all real teams
     * equally often, and on no matchday does more than one fixture contain the
     * ghost team (R9.7).
     *
     * Feature: bowlingliga-website, Property 18: Ghost team in the season planning
     *
     * Validates: R9.6, R9.7
     *
     * At least 100 inputs are generated (limitTo(100)). Odd numbers of real
     * teams (3, 5, 7) and season lengths as integer multiples of the round
     * length are generated, so that the rounds are complete.
     */
    public function testOddTeamCountAddsGhostTeamAndDistributesEvenly(): void
    {
        $planner = new SeasonPlanner();

        $this
            ->limitTo(100)
            ->forAll(
                Generators::elements(3, 5, 7),
                Generators::choose(1, 4)
            )
            ->then(function (int $realTeamCount, int $rounds) use ($planner): void {
                $teams = $this->realTeams($realTeamCount);

                // With an odd number of real teams the total including the
                // ghost team is even; the round length is total − 1.
                $totalCount = $realTeamCount + 1;
                $roundLength = $totalCount - 1;
                $matchdayCount = $roundLength * $rounds;

                $result = $planner->generateSchedule(
                    $teams,
                    SeasonLength::fromMatchdayCount($matchdayCount),
                    0, // without positiondays: pure round robin (special days: SpecialDaysPropertyTest)
                );

                self::assertTrue($result->successful, 'A plan must be created for >= 2 teams.');
                $schedule = $result->schedule;
                self::assertInstanceOf(Schedule::class, $schedule);

                // R9.6: with an odd number of real teams a ghost team is added.
                self::assertTrue(
                    $schedule->containsGhostTeam,
                    sprintf('Bei %d realen Teams (ungerade) muss ein Geisterteam ergänzt werden (R9.6).', $realTeamCount)
                );

                // R9.7: on every matchday at most one fixture contains the
                // ghost team.
                foreach ($schedule->matchdays as $matchday) {
                    $withGhostTeam = 0;
                    foreach ($matchday->fixtures as $fixture) {
                        if ($fixture->containsGhostTeam()) {
                            $withGhostTeam++;
                        }
                    }

                    self::assertLessThanOrEqual(
                        1,
                        $withGhostTeam,
                        sprintf(
                            'On matchday %d at most one fixture may contain the ghost team (R9.7), found: %d.',
                            $matchday->number,
                            $withGhostTeam
                        )
                    );
                }

                // R9.7: over k complete rounds every real team meets the ghost team
                // exactly k times; all real teams equally often.
                $assignments = $this->countGhostTeamAssignmentsPerRealTeam($schedule, $teams);

                foreach ($teams as $team) {
                    self::assertSame(
                        $rounds,
                        $assignments[$team->id],
                        sprintf(
                            'Real team %s must play the ghost team exactly %d times over %d round(s) (R9.7), found: %d.',
                            $team->id,
                            $rounds,
                            $rounds,
                            $assignments[$team->id]
                        )
                    );
                }

                // Balance across all real teams: identical count.
                $values = array_values($assignments);
                self::assertSame(
                    [$rounds],
                    array_values(array_unique($values)),
                    'Every real team must play the ghost team equally often (R9.7).'
                );
            });
    }

    /**
     * Property 18 (even number of teams): with an even number of real teams the
     * season planner adds no ghost team, and no fixture of the plan contains a
     * ghost team (R9.8).
     *
     * Feature: bowlingliga-website, Property 18: Ghost team in the season planning
     *
     * Validates: R9.8
     *
     * At least 100 inputs are generated (limitTo(100)). Even numbers of real
     * teams (2, 4, 6) and complete rounds are generated.
     */
    public function testEvenTeamCountAddsNoGhostTeam(): void
    {
        $planner = new SeasonPlanner();

        $this
            ->limitTo(100)
            ->forAll(
                Generators::elements(2, 4, 6),
                Generators::choose(1, 4)
            )
            ->then(function (int $realTeamCount, int $rounds) use ($planner): void {
                $teams = $this->realTeams($realTeamCount);

                $roundLength = $realTeamCount - 1;
                $matchdayCount = $roundLength * $rounds;

                $result = $planner->generateSchedule(
                    $teams,
                    SeasonLength::fromMatchdayCount($matchdayCount),
                    0, // without positiondays: pure round robin (special days: SpecialDaysPropertyTest)
                );

                self::assertTrue($result->successful, 'A plan must be created for >= 2 teams.');
                $schedule = $result->schedule;
                self::assertInstanceOf(Schedule::class, $schedule);

                // R9.8: with an even number of real teams no ghost team is added.
                self::assertFalse(
                    $schedule->containsGhostTeam,
                    sprintf('Bei %d realen Teams (gerade) darf kein Geisterteam ergänzt werden (R9.8).', $realTeamCount)
                );

                // R9.8: no fixture of the plan contains a ghost team.
                foreach ($schedule->matchdays as $matchday) {
                    foreach ($matchday->fixtures as $fixture) {
                        self::assertFalse(
                            $fixture->containsGhostTeam(),
                            sprintf('An Spieltag %d darf keine Paarung ein Geisterteam enthalten (R9.8).', $matchday->number)
                        );
                    }
                }
            });
    }

    /**
     * Creates a list of n real teams with stable ids and names.
     *
     * @return list<Team>
     */
    private function realTeams(int $quantity): array
    {
        $teams = [];
        for ($i = 1; $i <= $quantity; $i++) {
            $teams[] = new Team('team-' . $i, 'Team ' . $i);
        }

        return $teams;
    }

    /**
     * Counts per real team how often it is assigned to the ghost team in a
     * fixture across the whole schedule (R9.7).
     *
     * @param list<Team> $realTeams
     *
     * @return array<string, int> id of the real team => number of assignments
     *                            to the ghost team.
     */
    private function countGhostTeamAssignmentsPerRealTeam(Schedule $schedule, array $realTeams): array
    {
        $assignments = [];
        foreach ($realTeams as $team) {
            $assignments[$team->id] = 0;
        }

        foreach ($schedule->matchdays as $matchday) {
            foreach ($matchday->fixtures as $fixture) {
                if (!$fixture->containsGhostTeam()) {
                    continue;
                }

                $realTeam = $fixture->home->isGhostTeam ? $fixture->away : $fixture->home;
                self::assertArrayHasKey(
                    $realTeam->id,
                    $assignments,
                    'The team assigned to the ghost team must be a real input team.'
                );
                $assignments[$realTeam->id]++;
            }
        }

        return $assignments;
    }
}
