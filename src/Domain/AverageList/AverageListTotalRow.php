<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\AverageList;

/**
 * Immutable closing total row of an average list (R2.14).
 *
 * The total row summarises all player rows of the team and shows the total
 * number of games played, the total net pins, the total gross pins as well
 * as the team net average and the team gross average, each as a number with
 * two decimals (R2.14).
 *
 * The team averages are formed from the respective pin totals and the
 * corresponding total number of games, not as the mean of the player
 * averages; this keeps the shown average consistent with the sums of the
 * row. Without included games the average is 0.00.
 *
 * Players without a frozen handicap (status "without handicap" according to
 * R12, R2.15) have no gross values. Their games therefore count
 * neither towards the gross pin total nor towards the base of the team gross
 * average; they do count regularly towards the number of games played and the
 * net pins. If no player has a gross value, the gross pin total is null and
 * the team gross average is 0.00.
 */
final class AverageListTotalRow
{
    /**
     * @param int   $numberOfGames Total number of games played across all
     *                             players (R2.14).
     * @param int   $netPins       Total net pins across all players (R2.14).
     * @param int   $grossPins     Total gross pins across all players with a
     *                             handicap (R2.14).
     * @param float $averageNet    Team net average with two decimals (R2.14).
     * @param float $averageGross  Team gross average with two decimals (R2.14).
     */
    private function __construct(
        public readonly int $numberOfGames,
        public readonly int $netPins,
        public readonly int $grossPins,
        public readonly float $averageNet,
        public readonly float $averageGross,
    ) {
    }

    /**
     * Forms the total row from the already calculated player rows (R2.14).
     *
     * Sums the number of games and net pins across all players, and the gross
     * pins with the corresponding game count only across players with a
     * handicap (R2.15). From these the team averages rounded to two decimals
     * are formed. Empty input yields sums and averages of 0 or 0.00.
     *
     * @param list<AverageListRow> $rows All player rows of the team.
     */
    public static function fromRows(array $rows): self
    {
        $numberOfGames = 0;
        $netPins = 0;
        $grossPins = 0;
        $gamesWithGross = 0;

        foreach ($rows as $row) {
            $numberOfGames += $row->numberOfGames;
            $netPins += $row->netPins;

            if ($row->grossPins !== null) {
                $grossPins += $row->grossPins;
                $gamesWithGross += $row->numberOfGames;
            }
        }

        return new self(
            $numberOfGames,
            $netPins,
            $grossPins,
            self::average($netPins, $numberOfGames),
            self::average($grossPins, $gamesWithGross),
        );
    }

    /**
     * Forms the average rounded to two decimals from a pin total and a game
     * count. Without games the average is 0.00; this avoids a division by
     * zero.
     */
    private static function average(int $pins, int $numberOfGames): float
    {
        if ($numberOfGames === 0) {
            return 0.0;
        }

        return round($pins / $numberOfGames, 2);
    }
}
