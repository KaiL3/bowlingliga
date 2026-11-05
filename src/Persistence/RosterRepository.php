<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\RosterReader;
use Bowlingliga\Application\RosterStore;
use Bowlingliga\Domain\RosterPlayer;

/**
 * Raw data repository for the per-season assignment player → team (table
 * `roster`, R6.6, R21.1).
 *
 * A player is assigned at most once per team season (unique constraint on
 * (team_season_id, player_id)). Write operations transactional (R4.3),
 * prepared statements (R4.1), reads unchanged (R4.5).
 *
 * Serves both the writing {@see RosterStore} (create/remove assignment) and
 * the reading {@see RosterReader}, which
 * {@see \Bowlingliga\Application\LineupManagement} needs for the roster
 * membership check (R21.5) and for determining the blind player (R27.4).
 */
final class RosterRepository extends AbstractRepository implements RosterStore, RosterReader
{
    /**
     * Assigns a player to the roster of a team season (R6.6, R21.1).
     */
    public function create(int $teamSeasonId, int $playerId): int
    {
        return $this->transactional('create_roster_entry', function ($pdo) use ($teamSeasonId, $playerId): int {
            $this->perform(
                'INSERT INTO roster (team_season_id, player_id) VALUES (:team_season_id, :player_id)',
                ['team_season_id' => $teamSeasonId, 'player_id' => $playerId]
            );

            return (int) $pdo->lastInsertId();
        });
    }

    public function findAssignmentInSeason(int $teamSeasonId, int $playerId): ?array
    {
        $row = $this->findOne(
            'SELECT k.team_season_id, ts.team_name, sp.name AS player_name
             FROM roster k
             JOIN team_season ts ON ts.id = k.team_season_id
             JOIN player sp ON sp.id = k.player_id
             WHERE k.player_id = :player
               AND ts.season_id = (SELECT season_id FROM team_season WHERE id = :team_season)
             ORDER BY k.team_season_id = :team_season2 DESC
             LIMIT 1',
            ['player' => $playerId, 'team_season' => $teamSeasonId, 'team_season2' => $teamSeasonId]
        );

        return $row === null ? null : [
            'team_season_id' => (int) $row['team_season_id'],
            'team_name'       => (string) $row['team_name'],
            'player_name'    => (string) $row['player_name'],
        ];
    }

    /**
     * Removes a roster assignment (R6.5).
     */
    public function delete(int $id): void
    {
        $this->transactional('delete_roster_entry', function () use ($id): void {
            $this->perform('DELETE FROM roster WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Returns a roster assignment or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, team_season_id, player_id FROM roster WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Returns all roster entries of a team season (R4.5).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForTeamSeason(int $teamSeasonId): array
    {
        return $this->findAll(
            'SELECT id, team_season_id, player_id FROM roster WHERE team_season_id = :team_season_id ORDER BY id',
            ['team_season_id' => $teamSeasonId]
        );
    }

    /**
     * Returns the player ids of a team season's roster (R21.1, R21.5).
     *
     * @return list<int>
     */
    public function findPlayerIdsForTeamSeason(int $teamSeasonId): array
    {
        $rows = $this->findAll(
            'SELECT player_id FROM roster WHERE team_season_id = :team_season_id ORDER BY player_id',
            ['team_season_id' => $teamSeasonId]
        );

        return array_map(static fn (array $row): int => (int) $row['player_id'], $rows);
    }

    /**
     * Returns the roster players of a team season together with the games
     * actually played so far, for determining the blind player (R27.4).
     *
     * Every game result not marked blind (`game_result.is_blind = 0`)
     * of a lineup position on which the player is listed in this team season
     * counts as a played game; this matches the definition "games actually
     * played personally" (R11, R27.4). Roster players without played games are
     * returned with the count 0.
     *
     * The field {@see RosterPlayer::$regularlyLinedUp} is always `false`; the
     * concrete lineup of the evening is set by
     * {@see \Bowlingliga\Application\LineupManagement}.
     *
     * @return list<RosterPlayer>
     */
    public function findRosterWithPlayedGames(int $teamSeasonId): array
    {
        $rows = $this->findAll(
            'SELECT k.player_id AS player_id,
                    COUNT(e.id) AS games_played
             FROM roster k
             LEFT JOIN lineup a
                    ON a.team_season_id = k.team_season_id
                   AND a.player_id = k.player_id
             LEFT JOIN game_result e
                    ON e.lineup_id = a.id
                   AND e.is_blind = 0
             WHERE k.team_season_id = :team_season_id
             GROUP BY k.player_id
             ORDER BY k.player_id',
            ['team_season_id' => $teamSeasonId]
        );

        return array_map(
            static fn (array $row): RosterPlayer => new RosterPlayer(
                (int) $row['player_id'],
                (int) $row['games_played'],
                false
            ),
            $rows
        );
    }

    /**
     * Returns all roster entries of the teams of a season including the player
     * name, for roster maintenance in the admin area (R21.1).
     *
     * @return list<array{roster_id: int, team_season_id: int, player_id: int, name: string}>
     */
    public function findSeasonRosterWithNames(int $seasonId): array
    {
        $rows = $this->findAll(
            'SELECT k.id AS roster_id, k.team_season_id, k.player_id, sp.name
             FROM roster k
             JOIN team_season ts ON ts.id = k.team_season_id
             JOIN player sp ON sp.id = k.player_id
             WHERE ts.season_id = :season_id
             ORDER BY sp.name',
            ['season_id' => $seasonId]
        );

        return array_map(
            static fn (array $z): array => [
                'roster_id'       => (int) $z['roster_id'],
                'team_season_id' => (int) $z['team_season_id'],
                'player_id'     => (int) $z['player_id'],
                'name'           => (string) $z['name'],
            ],
            $rows
        );
    }
}
