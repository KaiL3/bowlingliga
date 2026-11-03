<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Aggregation;

use Bowlingliga\Application\Aggregation\AverageListRawDataReader;
use Bowlingliga\Application\Aggregation\PlayerRawData;

/**
 * Fake of the {@see AverageListRawDataReader} port for the aggregator unit
 * tests, without a database.
 */
final class FixedAverageListRawDataReader implements AverageListRawDataReader
{
    /**
     * @param list<PlayerRawData> $player
     */
    public function __construct(private readonly array $player)
    {
    }

    public function findPlayerRawData(int $teamSeasonId, int $toMatchdayCounter): array
    {
        return $this->player;
    }
}
