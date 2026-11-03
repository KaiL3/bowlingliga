<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

/**
 * Port for persisting a player's manually entered previous season record
 * (R29.8, R29.9).
 *
 * Decouples {@see PreviousSeasonRecordManagement} from the concrete
 * persistence technology: the use case only knows the domain write
 * operations. A record belongs to the season it opens; there is at most one
 * record per player and season (upsert, R29.11). The port is implemented by
 * the {@see \Bowlingliga\Persistence\PreviousSeasonRecordRepository}; in tests
 * a slim in-memory fake is enough.
 */
interface PreviousSeasonRecordStore
{
    /**
     * Creates or updates a player's previous season record for a season
     * (upsert per player and season, R29.8, R29.9).
     */
    public function save(int $seasonId, int $playerId, int $numberOfGames, int $netPinsTotal): void;

    /**
     * Removes a player's previous season record for a season.
     */
    public function delete(int $seasonId, int $playerId): void;
}
