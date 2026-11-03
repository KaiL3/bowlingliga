<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Table;

/**
 * Pure calculation service for the league and the matchday table of a season
 * (R16, R15.3, R20.8, R24.3).
 *
 * The service is free of side effects: it neither accesses the database nor
 * persists anything, but receives the key figures already aggregated per team
 * as {@see TeamStatistics} and produces a sorted {@see Table} with rank and
 * total row from them. This makes the calculation reproducible (R35.2) and
 * fully verifiable by property tests.
 *
 * Selecting the included matchdays is the responsibility of the aggregating
 * layer: for the league table only played matchdays of the season are to be
 * included (R16.6, R24.3), for the structurally identical matchday table only
 * the one matchday in question (R15.3). Both tables are produced by the same
 * algorithm because they have the same structure; {@see leagueTable()} and
 * {@see matchdayTable()} therefore only differ in the domain meaning of the
 * input.
 *
 * The ghost team according to R20 is passed as an ordinary
 * {@see TeamStatistics} and thus kept as its own row, sorted in regularly and
 * included in the total row (R16.10, R16.11, R20.8).
 *
 * Sorting (R16.2 to R16.5):
 * 1. Points descending (R16.2).
 * 2. On a tie: gross of the games played descending (R16.3).
 * 3. On a further tie: net pins descending (R16.4).
 * 4. Teams still tied after that get the same rank; the following ranks are
 *    skipped by the number of tied teams (R16.5).
 *
 * For the display order within a tie group (teams matching in all three
 * domain criteria) the team id (teamId) ascending is used as the last, purely
 * technical tie-break. This makes the output reproducible (R35.2) and does not
 * change the shared rank assignment: the rank is assigned solely by the three
 * domain criteria, so teams tied in the domain sense still share the same
 * rank (R16.5).
 */
final class TableService
{
    /**
     * Generates a season's league table from the key figures aggregated per
     * team over all played matchdays (R16).
     *
     * An empty season is represented by the caller passing all teams with zero
     * values; the result is then a table with all teams and points and pins of
     * zero each (R16.7).
     *
     * @param list<TeamStatistics> $teamStatistics Aggregated key figures per team
     *                                             over all included played
     *                                             matchdays (R16.6, R24.3).
     */
    public function leagueTable(array $teamStatistics): Table
    {
        return $this->buildTable($teamStatistics);
    }

    /**
     * Generates the matchday table of a single matchday from the key figures
     * per team limited to this matchday alone (R15.3).
     *
     * Structure, sorting, rank assignment and total row are identical to the
     * league table; only the matchday in question is included, which is
     * reflected in the aggregates passed in (R15.3).
     *
     * @param list<TeamStatistics> $teamStatistics Aggregated key figures per team
     *                                             limited to the one matchday
     *                                             (R15.3).
     */
    public function matchdayTable(array $teamStatistics): Table
    {
        return $this->buildTable($teamStatistics);
    }

    /**
     * Common algorithm for league and matchday table: sorts the team
     * statistics, assigns the shared rank and adds the total row.
     *
     * @param list<TeamStatistics> $teamStatistics
     */
    private function buildTable(array $teamStatistics): Table
    {
        $sorted = $this->sortRows($teamStatistics);
        $rows = $this->assignRanks($sorted);
        $total = TotalRow::fromStatistics($teamStatistics);

        return new Table($rows, $total);
    }

    /**
     * Sorts the team statistics descending by points, then by gross of the
     * games played, then by net pins (R16.2 to R16.4).
     *
     * Sorting works on a copy and leaves the input unchanged, so that the
     * service stays free of side effects. For tied teams – matching in all
     * three domain criteria – the relative order is not defined by the domain;
     * they share the same rank (R16.5). So that the display order within such
     * a tie group is still reproducible (R35.2), the
     * {@see TeamStatistics::$teamId} ascending is used as the last, purely
     * technical tie-break criterion. This criterion only stabilises the order
     * and deliberately does NOT enter the rank assignment:
     * {@see areEqualRank()} still compares only the three domain criteria, so
     * teams tied in the domain sense keep the same rank (R16.5).
     *
     * @param list<TeamStatistics> $teamStatistics
     *
     * @return list<TeamStatistics>
     */
    private function sortRows(array $teamStatistics): array
    {
        usort(
            $teamStatistics,
            static function (TeamStatistics $a, TeamStatistics $b): int {
                return ($b->points <=> $a->points)
                    ?: (($b->grossPins <=> $a->grossPins)
                        ?: (($b->netPins <=> $a->netPins)
                            ?: ($a->teamId <=> $b->teamId)));
            }
        );

        return $teamStatistics;
    }

    /**
     * Assigns the rank to the already sorted team statistics and creates the
     * table rows (R16.8).
     *
     * The rank starts at 1 (R16.8). If two consecutive teams match in all sort
     * criteria, they get the same rank; the next different rank is the 1-based
     * position in the sorted list, which skips the ranks in between by the
     * number of tied teams (e.g. 1, 1, 3) (R16.5).
     *
     * @param list<TeamStatistics> $sorted
     *
     * @return list<TableRow>
     */
    private function assignRanks(array $sorted): array
    {
        $rows = [];
        $lastRank = 0;
        /** @var TeamStatistics|null $prior */
        $prior = null;

        foreach ($sorted as $position => $statistic) {
            if ($prior !== null && $this->areEqualRank($prior, $statistic)) {
                $rank = $lastRank;
            } else {
                $rank = $position + 1;
            }

            $rows[] = TableRow::fromStatistic($statistic, $rank);
            $lastRank = $rank;
            $prior = $statistic;
        }

        return $rows;
    }

    /**
     * Checks whether two teams are tied, i.e. match in all sort criteria –
     * points, gross of the games played and net pins (R16.5).
     */
    private function areEqualRank(TeamStatistics $a, TeamStatistics $b): bool
    {
        return $a->points === $b->points
            && $a->grossPins === $b->grossPins
            && $a->netPins === $b->netPins;
    }
}
