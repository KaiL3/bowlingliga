<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\SeasonReader;

/**
 * In-memory fake of the season read port for the use case tests, without a
 * database.
 */
final class InMemorySeasonReader implements SeasonReader
{
    /** @var array<int, array<string, mixed>> */
    private array $seasons = [];

    public function stash(int $id, string $startDate, string $endDate = '2099-12-31'): void
    {
        $this->seasons[$id] = [
            'id'         => $id,
            'start_date' => $startDate,
            'end_date'   => $endDate,
        ];
    }

    public function find(int $id): ?array
    {
        return $this->seasons[$id] ?? null;
    }
}
