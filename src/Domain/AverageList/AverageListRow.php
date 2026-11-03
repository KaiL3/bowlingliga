<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\AverageList;

use Bowlingliga\Domain\Honours\Gender;

/**
 * Immutable player row of an average list (R2.2, R2.7 to R2.13, R2.15).
 *
 * An AverageListRow bundles the values needed to display a player as of the
 * matchday in question, inclusive: gender (R2.7), the handicap frozen for this
 * matchday (R2.8), the number of games played (R2.9), net and gross pins
 * (R2.10), net and gross average (R2.2, R2.11), best net and gross series
 * (R2.12) and best net and gross game (R2.13).
 *
 * All values are derived values and follow reproducibly from the
 * {@see AverageListPlayerInput} raw data (R35.2). The net values follow
 * directly from the entered net games; the gross values arise from the
 * handicap frozen for this matchday by the rule gross = net + HDC (R12.11,
 * R13.3):
 *
 * - Gross pins = net pins + HDC × number of games.
 * - Best gross game = best net game + HDC.
 * - Best gross series = best net series + HDC × 3 (a series comprises exactly
 *   3 games).
 * - Gross average = net average + HDC (the average refers to one handicap per
 *   game).
 *
 * Without a frozen handicap (status "without handicap" according to
 * R12) the HDC field is empty and all gross values are null; the
 * net values stay unchanged (R2.15). If a player has no includable games, the
 * net average, best series and best game are 0 or 0.00.
 *
 * The best series is the highest sum of three consecutive games in the given
 * order and is only defined if at least three games exist (R2.12); otherwise
 * the best series is null.
 */
final class AverageListRow
{
    /**
     * @param string     $playerId        Technical player id.
     * @param string     $playerName      Display name of the player.
     * @param Gender     $gender          Gender of the player (R2.7).
     * @param int|null   $handicap        Frozen handicap of the matchday (R2.8)
     *                                    or null for "without handicap" (R2.15).
     * @param int        $numberOfGames   Number of included games (R2.9). Not
     *                                    negative.
     * @param int        $netPins         Sum of the net results (R2.10).
     * @param int|null   $grossPins       Sum of the gross results (R2.10) or null
     *                                    for "without handicap".
     * @param float      $averageNet      Net average with two decimals in the
     *                                    range 0.00 to 300.00 (R2.2).
     * @param float|null $averageGross    Gross average with two decimals (R2.11)
     *                                    or null for "without handicap".
     * @param int|null   $bestSeriesNet   Best net series of three consecutive
     *                                    games (R2.12) or null for fewer than
     *                                    three games.
     * @param int|null   $bestSeriesGross Best gross series (R2.12) or null for
     *                                    "without handicap" or fewer than three
     *                                    games.
     * @param int|null   $bestGameNet     Best net game (R2.13) or null without
     *                                    games.
     * @param int|null   $bestGameGross   Best gross game (R2.13) or null for
     *                                    "without handicap" or without games.
     */
    private function __construct(
        public readonly string $playerId,
        public readonly string $playerName,
        public readonly Gender $gender,
        public readonly ?int $handicap,
        public readonly int $numberOfGames,
        public readonly int $netPins,
        public readonly ?int $grossPins,
        public readonly float $averageNet,
        public readonly ?float $averageGross,
        public readonly ?int $bestSeriesNet,
        public readonly ?int $bestSeriesGross,
        public readonly ?int $bestGameNet,
        public readonly ?int $bestGameGross,
    ) {
    }

    /**
     * Creates an AverageListRow from the raw data collected per player (R2.2,
     * R2.7 to R2.13, R2.15).
     *
     * The net figures are derived from the net games of the input; the gross
     * figures arise exclusively from the frozen handicap by the rule
     * gross = net + HDC (R12.11, R13.3). Without a handicap, the HDC field and
     * the gross values stay empty (R2.15).
     */
    public static function fromInput(AverageListPlayerInput $input): self
    {
        $netResults = array_values($input->netResults);
        $numberOfGames = count($netResults);
        $handicap = $input->frozenHandicap;

        $netPins = array_sum($netResults);
        $averageNet = $numberOfGames === 0
            ? 0.0
            : round($netPins / $numberOfGames, 2);

        $bestGameNet = $numberOfGames === 0 ? null : max($netResults);
        $bestSeriesNet = self::bestSeries($netResults);

        // Gross values only when a frozen handicap exists (R2.15).
        if ($handicap === null) {
            $grossPins = null;
            $averageGross = null;
            $bestSeriesGross = null;
            $bestGameGross = null;
        } else {
            $grossPins = $netPins + $handicap * $numberOfGames;
            $averageGross = $numberOfGames === 0
                ? 0.0
                : round($averageNet + $handicap, 2);
            $bestSeriesGross = $bestSeriesNet === null
                ? null
                : $bestSeriesNet + $handicap * 3;
            $bestGameGross = $bestGameNet === null
                ? null
                : $bestGameNet + $handicap;
        }

        return new self(
            $input->playerId,
            $input->playerName,
            $input->gender,
            $handicap,
            $numberOfGames,
            $netPins,
            $grossPins,
            $averageNet,
            $averageGross,
            $bestSeriesNet,
            $bestSeriesGross,
            $bestGameNet,
            $bestGameGross,
        );
    }

    /**
     * Determines the best net series as the highest sum of three consecutive
     * games in the given order (R2.12).
     *
     * A countable series consists of exactly three games; with fewer than three
     * games no series can be determined and the return value is null.
     *
     * @param list<int> $netResults
     */
    private static function bestSeries(array $netResults): ?int
    {
        $quantity = count($netResults);

        if ($quantity < 3) {
            return null;
        }

        $best = null;

        for ($i = 0; $i + 3 <= $quantity; ++$i) {
            $sum = $netResults[$i] + $netResults[$i + 1] + $netResults[$i + 2];

            if ($best === null || $sum > $best) {
                $best = $sum;
            }
        }

        return $best;
    }
}
