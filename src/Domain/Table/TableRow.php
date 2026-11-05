<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Table;

/**
 * Immutable team row of a league or matchday table (R16.1, R16.8).
 *
 * A TableRow bundles the values needed to display a team: the raw sums taken
 * from the {@see TeamStatistics} as well as the net/gross averages derived
 * from them and the assigned rank.
 *
 * The averages are derived values: they follow reproducibly from the pins and
 * the number of games played (R16.1) and are rounded to two decimals here. If
 * a team has no games played – for instance in a season not played yet
 * (R16.7) – both averages are 0.00, without a division by zero.
 *
 * The rank follows the sorting by the criteria points, gross of the games
 * played and net pins and starts at 1 (R16.8). On an equal rank several teams
 * share the same rank; the following ranks are skipped by the number of tied
 * teams (R16.5). The rank is assigned in the {@see TableService}; this object
 * carries the rank already determined.
 */
final class TableRow
{
    /**
     * @param int    $rank          Placement of the team according to the
     *                              sorting, starting at 1 (R16.8, R16.5). >= 1.
     * @param string $teamId        Technical id of the team.
     * @param string $teamName      Display name of the team (R16.1).
     * @param int    $points        Points achieved (R16.1).
     * @param int    $netPins       Sum of the net results (R16.1).
     * @param int    $grossPins     Sum of the gross results (R16.1).
     * @param float  $averageNet    Net average with two decimals in the range
     *                              0.00 to 300.00 (R16.1).
     * @param float  $averageGross  Gross average with two decimals in the range
     *                              0.00 to 300.00 (R16.1).
     * @param int    $numberOfGames Number of games played (R16.1).
     * @param bool   $isGhostTeam   True for the row of the ghost team according
     *                              to R20 (R16.10).
     */
    private function __construct(
        public readonly int $rank,
        public readonly string $teamId,
        public readonly string $teamName,
        public readonly int $points,
        public readonly int $netPins,
        public readonly int $grossPins,
        public readonly float $averageNet,
        public readonly float $averageGross,
        public readonly int $numberOfGames,
        public readonly bool $isGhostTeam,
    ) {
    }

    /**
     * Creates a TableRow from an aggregated {@see TeamStatistics} and the rank
     * determined in the {@see TableService}.
     *
     * The net and gross averages are formed from the respective pins and the
     * number of games played and rounded to two decimals (R16.1). With zero
     * games played both averages are 0.00 (R16.7).
     *
     * @throws \InvalidArgumentException If the rank is less than 1.
     */
    public static function fromStatistic(TeamStatistics $statistic, int $rank): self
    {
        if ($rank < 1) {
            throw new \InvalidArgumentException('The rank of a table row must be at least 1, given: ' . $rank);
        }

        return new self(
            $rank,
            $statistic->teamId,
            $statistic->teamName,
            $statistic->points,
            $statistic->netPins,
            $statistic->grossPins,
            self::average($statistic->netPins, $statistic->numberOfGames),
            self::average($statistic->grossPins, $statistic->numberOfGames),
            $statistic->numberOfGames,
            $statistic->isGhostTeam,
        );
    }

    /**
     * Forms the average rounded to two decimals from a pin total and the number
     * of games played. Without games played the average is 0.00 by definition
     * (R16.7); this avoids a division by zero.
     */
    private static function average(int $pins, int $numberOfGames): float
    {
        if ($numberOfGames === 0) {
            return 0.0;
        }

        return round($pins / $numberOfGames, 2);
    }
}
