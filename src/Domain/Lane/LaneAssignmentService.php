<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Lane;

use Bowlingliga\Domain\Message;

/**
 * Pure calculation service for the automatic lane assignment in alternating
 * lane mode (R18).
 *
 * All methods are side-effect-free and deterministic: they calculate solely
 * from the inputs passed and change no state; there is no database access and
 * neither time nor randomness is used. Persisting (R18.4) is up to
 * the calling use case.
 *
 * Domain invariants of the assignment:
 * - R18.1: every fixture of every matchday is assigned exactly one
 *   lane pair of two adjacent lanes.
 * - R18.3: on a matchday every lane pair is assigned to at most one
 *   fixture.
 * - R18.2: the assignment is distributed as evenly as possible. If
 *   there is a valid assignment in which the number of matchdays a team plays
 *   on a given lane pair differs between the lane pairs (lane pairs never used
 *   count as 0) by at most one matchday, such an assignment is chosen;
 *   otherwise an assignment with the smallest possible maximum spread across
 *   all teams.
 * - R18.9, R18.10: if a spread ≤ 1 is not reachable for every team,
 *   the assignment is still calculated and returned with a warning and a
 *   deviation overview per affected team.
 * - R18.7: matchdays whose fixtures are only fixed later
 *   (positionday, R31) are assigned by the same principle as soon
 *   as their fixtures are part of the schedule passed in.
 * - R18.8: a fixture of a real team and the ghost team takes a
 *   regular lane pair like any other fixture; the ghost team enters the even
 *   distribution like a real team.
 *
 * Error cases return no assignment but an error message (R18.5,
 * 18.6); no partial assignment is produced on purpose, so that the caller
 * saves nothing.
 *
 * Procedure for the even distribution (R18.2), in two stages:
 *
 * 1. Heuristic: the fixtures are placed greedily, matchday by matchday, on
 *    the lane pair with the lowest usage so far; then each matchday is
 *    improved by moving to free lane pairs and swapping two fixtures as long
 *    as the sum of squared usages decreases. This is polynomial and reaches
 *    spread ≤ 1 in practice; then the result is already optimal.
 * 2. Only if the heuristic yields spread > 1 does the exact search (below)
 *    try a smaller spread – with a limited node budget per stage. If the
 *    budget is exhausted before a stage is proven impossible, the best result
 *    so far applies; the warning then points out that no more even
 *    distribution was found.
 *
 * Exact search: deterministic depth-first search with backtracking and
 * iterative deepening over the allowed spread. The matchdays are processed in
 * a fixed order; per matchday the fixtures are placed in a fixed order on
 * pairwise different lane pairs (R18.1, R18.3), trying the lane pairs
 * in ascending order.
 *
 * First the search runs with an allowed spread of 1 (first case of
 * R18.2): each team's usage per lane pair is capped at
 * ⌊games/lane pairs⌋ + 1 (pruning), and a complete occupancy is only accepted
 * if the actual per-team spread is ≤ 1. If this search finds no solution, the
 * allowed spread is increased step by step until the smallest possible
 * maximum spread across all teams is reached (second case of requirement
 * 18.2). Because a stage is only entered after all smaller spreads have been
 * proven impossible, the spread found is guaranteed to be the smallest
 * possible.
 *
 * If the spread is > 1, the assignment is still returned, together with a
 * warning (R18.9) and a deviation overview per affected team
 * (R18.10). Since the search uses a fixed lane pair, matchday and
 * fixture order and uses neither time nor randomness, the result is
 * deterministic.
 */
final class LaneAssignmentService
{
    /**
     * Node budget of the exact search per spread stage. The search grows
     * exponentially with more lane pairs than fixtures per matchday (e.g.
     * 4 teams on 3 lane pairs over a whole season); the budget keeps the
     * runtime within a few seconds.
     */
    private const MAX_SEARCH_NODES = 300000;

