<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\ResultEntry;

use Bowlingliga\Application\SeasonConfiguration;
use Bowlingliga\Persistence\AbstractRepository;
use Bowlingliga\Presentation\EntryData;

/**
 * Production adapter of the {@see EntryData} port (task 35.3).
 *
 * Read-only via prepared statements (R4.1, R4.5).
 */
final class EntryDataRepository extends AbstractRepository implements EntryData
{
    public function teamFixture(int $fixtureId): ?array
    {
        $row = $this->findOne(
            'SELECT fx.id, fx.matchday_id, fx.completion_status, fx.home_team_season_id, fx.away_team_season_id,
                    md.matchday_number, md.date, md.matchday_type, md.season_id,
                    lp.lane_a, lp.lane_b, COALESCE(k.ghost_score, ' . SeasonConfiguration::DEFAULT_GHOST_SCORE . ') AS ghost_score
             FROM fixture fx
             JOIN matchday md ON md.id = fx.matchday_id
             LEFT JOIN lane_pair lp ON lp.id = fx.lane_pair_id
             LEFT JOIN season_config k ON k.season_id = md.season_id
             WHERE fx.id = :id',
            ['id' => $fixtureId]
        );
        if ($row === null) {
            return null;
        }

        return [
            'id'               => (int) $row['id'],
            'matchday_id'      => (int) $row['matchday_id'],
            'matchday_number' => (int) $row['matchday_number'],
            'date'            => (string) $row['date'],
            'matchday_type'     => (string) $row['matchday_type'],
            'season_id'        => (int) $row['season_id'],
            'status'           => (string) $row['completion_status'],
            'lanes'           => $row['lane_a'] !== null ? $row['lane_a'] . '/' . $row['lane_b'] : null,
            'ghost_score'      => (int) $row['ghost_score'],
            'home'             => $this->team($row['home_team_season_id']),
            'away'             => $this->team($row['away_team_season_id']),
        ];
    }

    public function roster(int $teamSeasonId): array
    {
        $rows = $this->findAll(
            'SELECT sp.id, sp.name FROM roster k JOIN player sp ON sp.id = k.player_id
             WHERE k.team_season_id = :id ORDER BY sp.name',
            ['id' => $teamSeasonId]
        );

        return array_map(
            static fn (array $z): array => ['player_id' => (int) $z['id'], 'name' => (string) $z['name']],
            $rows
        );
    }

    public function originalScoreSheet(int $fixtureId): array
    {
        $rows = $this->findAll(
            'SELECT id FROM original_score_sheet WHERE fixture_id = :id ORDER BY id',
            ['id' => $fixtureId]
        );

        return array_map(static fn (array $z): int => (int) $z['id'], $rows);
    }

    public function seasonMatchdays(int $seasonId): array
    {
        $matchdays = [];
        foreach ($this->findAll(
            'SELECT id, matchday_number, date FROM matchday WHERE season_id = :season ORDER BY matchday_number',
            ['season' => $seasonId]
        ) as $row) {
            $matchdays[(int) $row['id']] = ['number' => (int) $row['matchday_number'], 'date' => (string) $row['date']];
        }

        return $matchdays;
    }

    public function playerNames(array $playerIds): array
    {
        $names = [];
        foreach (array_values(array_unique($playerIds)) as $playerId) {
            $row = $this->findOne('SELECT name FROM player WHERE id = :id', ['id' => $playerId]);
            if ($row !== null) {
                $names[$playerId] = (string) $row['name'];
            }
        }

        return $names;
    }

    /**
     * @return array{team_season_id: int, team_name: string, is_ghost_team: bool}|null
     */
    private function team(mixed $teamSeasonId): ?array
    {
        if ($teamSeasonId === null) {
            return null;
        }

        $row = $this->findOne(
            'SELECT id, team_name, is_ghost_team FROM team_season WHERE id = :id',
            ['id' => (int) $teamSeasonId]
        );

        return $row === null ? null : [
            'team_season_id'  => (int) $row['id'],
            'team_name'        => (string) $row['team_name'],
            'is_ghost_team' => (int) $row['is_ghost_team'] === 1,
        ];
    }
}
