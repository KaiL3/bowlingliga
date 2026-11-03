<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Aggregation;

use Bowlingliga\Application\Aggregation\FixtureRawData;
use Bowlingliga\Application\Aggregation\TableRawDataReader;

/**
 * Fake of the {@see TableRawDataReader} port for the aggregator unit tests,
 * without a database.
 */
final class FixedTableRawDataReader implements TableRawDataReader
{
    /**
     * @param list<FixtureRawData> $seasonFixtures
     * @param list<FixtureRawData> $matchdayFixtures
     * @param array<int, string>    $teams
     */
    public function __construct(
        private readonly array $seasonFixtures = [],
        private readonly array $matchdayFixtures = [],
        private readonly array $teams = [],
    ) {
    }

    public function findFixturesForSeason(int $seasonId): array
    {
        return $this->seasonFixtures;
    }

    public function findFixturesForMatchday(int $matchdayId): array
    {
        return $this->matchdayFixtures;
    }

    public function findTeamsForSeason(int $seasonId): array
    {
        return $this->teams;
    }
}