    /**
     * Assigns exactly one lane pair to every fixture of the schedule.
     *
     * @param list<ScheduleMatchday> $schedule  Matchdays with fixed fixtures.
     * @param int                    $laneCount Configured lane count (even, ≥ 2; R8.11).
     *
     * @return LaneAssignmentResult Success (possibly with warning) or failure
     *         with error message (R18.5, R18.6, R18.9).
     */
    public function assignLanes(array $schedule, int $laneCount): LaneAssignmentResult
    {
        // R18.5: no valid lane count → no assignment.
        if ($laneCount < 2 || $laneCount % 2 !== 0) {
            return LaneAssignmentResult::error(new Message('lanes.error.lane_count'));
        }
        // R18.5: no schedule → no assignment.
        if ($schedule === []) {
            return LaneAssignmentResult::error(new Message('lanes.error.no_schedule'));
        }

        $lanePairCount = intdiv($laneCount, 2);

        // R18.6: if the lane pairs are not enough to assign all fixtures of a
        // matchday at the same time, no assignment is produced.
        foreach ($schedule as $matchday) {
            if (!$matchday instanceof ScheduleMatchday) {
                return LaneAssignmentResult::error(new Message('lanes.error.invalid_matchday'));
            }
            if ($matchday->fixtureCount() > $lanePairCount) {
                return LaneAssignmentResult::error(new Message('lanes.error.too_few_pairs', ['number' => $matchday->number]));
            }
        }

        // Number of matchdays per team – basis for the usage caps and
        // the cap of the spread stages to check.
        $gamesPerTeam = $this->gamesPerTeam($schedule);
        $maxMatchdays = $gamesPerTeam === [] ? 0 : max($gamesPerTeam);

        // Stage 1: heuristic (always valid, in practice spread ≤ 1).
        $occupancy = $this->heuristicOccupancy($schedule, $lanePairCount);
        $spread = $this->maximumSpread($schedule, $occupancy, $lanePairCount);
        $proven = true;

        // Stage 2: exact search, only as long as it can improve the heuristic.
        // Each stage is only entered once all smaller ones are proven
        // impossible; the first solution found is therefore the smallest.
        for ($allowedSpread = 1; $allowedSpread < min($spread, max(1, $maxMatchdays) + 1); $allowedSpread++) {
            $exhausted = false;
            $exact = $this->searchOccupancy($schedule, $lanePairCount, $gamesPerTeam, $allowedSpread, $exhausted);

            if ($exact !== null) {
                $occupancy = $exact;
                $spread = $this->maximumSpread($schedule, $exact, $lanePairCount);
                break;
            }
            if ($exhausted) {
                // Not proven: the best result so far stays.
                $proven = false;
                break;
            }
        }

        $assignments = $this->buildAssignments($schedule, $occupancy);

        if ($spread <= 1) {
            // R18.2, first case: best possible even distribution
            // with spread ≤ 1 per team reached.
            return LaneAssignmentResult::success($assignments);
        }

        // R18.2, second case / 18.9 / 18.10: assignment with warning
        // and deviation overview per affected team.
        return LaneAssignmentResult::successWithWarning(
            $assignments,
            $this->buildWarning($spread, $proven),
            $this->deviations($schedule, $occupancy, $lanePairCount)
        );
    }

    /**
     * Heuristic occupancy from several deterministic starting distributions:
     * greedy (lowest usage so far), greedy with a lane pair order rotating per
     * matchday, and purely rotating (fixture i on lane pair i + matchday).
     * Each starting distribution is improved locally until no move or swap
     * lowers the sum of squared usages any more (every improvement strictly
     * lowers this integer sum, so the procedure terminates). The occupancy
     * with the smallest maximum spread is chosen, ties by the smaller sum of
     * squares.
     *
     * @param list<ScheduleMatchday> $schedule
     *
     * @return array<int, array<int, int>> Occupancy [matchdayIndex][fixtureIndex] => lane pair
     */
    private function heuristicOccupancy(array $schedule, int $lanePairCount): array
    {
        $best = null;
        $bestRating = null;

        foreach (['greedy', 'greedy-rotating', 'rotating'] as $start) {
            [$occupancy, $usage] = $this->startOccupancy($schedule, $lanePairCount, $start);

            do {
                $improved = false;
                foreach ($schedule as $matchdayIndex => $matchday) {
                    if ($this->improveMatchday($matchday, $matchdayIndex, $lanePairCount, $occupancy, $usage)) {
                        $improved = true;
                    }
                }
            } while ($improved);

            $sumOfSquares = 0;
            foreach ($usage as $perLanePair) {
                foreach ($perLanePair as $value) {
                    $sumOfSquares += $value * $value;
                }
            }
            $rating = [$this->maximumSpread($schedule, $occupancy, $lanePairCount), $sumOfSquares];

            if ($bestRating === null || $rating < $bestRating) {
                $best = $occupancy;
                $bestRating = $rating;
            }
            if ($rating[0] <= 1) {
                break; // already optimal
            }
        }

        if ($best !== null && $bestRating[0] > 1) {
            $best = $this->randomImprovement($schedule, $lanePairCount, $best);
        }

        return $best ?? [];
    }

