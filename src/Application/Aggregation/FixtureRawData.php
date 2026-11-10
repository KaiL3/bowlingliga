<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Aggregation;

/**
 * Raw data of a played fixture as input to the table aggregation (R16, R17).
 *
 * Bundles the two teams of a fixture with their complete lineup results, from
 * which the {@see TableAggregator} derives the points (via the points
 * service) and the net/gross pins per team.
 *
 * Pure, immutable value object.
 */
final class FixtureRawData
{
    /**
     * @param int $matchdayId Matchday of the fixture; the team page groups by it (R41.3), 0 if unknown
     */
    public function __construct(
        public readonly FixtureTeamRawData $home,
        public readonly FixtureTeamRawData $away,
        public readonly int $matchdayId = 0,
    ) {
    }
}
