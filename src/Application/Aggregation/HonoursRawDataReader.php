<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Aggregation;

use Bowlingliga\Domain\Honours\GameResultInput;

/**
 * Read port for the raw data of the honours aggregation (R13).
 *
 * Returns all raw games collected for a season as {@see GameResultInput}
 * (real/blind/ghost team mixed) and whether the season's end date has passed.
 * The exclusion and series logic is handled entirely by the pure
 * {@see HonoursModule}; the port only supplies the raw data and the
 * visibility flag.
 *
 * The port is implemented by an adapter based on the raw data repositories;
 * in tests a fake is enough.
 */
interface HonoursRawDataReader
{
    /**
     * Returns all raw games of a season for the honours calculation (R13.1).
     *
     * @return list<GameResultInput>
     */
    public function findGameResults(int $seasonId): array;

    /**
     * Tells whether the season's end date has already passed; determines the
     * public visibility of the honours (R13.8, R13.10).
     */
    public function isAfterEndDate(int $seasonId): bool;
}
