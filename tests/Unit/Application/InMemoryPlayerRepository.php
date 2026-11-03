<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\PlayerStore;

/**
 * In-memory fake of the player persistence port for the use case tests,
 * without a database.
 *
 * Deleting a player reproduces the database's `ON DELETE CASCADE` by removing
 * the corresponding roster entries in the linked
 * {@see InMemoryRosterRepository} (R6.5/R25.4).
 */
final class InMemoryPlayerRepository implements PlayerStore
{
    /** @var array<int, array<string, mixed>> */
    private array $player = [];

    private int $nextId = 1;

    public function __construct(private readonly InMemoryRosterRepository $roster)
    {
    }

    public function create(string $name, string $gender): int
    {
        $id = $this->nextId++;
        $this->player[$id] = ['id' => $id, 'name' => $name, 'gender' => $gender];

        return $id;
    }

    public function update(int $id, string $name, string $gender): void
    {
        if (isset($this->player[$id])) {
            $this->player[$id]['name']       = $name;
            $this->player[$id]['gender'] = $gender;
        }
    }

    public function delete(int $id): void
    {
        unset($this->player[$id]);
        $this->roster->removeForPlayer($id);
    }

    public function find(int $id): ?array
    {
        return $this->player[$id] ?? null;
    }

    public function quantity(): int
    {
        return count($this->player);
    }
}
