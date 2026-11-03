<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\SeasonPlanner;

/**
 * Immutable value object of the defined length of a season (R9.10).
 *
 * The length of a season is defined either by a number of matchdays or by an
 * end date (R9.10). So that the season planner stays a pure, deterministic
 * calculation unit without any dependency on time or calendar, both kinds of
 * definition are mapped to an integer number of matchdays: for a definition by
 * end date it is up to the calling layer to determine the number of matchdays
 * from start date, end date and game rhythm and pass it via
 * {@see fromEndDate()}. The season planner thus receives a matchday count in
 * both cases and spreads the fixtures over exactly this number of matchdays
 * (R9.10, R9.11).
 *
 * The source of the definition – matchday count or end date – is kept via
 * {@see source}, so that the calling layer can give the administrator
 * suitable feedback (R9.12).
 */
final class SeasonLength
{
    public const SOURCE_MATCHDAY_COUNT = 'matchday_count';
    public const SOURCE_END_DATE = 'end_date';

    /**
     * @param int    $matchdayCount Number of matchdays over which the fixtures
     *                              are to be spread (R9.10). >= 1.
     * @param string $source        Kind of definition: "Spieltaganzahl" or
     *                              "Enddatum" (R9.10).
     *
     * @throws \InvalidArgumentException If the count is less than 1 or the
     *                                   source is unknown.
     */
    private function __construct(
        public readonly int $matchdayCount,
        public readonly string $source,
    ) {
        if ($matchdayCount < 1) {
            throw new \InvalidArgumentException('Die Anzahl der Spieltage einer Saison muss mindestens 1 betragen, gegeben: ' . $matchdayCount);
        }

        if ($source !== self::SOURCE_MATCHDAY_COUNT && $source !== self::SOURCE_END_DATE) {
            throw new \InvalidArgumentException('Unbekannte Herkunft der Saisonlänge: ' . $source);
        }
    }

    /**
     * Defines the season length directly by a number of matchdays (R9.10).
     */
    public static function fromMatchdayCount(int $matchdayCount): self
    {
        return new self($matchdayCount, self::SOURCE_MATCHDAY_COUNT);
    }

    /**
     * Defines the season length by an end date (R9.10).
     *
     * Since the season planner works independently of the calendar, the number
     * of matchdays available until the end date, determined by the calling
     * layer from start date, end date and game rhythm, is passed in. The
     * source is kept as "Enddatum" (R9.10, R9.12).
     */
    public static function fromEndDate(int $matchdayCountUntilEndDate): self
    {
        return new self($matchdayCountUntilEndDate, self::SOURCE_END_DATE);
    }

    /**
     * Tells whether the season length was defined by an end date (R9.10).
     */
    public function setByEndDate(): bool
    {
        return $this->source === self::SOURCE_END_DATE;
    }
}
