<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\PlayerProfile;

/**
 * History of a player within one season: one point per played matchday,
 * starting with the first matchday on which the player has an average
 * (R39.3, R39.4).
 */
final class PlayerHistory
{
    /** @var list<PlayerHistoryPoint> */
    public readonly array $points;

    /**
     * @param list<PlayerHistoryPoint> $points ascending by matchday counter
     */
    public function __construct(array $points = [])
    {
        $this->points = array_values($points);
    }

    public function isEmpty(): bool
    {
        return $this->points === [];
    }

    /** Average after the last played matchday (R11), null without one. */
    public function lastAverage(): ?float
    {
        for ($i = count($this->points) - 1; $i >= 0; --$i) {
            if ($this->points[$i]->average !== null) {
                return $this->points[$i]->average;
            }
        }

        return null;
    }

    /** Handicap frozen last in this season (R12.12), null without one. */
    public function lastHandicap(): ?int
    {
        for ($i = count($this->points) - 1; $i >= 0; --$i) {
            if ($this->points[$i]->handicap !== null) {
                return $this->points[$i]->handicap;
            }
        }

        return null;
    }

    /** Number of games the player actually played in this season. */
    public function games(): int
    {
        return array_sum(array_map(static fn (PlayerHistoryPoint $p): int => $p->games, $this->points));
    }

    /** Number of matchdays on which the player actually played. */
    public function matchdaysPlayed(): int
    {
        return count(array_filter($this->points, static fn (PlayerHistoryPoint $p): bool => $p->played()));
    }
}
