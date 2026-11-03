<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

/**
 * Raw data repository for season master data (table `saison`, R8).
 *
 * The configuration values (lane count, entry fee, blind fee, maximum
 * handicap, handicap formula, target score) live in `season_config` and are
 * managed via {@see SeasonConfigRepository}. Write operations transactional
 * (R4.3), prepared statements (R4.1), reads unchanged (R4.5).
 */
final class SeasonRepository extends AbstractRepository implements
    \Bowlingliga\Application\SeasonReader,
    \Bowlingliga\Application\SeasonStore
{
    /**
     * Creates a season and returns its id (R8.1).
     */
    public function create(
        string $name,
        string $startDate,
        string $endDate,
        ?int $matchdayCount = null
    ): int {
        return $this->transactional('Saison anlegen', function ($pdo) use ($name, $startDate, $endDate, $matchdayCount): int {
            $this->perform(
                'INSERT INTO season (name, start_date, end_date, matchday_count)
                 VALUES (:name, :start_date, :end_date, :matchday_count)',
                [
                    'name'             => $name,
                    'start_date'       => $startDate,
                    'end_date'         => $endDate,
                    'matchday_count' => $matchdayCount,
                ]
            );

            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Updates the master data of a season (R8.5).
     */
    public function update(
        int $id,
        string $name,
        string $startDate,
        string $endDate,
        ?int $matchdayCount = null
    ): void {
        $this->transactional('Saison aktualisieren', function () use ($id, $name, $startDate, $endDate, $matchdayCount): void {
            $this->perform(
                'UPDATE season
                 SET name = :name, start_date = :start_date, end_date = :end_date, matchday_count = :matchday_count
                 WHERE id = :id',
                [
                    'id'               => $id,
                    'name'             => $name,
                    'start_date'       => $startDate,
                    'end_date'         => $endDate,
                    'matchday_count' => $matchdayCount,
                ]
            );
        });
    }

    public function setScoreSheetLanguage(int $id, ?string $language): void
    {
        $this->transactional('Sprache der Spielzettel setzen', function () use ($id, $language): void {
            $this->perform('UPDATE season SET score_sheet_language = :language WHERE id = :id', ['id' => $id, 'language' => $language]);
        });
    }

    /**
     * Deletes a (not yet started) season (R8.9).
     */
    public function delete(int $id): void
    {
        $this->transactional('Saison löschen', function () use ($id): void {
            $this->perform('DELETE FROM season WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Returns a season or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, name, start_date, end_date, matchday_count, score_sheet_language FROM season WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Returns all seasons (R4.5).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllSeasons(): array
    {
        return $this->findAll(
            'SELECT id, name, start_date, end_date, matchday_count, score_sheet_language FROM season ORDER BY start_date'
        );
    }
}
