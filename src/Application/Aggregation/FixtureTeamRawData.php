<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Aggregation;

/**
 * Raw data of one team within a fixture as input to the table aggregation
 * (R16.1, R17).
 *
 * Carries the team id, the display name, the ghost team flag and the rows
 * of the score sheet. Each row supplies for every game it took part in the
 * net value and the handicap relevant for the gross (net + HDC = gross,
 * R17.2); for the ghost team the handicap is 0 (R20.2). Per game a complete
 * team has exactly 4 results (R17.2, R21.2). It also records whether the
 * team fields at least one real player (R21.6/R27.3).
 *
 * Pure, immutable value object.
 */
final class FixtureTeamRawData
{
    /**
     * @param int                   $teamSeasonId  Team season id (0 for the ghost team)
     * @param string                $teamName      Display name
     * @param bool                  $isGhostTeam   true for the ghost team (R20)
     * @param bool                  $hasRealPlayer false if no real player takes part (R21.6/R27.3)
     * @param list<SheetRowResults> $rows          rows of the score sheet
     */
    public function __construct(
        public readonly int $teamSeasonId,
        public readonly string $teamName,
        public readonly bool $isGhostTeam,
        public readonly bool $hasRealPlayer,
        public readonly array $rows,
    ) {
    }
}
