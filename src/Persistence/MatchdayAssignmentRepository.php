<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Domain\Ocr\MatchdayAssignmentReader;

/**
 * Persistence adapter for the OCR assignment to matchday and fixture
 * (R3.3–R3.5).
 *
 * Implements the {@see MatchdayAssignmentReader} port: returns for a date all
 * fixtures of the matching matchdays together with the season's team names
 * involved. Reads happen via prepared statements (R4.1) and return the
 * persisted values unchanged (R4.5).
 */
final class MatchdayAssignmentRepository extends AbstractRepository implements MatchdayAssignmentReader
{
    /**
     * @return list<array{matchday_id: int, fixture_id: int, team_names: list<string>}>
     */
    public function findFixturesByDate(string $date): array
    {
        $rows = $this->findAll(
            'SELECT fx.id AS fixture_id,
                    fx.matchday_id AS matchday_id,
                    home.team_name AS home_name,
                    away.team_name AS away_name
             FROM matchday s
             JOIN fixture fx ON fx.matchday_id = s.id
             LEFT JOIN team_season home ON home.id = fx.home_team_season_id
             LEFT JOIN team_season away ON away.id = fx.away_team_season_id
             WHERE s.date = :date
             ORDER BY fx.id',
            ['date' => $date]
        );

        return array_map(
            static function (array $row): array {
                $teamNames = [];
                if ($row['home_name'] !== null) {
                    $teamNames[] = (string) $row['home_name'];
                }
                if ($row['away_name'] !== null) {
                    $teamNames[] = (string) $row['away_name'];
                }

                return [
                    'matchday_id'    => (int) $row['matchday_id'],
                    'fixture_id' => (int) $row['fixture_id'],
                    'team_names'      => $teamNames,
                ];
            },
            $rows
        );
    }
}
