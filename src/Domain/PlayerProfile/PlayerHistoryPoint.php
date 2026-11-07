<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\PlayerProfile;

/**
 * State of a player after one played matchday of a season (R39.3, R39.4).
 */
final class PlayerHistoryPoint
{
    /**
     * @param float|null $average    Player average after this matchday (R11), exact; null without one.
     * @param float|null $dayAverage Mean of the games the player actually played on this matchday; null if none.
     * @param int        $games      Number of these games.
     * @param int|null   $handicap   Handicap frozen for this matchday (R12.12); null without one.
     */
    public function __construct(
        public readonly int $matchdayId,
        public readonly int $matchdayNumber,
        public readonly string $date,
        public readonly ?float $average,
        public readonly ?float $dayAverage,
        public readonly int $games,
        public readonly ?int $handicap,
    ) {
    }

    /** Tells whether the player actually played games on this matchday. */
    public function played(): bool
    {
        return $this->games > 0;
    }
}
