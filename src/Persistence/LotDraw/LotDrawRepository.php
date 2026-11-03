<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\LotDraw;

use Bowlingliga\Application\LotDraw\LotDrawReader;
use Bowlingliga\Persistence\AbstractRepository;

/**
 * Read adapter for entering the fixtures of a lot draw matchday.
 */
final class LotDrawRepository extends AbstractRepository implements LotDrawReader
{
    public function matchday(int $matchdayId): ?array
    {
        $row = $this->findOne(
            'SELECT season_id, matchday_number, matchday_type FROM matchday WHERE id = :id',
            ['id' => $matchdayId]
        );

        return $row === null ? null : [
            'season_id' => (int) $row['season_id'],
            'number'    => (int) $row['matchday_number'],
            'type'      => (string) $row['matchday_type'],
        ];
    }

    public function teams(int $seasonId): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['team_name']],
            $this->findAll(
                'SELECT id, team_name FROM team_season WHERE season_id = :season_id ORDER BY is_ghost_team, team_name',
                ['season_id' => $seasonId]
            )
        );
    }

    public function hasResults(int $matchdayId): bool
    {
        return $this->findOne(
            "SELECT 1 FROM fixture fx
             LEFT JOIN lineup l ON l.fixture_id = fx.id
             LEFT JOIN game_result gr ON gr.lineup_id = l.id
             WHERE fx.matchday_id = :id AND (fx.completion_status <> 'open' OR gr.id IS NOT NULL)
             LIMIT 1",
            ['id' => $matchdayId]
        ) !== null;
    }
}