    /** Number of steps of the randomised improvement (at most). */
    private const RANDOM_STEPS = 150000;

    /**
     * Improves an occupancy stuck in a local optimum (spread > 1): randomly
     * chosen fixtures move to another lane pair of their matchday (or swap).
     * Equal or better steps are always accepted, worse ones rarely at first
     * and never towards the end. The randomness comes from a linear
     * congruential generator with a fixed seed – the result is reproducible.
     * The best occupancy seen is returned (smallest spread, then smallest sum
     * of squares).
     *
     * @param list<ScheduleMatchday>      $schedule
     * @param array<int, array<int, int>> $occupancy
     *
     * @return array<int, array<int, int>>
     */
    private function randomImprovement(array $schedule, int $lanePairCount, array $occupancy): array
    {
        $usage = $this->usagePerTeam($schedule, $occupancy);
        $tasks = [];
        foreach ($schedule as $matchdayIndex => $matchday) {
            foreach ($matchday->fixtures as $fixtureIndex => $_fixture) {
                $tasks[] = [$matchdayIndex, $fixtureIndex];
            }
        }
        if ($tasks === [] || $lanePairCount < 2) {
            return $occupancy;
        }

        $random = 20260512;
        $next = static function (int $bound) use (&$random): int {
            $random = ($random * 1103515245 + 12345) & 0x7FFFFFFF;

            return $random % $bound;
        };

        $best = $occupancy;
        $bestSpread = $this->maximumSpread($schedule, $occupancy, $lanePairCount);

        for ($step = 0; $step < self::RANDOM_STEPS; $step++) {
            [$matchdayIndex, $i] = $tasks[$next(count($tasks))];
            $matchday = $schedule[$matchdayIndex];
            $from = $occupancy[$matchdayIndex][$i];
            $to = $next($lanePairCount - 1) + 1;
            if ($to >= $from) {
                $to++;
            }

            $j = array_search($to, $occupancy[$matchdayIndex], true);
            $j = $j === false ? null : $j;

            $delta = $this->shiftCost($matchday->fixtures[$i]->teamIds(), $from, $to, $usage);
            if ($j !== null) {
                $delta += $this->shiftCost($matchday->fixtures[$j]->teamIds(), $to, $from, $usage);
            }

            // Worse steps only in the first half and rarely.
            $accept = $delta <= 0
                || ($step < self::RANDOM_STEPS / 2 && $delta <= 4 && $next(100) < 3);
            if (! $accept) {
                continue;
            }

            $this->shift($matchday->fixtures[$i]->teamIds(), $from, $to, $usage);
            $occupancy[$matchdayIndex][$i] = $to;
            if ($j !== null) {
                $this->shift($matchday->fixtures[$j]->teamIds(), $to, $from, $usage);
                $occupancy[$matchdayIndex][$j] = $from;
            }

            if ($step % 64 === 0) {
                $spread = $this->spreadFromUsage($usage, $lanePairCount);
                if ($spread < $bestSpread) {
                    $best = $occupancy;
                    $bestSpread = $spread;
                    if ($spread <= 1) {
                        break;
                    }
                }
            }
        }

        // Also consider the final state.
        if ($this->spreadFromUsage($usage, $lanePairCount) < $bestSpread) {
            $best = $occupancy;
        }

        return $best;
    }

