<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\Positionday;

use Bowlingliga\Application\Aggregation\TableAggregator;
use Bowlingliga\Application\Positionday\PositiondayContextReader;
use Bowlingliga\Domain\Positionday\PositiondayService;
use Bowlingliga\Domain\Table\TeamStatistics;
use Bowlingliga\Persistence\AbstractRepository;
use Bowlingliga\Persistence\Database;

/**
 * Production adapter of the {@see PositiondayContextReader} (R31.2–R31.7).
 *
 * - Previous matchdays: all matchdays of the season with a smaller counter
 *   must be played, i.e. all their fixtures "complete" (R24.1).
 * - Standings: the league table of the season over the played matchdays
 *   (R31.3). Since all previous matchdays must be played first, this equals
 *   the state before the positionday, as long as no later matchdays were
 *   entered in advance.
 * - Locked (R31.7): as soon as results are entered for a fixture of the
 *   positionday (status no longer "offen"). Forming again would replace the
 *   fixtures and thus discard entered results.
 * - Team mapping: the team id of the table is the team season id; the
 *   flagged ghost team of the season is included, also under the placeholder
 *   of the {@see PositiondayService}.
 */
final class PositiondayContextRepository extends AbstractRepository implements PositiondayContextReader
{
    public function __construct(
        Database $database,
        private readonly TableAggregator $tables,
    ) {
        parent::__construct($database);
    }

    public function allPreviousMatchdaysComplete(int $matchdayId): bool
    {
        $context = $this->matchday($matchdayId);
        if ($context === null) {
            return false;
        }

        $open = $this->findOne(
            'SELECT COUNT(*) AS quantity FROM matchday
             WHERE season_id = :season_id AND matchday_number < :number AND is_played = 0',
            ['season_id' => $context['season_id'], 'number' => $context['matchday_number']]
        );

        return (int) ($open['quantity'] ?? 1) === 0;
    }

    public function standingsBefore(int $matchdayId): array
    {
        $context = $this->matchday($matchdayId);
        if ($context === null) {
            return [];
        }

        $standing = [];
        foreach ($this->tables->leagueTable($context['season_id'])->rows as $row) {
            $standing[] = new TeamStatistics(
                $row->teamId,
                $row->teamName,
                $row->points,
                $row->netPins,
                $row->grossPins,
                $row->numberOfGames,
                $row->isGhostTeam,
            );
        }

        return $standing;
    }

    public function positiondayComplete(int $matchdayId): bool
    {
        $row = $this->findOne(
            "SELECT COUNT(*) AS quantity FROM fixture WHERE matchday_id = :id AND completion_status <> 'open'",
            ['id' => $matchdayId]
        );

        return (int) ($row['quantity'] ?? 0) > 0;
    }

    public function laneCount(int $matchdayId): int
    {
        $row = $this->findOne(
            'SELECT k.lane_count FROM matchday s JOIN season_config k ON k.season_id = s.season_id WHERE s.id = :id',
            ['id' => $matchdayId]
        );

        return $row !== null ? (int) $row['lane_count'] : 2;
    }

    public function teamSeasonIds(int $matchdayId): array
    {
        $context = $this->matchday($matchdayId);
        if ($context === null) {
            return [];
        }

        $assignment = [];
        foreach ($this->findAll(
            'SELECT id, is_ghost_team FROM team_season WHERE season_id = :season_id',
            ['season_id' => $context['season_id']]
        ) as $row) {
            $id = (int) $row['id'];
            $assignment[(string) $id] = $id;
            if ((int) $row['is_ghost_team'] === 1) {
                $assignment[PositiondayService::GHOST_TEAM_ID] = $id;
            }
        }

        return $assignment;
    }

    /**
     * @return array{season_id: int, matchday_number: int}|null
     */
    private function matchday(int $matchdayId): ?array
    {
        $row = $this->findOne(
            'SELECT season_id, matchday_number FROM matchday WHERE id = :id',
            ['id' => $matchdayId]
        );

        return $row === null ? null : [
            'season_id'        => (int) $row['season_id'],
            'matchday_number' => (int) $row['matchday_number'],
        ];
    }
}
