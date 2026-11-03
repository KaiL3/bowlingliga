<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Table;

/**
 * Immutable result of a table calculation – the complete league or matchday
 * table (R16, R15.3).
 *
 * A table consists of the team rows sorted descending by the criteria points,
 * gross of the games played and net pins and provided with a rank (R16.2 to
 * R16.5, R16.8), plus the closing total row across all teams (R16.9). League
 * and matchday table have the same structure; the only difference is the
 * matchdays included, which are already reflected in the aggregated inputs
 * (R15.3).
 *
 * The rows are stored in display order: index 0 is the first placed team. The
 * ghost team according to R20 appears as a regular row at the
 * position matching its rank (R16.10).
 */
final class Table
{
    /**
     * @param list<TableRow> $rows  Team rows in display order, sorted ascending
     *                              by rank (R16.8).
     * @param TotalRow       $total Closing total row (R16.9).
     */
    public function __construct(
        public readonly array $rows,
        public readonly TotalRow $total,
    ) {
    }

    /**
     * Number of team rows of the table (excluding the total row). In a season
     * not played yet this equals the number of all teams, whose values are all
     * zero (R16.7).
     */
    public function rowCount(): int
    {
        return count($this->rows);
    }
}