    /**
     * Largest per-team spread from a usage table.
     *
     * @param array<int, array<int, int>> $usage
     */
    private function spreadFromUsage(array $usage, int $lanePairCount): int
    {
        $largest = 0;
        foreach ($usage as $perLanePair) {
            $values = [];
            for ($lanePair = 1; $lanePair <= $lanePairCount; $lanePair++) {
                $values[] = $perLanePair[$lanePair] ?? 0;
            }
            $largest = max($largest, max($values) - min($values));
        }

        return $largest;
    }

    /**
     * Starting distribution for the heuristic.
     *
     * @param list<ScheduleMatchday> $schedule
     * @param 'gierig'|'gierig-rotierend'|'rotierend' $kind
     *
     * @return array{0: array<int, array<int, int>>, 1: array<int, array<int, int>>} Occupancy and usage
     */
    private function startOccupancy(array $schedule, int $lanePairCount, string $kind): array
    {
        $usage = [];
        $occupancy = [];

        foreach ($schedule as $matchdayIndex => $matchday) {
            $offset = $kind === 'greedy' ? 0 : $matchdayIndex % $lanePairCount;
            $order = [];
            for ($k = 0; $k < $lanePairCount; $k++) {
                $order[] = (($k + $offset) % $lanePairCount) + 1;
            }

            foreach ($matchday->fixtures as $fixtureIndex => $fixture) {
                [$teamA, $teamB] = $fixture->teamIds();

                if ($kind === 'rotierend') {
                    $bestOne = $order[0];
                } else {
                    $bestOne = $order[0];
                    $bestCost = PHP_INT_MAX;
                    foreach ($order as $lanePair) {
                        $cost = ($usage[$teamA][$lanePair] ?? 0) + ($usage[$teamB][$lanePair] ?? 0);
                        if ($cost < $bestCost) {
                            $bestCost = $cost;
                            $bestOne = $lanePair;
                        }
                    }
                }

                $occupancy[$matchdayIndex][$fixtureIndex] = $bestOne;
                $order = array_values(array_diff($order, [$bestOne]));
                $usage[$teamA][$bestOne] = ($usage[$teamA][$bestOne] ?? 0) + 1;
                $usage[$teamB][$bestOne] = ($usage[$teamB][$bestOne] ?? 0) + 1;
            }
        }

        return [$occupancy, $usage];
    }

    /**
     * Improves the occupancy of a matchday: a fixture moves to a free lane pair
     * or swaps with the fixture of another lane pair, provided this lowers the
     * sum of squared usages.
     *
     * @param array<int, array<int, int>> $occupancy (by reference)
     * @param array<int, array<int, int>> $usage     (by reference)
     *
     * @return bool true if the occupancy has changed
     */
    private function improveMatchday(ScheduleMatchday $matchday, int $matchdayIndex, int $lanePairCount, array &$occupancy, array &$usage): bool
    {
        $changed = false;

        do {
            $step = false;
            /** @var array<int, int> $fixtureOn [bahnpaar] => fixtureIndex */
            $fixtureOn = array_flip($occupancy[$matchdayIndex]);

            foreach ($matchday->fixtures as $i => $fixture) {
                $from = $occupancy[$matchdayIndex][$i];
                for ($to = 1; $to <= $lanePairCount; $to++) {
                    if ($to === $from) {
                        continue;
                    }

                    $j = $fixtureOn[$to] ?? null;
                    $delta = $this->shiftCost($fixture->teamIds(), $from, $to, $usage);
                    if ($j !== null) {
                        // On the same matchday the teams of both fixtures are different.
                        $delta += $this->shiftCost($matchday->fixtures[$j]->teamIds(), $to, $from, $usage);
                    }

                    if ($delta < 0) {
                        $this->shift($fixture->teamIds(), $from, $to, $usage);
                        $occupancy[$matchdayIndex][$i] = $to;
                        if ($j !== null) {
                            $this->shift($matchday->fixtures[$j]->teamIds(), $to, $from, $usage);
                            $occupancy[$matchdayIndex][$j] = $from;
                        }
                        $step = true;
                        $changed = true;
                        continue 3;
                    }
                }
            }
        } while ($step);

        return $changed;
    }

