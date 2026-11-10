<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\TeamProfile;

use Bowlingliga\Application\TeamProfile\TeamProfileReader;
use Bowlingliga\Persistence\AbstractRepository;

/**
 * Persistence adapter of the {@see TeamProfileReader} port (R41): reads team,
 * seasons and matchdays read-only via prepared statements (R4.1).
 */
final class TeamProfileRepository extends AbstractRepository implements TeamProfileReader
{
    public function team(int $teamSeasonId): ?array
    {
        $row = $this->findOne(
            'SELECT ts.id, ts.team_id, ts.season_id, s.name AS season_name, ts.team_name
             FROM team_season ts JOIN season s ON s.id = ts.season_id
             WHERE ts.id = :id AND ts.is_ghost_team = 0',
            ['id' => $teamSeasonId]
        );

        return $row === null ? null : [
            'team_season_id' => (int) $row['id'],
            'team_id'        => (int) $row['team_id'],
            'season_id'      => (int) $row['season_id'],
            'season_name'    => (string) $row['season_name'],
            'team_name'      => (string) $row['team_name'],
        ];
    }

    public function seasons(int $teamId): array
    {
        $rows = $this->findAll(
            'SELECT ts.id, ts.season_id, s.name AS season_name
             FROM team_season ts JOIN season s ON s.id = ts.season_id
             WHERE ts.team_id = :team AND ts.is_ghost_team = 0
             ORDER BY s.start_date, s.id',
            ['team' => $teamId]
        );

        return array_map(static fn (array $row): array => [
            'team_season_id' => (int) $row['id'],
            'season_id'      => (int) $row['season_id'],
            'season_name'    => (string) $row['season_name'],
        ], $rows);
    }

    public function matchdays(int $seasonId): array
    {
        $rows = $this->findAll(
            'SELECT id, matchday_number, date, is_played, is_cancelled
             FROM matchday WHERE season_id = :season ORDER BY matchday_number',
            ['season' => $seasonId]
        );

        return array_map(static fn (array $row): array => [
            'id'        => (int) $row['id'],
            'number'    => (int) $row['matchday_number'],
            'date'      => (string) $row['date'],
            'played'    => (int) $row['is_played'] === 1,
            'cancelled' => (int) $row['is_cancelled'] === 1,
        ], $rows);
    }
}
