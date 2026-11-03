<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\ResultEntry\ScoreSheetReader;
use Bowlingliga\Application\ResultEntry\ScoreSheetTeamRawData;

/**
 * Persistence adapter for the raw data of a fixture's virtual score sheet
 * (R30.5, R30.17).
 *
 * Implements the {@see ScoreSheetReader} port: returns the matchday id and
 * for home and away team the stored rows of the score sheet (player, blind
 * slot flag) with the game results already stored (for resuming a draft,
 * R30.17). Reads via prepared statements (R4.1), unchanged return (R4.5).
 */
final class ScoreSheetRepository extends AbstractRepository implements ScoreSheetReader
{
    public function findMatchdayId(int $fixtureId): ?int
    {
        $row = $this->findOne(
            'SELECT matchday_id FROM fixture WHERE id = :id',
            ['id' => $fixtureId]
        );

        return $row !== null ? (int) $row['matchday_id'] : null;
    }

    /**
     * @return array{0: ScoreSheetTeamRawData, 1: ScoreSheetTeamRawData}|null
     */
    public function findTeamRawData(int $fixtureId): ?array
    {
        $fixture = $this->findOne(
            'SELECT home_team_season_id, away_team_season_id FROM fixture WHERE id = :id',
            ['id' => $fixtureId]
        );

        if ($fixture === null) {
            return null;
        }

        return [
            $this->buildTeamRawData($fixtureId, $fixture['home_team_season_id'] !== null ? (int) $fixture['home_team_season_id'] : null),
            $this->buildTeamRawData($fixtureId, $fixture['away_team_season_id'] !== null ? (int) $fixture['away_team_season_id'] : null),
        ];
    }

    /**
     * Builds the raw data of a team: team name and the stored rows with their
     * game results.
     */
    private function buildTeamRawData(int $fixtureId, ?int $teamSeasonId): ScoreSheetTeamRawData
    {
        if ($teamSeasonId === null) {
            return new ScoreSheetTeamRawData(0, null, []);
        }

        $teamLabel = $this->findOne(
            'SELECT team_name FROM team_season WHERE id = :id',
            ['id' => $teamSeasonId]
        );

        // Rows including player name (LEFT JOIN: ghost team rows have no
        // player) and their results.
        $records = $this->findAll(
            'SELECT a.id, a.player_id, a.is_blind, sp.name, e.game_number, e.net, e.is_blind AS game_blind
             FROM lineup a
             LEFT JOIN player sp ON sp.id = a.player_id
             LEFT JOIN game_result e ON e.lineup_id = a.id
             WHERE a.fixture_id = :fixture_id AND a.team_season_id = :team_season_id
             ORDER BY a.sheet_row, e.game_number',
            ['fixture_id' => $fixtureId, 'team_season_id' => $teamSeasonId]
        );

        $rows = [];
        foreach ($records as $record) {
            $id = (int) $record['id'];
            $rows[$id] ??= [
                'player_id' => $record['player_id'] !== null ? (int) $record['player_id'] : null,
                'name'      => $record['name'] !== null ? (string) $record['name'] : null,
                'is_blind'  => (int) $record['is_blind'] === 1,
                'results'   => [],
            ];
            if ($record['game_number'] !== null) {
                $rows[$id]['results'][(int) $record['game_number']] = [
                    'net'      => (int) $record['net'],
                    'is_blind' => (int) $record['game_blind'] === 1,
                ];
            }
        }

        return new ScoreSheetTeamRawData(
            $teamSeasonId,
            $teamLabel !== null ? (string) $teamLabel['team_name'] : null,
            array_values($rows),
        );
    }
}
