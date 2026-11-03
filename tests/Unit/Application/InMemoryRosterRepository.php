<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\RosterReader;
use Bowlingliga\Application\RosterStore;
use Bowlingliga\Domain\RosterPlayer;

/**
 * In-memory fake of the roster persistence port for the use case tests,
 * without a database.
 */
final class InMemoryRosterRepository implements RosterStore, RosterReader
{
    /** @var array<int, array<string, mixed>> */
    private array $roster = [];

    /** @var array<int, int> team season id → season id (if not given: season 1) */
    public array $seasonOfTeamSeason = [];

    private int $nextId = 1;

    public function create(int $teamSeasonId, int $playerId): int
    {
        $id = $this->nextId++;
        $this->roster[$id] = ['id' => $id, 'team_season_id' => $teamSeasonId, 'player_id' => $playerId];

        return $id;
    }

    public function findPlayerIdsForTeamSeason(int $teamSeasonId): array
    {
        $ids = [];
        foreach ($this->roster as $entry) {
            if ($entry['team_season_id'] === $teamSeasonId) {
                $ids[] = $entry['player_id'];
            }
        }

        return $ids;
    }

    public function findRosterWithPlayedGames(int $teamSeasonId): array
    {
        return array_map(
            static fn (int $playerId): RosterPlayer => new RosterPlayer($playerId, 0, false),
            $this->findPlayerIdsForTeamSeason($teamSeasonId)
        );
    }

    public function delete(int $id): void
    {
        unset($this->roster[$id]);
    }

    public function findAssignmentInSeason(int $teamSeasonId, int $playerId): ?array
    {
        $season = $this->seasonOfTeamSeason[$teamSeasonId] ?? 1;
        foreach ($this->roster as $entry) {
            if ($entry['player_id'] === $playerId && ($this->seasonOfTeamSeason[$entry['team_season_id']] ?? 1) === $season) {
                return [
                    'team_season_id' => $entry['team_season_id'],
                    'team_name'       => 'Team ' . $entry['team_season_id'],
                    'player_name'    => 'Spieler ' . $playerId,
                ];
            }
        }

        return null;
    }

    public function find(int $id): ?array
    {
        return $this->roster[$id] ?? null;
    }

    public function countForPlayer(int $playerId): int
    {
        $quantity = 0;
        foreach ($this->roster as $entry) {
            if ($entry['player_id'] === $playerId) {
                ++$quantity;
            }
        }

        return $quantity;
    }

    /**
     * Removes all roster entries of a deleted player (reproduces the DB-side
     * ON DELETE CASCADE, R6.5/R25.4).
     */
    public function removeForPlayer(int $playerId): void
    {
        foreach ($this->roster as $id => $entry) {
            if ($entry['player_id'] === $playerId) {
                unset($this->roster[$id]);
            }
        }
    }
}
