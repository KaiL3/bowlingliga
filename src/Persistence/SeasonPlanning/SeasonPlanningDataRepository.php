<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\SeasonPlanning;

use Bowlingliga\Application\SeasonPlanning\SeasonTeamsReader;
use Bowlingliga\Domain\Message;
use Bowlingliga\Persistence\AbstractRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\SeasonPlanRepository;
use Bowlingliga\Presentation\SeasonPlanningReader;

/**
 * Production read adapter of the season planning (task 35.2).
 *
 * Serves two ports:
 * - {@see SeasonTeamsReader} for the use case: real teams (without ghost
 *   team), relevant matchday count and lane count (R9.1, R9.10, R8.11);
 * - {@see SeasonPlanningReader} for the display in the admin area (R9.14).
 *
 * Read-only via prepared statements (R4.1, R4.5).
 */
final class SeasonPlanningDataRepository extends AbstractRepository implements SeasonTeamsReader, SeasonPlanningReader
{
    public function __construct(
        Database $database,
        private readonly SeasonPlanRepository $seasonPlan,
    ) {
        parent::__construct($database);
    }

    public function findTeams(int $seasonId): array
    {
        $rows = $this->findAll(
            'SELECT id, team_name FROM team_season
             WHERE season_id = :season_id AND is_ghost_team = 0
             ORDER BY id',
            ['season_id' => $seasonId]
        );

        return array_map(
            static fn (array $z): array => ['team_season_id' => (int) $z['id'], 'team_name' => (string) $z['team_name']],
            $rows
        );
    }

    /**
     * Defined matchday count of the season; without a definition the number of
     * weekly matchdays from start to end date (R9.10).
     */
    public function findMatchdayCount(int $seasonId): ?int
    {
        $season = $this->findOne(
            'SELECT start_date, end_date, matchday_count FROM season WHERE id = :id',
            ['id' => $seasonId]
        );
        if ($season === null) {
            return null;
        }

        if ($season['matchday_count'] !== null && (int) $season['matchday_count'] > 0) {
            return (int) $season['matchday_count'];
        }

        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $season['start_date']);
        $end  = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $season['end_date']);
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return intdiv((int) $start->diff($end)->days, 7) + 1;
    }

    public function findLaneCount(int $seasonId): ?int
    {
        $config = $this->findOne(
            'SELECT lane_count FROM season_config WHERE season_id = :id',
            ['id' => $seasonId]
        );

        return $config !== null ? (int) $config['lane_count'] : null;
    }

    public function findPositiondayInterval(int $seasonId): int
    {
        $config = $this->findOne(
            'SELECT positionday_interval FROM season_config WHERE season_id = :id',
            ['id' => $seasonId]
        );

        return $config !== null
            ? (int) $config['positionday_interval']
            : \Bowlingliga\Application\SeasonConfiguration::DEFAULT_POSITIONDAY_INTERVAL;
    }

    public function season(int $seasonId): ?array
    {
        $season = $this->findOne(
            'SELECT id, name, start_date, end_date, start_date <= CURDATE() AS started
             FROM season WHERE id = :id',
            ['id' => $seasonId]
        );
        if ($season === null) {
            return null;
        }

        return [
            'id'               => (int) $season['id'],
            'name'             => (string) $season['name'],
            'start_date'       => (string) $season['start_date'],
            'end_date'         => (string) $season['end_date'],
            'team_count'     => count($this->findTeams($seasonId)),
            'matchday_count' => $this->findMatchdayCount($seasonId),
            'lane_count'       => $this->findLaneCount($seasonId),
            'positionday_interval' => $this->findPositiondayInterval($seasonId),
            'started'        => (int) $season['started'] === 1,
        ];
    }

    public function teamNames(int $seasonId): array
    {
        $names = [0 => SeasonPlanRepository::GHOST_TEAM_NAME];

        $rows = $this->findAll(
            'SELECT id, team_name, is_ghost_team FROM team_season WHERE season_id = :season_id',
            ['season_id' => $seasonId]
        );
        foreach ($rows as $row) {
            $names[(int) $row['id']] = (string) $row['team_name'];
            if ((int) $row['is_ghost_team'] === 1) {
                $names[0] = (string) $row['team_name'];
            }
        }

        return $names;
    }

    public function storedPlan(int $seasonId): array
    {
        $matchdays = $this->findAll(
            'SELECT id, matchday_number, date, matchday_type, is_played
             FROM matchday WHERE season_id = :season_id
             ORDER BY matchday_number',
            ['season_id' => $seasonId]
        );
        if ($matchdays === []) {
            return [];
        }

        $fixtures = $this->findAll(
            "SELECT fx.matchday_id, fx.completion_status,
                    COALESCE(h.team_name, 'offen') AS home,
                    COALESCE(g.team_name, 'offen') AS away,
                    lp.lane_a, lp.lane_b
             FROM fixture fx
             JOIN matchday md ON md.id = fx.matchday_id
             LEFT JOIN team_season h ON h.id = fx.home_team_season_id
             LEFT JOIN team_season g ON g.id = fx.away_team_season_id
             LEFT JOIN lane_pair lp ON lp.id = fx.lane_pair_id
             WHERE md.season_id = :season_id
             ORDER BY fx.matchday_id, lp.lane_a IS NULL, lp.lane_a, fx.id",
            ['season_id' => $seasonId]
        );

        $byMatchday = [];
        foreach ($fixtures as $p) {
            $byMatchday[(int) $p['matchday_id']][] = [
                'home'   => (string) $p['home'],
                'away'   => (string) $p['away'],
                'lanes' => $p['lane_a'] !== null ? $p['lane_a'] . '/' . $p['lane_b'] : null,
                'status' => (string) $p['completion_status'],
            ];
        }

        return array_map(
            static fn (array $st): array => [
                'id'               => (int) $st['id'],
                'matchday_number' => (int) $st['matchday_number'],
                'date'            => (string) $st['date'],
                'matchday_type'     => (string) $st['matchday_type'],
                'is_played'     => (int) $st['is_played'] === 1,
                'pairings'        => $byMatchday[(int) $st['id']] ?? [],
            ],
            $matchdays
        );
    }

    public function reasonAgainstReplanning(int $seasonId): ?Message
    {
        return $this->seasonPlan->reasonAgainstReplanning($seasonId);
    }
}
