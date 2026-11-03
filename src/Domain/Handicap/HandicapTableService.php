<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Handicap;

/**
 * Builds the player-independent {@see HandicapTable} of a season.
 *
 * Every value comes from the {@see HandicapModule}, so the table uses exactly
 * the rounding and limits of the real handicap calculation. Only the range
 * in which the handicap changes is listed: from the largest average that
 * still gets the maximum handicap up to the smallest average with handicap 0.
 */
final class HandicapTableService
{
    /** Averages examined: a game has at most 300 pins. */
    private const AVERAGE_MAX = 300;

    public function __construct(
        private readonly HandicapModule $handicaps = new HandicapModule(),
    ) {
    }

    public function build(HandicapFormula $formula, int $targetScore, int $maxHandicap): HandicapTable
    {
        /** @var array<int, int> $handicapByAverage */
        $handicapByAverage = [];
        for ($average = 0; $average <= self::AVERAGE_MAX; $average++) {
            $result = $this->handicaps->calculateHandicap((float) $average, $targetScore, $formula, $maxHandicap);
            if ($result->hasHandicap()) {
                $handicapByAverage[$average] = (int) $result->handicap();
            }
        }

        if ($handicapByAverage === []) {
            return new HandicapTable($targetScore, $formula->factor(), $maxHandicap, []);
        }

        // Cut off the constant ends: keep only the last average with the
        // maximum handicap and the first average with handicap 0.
        $averages = array_keys($handicapByAverage);
        $first = $averages[0];
        $last = $averages[array_key_last($averages)];
        foreach ($handicapByAverage as $average => $handicap) {
            if ($handicap === $maxHandicap) {
                $first = $average;
            }
        }
        foreach (array_reverse($handicapByAverage, true) as $average => $handicap) {
            if ($handicap === 0) {
                $last = $average;
            }
        }

        $rows = [];
        foreach ($handicapByAverage as $average => $handicap) {
            if ($average >= $first && $average <= $last) {
                $rows[] = new HandicapTableRow($average, $handicap);
            }
        }

        return new HandicapTable($targetScore, $formula->factor(), $maxHandicap, $rows);
    }
}
