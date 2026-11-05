<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Average;

/**
 * AverageModule: pure, side-effect-free calculation service for the player
 * average (net) according to R11.
 *
 * The module calculates a player's average exclusively from the raw data
 * passed to it ({@see AverageInput}) and accesses neither the database nor
 * any other external state. For the same inputs it always returns the same
 * result (reproducibility, R35).
 *
 * Only net games actually played by the player are included. Games marked
 * blind (R27.13) and ghost team results (R20.4) are ignored (R11.1).
 *
 * Inclusion logic by matchday counter of the current season (the limit of 5 is
 * the default and configurable per season):
 * - Matchday counter <= 5: in addition to the games actually played in the
 *   current season, the games actually played in the previous season are
 *   included (R11.3). If no previous season games were actually played but a
 *   manual previous season record exists, its pin total and game count are
 *   included instead (R11.4). If there is neither a previous season with
 *   played games nor a record, the average is formed from the current season
 *   only (R11.6).
 * - Matchday counter >= 6: only the games actually played in the current
 *   season (R11.5).
 *
 * If there is no includable played game, the module determines no average
 * and marks the player as "without average" (R11.7); this status is
 * represented by the return value {@see self::WITHOUT_AVERAGE} (null).
 */
final class AverageModule
{
    /**
     * Default matchday counter up to which (inclusive) the previous season is
     * included (R11.3, R11.5); configurable per season.
     */
    public const PREVIOUS_SEASON_THRESHOLD = 5;

    /**
     * Status value "without average" (R11.7): the player has no includable
     * played game, so no average is determined.
     */
    public const WITHOUT_AVERAGE = null;

    /**
     * Calculates a player's net average as a pure function (R11).
     *
     * @param int          $player                  Player id (for attribution only,
     *                                              no side effect).
     * @param int          $season                  Id of the current season (for
     *                                              attribution only).
     * @param int          $matchdayCounter         Sequential number of the current
     *                                              matchday within the season,
     *                                              starting at 1 (R11.3, R11.5).
     * @param AverageInput $rawData                 The player's raw data (games and
     *                                              optional previous season record).
     * @param int          $previousSeasonMatchdays Last matchday counter on which the
     *                                              previous season is included
     *                                              (configurable per season, 0 = never).
     *
     * @return float|null The average rounded to two decimals (R11.2) or
     *                    {@see self::WITHOUT_AVERAGE} (null) if there is no
     *                    includable game (R11.7).
     *
     * @throws \InvalidArgumentException If the matchday counter is less than 1.
     */
    public function calculateAverage(
        int $player,
        int $season,
        int $matchdayCounter,
        AverageInput $rawData,
        int $previousSeasonMatchdays = self::PREVIOUS_SEASON_THRESHOLD,
    ): ?float {
        if ($matchdayCounter < 1) {
            throw new \InvalidArgumentException(
                'The matchday counter must be at least 1, given: ' . $matchdayCounter
            );
        }

        $average = $this->calculateExactAverage($matchdayCounter, $rawData, $previousSeasonMatchdays);

        // Arithmetic mean, rounded to two decimals (R11.2).
        return $average === null ? self::WITHOUT_AVERAGE : round($average, 2);
    }

    /**
     * Unrounded net average after the given matchday counter.
     *
     * The handicap uses this exact value and rounds it to whole pins itself;
     * rounding to two decimals first could shift an average like 165.4951 to
     * 166 instead of 165. Counter 0 is the state before the first matchday,
     * which still includes the previous season (unless it is switched off).
     *
     * @throws \InvalidArgumentException If the matchday counter is negative.
     */
    public function calculateExactAverage(
        int $matchdayCounter,
        AverageInput $rawData,
        int $previousSeasonMatchdays = self::PREVIOUS_SEASON_THRESHOLD,
    ): ?float {
        if ($matchdayCounter < 0) {
            throw new \InvalidArgumentException(
                'The matchday counter must not be negative, given: ' . $matchdayCounter
            );
        }

        // The base is always the games actually played in the current
        // season (R11.1, R11.5).
        $netPinsTotal = 0;
        $numberOfGames   = 0;
        foreach ($rawData->realGamesCurrentSeason() as $game) {
            $netPinsTotal += $game->netResult;
            ++$numberOfGames;
        }

        // During the first matchdays the previous season is included (R11.3).
        // Actually played previous season games take precedence; only if there
        // are none, the manual previous season record is added instead
        // (R11.4). Without either, only the current season counts (R11.6).
        if (self::includesPreviousSeason($matchdayCounter, $previousSeasonMatchdays)) {
            $previousSeasonGames = $rawData->realGamesPreviousSeason();

            if ($previousSeasonGames !== []) {
                foreach ($previousSeasonGames as $game) {
                    $netPinsTotal += $game->netResult;
                    ++$numberOfGames;
                }
            } elseif ($rawData->previousSeasonRecord !== null && $rawData->previousSeasonRecord->isIncludable()) {
                $netPinsTotal += $rawData->previousSeasonRecord->netPinsTotal;
                $numberOfGames   += $rawData->previousSeasonRecord->numberOfGames;
            }
        }

        // No includable game: "without average" (R11.7).
        if ($numberOfGames === 0) {
            return self::WITHOUT_AVERAGE;
        }

        return $netPinsTotal / $numberOfGames;
    }

    /**
     * Whether the average after the given matchday counter includes the
     * previous season: up to and including the configured matchday, never
     * with the setting 0.
     */
    public static function includesPreviousSeason(int $matchdayCounter, int $previousSeasonMatchdays): bool
    {
        return $previousSeasonMatchdays > 0 && $matchdayCounter <= $previousSeasonMatchdays;
    }
}
