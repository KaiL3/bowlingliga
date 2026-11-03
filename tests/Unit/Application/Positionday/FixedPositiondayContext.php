<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Positionday;

use Bowlingliga\Application\Positionday\PositiondayContextReader;
use Bowlingliga\Domain\Table\TeamStatistics;

/**
 * Fake of the {@see PositiondayContextReader} port for the unit tests, without
 * a database.
 */
final class FixedPositiondayContext implements PositiondayContextReader
{
    /**
     * @param list<TeamStatistics> $standings
     * @param array<string, int>  $teamSeasonIds
     */
    public function __construct(
        public bool $previousMatchdaysComplete,
        public array $standings,
        public array $teamSeasonIds,
        public int $laneCount = 4,
        public bool $positiondayComplete = false,
    ) {
    }

    public function allPreviousMatchdaysComplete(int $matchdayId): bool
    {
        return $this->previousMatchdaysComplete;
    }

    public function standingsBefore(int $matchdayId): array
    {
        return $this->standings;
    }

    public function positiondayComplete(int $matchdayId): bool
    {
        return $this->positiondayComplete;
    }

    public function laneCount(int $matchdayId): int
    {
        return $this->laneCount;
    }

    public function teamSeasonIds(int $matchdayId): array
    {
        return $this->teamSeasonIds;
    }
}
