<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\MatchdayStore;

/**
 * In-memory fake of the matchday persistence port for the use case tests,
 * without a database.
 */
final class InMemoryMatchdayRepository implements MatchdayStore
{
    /** @var array<int, array<string, mixed>> */
    private array $matchdays = [];

    private int $nextId = 1;

    public function create(
        int $seasonId,
        int $matchdayCounter,
        string $date,
        string $matchdayType = 'regular',
        bool $isPlayed = false
    ): int {
        $id = $this->nextId++;
        $this->matchdays[$id] = [
            'id'               => $id,
            'season_id'        => $seasonId,
            'matchday_number' => $matchdayCounter,
            'date'            => $date,
            'matchday_type'     => $matchdayType,
            'is_played'     => $isPlayed ? 1 : 0,
        ];

        return $id;
    }

    public function update(int $id, string $date, string $matchdayType): void
    {
        if (isset($this->matchdays[$id])) {
            $this->matchdays[$id]['date']        = $date;
            $this->matchdays[$id]['matchday_type'] = $matchdayType;
        }
    }

    public function delete(int $id): void
    {
        unset($this->matchdays[$id]);
    }

    public function find(int $id): ?array
    {
        return $this->matchdays[$id] ?? null;
    }

    public function findAllForSeason(int $seasonId): array
    {
        $matches = [];
        foreach ($this->matchdays as $matchday) {
            if ($matchday['season_id'] === $seasonId) {
                $matches[] = $matchday;
            }
        }

        return $matches;
    }
}
