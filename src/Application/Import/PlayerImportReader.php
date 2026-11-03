<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Import;

/**
 * Read port of the player import: what already exists in the database.
 *
 * Implemented by {@see \Bowlingliga\Persistence\Import\PlayerImportRepository};
 * in tests a fake with fixed values is enough.
 */
interface PlayerImportReader
{
    public function seasonExists(int $seasonId): bool;

    /**
     * Names of all players.
     *
     * @return list<string>
     */
    public function playerNames(): array;

    /**
     * Teams of a season.
     *
     * @return list<array{id: int, name: string, is_ghost_team: bool}>
     */
    public function seasonTeams(int $seasonId): array;
}
