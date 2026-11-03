<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Positionday;

/**
 * A positionday fixture to save with team season assignment and lane pair
 * (R31.2, R31.5).
 *
 * Technology-neutral transfer structure between {@see PositiondayManagement}
 * and {@see PositiondayFixtureStore}. The team ids are team season ids of real
 * teams or null for the ghost team. The lane pair index is the 1-based index
 * or null if there is no lane assignment (yet).
 *
 * Pure, immutable value object.
 */
final class SeededPositiondayFixture
{
    /**
     * @param int|null $homeTeamSeasonId Team season id home or null (ghost team)
     * @param int|null $awayTeamSeasonId Team season id away or null (ghost team)
     * @param int|null $lanePairIndex    1-based lane pair index or null
     */
    public function __construct(
        public readonly ?int $homeTeamSeasonId,
        public readonly ?int $awayTeamSeasonId,
        public readonly ?int $lanePairIndex,
    ) {
    }
}
