<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

/**
 * Raw data repository for players (table `player`, R6, R25.1–2).
 *
 * Stores only name and gender (data minimisation). All write operations run
 * transactionally (R4.3) via prepared statements (R4.1); reads return the
 * persisted records unchanged (R4.5).
 */
final class PlayerRepository extends AbstractRepository implements \Bowlingliga\Application\PlayerStore
{
    /**
     * Creates a player and returns its generated id (R6.1).
     *
     * @param 'male'|'female' $gender
     */
    public function create(string $name, string $gender): int
    {
        return $this->transactional('create_player', function ($pdo) use ($name, $gender): int {
            $this->perform(
                'INSERT INTO player (name, gender) VALUES (:name, :gender)',
                ['name' => $name, 'gender' => $gender]
            );

            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Updates name and gender of a player (R6.4).
     *
     * @param 'male'|'female' $gender
     */
    public function update(int $id, string $name, string $gender): void
    {
        $this->transactional('update_player', function () use ($id, $name, $gender): void {
            $this->perform(
                'UPDATE player SET name = :name, gender = :gender WHERE id = :id',
                ['id' => $id, 'name' => $name, 'gender' => $gender]
            );
        });
    }

    /**
     * Removes a player (R6.5).
     */
    public function delete(int $id): void
    {
        $this->transactional('delete_player', function () use ($id): void {
            $this->perform('DELETE FROM player WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Returns a player or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, name, gender FROM player WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Returns all players (R4.5).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllPlayers(): array
    {
        return $this->findAll('SELECT id, name, gender FROM player ORDER BY name');
    }
}
