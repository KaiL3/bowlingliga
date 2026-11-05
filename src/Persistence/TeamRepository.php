<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

/**
 * Raw data repository for the team identity spanning seasons (table `team`,
 * R7.1, R7.4).
 *
 * The table only keeps the id; the per-season team name lives in
 * `team_season` and is managed via {@see TeamSeasonRepository}. Write
 * operations transactional (R4.3), prepared statements (R4.1), reads
 * unchanged (R4.5).
 */
final class TeamRepository extends AbstractRepository implements \Bowlingliga\Application\TeamStore
{
    /**
     * Creates a new team identity and returns its id (R7.1).
     */
    public function create(): int
    {
        return $this->transactional('create_team', function ($pdo): int {
            // team only has an AUTO_INCREMENT id.
            $this->perform('INSERT INTO team () VALUES ()');

            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Removes a team identity (R7).
     */
    public function delete(int $id): void
    {
        $this->transactional('delete_team', function () use ($id): void {
            $this->perform('DELETE FROM team WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Returns a team identity or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->findOne('SELECT id FROM team WHERE id = :id', ['id' => $id]);
    }

    /**
     * Returns all team identities (R4.5).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllTeams(): array
    {
        return $this->findAll('SELECT id FROM team ORDER BY id');
    }
}
