<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Aggregation;

/**
 * Read port for a team's average list raw data as of a matchday (R2.1, R2.8).
 *
 * Returns per player of the team the raw data needed for the average list:
 * master data (name, gender), the net games entered up to and including the
 * matchday in question with their blind flag, and the frozen handicap
 * assigned to the matchday. Excluding games marked blind and ghost team
 * results (R2.1, R20.4, R27.13) deliberately does NOT happen here but in the
 * pure {@see AverageListAggregator}, so that this domain rule stays testable
 * without a database.
 *
 * The port is implemented by an adapter based on the raw data repositories;
 * in tests a fake is enough.
 */
interface AverageListRawDataReader
{
    /**
     * Returns the player raw data of a team (team season) up to and including
     * the given matchday counter.
     *
     * @return list<PlayerRawData>
     */
    public function findPlayerRawData(int $teamSeasonId, int $toMatchdayCounter): array;
}
