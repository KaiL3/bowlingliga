<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\Aggregation;

use Bowlingliga\Application\Aggregation\FixtureRawData;
use Bowlingliga\Application\Aggregation\FixtureTeamRawData;
use Bowlingliga\Application\Aggregation\SheetRowResults;
use Bowlingliga\Application\Aggregation\TableRawDataReader;
use Bowlingliga\Application\SeasonConfiguration;
use Bowlingliga\Domain\Points\PointsRules;
use Bowlingliga\Persistence\AbstractRepository;

/**
 * Persistence adapter of the {@see TableRawDataReader} port (R16, R15.3,
 * R24.3, R35.2).
 *
 * Reads only the played fixtures (matchday with `is_played = 1`) with
 * their complete lineup results from the raw data tables and forms
 * {@see FixtureRawData} from them. Unplayed matchdays are deliberately
 * excluded (R16.6, R24.3); the domain points/pins aggregation is done by the
 * pure {@see \Bowlingliga\Application\Aggregation\TableAggregator}.
 *
 * Gross per score sheet row: net + frozen handicap of the row's player on the
 * matchday (R17.2). For the ghost team (`team_season.is_ghost_team = 1`)
 * the handicap is always 0, since the ghost team gets no handicap (R20.2). A
 * team counts as staffed with a real player as soon as at least one lineup
 * row is assigned to a player (`player_id IS NOT NULL`)
 * (R21.6/R27.3).
 *
 * All access is read-only via prepared statements (R4.1) and returns the
 * persisted values unchanged (R4.5).
 */
final class TableRawDataRepository extends AbstractRepository implements TableRawDataReader
{
    public function pointsRulesForSeason(int $seasonId): PointsRules
    {
        return SeasonConfiguration::pointsRulesFromRow($this->findOne(
            'SELECT win_points, draw_points, loss_points, bonus_threshold, bonus_step, bonus_maximum
             FROM season_config WHERE season_id = :season',
            ['season' => $seasonId]
        ));
    }

    public function pointsRulesForMatchday(int $matchdayId): PointsRules
    {
        return SeasonConfiguration::pointsRulesFromRow($this->findOne(
            'SELECT c.win_points, c.draw_points, c.loss_points, c.bonus_threshold, c.bonus_step, c.bonus_maximum
             FROM matchday md JOIN season_config c ON c.season_id = md.season_id WHERE md.id = :matchday',
            ['matchday' => $matchdayId]
        ));
    }

    public function findFixturesForSeason(int $seasonId): array
    {
        return $this->loadFixtures('md.season_id = :value', $seasonId);
    }

    public function findFixturesForMatchday(int $matchdayId): array
    {
        return $this->loadFixtures('md.id = :value', $matchdayId);
    }

