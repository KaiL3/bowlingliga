<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Domain\Handicap\FrozenHandicapStore;

/**
 * Raw data repository for frozen handicaps (table `frozen_handicap`,
 * R12.12, R35.5).
 *
 * The frozen handicap is **raw data** (not a derived value): it preserves per
 * matchday/player the handicap state immediately before the matchday and is
 * never touched by the cache invalidation mechanism. The HDC value is in the
 * range 0–300 (ensured by a DB CHECK). There is at most one entry per
 * matchday/player (unique constraint). Write operations transactional
 * (R4.3), prepared statements (R4.1), reads unchanged (R4.5).
 */
final class FrozenHandicapRepository extends AbstractRepository implements FrozenHandicapStore
{
    /**
     * Freezes a player's handicap for a matchday or updates it (upsert per
     * matchday/player, R12.12, R12.18).
     */
    public function save(int $matchdayId, int $playerId, int $hdcValue): void
    {
        $this->transactional('Eingefrorenes Handicap speichern', function () use ($matchdayId, $playerId, $hdcValue): void {
            $this->perform(
                'INSERT INTO frozen_handicap (matchday_id, player_id, handicap)
                 VALUES (:matchday_id, :player_id, :handicap)
                 ON DUPLICATE KEY UPDATE handicap = VALUES(handicap)',
                ['matchday_id' => $matchdayId, 'player_id' => $playerId, 'handicap' => $hdcValue]
            );
        });
    }

    /**
     * Deletes a frozen handicap (R4).
     */
    public function delete(int $id): void
    {
        $this->transactional('Eingefrorenes Handicap löschen', function () use ($id): void {
            $this->perform('DELETE FROM frozen_handicap WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Returns a player's frozen handicap for a matchday, or null (R2.8, R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function findForMatchdayAndPlayer(int $matchdayId, int $playerId): ?array
    {
        return $this->findOne(
            'SELECT id, matchday_id, player_id, handicap
             FROM frozen_handicap WHERE matchday_id = :matchday_id AND player_id = :player_id',
            ['matchday_id' => $matchdayId, 'player_id' => $playerId]
        );
    }

    /**
     * Returns all frozen handicaps of a matchday (R4.5).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForMatchday(int $matchdayId): array
    {
        return $this->findAll(
            'SELECT id, matchday_id, player_id, handicap
             FROM frozen_handicap WHERE matchday_id = :matchday_id ORDER BY player_id',
            ['matchday_id' => $matchdayId]
        );
    }
}
