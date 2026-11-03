<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\TeamStore;

/**
 * In-memory fake of the team persistence port for the use case tests.
 *
 * Lives in its own file so that several management tests (team, season
 * management) can share it without multiple declarations.
 */
final class InMemoryTeamRepository implements TeamStore
{
    /** @var array<int, array<string, mixed>> */
    private array $teams = [];

    private int $nextId = 1;

    public function create(): int
    {
        $id = $this->nextId++;
        $this->teams[$id] = ['id' => $id];

        return $id;
    }

    public function delete(int $id): void
    {
        unset($this->teams[$id]);
    }

    public function find(int $id): ?array
    {
        return $this->teams[$id] ?? null;
    }

    public function quantity(): int
    {
        return count($this->teams);
    }
}
