<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\AverageList;

/**
 * Immutable result of an average list calculation – a team's complete average
 * list as of a matchday, inclusive (R2).
 *
 * An average list consists of the player rows sorted descending by net
 * average (R2.3) and the closing total row across all players (R2.14).
 *
 * If a team has no played games up to and including the matchday in
 * question, the average list is empty: {@see $rows} has no entries and
 * {@see isEmpty()} returns true (R2.4). The calling layer then shows the
 * message that no averages are available for the team; the total row shows
 * zero values throughout.
 */
final class AverageList
{
    /**
     * @param list<AverageListRow> $rows  Player rows in display order, sorted
     *                                    descending by net average (R2.3).
     * @param AverageListTotalRow  $total Closing total row (R2.14).
     */
    public function __construct(
        public readonly array $rows,
        public readonly AverageListTotalRow $total,
    ) {
    }

    /**
     * Number of player rows in the average list (excluding the total row).
     */
    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * Tells whether the average list is empty, i.e. the team has no played
     * games up to and including the matchday in question (R2.4).
     */
    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
