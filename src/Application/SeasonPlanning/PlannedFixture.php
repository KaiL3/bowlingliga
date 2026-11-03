<?php

declare(strict_types=1);

namespace Bowlingliga\Application\SeasonPlanning;

/**
 * A fixture of a matchday to save with an optional lane assignment (R9.3,
 * R18.1, R18.7).
 *
 * The team ids are team season ids of real teams or null for the ghost team
 * (which has no team season row of its own). The lane pair is the 1-based
 * index of the assigned lane pair or null if there is no lane assignment
 * (yet) (positionday, R18.7).
 *
 * Pure, immutable value object.
 */
final class PlannedFixture
{
    /**
     * @param int|null $homeTeamSeasonId Team season id home or null (ghost team)
     * @param int|null $awayTeamSeasonId Team season id away or null (ghost team)
     * @param int|null $lanePairIndex    1-based lane pair index or null (R18.7)
     */
    public function __construct(
        public readonly ?int $homeTeamSeasonId,
        public readonly ?int $awayTeamSeasonId,
        public readonly ?int $lanePairIndex,
    ) {
    }
}
