<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Aggregation;

use Bowlingliga\Application\Aggregation\HonoursRawDataReader;
use Bowlingliga\Domain\Honours\GameResultInput;

/**
 * Fake of the {@see HonoursRawDataReader} port for the aggregator unit tests,
 * without a database.
 */
final class FixedHonoursRawDataReader implements HonoursRawDataReader
{
    /**
     * @param list<GameResultInput> $results
     */
    public function __construct(
        private readonly array $results,
        private readonly bool $afterEndDate,
    ) {
    }

    public function findGameResults(int $seasonId): array
    {
        return $this->results;
    }

    public function isAfterEndDate(int $seasonId): bool
    {
        return $this->afterEndDate;
    }
}