    /**
     * Change of the sum of squared usages when the teams of a fixture switch
     * from one lane pair to another:
     * (u_to + 1)² − u_to² + (u_from − 1)² − u_from² = 2·(u_to − u_from) + 2.
     *
     * @param array{0: int, 1: int}       $teams
     * @param array<int, array<int, int>> $usage
     */
    private function shiftCost(array $teams, int $from, int $to, array $usage): int
    {
        $delta = 0;
        foreach ($teams as $team) {
            $delta += 2 * (($usage[$team][$to] ?? 0) - ($usage[$team][$from] ?? 0)) + 2;
        }

        return $delta;
    }

    /**
     * @param array{0: int, 1: int}       $teams
     * @param array<int, array<int, int>> $usage (per Referenz)
     */
    private function shift(array $teams, int $from, int $to, array &$usage): void
    {
        foreach ($teams as $team) {
            $usage[$team][$from] = ($usage[$team][$from] ?? 0) - 1;
            $usage[$team][$to] = ($usage[$team][$to] ?? 0) + 1;
        }
    }

    /**
     * Deterministic depth-first search with backtracking that looks for an
     * occupancy in which the per-team spread (max−min of the lane pair usage
     * over all lane pairs, lane pairs never used count as 0) does not exceed
     * the allowed spread. Within a matchday every fixture takes a different
     * lane pair (R18.1, R18.3).
     *
     * Pruning: a team may only take an additional slot on a lane pair if the
     * usage there afterwards does not exceed ⌊games/lane pairs⌋ +
     * allowedSpread. This upper cap admits a final spread within the allowed
     * limit and at the same time cuts off hopeless branches early. Actually
     * keeping the spread (including the lower bound) is checked after the
     * occupancy is complete.
     *
     * @param list<ScheduleMatchday> $schedule
     * @param array<int, int>        $gamesPerTeam [teamId] => number of matchdays
     *
     * @param bool                   $exhausted    becomes true if the node budget
     *        was exhausted – then "no solution" is not proven
     *
     * @return array<int, array<int, int>>|null Occupancy [matchdayIndex][fixtureIndex] => lane pair index
     *         (1-based) or null if no solution was found for the allowed spread.
     */
    private function searchOccupancy(array $schedule, int $lanePairCount, array $gamesPerTeam, int $allowedSpread, bool &$exhausted = false): ?array
    {
        // Flat, deterministic task list (matchday by matchday,
        // fixture by fixture), including the teams involved.
        /** @var list<array{matchday:int, pairing:int, teams:array{0:int,1:int}}> $tasks */
        $tasks = [];
        foreach ($schedule as $matchdayIndex => $matchday) {
            foreach ($matchday->fixtures as $fixtureIndex => $fixture) {
                $tasks[] = [
                    'matchday' => $matchdayIndex,
                    'pairing' => $fixtureIndex,
                    'teams' => $fixture->teamIds(),
                ];
            }
        }

        // Upper bound of the usage per team/lane pair and – only for the search
        // with spread 1 – a lower bound that characterises every complete
        // occupancy with spread ≤ 1. For spread 1, upper bound =
        // ⌈games/lane pairs⌉ and lower bound = upper bound − 1; every occupancy in
        // which each team on each lane pair lies between lower and upper bound
        // is guaranteed to have spread ≤ 1. The lower bound is used during the
        // search for strong, exact pruning (remaining games balance).
        //
        // For larger allowed spreads (only the fallback when spread 1
        // is not reachable) the upper bound ⌊games/lane pairs⌋ +
        // spread together with the final spread check is enough; here
        // the lower bound is omitted because the minimum reachable
        // maximum is not known in advance. These search spaces are small, since only
        // schedules without a spread ≤ 1 solution get here at all.
        /** @var array<int, int> $upperBound */
        $upperBound = [];
        /** @var array<int, int>|null $lowerBound */
        $lowerBound = $allowedSpread === 1 ? [] : null;
        foreach ($gamesPerTeam as $teamId => $games) {
            if ($allowedSpread === 1) {
                $upper = (int) ceil($games / $lanePairCount);
                $upperBound[$teamId] = $upper;
                $lowerBound[$teamId] = max(0, $upper - 1);
            } else {
                $upperBound[$teamId] = intdiv($games, $lanePairCount) + $allowedSpread;
            }
        }

        /** @var array<int, array<int, int>> $usage [teamId][bahnpaar] => Anzahl */
        $usage = [];
        /** @var array<int, array<int, int>> $occupancy [matchdayIndex][fixtureIndex] => LanePair */
        $occupancy = [];
        /** @var array<int, array<int, bool>> $occupiedInMatchday [matchdayIndex][bahnpaar] => true */
        $occupiedInMatchday = [];
        // Remaining games per team (fixtures not yet placed) for the
        // lower bound pruning.
        $remainingGames = $gamesPerTeam;
        $node = 0;

        $solution = $this->place(
            $tasks,
            0,
            $lanePairCount,
            $allowedSpread,
            $upperBound,
            $lowerBound,
            $gamesPerTeam,
            $usage,
            $occupancy,
            $occupiedInMatchday,
            $remainingGames,
            $node
        );

        $exhausted = ! $solution && $node > self::MAX_SEARCH_NODES;

        return $solution ? $occupancy : null;
    }

