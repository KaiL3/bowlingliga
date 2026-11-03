<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

/**
 * Read port for season master data (R7.6, R7.7, R8.10).
 *
 * Provides the use cases with the season data needed for rules depending on
 * start/end date (in particular the start date to check whether a season has
 * already started). The port is implemented by the
 * {@see \Bowlingliga\Persistence\SeasonRepository}; in tests a slim
 * in-memory fake is enough.
 */
interface SeasonReader
{
    /**
     * Returns the master data of a season (e.g. `start_date`, `end_date`), or
     * null if the season does not exist (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array;
}
