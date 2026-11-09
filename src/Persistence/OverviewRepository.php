<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\ResultEntry\OverviewEntry;
use Bowlingliga\Application\ResultEntry\OverviewReader;

/**
 * Persistence adapter for a season's result entry overview (R30.1–R30.4).
 *
 * Implements the {@see OverviewReader} port: returns all fixtures of a
 * season's matchdays with completion status and the team names involved,
 * chronologically by matchday counter; cancelled matchdays are left out
 * (R8.20). Reads via prepared statements (R4.1),
 * unchanged return (R4.5).
 */
final class OverviewRepository extends AbstractRepository implements OverviewReader
{
    /**
     * @return list<OverviewEntry>
     */
    public function findEntriesForSeason(int $seasonId): array
    {
        $rows = $this->findAll(
            'SELECT fx.id AS fixture_id,
                    s.id AS matchday_id,
                    s.matchday_number AS matchday_number,
                    s.date AS date,
                    lp.lane_a, lp.lane_b,
                    home.team_name AS home_name,
                    away.team_name AS away_name,
                    fx.completion_status AS completion_status
             FROM matchday s
             JOIN fixture fx ON fx.matchday_id = s.id
             LEFT JOIN team_season home ON home.id = fx.home_team_season_id
             LEFT JOIN team_season away ON away.id = fx.away_team_season_id
             LEFT JOIN lane_pair lp ON lp.id = fx.lane_pair_id
             WHERE s.season_id = :season_id AND s.is_cancelled = 0
             ORDER BY s.matchday_number, fx.id',
            ['season_id' => $seasonId]
        );

        return array_map(
            static fn (array $row): OverviewEntry => new OverviewEntry(
                (int) $row['fixture_id'],
                (int) $row['matchday_id'],
                (int) $row['matchday_number'],
                $row['home_name'] !== null ? (string) $row['home_name'] : null,
                $row['away_name'] !== null ? (string) $row['away_name'] : null,
                (string) $row['completion_status'],
                (string) $row['date'],
                $row['lane_a'] !== null ? $row['lane_a'] . '/' . $row['lane_b'] : null,
            ),
            $rows
        );
    }
}