    /**
     * Recursive backtracking step: places the task at index $index on an
     * allowed free lane pair and calls itself for the next task. The lane
     * pairs are tried in ascending order, which makes the search
     * deterministic.
     *
     * @param list<array{matchday:int, pairing:int, teams:array{0:int,1:int}}> $tasks
     * @param array<int, int>                                                  $upperBound         [teamId] => max usage/lane pair
     * @param array<int, int>                                                  $gamesPerTeam       [teamId] => number of matchdays
     * @param array<int, int>|null                                             $lowerBound         [teamId] => min usage/lane pair or null (no LB pruning)
     * @param array<int, array<int, int>>                                      $usage              (by reference)
     * @param array<int, array<int, int>>                                      $occupancy          (by reference)
     * @param array<int, array<int, bool>>                                     $occupiedInMatchday (by reference)
     * @param array<int, int>                                                  $remainingGames     (by reference)
     * @param int                                                              $node               (by reference)
     */
    private function place(
        array $tasks,
        int $index,
        int $lanePairCount,
        int $allowedSpread,
        array $upperBound,
        ?array $lowerBound,
        array $gamesPerTeam,
        array &$usage,
        array &$occupancy,
        array &$occupiedInMatchday,
        array &$remainingGames,
        int &$node
    ): bool {
        if ($index >= count($tasks)) {
            // Complete occupancy: check the final spread per team.
            return $this->spreadKept($gamesPerTeam, $usage, $lanePairCount, $allowedSpread);
        }

        if (++$node > self::MAX_SEARCH_NODES) {
            return false;
        }

        $task = $tasks[$index];
        $matchdayIndex = $task['matchday'];
        [$teamA, $teamB] = $task['teams'];

        for ($lanePair = 1; $lanePair <= $lanePairCount; $lanePair++) {
            // R18.3: lane pair still free on this matchday?
            if (isset($occupiedInMatchday[$matchdayIndex][$lanePair])) {
                continue;
            }

            $newA = ($usage[$teamA][$lanePair] ?? 0) + 1;
            $newB = ($usage[$teamB][$lanePair] ?? 0) + 1;

            // Pruning: keep the upper bound of the usage per team/lane pair.
            if ($newA > $upperBound[$teamA] || $newB > $upperBound[$teamB]) {
                continue;
            }

            // Belegen.
            $occupancy[$matchdayIndex][$task['pairing']] = $lanePair;
            $occupiedInMatchday[$matchdayIndex][$lanePair] = true;
            $usage[$teamA][$lanePair] = $newA;
            $usage[$teamB][$lanePair] = $newB;
            $remainingGames[$teamA]--;
            $remainingGames[$teamB]--;

            // Lower bound pruning (only for spread 1): a team can only reach its
            // lower bound on every lane pair if the sum of
            // slots still missing up to the lower bound does not exceed the remaining
            // games of the team. Otherwise an occupancy
            // with spread ≤ 1 is impossible in this branch.
            $possible = $lowerBound === null
                || ($this->lowerBoundReachable($teamA, $lanePairCount, $lowerBound, $remainingGames, $usage)
                    && $this->lowerBoundReachable($teamB, $lanePairCount, $lowerBound, $remainingGames, $usage));

            if ($possible && $this->place(
                $tasks,
                $index + 1,
                $lanePairCount,
                $allowedSpread,
                $upperBound,
                $lowerBound,
                $gamesPerTeam,
                $usage,
                $occupancy,
                $occupiedInMatchday,
                $remainingGames,
                $node
            )) {
                return true;
            }

            // Undo (backtracking).
            unset($occupiedInMatchday[$matchdayIndex][$lanePair]);
            $usage[$teamA][$lanePair] = $newA - 1;
            $usage[$teamB][$lanePair] = $newB - 1;
            $remainingGames[$teamA]++;
            $remainingGames[$teamB]++;
            if ($usage[$teamA][$lanePair] === 0) {
                unset($usage[$teamA][$lanePair]);
            }
            if ($usage[$teamB][$lanePair] === 0) {
                unset($usage[$teamB][$lanePair]);
            }
            unset($occupancy[$matchdayIndex][$task['pairing']]);
        }

        return false;
    }

