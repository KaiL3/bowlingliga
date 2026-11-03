<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\SeasonPlanning;

use Bowlingliga\Application\SeasonPlanning\SeasonTeamsReader;

/**
 * Fake of the {@see SeasonTeamsReader} port for the unit tests of the season
 * planning, without a database.
 */
final class FixedSeasonTeamsReader implements SeasonTeamsReader
{
    /**
     * @param list<array{team_season_id: int, team_name: string}> $teams
     */
    public function __construct(
        private readonly array $teams,
        private readonly ?int $matchdayCount,
        private readonly ?int $laneCount,
        private readonly int $positiondayInterval = 10,
    ) {
    }

    public function findTeams(int $seasonId): array
    {
        return $this->teams;
    }

    public function findMatchdayCount(int $seasonId): ?int
    {
        return $this->matchdayCount;
    }

    public function findLaneCount(int $seasonId): ?int
    {
        return $this->laneCount;
    }

    public function findPositiondayInterval(int $seasonId): int
    {
        return $this->positiondayInterval;
    }
}
