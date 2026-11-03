<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

/**
 * Port for persisting players (R6.1, R6.4, R6.5, R25.1).
 *
 * Decouples {@see PlayerManagement} from the concrete persistence technology:
 * the use case only knows the domain write operations for players. The port
 * is implemented by the {@see \Bowlingliga\Persistence\PlayerRepository};
 * in tests a slim in-memory fake is enough.
 */
interface PlayerStore
{
    /**
     * Creates a player with name and gender and returns its id (R6.1).
     *
     * @param 'male'|'female' $gender
     */
    public function create(string $name, string $gender): int;

    /**
     * Updates name and gender of a player (R6.4).
     *
     * @param 'male'|'female' $gender
     */
    public function update(int $id, string $name, string $gender): void;

    /**
     * Removes a player; the per-season roster assignments are removed as well
     * via the database's `ON DELETE CASCADE` (R6.5, R25.4).
     */
    public function delete(int $id): void;
}
