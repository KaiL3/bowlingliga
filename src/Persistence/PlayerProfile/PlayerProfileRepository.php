<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\PlayerProfile;

use Bowlingliga\Application\PlayerProfile\PlayerProfileReader;
use Bowlingliga\Application\PlayerProfile\PlayerSeasonRawData;
use Bowlingliga\Domain\Average\AverageModule;
use Bowlingliga\Domain\Average\Game;
use Bowlingliga\Domain\Average\PreviousSeasonRecord;
use Bowlingliga\Domain\PlayerProfile\PlayerMatchdayInput;
use Bowlingliga\Persistence\AbstractRepository;

/**
 * Production adapter of the {@see PlayerProfileReader} (R39).
 *
 * Reads only raw data: rosters, lineups, results of complete fixtures,
 * frozen handicaps and the previous season (R35). A matchday counts as played
 * as soon as one of its fixtures is complete – like for the average of the
 * handicap. The previous season follows the same precedence as there: the
 * season's own record, otherwise the games of the preceding season (R29.2,
 * R11.3).
 */
final class PlayerProfileRepository extends AbstractRepository implements PlayerProfileReader
{
    public function player(int $playerId): ?array
    {
        $row = $this->findOne('SELECT id, name, gender FROM player WHERE id = :id', ['id' => $playerId]);

        return $row === null ? null : [
            'id'     => (int) $row['id'],
            'name'   => (string) $row['name'],
            'gender' => (string) $row['gender'],
        ];
    }

    public function seasons(int $playerId): array
    {
        $rows = $this->findAll(
            'SELECT s.id AS season_id, s.name AS season_name, s.start_date, ts.id AS team_season_id, ts.team_name, 0 AS source
             FROM roster r
             JOIN team_season ts ON ts.id = r.team_season_id AND ts.is_ghost_team = 0
             JOIN season s ON s.id = ts.season_id
             WHERE r.player_id = :roster_player
             UNION
             SELECT s.id, s.name, s.start_date, ts.id, ts.team_name, 1
             FROM lineup a
             JOIN team_season ts ON ts.id = a.team_season_id AND ts.is_ghost_team = 0
             JOIN season s ON s.id = ts.season_id
             WHERE a.player_id = :lineup_player
             ORDER BY start_date, season_id, source, team_season_id',
            ['roster_player' => $playerId, 'lineup_player' => $playerId]
        );

        $seasons = [];
        foreach ($rows as $row) {
            $seasonId = (int) $row['season_id'];
            // One team per season (R6.8); the roster comes first.
            $seasons[$seasonId] ??= [
                'season_id'      => $seasonId,
                'season_name'    => (string) $row['season_name'],
                'team_season_id' => (int) $row['team_season_id'],
                'team_name'      => (string) $row['team_name'],
            ];
        }

        return array_values($seasons);
    }

    public function seasonRawData(int $playerId, int $seasonId): PlayerSeasonRawData
    {
        $matchdays = [];
        foreach ($this->findAll(
            "SELECT md.id, md.matchday_number, md.date
             FROM matchday md
             WHERE md.season_id = :season_id
               AND EXISTS (SELECT 1 FROM fixture fx WHERE fx.matchday_id = md.id AND fx.completion_status = 'complete')
             ORDER BY md.matchday_number",
            ['season_id' => $seasonId]
        ) as $row) {
            $matchdays[(int) $row['id']] = ['number' => (int) $row['matchday_number'], 'date' => (string) $row['date'], 'games' => []];
        }

        foreach ($this->findAll(
            "SELECT fx.matchday_id, e.net, (e.is_blind = 1 OR a.is_blind = 1) AS blind, ts.is_ghost_team
             FROM game_result e
             JOIN lineup a ON a.id = e.lineup_id
             JOIN team_season ts ON ts.id = a.team_season_id
             JOIN fixture fx ON fx.id = a.fixture_id AND fx.completion_status = 'complete'
             JOIN matchday md ON md.id = fx.matchday_id
             WHERE a.player_id = :player_id AND md.season_id = :season_id
             ORDER BY md.matchday_number, a.id, e.game_number",
            ['player_id' => $playerId, 'season_id' => $seasonId]
        ) as $row) {
            $matchdayId = (int) $row['matchday_id'];
            if (isset($matchdays[$matchdayId])) {
                $matchdays[$matchdayId]['games'][] = new Game((int) $row['net'], (int) $row['blind'] === 1, (int) $row['is_ghost_team'] === 1);
            }
        }

        $handicaps = [];
        foreach ($this->findAll(
            'SELECT fh.matchday_id, fh.handicap
             FROM frozen_handicap fh
             JOIN matchday md ON md.id = fh.matchday_id
             WHERE fh.player_id = :player_id AND md.season_id = :season_id',
            ['player_id' => $playerId, 'season_id' => $seasonId]
        ) as $row) {
            $handicaps[(int) $row['matchday_id']] = (int) $row['handicap'];
        }

        $inputs = [];
        foreach ($matchdays as $matchdayId => $matchday) {
            $inputs[] = new PlayerMatchdayInput($matchdayId, $matchday['number'], $matchday['date'], $matchday['games'], $handicaps[$matchdayId] ?? null);
        }

        $config = $this->findOne('SELECT previous_season_matchdays FROM season_config WHERE season_id = :id', ['id' => $seasonId]);
        $previousSeasonMatchdays = $config === null ? AverageModule::PREVIOUS_SEASON_THRESHOLD : (int) $config['previous_season_matchdays'];

        // The season's own record takes precedence over the preceding season (R29.2).
        $record = $this->findOne(
            'SELECT game_count, net_pins_total FROM previous_season_record WHERE season_id = :season_id AND player_id = :player_id',
            ['season_id' => $seasonId, 'player_id' => $playerId]
        );
        if ($record !== null) {
            return new PlayerSeasonRawData(
                $inputs,
                [],
                new PreviousSeasonRecord((int) $record['game_count'], (int) $record['net_pins_total']),
                $previousSeasonMatchdays,
            );
        }

        $previous = [];
        foreach ($this->findAll(
            "SELECT e.net, (e.is_blind = 1 OR a.is_blind = 1) AS blind, ts.is_ghost_team
             FROM game_result e
             JOIN lineup a ON a.id = e.lineup_id
             JOIN team_season ts ON ts.id = a.team_season_id
             JOIN fixture fx ON fx.id = a.fixture_id AND fx.completion_status = 'complete'
             JOIN matchday md ON md.id = fx.matchday_id
             WHERE a.player_id = :player_id
               AND md.season_id = (
                   SELECT v.id FROM season v, season s
                   WHERE s.id = :season_id AND v.start_date < s.start_date
                   ORDER BY v.start_date DESC LIMIT 1
               )",
            ['player_id' => $playerId, 'season_id' => $seasonId]
        ) as $row) {
            $previous[] = new Game((int) $row['net'], (int) $row['blind'] === 1, (int) $row['is_ghost_team'] === 1, true);
        }

        return new PlayerSeasonRawData($inputs, $previous, null, $previousSeasonMatchdays);
    }
}
