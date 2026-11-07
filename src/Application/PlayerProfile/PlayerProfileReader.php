<?php

declare(strict_types=1);

namespace Bowlingliga\Application\PlayerProfile;

/**
 * Read port for the player page (R39).
 */
interface PlayerProfileReader
{
    /**
     * The player, or null if they do not exist.
     *
     * @return array{id: int, name: string, gender: string}|null
     */
    public function player(int $playerId): ?array;

    /**
     * The player's seasons with their team, ascending by start date (R39.5):
     * every season in which they are on a roster or played for a team (not
     * the ghost team). At most one team per season (R6.8); with legacy data
     * the roster wins.
     *
     * @return list<array{season_id: int, season_name: string, team_season_id: int, team_name: string}>
     */
    public function seasons(int $playerId): array;

    /**
     * Raw data of the player's history in a season (R39.3, R39.4).
     */
    public function seasonRawData(int $playerId, int $seasonId): PlayerSeasonRawData;
}
