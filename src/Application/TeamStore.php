<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

/**
 * Port for persisting the team identity spanning seasons (R7.1, R7.5).
 *
 * Decouples {@see TeamManagement} from the concrete persistence technology.
 * The port is implemented by the
 * {@see \Bowlingliga\Persistence\TeamRepository}; in tests a slim in-memory
 * fake is enough.
 */
interface TeamStore
{
    /**
     * Creates a new team identity and returns its id (R7.1).
     */
    public function create(): int;

    /**
     * Removes a team identity (R7).
     */
    public function delete(int $id): void;

    /**
     * Returns a team identity or null (R4.5, R7.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array;
}
