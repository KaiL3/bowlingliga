<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Average;

/**
 * Immutable value object for a player's manually entered previous season
 * record (R11.4, R29).
 *
 * The record consists of the number of games played in the previous season
 * and the total net pins scored in them. The AverageModule treats it like
 * played previous season games during the first five matchdays, but only if
 * no actually played previous season games of the player exist (R11.4, R29).
 */
final class PreviousSeasonRecord
{
    /**
     * @param int $numberOfGames Number of games played in the previous season
     *                           (>= 0).
     * @param int $netPinsTotal  Total net pins scored in the previous season
     *                           (>= 0).
     *
     * @throws \InvalidArgumentException If a value is negative.
     */
    public function __construct(
        public readonly int $numberOfGames,
        public readonly int $netPinsTotal,
    ) {
        if ($numberOfGames < 0) {
            throw new \InvalidArgumentException(
                'Die Anzahl der Vorsaison-Spiele darf nicht negativ sein, gegeben: ' . $numberOfGames
            );
        }
        if ($netPinsTotal < 0) {
            throw new \InvalidArgumentException(
                'Die Summe der Vorsaison-Netto-Pins darf nicht negativ sein, gegeben: ' . $netPinsTotal
            );
        }
    }

    /**
     * Tells whether an includable previous season record exists, i.e. at least
     * one played previous season game is recorded (R11.4).
     */
    public function isIncludable(): bool
    {
        return $this->numberOfGames > 0;
    }
}
