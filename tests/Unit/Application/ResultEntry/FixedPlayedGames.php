<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\PlayedGamesReader;
use Bowlingliga\Application\ResultEntry\PlayerAverageReader;

/**
 * In-memory fake of the {@see PlayedGamesReader} and
 * {@see PlayerAverageReader} ports: fixed games and averages per player,
 * independent of the matchday.
 */
final class FixedPlayedGames implements PlayedGamesReader, PlayerAverageReader
{
    /** @var array<int, int> player id → games played so far (if not given: 0) */
    public array $games = [];

    /** @var array<int, float> player id → net average before the matchday (if not given: none) */
    public array $averages = [];

    public function gamesPlayedBefore(int $matchdayId, int $playerId): int
    {
        return $this->games[$playerId] ?? 0;
    }

    public function averageBefore(int $matchdayId, int $playerId): ?float
    {
        return $this->averages[$playerId] ?? null;
    }
}
