<?php

declare(strict_types=1);

namespace Bowlingliga\Application\PlayerProfile;

/**
 * One row of the team history on the player page (R39.5).
 */
final class PlayerSeasonSummary
{
    /**
     * @param float|null $average  Player average after the last played matchday of the season (R11), exact.
     * @param int|null   $handicap Handicap frozen last in the season (R12.12).
     */
    public function __construct(
        public readonly int $seasonId,
        public readonly string $seasonName,
        public readonly int $teamSeasonId,
        public readonly string $teamName,
        public readonly int $matchdaysPlayed,
        public readonly int $games,
        public readonly ?float $average,
        public readonly ?int $handicap,
    ) {
    }
}
