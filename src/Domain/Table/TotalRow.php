<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Table;

/**
 * Immutable closing total row of a league or matchday table (R16.9).
 *
 * The total row summarises all team rows of the table and shows the total net
 * pins, the total gross pins, the total number of games played as well as the
 * overall net average and the overall gross average, each as a number with
 * two decimals (R16.9).
 *
 * The ghost team according to R20 is included in the sums like any
 * other team (R16.10, R20.8); there is no separate handling because the
 * {@see TeamStatistics} of the ghost team has the same structure.
 *
 * The overall averages are formed from the total pins and the total number of
 * games played, not as the mean of the team averages; this keeps the shown
 * average consistent with the sums of the row. Without games played both
 * averages are 0.00 (R16.7).
 */
final class TotalRow
{
    /**
     * @param int   $netPins       Total net pins across all teams (R16.9).
     * @param int   $grossPins     Total gross pins across all teams (R16.9).
     * @param float $averageNet    Overall net average with two decimals (R16.9).
     * @param float $averageGross  Overall gross average with two decimals (R16.9).
     * @param int   $numberOfGames Total number of games played (R16.9).
     */
    private function __construct(
        public readonly int $netPins,
        public readonly int $grossPins,
        public readonly float $averageNet,
        public readonly float $averageGross,
        public readonly int $numberOfGames,
    ) {
    }

    /**
     * Forms the total row from the already aggregated team statistics (R16.9).
     *
     * Sums net pins, gross pins and the number of games played across all teams
     * passed – including the ghost team (R16.10, R20.8) – and calculates the
     * overall averages rounded to two decimals from them. Empty input or no
     * games played give sums and averages of 0 or 0.00 (R16.7).
     *
     * @param list<TeamStatistics> $statistics All team statistics included in the
     *                                         table.
     */
    public static function fromStatistics(array $statistics): self
    {
        $netPins = 0;
        $grossPins = 0;
        $numberOfGames = 0;

        foreach ($statistics as $statistic) {
            $netPins += $statistic->netPins;
            $grossPins += $statistic->grossPins;
            $numberOfGames += $statistic->numberOfGames;
        }

        return new self(
            $netPins,
            $grossPins,
            self::average($netPins, $numberOfGames),
            self::average($grossPins, $numberOfGames),
            $numberOfGames,
        );
    }

    /**
     * Forms the overall average rounded to two decimals from a pin total and
     * the total number of games played. Without games played the average is
     * 0.00 (R16.7).
     */
    private static function average(int $pins, int $numberOfGames): float
    {
        if ($numberOfGames === 0) {
            return 0.0;
        }

        return round($pins / $numberOfGames, 2);
    }
}
