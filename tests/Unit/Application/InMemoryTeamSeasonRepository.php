<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\TeamSeasonStore;

/**
 * In-memory fake of the team season persistence port for the use case tests.
 *
 * Lives in its own file so that several management tests (team, season
 * management) can share it without multiple declarations.
 */
final class InMemoryTeamSeasonRepository implements TeamSeasonStore
{
    /** @var array<int, array<string, mixed>> */
    private array $assignments = [];

    private int $nextId = 1;

    public function create(int $teamId, int $seasonId, string $teamLabel): int
    {
        $id = $this->nextId++;
        $this->assignments[$id] = [
            'id'        => $id,
            'team_id'   => $teamId,
            'season_id' => $seasonId,
            'team_name'  => $teamLabel,
            'is_ghost_team' => 0,
        ];

        return $id;
    }

    /**
     * As in the {@see \Bowlingliga\Persistence\TeamSeasonRepository}: creates
     * the flagged ghost team of a season.
     */
    public function createGhostTeam(int $teamId, int $seasonId, string $teamLabel): int
    {
        $id = $this->create($teamId, $seasonId, $teamLabel);
        $this->assignments[$id]['is_ghost_team'] = 1;

        return $id;
    }

    public function updateTeamName(int $id, string $teamLabel): void
    {
        if (isset($this->assignments[$id])) {
            $this->assignments[$id]['team_name'] = $teamLabel;
        }
    }

    public function delete(int $id): void
    {
        unset($this->assignments[$id]);
    }

    public function find(int $id): ?array
    {
        return $this->assignments[$id] ?? null;
    }

    public function findAllForSeason(int $seasonId): array
    {
        $matches = [];
        foreach ($this->assignments as $assignment) {
            if ($assignment['season_id'] === $seasonId) {
                $matches[] = $assignment;
            }
        }

        return $matches;
    }

    public function quantity(): int
    {
        return count($this->assignments);
    }
}