    /**
     * Loads all countable fixtures (only played matchdays, both teams fixed;
     * R16.6, R24.3) including lineups, net values and frozen handicaps in
     * **one** query and builds the raw data objects from it. Previously this
     * took around 20 single queries per fixture.
     *
     * @return list<FixtureRawData> chronologically by matchday, per matchday by fixture
     */
    private function loadFixtures(string $condition, int $value): array
    {
        $rows = $this->findAll(
            "SELECT fx.id AS fixture_id, fx.matchday_id, fx.home_team_season_id AS home_id, fx.away_team_season_id AS away_id,
                    ts.id AS team_season_id, ts.team_name, ts.is_ghost_team,
                    a.id AS lineup_id, a.player_id,
                    e.game_number, e.net, (e.is_blind = 1 OR a.is_blind = 1) AS blind, h.handicap
             FROM fixture fx
             JOIN matchday md ON md.id = fx.matchday_id AND md.is_played = 1
             JOIN team_season ts ON ts.id IN (fx.home_team_season_id, fx.away_team_season_id)
             LEFT JOIN lineup a ON a.fixture_id = fx.id AND a.team_season_id = ts.id
             LEFT JOIN game_result e ON e.lineup_id = a.id
             LEFT JOIN frozen_handicap h ON h.matchday_id = fx.matchday_id AND h.player_id = a.player_id
             WHERE {$condition}
               AND fx.home_team_season_id IS NOT NULL AND fx.away_team_season_id IS NOT NULL
             ORDER BY md.matchday_number, fx.id, ts.id, a.sheet_row, e.game_number",
            ['value' => $value]
        );

        /** @var array<int, array{matchday: int, home: int, away: int, teams: array<int, array<string, mixed>>}> $fixtures */
        $fixtures = [];
        foreach ($rows as $z) {
            $tp = (int) $z['fixture_id'];
            $ts = (int) $z['team_season_id'];
            $fixtures[$tp] ??= ['matchday' => (int) $z['matchday_id'], 'home' => (int) $z['home_id'], 'away' => (int) $z['away_id'], 'teams' => []];
            $fixtures[$tp]['teams'][$ts] ??= [
                'name'       => (string) $z['team_name'],
                'ghost'      => (int) $z['is_ghost_team'] === 1,
                'rows'  => [],
            ];

            if ($z['lineup_id'] === null) {
                continue; // Team without lineup
            }

            $team = &$fixtures[$tp]['teams'][$ts];
            $aId  = (int) $z['lineup_id'];
            $team['rows'][$aId] ??= [
                'player' => $z['player_id'] === null ? null : (int) $z['player_id'],
                'net'   => [],
                'blind' => [],
                // Ghost team without handicap (R20.2); otherwise frozen HDC (R17.2, R12.12).
                'hdc'     => $team['ghost'] || $z['player_id'] === null || $z['handicap'] === null ? 0 : (int) $z['handicap'],
            ];
            $num = $z['game_number'] === null ? 0 : (int) $z['game_number'];
            if ($num >= 1 && $num <= 3) {
                $team['rows'][$aId]['net'][$num] = (int) $z['net'];
                if ((int) $z['blind'] === 1) {
                    $team['rows'][$aId]['blind'][] = $num;
                }
            }
            unset($team);
        }

        $result = [];
        foreach ($fixtures as $fixture) {
            $result[] = new FixtureRawData(
                $this->buildTeamFrom($fixture['home'], $fixture['teams'][$fixture['home']] ?? null),
                $this->buildTeamFrom($fixture['away'], $fixture['teams'][$fixture['away']] ?? null),
                $fixture['matchday'],
            );
        }

        return $result;
    }

    /**
     * @param array{name: string, ghost: bool, rows: array<int, array{player: int|null, net: array<int, int>, blind: list<int>, hdc: int}>}|null $team
     */
    private function buildTeamFrom(int $teamSeasonId, ?array $team): FixtureTeamRawData
    {
        $isGhostTeam   = $team !== null && $team['ghost'];
        $rows          = [];
        $hasRealPlayer = false;

        foreach ($team['rows'] ?? [] as $row) {
            $hasRealPlayer = $hasRealPlayer || $row['player'] !== null;
            $rows[]        = new SheetRowResults($row['net'], $row['hdc'], $row['blind']);
        }

        return new FixtureTeamRawData(
            $teamSeasonId,
            $team['name'] ?? '',
            $isGhostTeam,
            $isGhostTeam ? true : $hasRealPlayer, // The ghost team counts as fully staffed (R20.7).
            $rows,
        );
    }

    public function findTeamsForSeason(int $seasonId): array
    {
        // Real teams of the season (the ghost team is not preset, it
        // only arises from its fixtures – see TableAggregator).
        $rows = $this->findAll(
            'SELECT id, team_name FROM team_season
             WHERE season_id = :season AND is_ghost_team = 0 ORDER BY team_name',
            ['season' => $seasonId]
        );

        $teams = [];
        foreach ($rows as $row) {
            $teams[(int) $row['id']] = (string) $row['team_name'];
        }

        return $teams;
    }
}
