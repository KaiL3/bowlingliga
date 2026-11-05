<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

/**
 * Raw data repository for the lane pairs of a season (table `lane_pair`,
 * R8.11, R18).
 *
 * A lane pair consists of two adjacent lane numbers (`lane_a`, `lane_b`).
 * Write operations transactional (R4.3), prepared statements (R4.1), reads
 * unchanged (R4.5).
 */
final class LanePairRepository extends AbstractRepository
{
    /**
     * Creates a lane pair for a season and returns its id (R8.11).
     */
    public function create(int $seasonId, int $laneA, int $laneB): int
    {
        return $this->transactional('create_lane_pair', function ($pdo) use ($seasonId, $laneA, $laneB): int {
            $this->perform(
                'INSERT INTO lane_pair (season_id, lane_a, lane_b) VALUES (:season_id, :lane_a, :lane_b)',
                ['season_id' => $seasonId, 'lane_a' => $laneA, 'lane_b' => $laneB]
            );

            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Deletes a lane pair (R4).
     */
    public function delete(int $id): void
    {
        $this->transactional('delete_lane_pair', function () use ($id): void {
            $this->perform('DELETE FROM lane_pair WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Returns a lane pair or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, season_id, lane_a, lane_b FROM lane_pair WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Returns all lane pairs of a season (R4.5).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForSeason(int $seasonId): array
    {
        return $this->findAll(
            'SELECT id, season_id, lane_a, lane_b FROM lane_pair WHERE season_id = :season_id ORDER BY lane_a',
            ['season_id' => $seasonId]
        );
    }
}
