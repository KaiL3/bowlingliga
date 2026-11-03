<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\SeasonPlanner;

use Bowlingliga\Domain\Message;

/**
 * Pure calculation service for automatically generating a season's schedule
 * in round robin mode with balanced fixture distribution (R9).
 *
 * The service is free of side effects: it neither accesses the database nor
 * persists anything, but receives the participating real teams and the
 * defined season length and generates a {@see Schedule} from them. Persisting
 * (R9.3, R9.4) and the lane assignment (R9.9, R18) are up to later layers.
 * This makes the generation deterministic and reproducible (R35.2) and fully
 * verifiable by property tests.
 *
 * Algorithm (circle/round method, deterministic):
 * With an even number of teams n, one complete pass ("round") forms exactly
 * n − 1 matchdays with n / 2 fixtures each, in which every pair of teams meets
 * exactly once (R9.1, R9.11). One team stays fixed, the others rotate; this
 * way no team is scheduled more than once on any matchday (R9.2). With an odd
 * number of real teams a ghost team is added first so that the total is even
 * (R9.6, R9.8); every real team then meets the ghost team exactly once per
 * complete round, i.e. equally often over several complete rounds (R9.7).
 *
 * As many matchdays are generated as the defined season length specifies
 * (R9.10). If the length is a multiple of the round length (n − 1), the
 * fixtures spread evenly over complete rounds and every pair of teams meets
 * equally often (R9.11). Otherwise the distribution stays uneven; the service
 * still generates the plan but marks the result with a warning whose
 * application requires explicit confirmation by the administrator (R9.12).
 *
 * Every tenth matchday (10, 20, 30, …; interval configurable per season, types adjustable per matchday) is automatically marked as positionday
 * according to R31 (R9.13).
 *
 * Error cases: if fewer than 2 real teams take part, no plan can be
 * generated; the result names the cause and no plan is saved (R9.5).
 */
final class SeasonPlanner
{
    /**
     * Generates a season's schedule from the participating real teams and the
     * defined season length (R9.1, R9.2, R9.5 to R9.13).
     *
     * Expects only real teams as input; any ghost team is added by the service
     * itself for an odd number of teams (R9.6). The result encapsulates either
     * the generated plan (possibly with a distribution warning, R9.12) or – in
     * the error case – the named cause without a plan (R9.5).
     *
     * @param list<Team>   $teams        The participating real teams (R9.1).
     *                                   No ghost teams are expected.
     * @param SeasonLength $seasonLength The defined length of the season by
     *                                   matchday count or end date (R9.10).
     * @param int          $positiondayInterval Every n-th matchday is a
     *                                   positionday (default 10, 0 = none).
     * @param array<int, string> $types Types chosen by the administrator per
     *                                   matchday number; numbers without an
     *                                   entry follow the interval.
     */
    public function generateSchedule(
        array $teams,
        SeasonLength $seasonLength,
        int $positiondayInterval = Matchday::DEFAULT_POSITIONDAY_INTERVAL,
        array $types = [],
    ): ScheduleResult
    {
        $realTeams = array_values($teams);

        // R9.5: fewer than 2 teams give no schedule.
        if (count($realTeams) < 2) {
            return ScheduleResult::error(new Message('planning.error.too_few_teams', ['count' => count($realTeams)]));
        }

        // R9.6, R9.8: with an odd number of real teams add a ghost team,
        // with an even number add none.
        $containsGhostTeam = (count($realTeams) % 2) === 1;
        $planningTeams = $realTeams;
        if ($containsGhostTeam) {
            $planningTeams[] = Team::ghostTeam();
        }

        $numberOfTeams = count($planningTeams);
        $roundLength = $numberOfTeams - 1;

        // R9.11: a round grid (n − 1 matchdays) is repeated cyclically over the
        // defined season length.
        $roundGrid = $this->generateRoundGrid($planningTeams);

        foreach ($types as $number => $type) {
            if (! in_array($type, Matchday::TYPES, true)) {
                return ScheduleResult::error(new Message('planning.error.unknown_type', ['number' => $number]));
            }
        }

        // R9.13: every n-th matchday (default: tenth) is a positionday unless the
        // administrator chose a type. The round robin only runs over the
        // regular matchdays, so special days do not swallow fixtures.
        $matchdays = [];
        $regularDays = 0;
        for ($number = 1; $number <= $seasonLength->matchdayCount; $number++) {
            $type = $types[$number] ?? Matchday::typeForNumber($number, $positiondayInterval);
            if ($type === Matchday::TYPE_REGULAR) {
                $matchdays[] = new Matchday($number, $roundGrid[$regularDays % $roundLength], $type);
                $regularDays++;
            } else {
                $matchdays[] = new Matchday($number, [], $type);
            }
        }

        $schedule = new Schedule($matchdays, $containsGhostTeam);

        // R9.12: if the regular matchdays are not a multiple of the round
        // length, the fixtures spread unevenly; the result gets a warning and
        // requires explicit confirmation by the administrator.
        if (($regularDays % $roundLength) !== 0) {
            return ScheduleResult::successWithWarning(
                $schedule,
                [$this->distributionWarning($regularDays, $roundLength)]
            );
        }

        return ScheduleResult::success($schedule);
    }

    /**
     * Generates the round grid of a complete round robin round using the
     * circle method (R9.1, R9.2, R9.11).
     *
     * The first team stays fixed, the others rotate clockwise. For n teams this
     * gives n − 1 matchdays with n / 2 fixtures each, in which every pair of
     * teams meets exactly once (R9.1) and no team is scheduled more than once
     * on any matchday (R9.2). The method is deterministic and always returns
     * the same grid for identical input.
     *
     * @param list<Team> $teams Even number of teams (including any ghost team).
     *
     * @return list<list<Fixture>> Per grid index the fixtures of one matchday of
     *                             the round.
     */
    private function generateRoundGrid(array $teams): array
    {
        $numberOfTeams = count($teams);
        $half = intdiv($numberOfTeams, 2);
        $roundLength = $numberOfTeams - 1;

        // Rotation list without the fixed first team.
        $rotating = array_slice($teams, 1);
        $fixed = $teams[0];

        $grid = [];
        for ($round = 0; $round < $roundLength; $round++) {
            $order = array_merge([$fixed], $rotating);

            $fixtures = [];
            for ($i = 0; $i < $half; $i++) {
                $fixtures[] = new Fixture(
                    $order[$i],
                    $order[$numberOfTeams - 1 - $i]
                );
            }

            $grid[] = $fixtures;

            // Rotation: last rotating team to the front (circle method).
            $lastItem = array_pop($rotating);
            array_unshift($rotating, $lastItem);
        }

        return $grid;
    }

    /**
     * Phrases the warning for an uneven round robin distribution when the
     * defined season length does not give complete rounds (R9.12).
     */
    private function distributionWarning(int $regularDays, int $roundLength): Message
    {
        return new Message('planning.warning.uneven', ['regular' => $regularDays, 'round' => $roundLength]);
    }
}
