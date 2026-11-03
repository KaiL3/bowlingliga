<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\PreviousSeasonRecordStore;

/**
 * Raw data repository for previous season records (table
 * `previous_season_record`, R29).
 *
 * Keeps per player and season the manually entered number of previous season
 * games played and the net pins total (both >= 0). A record belongs to the
 * season it opens; there is at most one per player and season (unique
 * constraint, R29.11). Write operations transactional (R4.3), prepared
 * statements (R4.1), reads unchanged (R4.5).
 *
 * Also implements the {@see PreviousSeasonRecordStore} port, via which
 * {@see \Bowlingliga\Application\PreviousSeasonRecordManagement} enters,
 * changes and removes the records (R29.8, R29.9).
 */
final class PreviousSeasonRecordRepository extends AbstractRepository implements PreviousSeasonRecordStore
{
    /**
     * Creates or updates a player's previous season record for a season
     * (upsert per player and season, R29.8–9).
     */
    public function save(int $seasonId, int $playerId, int $numberOfGames, int $netPinsTotal): void
    {
        $this->transactional('Vorsaison-Bestandsdaten speichern', function () use ($seasonId, $playerId, $numberOfGames, $netPinsTotal): void {
            $this->perform(
                'INSERT INTO previous_season_record (season_id, player_id, game_count, net_pins_total)
                 VALUES (:season_id, :player_id, :game_count, :net_pins_total)
                 ON DUPLICATE KEY UPDATE
                    game_count = VALUES(game_count),
                    net_pins_total = VALUES(net_pins_total)',
                [
                    'season_id'      => $seasonId,
                    'player_id'      => $playerId,
                    'game_count'     => $numberOfGames,
                    'net_pins_total' => $netPinsTotal,
                ]
            );
        });
    }

    /**
     * Deletes a player's previous season record for a season (R4).
     */
    public function delete(int $seasonId, int $playerId): void
    {
        $this->transactional('Vorsaison-Bestandsdaten löschen', function () use ($seasonId, $playerId): void {
            $this->perform(
                'DELETE FROM previous_season_record WHERE season_id = :season_id AND player_id = :player_id',
                ['season_id' => $seasonId, 'player_id' => $playerId]
            );
        });
    }

    /**
     * Returns a player's previous season record for a season, or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $seasonId, int $playerId): ?array
    {
        return $this->findOne(
            'SELECT id, season_id, player_id, game_count, net_pins_total
             FROM previous_season_record WHERE season_id = :season_id AND player_id = :player_id',
            ['season_id' => $seasonId, 'player_id' => $playerId]
        );
    }

    /**
     * Returns all previous season records (R4.5).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllRecords(): array
    {
        return $this->findAll(
            'SELECT id, season_id, player_id, game_count, net_pins_total FROM previous_season_record ORDER BY season_id, player_id'
        );
    }
}
