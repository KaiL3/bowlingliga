<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\TeamProfile;

use Bowlingliga\Application\TeamProfile\TeamProfileReader;

/** In-memory fake of the {@see TeamProfileReader} port. */
final class InMemoryTeamProfileReader implements TeamProfileReader
{
    /** @var array<int, array{team_season_id: int, team_id: int, season_id: int, season_name: string, team_name: string}> */
    public array $teams = [];

    /** @var array<int, list<array{team_season_id: int, season_id: int, season_name: string}>> */
    public array $seasons = [];

    /** @var array<int, list<array{id: int, number: int, date: string, played: bool, cancelled: bool}>> */
    public array $matchdays = [];

    public function team(int $teamSeasonId): ?array
    {
        return $this->teams[$teamSeasonId] ?? null;
    }

    public function seasons(int $teamId): array
    {
        return $this->seasons[$teamId] ?? [];
    }

    public function matchdays(int $seasonId): array
    {
        return $this->matchdays[$seasonId] ?? [];
    }
}
