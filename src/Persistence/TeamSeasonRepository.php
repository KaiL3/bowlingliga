<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

/**
 * Raw data repository for a team's per-season participation including the
 * team name (table `team_season`, R7.1–4, R8.7).
 *
 * The team name is unique per season (unique constraint on (season_id,
 * teamname)). Write operations transactional (R4.3), prepared statements
 * (R4.1), reads unchanged (R4.5).
 */
final class TeamSeasonRepository extends AbstractRepository implements \Bowlingliga\Application\TeamSeasonStore
{
    /**
     * Assigns a real team to a season with a team name (R7.1).
     */
    public function create(int $teamId, int $seasonId, string $teamLabel): int
    {
        return $this->append($teamId, $seasonId, $teamLabel, false);
    }

    /**
     * Creates the ghost team of a season (R20, R9.6). The ghost team consists
     * only of ghost players (no roster) and scores fixed results in every
     * round; it is uniquely marked via the flag `is_ghost_team`.
     */
    public function createGhostTeam(int $teamId, int $seasonId, string $teamLabel): int
    {
        return $this->append($teamId, $seasonId, $teamLabel, true);
    }

    private function append(int $teamId, int $seasonId, string $teamLabel, bool $isGhostTeam): int
    {
        return $this->transactional('create_team_season', function ($pdo) use ($teamId, $seasonId, $teamLabel, $isGhostTeam): int {
            $this->perform(
                'INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team)
                 VALUES (:team_id, :season_id, :team_name, :is_ghost_team)',
                [
                    'team_id'         => $teamId,
                    'season_id'       => $seasonId,
                    'team_name'        => $teamLabel,
                    'is_ghost_team' => $isGhostTeam ? 1 : 0,
                ]
            );

            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Changes the per-season team name (R7.4).
     */
    public function updateTeamName(int $id, string $teamLabel): void
    {
        $this->transactional('update_team_name', function () use ($id, $teamLabel): void {
            $this->perform(
                'UPDATE team_season SET team_name = :team_name WHERE id = :id',
                ['id' => $id, 'team_name' => $teamLabel]
            );
        });
    }

    /**
     * Ends a team's participation in a season (R7.6, R8.9).
     */
    public function delete(int $id): void
    {
        $this->transactional('delete_team_season', function () use ($id): void {
            $this->perform('DELETE FROM team_season WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Returns a team season assignment or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, team_id, season_id, team_name, is_ghost_team FROM team_season WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Returns all team season assignments of a season (R4.5).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForSeason(int $seasonId): array
    {
        return $this->findAll(
            'SELECT id, team_id, season_id, team_name, is_ghost_team
             FROM team_season WHERE season_id = :season_id ORDER BY team_name',
            ['season_id' => $seasonId]
        );
    }
}
