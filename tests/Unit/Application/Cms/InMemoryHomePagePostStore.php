<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Cms;

use Bowlingliga\Application\Cms\HomePagePostStore;

/**
 * In-memory fake of the {@see HomePagePostStore} port for the CMS unit tests,
 * without a database.
 */
final class InMemoryHomePagePostStore implements HomePagePostStore
{
    /** @var array<int, array{title: string, text: string}> */
    public array $contents = [];

    private int $nextId = 1;

    public function create(string $title, string $text): int
    {
        $id = $this->nextId++;
        $this->contents[$id] = ['title' => $title, 'text' => $text];

        return $id;
    }

    public function update(int $id, string $title, string $text): void
    {
        $this->contents[$id] = ['title' => $title, 'text' => $text];
    }

    public function delete(int $id): void
    {
        unset($this->contents[$id]);
    }

    public function quantity(): int
    {
        return count($this->contents);
    }
}
