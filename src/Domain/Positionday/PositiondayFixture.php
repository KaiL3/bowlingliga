<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Positionday;

/**
 * Immutable fixture of a positionday, formed from two teams placed next to
 * each other in the league table (R31.2, R31.3).
 *
 * A positionday fixture puts two teams that follow each other in the table
 * against each other: first against second, third against fourth and so on
 * (R31.2). The placement follows solely the sort order of the league table
 * according to R16 (R31.3); this service relies on the
 * {@see \Bowlingliga\Domain\Table\TableService} for that and takes the ranks
 * determined there unchanged.
 *
 * {$homeTeamId} carries the higher placed team (smaller rank), {$awayTeamId}
 * the one following it. {$homeIsGhostTeam} and {$awayIsGhostTeam} mark
 * whether one of the two teams is the ghost team according to R20;
 * with an odd number of real teams this is the case when the last placed real
 * team is paired with the ghost team.
 *
 * Pure, immutable value object without side effects.
 */
final class PositiondayFixture
{
    /**
     * @param string $homeTeamId      Id of the higher placed team (smaller rank).
     * @param string $awayTeamId      Id of the team placed next.
     * @param int    $homeRank        Rank of the home team in the league table (>= 1, R31.3).
     * @param int    $awayRank        Rank of the away team in the league table (>= 1, R31.3).
     * @param bool   $homeIsGhostTeam true if the home team is the ghost team according to R20.
     * @param bool   $awayIsGhostTeam true if the away team is the ghost team according to R20.
     *
     * @throws \InvalidArgumentException If both teams are identical or a rank is less than 1.
     */
    public function __construct(
        public readonly string $homeTeamId,
        public readonly string $awayTeamId,
        public readonly int $homeRank,
        public readonly int $awayRank,
        public readonly bool $homeIsGhostTeam = false,
        public readonly bool $awayIsGhostTeam = false,
    ) {
        if ($homeTeamId === $awayTeamId) {
            throw new \InvalidArgumentException('A positionday fixture must consist of two different teams.');
        }

        if ($homeRank < 1) {
            throw new \InvalidArgumentException('The rank of the home team must be at least 1, given: ' . $homeRank);
        }

        if ($awayRank < 1) {
            throw new \InvalidArgumentException('The rank of the away team must be at least 1, given: ' . $awayRank);
        }
    }
}
