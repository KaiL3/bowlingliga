<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\SeasonStore;

/**
 * In-memory fake of the season persistence port for the use case tests
 * (season and matchday management), without a database.
 */
final class InMemorySeasonRepository implements SeasonStore
{
    /** @var array<int, array<string, mixed>> */
    private array $seasons = [];

    private int $nextId = 1;

    /**
     * Creates a season with a given id (test helper to link configurations and
     * assignments specifically).
     */
    public function stash(int $id, string $name, string $startDate, string $endDate, ?int $matchdayCount = null): void
    {
        $this->seasons[$id] = [
            'id'               => $id,
            'name'             => $name,
            'start_date'       => $startDate,
            'end_date'         => $endDate,
            'matchday_count' => $matchdayCount,
        ];

        $this->nextId = max($this->nextId, $id + 1);
    }

    public function create(string $name, string $startDate, string $endDate, ?int $matchdayCount = null): int
    {
        $id = $this->nextId++;
        $this->seasons[$id] = [
            'id'               => $id,
            'name'             => $name,
            'start_date'       => $startDate,
            'end_date'         => $endDate,
            'matchday_count' => $matchdayCount,
        ];

        return $id;
    }

    public function update(int $id, string $name, string $startDate, string $endDate, ?int $matchdayCount = null): void
    {
        if (isset($this->seasons[$id])) {
            $this->seasons[$id] = ['score_sheet_language' => $this->seasons[$id]['score_sheet_language'] ?? null] + [
                'id'               => $id,
                'name'             => $name,
                'start_date'       => $startDate,
                'end_date'         => $endDate,
                'matchday_count' => $matchdayCount,
            ];
        }
    }

    public function delete(int $id): void
    {
        unset($this->seasons[$id]);
    }

    public function setScoreSheetLanguage(int $id, ?string $language): void
    {
        if (isset($this->seasons[$id])) {
            $this->seasons[$id]['score_sheet_language'] = $language;
        }
    }

    public function find(int $id): ?array
    {
        return $this->seasons[$id] ?? null;
    }

    public function findAllSeasons(): array
    {
        return array_values($this->seasons);
    }

    public function quantity(): int
    {
        return count($this->seasons);
    }
}
