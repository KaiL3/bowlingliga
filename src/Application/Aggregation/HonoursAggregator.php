<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Aggregation;

use Bowlingliga\Domain\Honours\Honours;
use Bowlingliga\Domain\Honours\HonoursModule;

/**
 * Aggregates a season's raw data into the honours (R13).
 *
 * The aggregator reads the raw games and the end date flag via the
 * {@see HonoursRawDataReader} and passes both to the pure
 * {@see HonoursModule}, which calculates the honours from them. Excluding
 * games marked blind (R27.13) and ghost team results (R20.5) as well as
 * forming series (R13.2) happen entirely in the HonoursModule; this
 * aggregator only orchestrates the reading.
 *
 * Visibility (after the end date) is determined by the flag read and carried
 * into the {@see Honours} result (R13.8, R13.10). The aggregation is free of
 * side effects; its only dependency is the reading raw data port (R35.2).
 */
final class HonoursAggregator
{
    public function __construct(
        private readonly HonoursRawDataReader $reader,
        private readonly HonoursModule $honoursModule = new HonoursModule(),
    ) {
    }

    /**
     * Builds a season's honours (R13).
     */
    public function honours(int $seasonId): Honours
    {
        return $this->honoursModule->calculateHonours(
            $this->reader->findGameResults($seasonId),
            $this->reader->isAfterEndDate($seasonId),
        );
    }
}