    /**
     * Lower bound pruning: checks whether the team can reach its required
     * minimum usage ({@see $lowerBound}) on every lane pair with the games it
     * has left. The difference up to the lower bound is summed over all lane
     * pairs; if it exceeds the team's remaining games, the branch is hopeless.
     *
     * @param array<int, int>             $lowerBound
     * @param array<int, int>             $remainingGames
     * @param array<int, array<int, int>> $usage
     */
    private function lowerBoundReachable(
        int $teamId,
        int $lanePairCount,
        array $lowerBound,
        array $remainingGames,
        array $usage
    ): bool {
        $required = 0;
        $target = $lowerBound[$teamId];
        for ($lanePair = 1; $lanePair <= $lanePairCount; $lanePair++) {
            $value = $usage[$teamId][$lanePair] ?? 0;
            if ($value < $target) {
                $required += $target - $value;
            }
        }

        return $required <= ($remainingGames[$teamId] ?? 0);
    }

    /**
     * Checks whether every per-team spread (max−min of the usage over ALL lane
     * pairs, lane pairs never used count as 0) keeps the allowed spread.
     *
     * @param array<int, int>             $gamesPerTeam
     * @param array<int, array<int, int>> $usage
     */
    private function spreadKept(
        array $gamesPerTeam,
        array $usage,
        int $lanePairCount,
        int $allowedSpread
    ): bool {
        foreach ($gamesPerTeam as $teamId => $_games) {
            $perLanePair = $usage[$teamId] ?? [];
            $maximum = 0;
            $minimum = null;
            for ($lanePair = 1; $lanePair <= $lanePairCount; $lanePair++) {
                $value = $perLanePair[$lanePair] ?? 0;
                if ($value > $maximum) {
                    $maximum = $value;
                }
                if ($minimum === null || $value < $minimum) {
                    $minimum = $value;
                }
            }
            if ($maximum - ($minimum ?? 0) > $allowedSpread) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determines per team the number of matchdays on which it plays
     * (regardless of the lane pair). Basis for the usage caps of the search.
     *
     * @param list<ScheduleMatchday> $schedule
     *
     * @return array<int, int> [teamId] => number of matchdays
     */
    private function gamesPerTeam(array $schedule): array
    {
        /** @var array<int, int> $games */
        $games = [];
        foreach ($schedule as $matchday) {
            foreach ($matchday->fixtures as $fixture) {
                foreach ($fixture->teamIds() as $teamId) {
                    $games[$teamId] = ($games[$teamId] ?? 0) + 1;
                }
            }
        }

        return $games;
    }

    /**
     * Largest per-team spread (max−min of the usage over ALL lane pairs, lane
     * pairs never used count as 0). If this value is ≤ 1, R18.2
     * (first case) is met for every team.
     *
     * @param list<ScheduleMatchday>      $schedule
     * @param array<int, array<int, int>> $occupancy
     * @param int                         $lanePairCount Number of available lane pairs.
     */
    private function maximumSpread(array $schedule, array $occupancy, int $lanePairCount): int
    {
        $maximumSpread = 0;

        foreach ($this->usagePerTeam($schedule, $occupancy) as $perLanePair) {
            $maximum = 0;
            $minimum = null;
            for ($lanePair = 1; $lanePair <= $lanePairCount; $lanePair++) {
                $value = $perLanePair[$lanePair] ?? 0;
                if ($value > $maximum) {
                    $maximum = $value;
                }
                if ($minimum === null || $value < $minimum) {
                    $minimum = $value;
                }
            }
            $spread = $maximum - ($minimum ?? 0);
            if ($spread > $maximumSpread) {
                $maximumSpread = $spread;
            }
        }

        return $maximumSpread;
    }

    /**
     * Builds the deviation overview per affected team – i.e. per team whose
     * spread is greater than 1 (R18.10). For each affected team the
     * usage per lane pair (over all lane pairs 1..lanePairCount, unused ones as
     * 0) and the resulting spread are shown.
     *
     * @param list<ScheduleMatchday>      $schedule
     * @param array<int, array<int, int>> $occupancy
     *
     * @return array<int, array{usage: array<int, int>, spread: int}>
     */
    private function deviations(array $schedule, array $occupancy, int $lanePairCount): array
    {
        /** @var array<int, array{usage: array<int, int>, spread: int}> $deviations */
        $deviations = [];

        foreach ($this->usagePerTeam($schedule, $occupancy) as $teamId => $perLanePair) {
            /** @var array<int, int> $usage */
            $usage = [];
            $maximum = 0;
            $minimum = null;
            for ($lanePair = 1; $lanePair <= $lanePairCount; $lanePair++) {
                $value = $perLanePair[$lanePair] ?? 0;
                $usage[$lanePair] = $value;
                if ($value > $maximum) {
                    $maximum = $value;
                }
                if ($minimum === null || $value < $minimum) {
                    $minimum = $value;
                }
            }

            $spread = $maximum - ($minimum ?? 0);
            if ($spread > 1) {
                $deviations[$teamId] = [
                    'usage' => $usage,
                    'spread' => $spread,
                ];
            }
        }

        return $deviations;
    }

    /**
     * Phrases the warning about the not fully even lane distribution
     * (R18.9).
     */
    private function buildWarning(int $spread, bool $proven = true): Message
    {
        return new Message($proven ? 'lanes.warning.uneven_proven' : 'lanes.warning.uneven_timeout', ['spread' => $spread]);
    }

    /**
     * Determines per team how often it plays on each lane pair.
     *
     * @param list<ScheduleMatchday>      $schedule
     * @param array<int, array<int, int>> $occupancy
     *
     * @return array<int, array<int, int>> [teamId][lanePairIndex] => count
     */
    private function usagePerTeam(array $schedule, array $occupancy): array
    {
        /** @var array<int, array<int, int>> $usage */
        $usage = [];

        foreach ($schedule as $matchdayIndex => $matchday) {
            foreach ($matchday->fixtures as $fixtureIndex => $fixture) {
                $lanePair = $occupancy[$matchdayIndex][$fixtureIndex];
                foreach ($fixture->teamIds() as $teamId) {
                    $usage[$teamId][$lanePair] = ($usage[$teamId][$lanePair] ?? 0) + 1;
                }
            }
        }

        return $usage;
    }

    /**
     * Assembles the list of {@see LaneAssignment} from the final occupancy.
     *
     * @param list<ScheduleMatchday>      $schedule
     * @param array<int, array<int, int>> $occupancy
     *
     * @return list<LaneAssignment>
     */
    private function buildAssignments(array $schedule, array $occupancy): array
    {
        /** @var list<LaneAssignment> $assignments */
        $assignments = [];

        foreach ($schedule as $matchdayIndex => $matchday) {
            foreach ($matchday->fixtures as $fixtureIndex => $fixture) {
                $assignments[] = new LaneAssignment(
                    $matchday->number,
                    $fixtureIndex,
                    $fixture,
                    LanePair::fromIndex($occupancy[$matchdayIndex][$fixtureIndex])
                );
            }
        }

        return $assignments;
    }
}
