<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

/**
 * Raw data repository for game results (table `game_result`, R22.1,
 * R27.5).
 *
 * Keeps per lineup position and game (1–3) the entered net value (0–300,
 * ensured by a DB CHECK) and the blind flag valid per game. The stored net
 * value is always the sheet value (R27.10). Write operations transactional
 * (R4.3), prepared statements (R4.1), reads unchanged (R4.5).
 */
final class GameResultRepository extends AbstractRepository
{
    /**
     * Creates a game result and returns its id (R22.1).
     */
    public function create(
        int $lineupId,
        int $gameNumber,
        int $net,
        bool $isBlind = false
    ): int {
        return $this->transactional('create_game_result', function ($pdo) use ($lineupId, $gameNumber, $net, $isBlind): int {
            $this->perform(
                'INSERT INTO game_result (lineup_id, game_number, net, is_blind)
                 VALUES (:lineup_id, :game_number, :net, :is_blind)',
                [
                    'lineup_id' => $lineupId,
                    'game_number' => $gameNumber,
                    'net'          => $net,
                    'is_blind'      => $isBlind ? 1 : 0,
                ]
            );

            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Updates net value and blind flag of a game (R22.2–3, R22.6).
     */
    public function update(int $id, int $net, bool $isBlind): void
    {
        $this->transactional('update_game_result', function () use ($id, $net, $isBlind): void {
            $this->perform(
                'UPDATE game_result SET net = :net, is_blind = :is_blind WHERE id = :id',
                ['id' => $id, 'net' => $net, 'is_blind' => $isBlind ? 1 : 0]
            );
        });
    }

    /**
     * Deletes a game result (R24.4).
     */
    public function delete(int $id): void
    {
        $this->transactional('delete_game_result', function () use ($id): void {
            $this->perform('DELETE FROM game_result WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Returns a game result or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, lineup_id, game_number, net, is_blind FROM game_result WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Returns all game results of a lineup position (R4.5).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForLineup(int $lineupId): array
    {
        return $this->findAll(
            'SELECT id, lineup_id, game_number, net, is_blind
             FROM game_result WHERE lineup_id = :lineup_id ORDER BY game_number',
            ['lineup_id' => $lineupId]
        );
    }
}
