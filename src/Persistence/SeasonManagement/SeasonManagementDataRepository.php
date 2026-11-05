<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\SeasonManagement;

use Bowlingliga\Application\Positionday\PositiondayContextReader;
use Bowlingliga\Persistence\AbstractRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\SeasonConfigRepository;
use Bowlingliga\Persistence\SeasonRepository;
use Bowlingliga\Presentation\SeasonManagementReader;

/**
 * Production adapter of the {@see SeasonManagementReader}: reads season,
 * configuration and positiondays including their formation state from the
 * raw data.
 */
final class SeasonManagementDataRepository extends AbstractRepository implements SeasonManagementReader
{
    public function __construct(
        Database $database,
        private readonly SeasonRepository $seasons,
        private readonly SeasonConfigRepository $config,
        private readonly PositiondayContextReader $positiondayContext,
    ) {
        parent::__construct($database);
    }

    public function season(int $seasonId): ?array
    {
        $season = $this->seasons->find($seasonId);

        return $season === null ? null : [
            'id'         => (int) $season['id'],
            'name'       => (string) $season['name'],
            'start_date' => (string) $season['start_date'],
            'end_date'   => (string) $season['end_date'],
            'score_sheet_language' => isset($season['score_sheet_language']) ? (string) $season['score_sheet_language'] : null,
        ];
    }

    public function configuration(int $seasonId): ?array
    {
        $config = $this->config->find($seasonId);

        return $config === null ? null : [
            'lane_count'           => (int) $config['lane_count'],
            'entry_fee'            => (string) $config['entry_fee'],
            'blind_fee' => (string) $config['blind_fee'],
            'max_handicap'     => (int) $config['max_handicap'],
            'handicap_formula'      => $config['handicap_formula'] !== null ? (string) $config['handicap_formula'] : null,
            'target_score'             => (int) $config['target_score'],
            'ghost_score'              => (int) $config['ghost_score'],
            'previous_season_matchdays' => (int) $config['previous_season_matchdays'],
            'positionday_interval'     => (int) $config['positionday_interval'],
            'win_points'                => (int) $config['win_points'],
            'draw_points'               => (int) $config['draw_points'],
            'loss_points'               => (int) $config['loss_points'],
            'bonus_threshold'           => (int) $config['bonus_threshold'],
            'bonus_step'                => (int) $config['bonus_step'],
            'bonus_maximum'             => (int) $config['bonus_maximum'],
        ];
    }

    public function positiondays(int $seasonId): array
    {
        $dayList = $this->findAll(
            "SELECT id, matchday_number, date FROM matchday
             WHERE season_id = :season_id AND matchday_type = 'positionday' ORDER BY matchday_number",
            ['season_id' => $seasonId]
        );

        $result = [];
        foreach ($dayList as $day) {
            $id = (int) $day['id'];
            $result[] = [
                'id'        => $id,
                'number'    => (int) $day['matchday_number'],
                'date'     => (string) $day['date'],
                'ready'    => $this->positiondayContext->allPreviousMatchdaysComplete($id),
                'locked'  => $this->positiondayContext->positiondayComplete($id),
                'pairings' => $this->fixtures($id),
            ];
        }

        return $result;
    }

    public function lotDraws(int $seasonId): array
    {
        $result = [];
        foreach ($this->findAll(
            "SELECT id, matchday_number, date FROM matchday
             WHERE season_id = :season_id AND matchday_type = 'lot_draw' ORDER BY matchday_number",
            ['season_id' => $seasonId]
        ) as $day) {
            $id = (int) $day['id'];
            $fixtures = $this->findAll(
                'SELECT home_team_season_id, away_team_season_id, completion_status FROM fixture WHERE matchday_id = :id ORDER BY id',
                ['id' => $id]
            );
            $locked = $this->findOne(
                "SELECT 1 FROM fixture fx LEFT JOIN lineup l ON l.fixture_id = fx.id LEFT JOIN game_result gr ON gr.lineup_id = l.id
                 WHERE fx.matchday_id = :id AND (fx.completion_status <> 'open' OR gr.id IS NOT NULL) LIMIT 1",
                ['id' => $id]
            ) !== null;
            $result[] = [
                'id'       => $id,
                'number'   => (int) $day['matchday_number'],
                'date'     => (string) $day['date'],
                'locked'   => $locked,
                'fixtures' => array_map(
                    static fn (array $row): array => ['home' => (int) $row['home_team_season_id'], 'away' => (int) $row['away_team_season_id']],
                    $fixtures
                ),
            ];
        }

        return $result;
    }

    public function seasonTeams(int $seasonId): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['team_name']],
            $this->findAll(
                'SELECT id, team_name FROM team_season WHERE season_id = :season_id ORDER BY is_ghost_team, team_name',
                ['season_id' => $seasonId]
            )
        );
    }

    /**
     * @return list<string>
     */
    private function fixtures(int $matchdayId): array
    {
        $rows = $this->findAll(
            'SELECT h.team_name AS home, g.team_name AS away, b.lane_a, b.lane_b
             FROM fixture fx
             LEFT JOIN team_season h ON h.id = fx.home_team_season_id
             LEFT JOIN team_season g ON g.id = fx.away_team_season_id
             LEFT JOIN lane_pair b ON b.id = fx.lane_pair_id
             WHERE fx.matchday_id = :id
             ORDER BY b.lane_a, fx.id',
            ['id' => $matchdayId]
        );

        return array_map(
            static fn (array $z): string => sprintf(
                '%s – %s%s',
                $z['home'] ?? '—',
                $z['away'] ?? '—',
                $z['lane_a'] !== null ? sprintf(' (Bahn %d/%d)', $z['lane_a'], $z['lane_b']) : ''
            ),
            $rows
        );
    }
}
