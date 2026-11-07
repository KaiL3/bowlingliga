<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\PlayerProfile;

use Bowlingliga\Application\PlayerProfile\PlayerProfileReader;
use Bowlingliga\Application\PlayerProfile\PlayerSeasonRawData;

/**
 * In-memory fake of the {@see PlayerProfileReader}.
 */
final class InMemoryPlayerProfileReader implements PlayerProfileReader
{
    /** @var array<int, array{id: int, name: string, gender: string}> */
    public array $players = [];

    /** @var array<int, list<array{season_id: int, season_name: string, team_season_id: int, team_name: string}>> */
    public array $seasons = [];

    /** @var array<int, array<int, PlayerSeasonRawData>> */
    public array $raw = [];

    public function player(int $playerId): ?array
    {
        return $this->players[$playerId] ?? null;
    }

    public function seasons(int $playerId): array
    {
        return $this->seasons[$playerId] ?? [];
    }

    public function seasonRawData(int $playerId, int $seasonId): PlayerSeasonRawData
    {
        return $this->raw[$playerId][$seasonId] ?? new PlayerSeasonRawData();
    }
}
