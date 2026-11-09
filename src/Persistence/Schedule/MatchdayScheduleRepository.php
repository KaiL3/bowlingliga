<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\Schedule;

use Bowlingliga\Application\Schedule\MatchdayScheduleStore;
use Bowlingliga\Persistence\AbstractRepository;

/**
 * Persistence adapter of the {@see MatchdayScheduleStore} port: cancelling
 * and postponing matchdays (R8.20, R8.21).
 */
final class MatchdayScheduleRepository extends AbstractRepository implements MatchdayScheduleStore
{
    public function matchdays(int $seasonId): array
    {
        $rows = $this->findAll(
            "SELECT md.id, md.season_id, md.matchday_number, md.date, md.matchday_type, md.is_cancelled,
                    EXISTS (SELECT 1 FROM fixture fx
                            LEFT JOIN lineup l ON l.fixture_id = fx.id
                            LEFT JOIN game_result gr ON gr.lineup_id = l.id
                            WHERE fx.matchday_id = md.id
                              AND (fx.completion_status <> 'open' OR gr.id IS NOT NULL)) AS has_results,
                    (EXISTS (SELECT 1 FROM fixture fx WHERE fx.matchday_id = md.id)
                     AND NOT EXISTS (SELECT 1 FROM fixture fx
                                     WHERE fx.matchday_id = md.id AND fx.completion_status <> 'complete')) AS is_complete
             FROM matchday md
             WHERE md.season_id = :season
             ORDER BY md.matchday_number",
            ['season' => $seasonId]
        );

        return array_map(static fn (array $row): array => [
            'id'           => (int) $row['id'],
            'season_id'    => (int) $row['season_id'],
            'number'       => (int) $row['matchday_number'],
            'date'         => (string) $row['date'],
            'type'         => (string) $row['matchday_type'],
            'is_cancelled' => (int) $row['is_cancelled'] === 1,
            'has_results'  => (int) $row['has_results'] === 1,
            'is_complete'  => (int) $row['is_complete'] === 1,
        ], $rows);
    }

    public function seasonOf(int $matchdayId): ?int
    {
        $row = $this->findOne('SELECT season_id FROM matchday WHERE id = :id', ['id' => $matchdayId]);

        return $row === null ? null : (int) $row['season_id'];
    }

    public function seasonEnd(int $seasonId): ?string
    {
        $row = $this->findOne('SELECT end_date FROM season WHERE id = :id', ['id' => $seasonId]);

        return $row === null ? null : (string) $row['end_date'];
    }

    public function setCancelled(int $matchdayId, bool $cancelled): void
    {
        $this->transactional('cancel_matchday', function () use ($matchdayId, $cancelled): void {
            $this->perform(
                'UPDATE matchday SET is_cancelled = :cancelled WHERE id = :id',
                ['id' => $matchdayId, 'cancelled' => $cancelled ? 1 : 0]
            );
        });
    }

    public function moveDates(int $seasonId, array $dates, string $seasonEnd): void
    {
        $this->transactional('postpone_matchday', function () use ($seasonId, $dates, $seasonEnd): void {
            // The season end first, so that every new date lies within the season.
            $this->perform('UPDATE season SET end_date = :end WHERE id = :id', ['id' => $seasonId, 'end' => $seasonEnd]);
            foreach ($dates as $matchdayId => $date) {
                $this->perform(
                    'UPDATE matchday SET date = :date WHERE id = :id AND season_id = :season',
                    ['id' => $matchdayId, 'season' => $seasonId, 'date' => $date]
                );
            }
        });
    }
}
