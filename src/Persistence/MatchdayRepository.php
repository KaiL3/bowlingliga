<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\ResultEntry\MatchdayFlagStore;
use Bowlingliga\Application\ResultEntry\MatchdayContext;
use Bowlingliga\Application\MatchdayStore;

/**
 * Raw data repository for matchdays (table `matchday`, R8.3–6, R8.18, R24).
 *
 * Keeps number (matchday counter), date, matchday type and the played
 * matchday flag (`is_played`). The matchday counter is unique per season.
 * Write operations transactional (R4.3), prepared statements (R4.1), reads
 * unchanged (R4.5).
 *
 * Additionally implements the ports of the result entry: the
 * {@see MatchdayFlagStore} (setting the played matchday flag, R24.1/R24.4)
 * and the {@see MatchdayContext} (season/counter for the cache invalidation,
 * R35.4).
 */
final class MatchdayRepository extends AbstractRepository implements MatchdayStore, MatchdayFlagStore, MatchdayContext
{
    /**
     * Creates a matchday and returns its id (R8.3).
     *
     * @param 'regular'|'positionday'|'lot_draw' $matchdayType
     */
    public function create(
        int $seasonId,
        int $matchdayCounter,
        string $date,
        string $matchdayType = 'regular',
        bool $isPlayed = false
    ): int {
        return $this->transactional('create_matchday', function ($pdo) use ($seasonId, $matchdayCounter, $date, $matchdayType, $isPlayed): int {
            $this->perform(
                'INSERT INTO matchday (season_id, matchday_number, date, matchday_type, is_played)
                 VALUES (:season_id, :matchday_number, :date, :matchday_type, :is_played)',
                [
                    'season_id'        => $seasonId,
                    'matchday_number' => $matchdayCounter,
                    'date'            => $date,
                    'matchday_type'     => $matchdayType,
                    'is_played'     => $isPlayed ? 1 : 0,
                ]
            );

            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Updates date and type of a matchday (R8.5, R8.19).
     *
     * @param 'regular'|'positionday'|'lot_draw' $matchdayType
     */
    public function update(int $id, string $date, string $matchdayType): void
    {
        $this->transactional('update_matchday', function () use ($id, $date, $matchdayType): void {
            $this->perform(
                'UPDATE matchday SET date = :date, matchday_type = :matchday_type WHERE id = :id',
                ['id' => $id, 'date' => $date, 'matchday_type' => $matchdayType]
            );
        });
    }

    /**
     * Sets the played matchday flag (R24.1, R24.4).
     */
    public function setIsPlayed(int $id, bool $isPlayed): void
    {
        $this->transactional('set_matchday_status', function () use ($id, $isPlayed): void {
            $this->perform(
                'UPDATE matchday SET is_played = :is_played WHERE id = :id',
                ['id' => $id, 'is_played' => $isPlayed ? 1 : 0]
            );
        });
    }

    /**
     * Deletes a matchday (R4).
     */
    public function delete(int $id): void
    {
        $this->transactional('delete_matchday', function () use ($id): void {
            $this->perform('DELETE FROM matchday WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Returns a matchday or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, season_id, matchday_number, date, matchday_type, is_played
             FROM matchday WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Returns all matchdays of a season chronologically (R14.2, R4.5).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForSeason(int $seasonId): array
    {
        return $this->findAll(
            'SELECT id, season_id, matchday_number, date, matchday_type, is_played
             FROM matchday WHERE season_id = :season_id ORDER BY matchday_number',
            ['season_id' => $seasonId]
        );
    }

    /**
     * Returns season id and matchday counter of a matchday for the targeted
     * cache invalidation, or null if the matchday does not exist (R35.4).
     *
     * @return array{season_id: int, matchday_number: int}|null
     */
    public function findSeasonAndCounter(int $matchdayId): ?array
    {
        $row = $this->findOne(
            'SELECT season_id, matchday_number FROM matchday WHERE id = :id',
            ['id' => $matchdayId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'season_id'        => (int) $row['season_id'],
            'matchday_number' => (int) $row['matchday_number'],
        ];
    }
}
