<?php

declare(strict_types=1);

namespace Bowlingliga\Application\PublicSite;

/**
 * An entry of a season's public matchday list (R14.2–R14.6, R1.2, R1.3).
 *
 * Bundles the values needed to show a matchday: date, matchday number, the
 * two team names involved and whether the matchday has been played. Only
 * played matchdays can be linked to the detail view (R14.4); matchdays not
 * played yet are not linked and get a tooltip (R14.5, R14.6).
 *
 * Pure, immutable value object.
 */
final class MatchdayListEntry
{
    /**
     * @param int         $matchdayId Matchday id
     * @param int         $number     Matchday counter
     * @param string      $date       Date (Y-m-d)
     * @param string|null $homeName   Team name home (or null if open)
     * @param string|null $awayName   Team name away (or null if open)
     * @param bool        $isPlayed   true if the matchday has been played (R14.4)
     */
    public function __construct(
        public readonly int $matchdayId,
        public readonly int $number,
        public readonly string $date,
        public readonly ?string $homeName,
        public readonly ?string $awayName,
        public readonly bool $isPlayed,
    ) {
    }

    /**
     * Tells whether there are no results for the matchday yet (R1.3): this is
     * the case exactly when it has not been played yet.
     */
    public function hasNoResults(): bool
    {
        return ! $this->isPlayed;
    }
}
