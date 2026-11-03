<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\AverageList;

/**
 * Pure calculation service for a team's average list as of a matchday,
 * inclusive (R2).
 *
 * The service is free of side effects: it neither accesses the database nor
 * persists anything, but receives the raw data already collected per player
 * as {@see AverageListPlayerInput} and produces an {@see AverageList} sorted
 * by net average with a total row. This makes the calculation reproducible
 * (R35.2) and fully testable.
 *
 * Selecting the included games is the responsibility of the aggregating
 * layer: only the team's games played personally and regularly up to and
 * including the matchday in question are included (R2.1); games marked blind
 * and ghost team results are already excluded (R20.4, R27.13). Likewise, the
 * aggregating layer supplies per player the handicap frozen for the matchday
 * according to R12.12 (R2.8), or null for "without
 * handicap" (R2.15).
 *
 * Flow:
 * 1. Players without includable games are discarded since they have no
 *    averages; if the whole team has no played games, this results in an
 *    empty average list (R2.4).
 * 2. An {@see AverageListRow} is formed for each remaining player (R2.2, R2.7
 *    to R2.13, R2.15).
 * 3. The rows are sorted descending by net average (R2.3).
 * 4. The {@see AverageListTotalRow} summarises all rows (R2.14).
 */
final class AverageListService
{
    /**
     * Creates a team's average list from the raw data collected per player up
     * to and including the matchday in question (R2).
     *
     * Players without includable games are not shown; if the team has no
     * played games at all, the returned average list is empty (R2.4). The
     * remaining players are sorted descending by net average (R2.3) and the
     * total row is added (R2.14). The input stays unchanged so that the
     * service remains free of side effects.
     *
     * @param list<AverageListPlayerInput> $playerInputs Raw data per player of
     *                                                   the team (R2.1).
     */
    public function averageList(array $playerInputs): AverageList
    {
        $rows = [];

        foreach ($playerInputs as $input) {
            // Players without includable games have no averages
            // and do not appear in the list; a team without any played
            // games thus leads to an empty average list (R2.4).
            if ($input->netResults === []) {
                continue;
            }

            $rows[] = AverageListRow::fromInput($input);
        }

        $rows = $this->sortRows($rows);
        $total = AverageListTotalRow::fromRows($rows);

        return new AverageList($rows, $total);
    }

    /**
     * Sorts the player rows descending by net average (R2.3).
     *
     * Sorting works on a copy and leaves the input unchanged. For players with
     * the same net average the relative order is not defined by the domain.
     *
     * @param list<AverageListRow> $rows
     *
     * @return list<AverageListRow>
     */
    private function sortRows(array $rows): array
    {
        usort(
            $rows,
            static fn (AverageListRow $a, AverageListRow $b): int
                => $b->averageNet <=> $a->averageNet,
        );

        return $rows;
    }
}
