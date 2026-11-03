<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Aggregation;

/**
 * Read port for the raw data of the table aggregation (R16, R15.3, R24.3).
 *
 * Returns the played fixtures with their complete lineup results – for the
 * league table over all played matchdays of a season (R16.6, R24.3), for the
 * matchday table only the one matchday (R15.3). In addition the participating
 * teams of the season are returned, so that teams without played games also
 * appear in the table with zero values (R16.7).
 *
 * Unplayed matchdays are deliberately not returned (R16.6, R24.3); this keeps
 * the aggregation itself free of matchday status logic.
 *
 * The port is implemented by an adapter based on the raw data repositories;
 * in tests a fake is enough.
 */
interface TableRawDataReader
{
    /**
     * Returns the played fixtures of all played matchdays of a season (R16.6,
     * R24.3).
     *
     * @return list<FixtureRawData>
     */
    public function findFixturesForSeason(int $seasonId): array;

    /**
     * Returns the played fixtures of a single matchday (R15.3).
     *
     * @return list<FixtureRawData>
     */
    public function findFixturesForMatchday(int $matchdayId): array;

    /**
     * Returns the participating teams of a season (team season id → team
     * name), so that teams without played games also appear with zero values
     * (R16.7). The ghost team is not included; it only arises from its
     * fixtures.
     *
     * @return array<int, string>
     */
    public function findTeamsForSeason(int $seasonId): array;
}
