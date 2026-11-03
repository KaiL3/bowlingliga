<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

/**
 * Port for persisting matchdays (R8.3–8.5, R8.18, R8.19).
 *
 * Decouples {@see MatchdayManagement} from the concrete persistence
 * technology. The port is implemented by the
 * {@see \Bowlingliga\Persistence\MatchdayRepository}; in tests a slim
 * in-memory fake is enough.
 */
interface MatchdayStore
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
    ): int;

    /**
     * Updates date and type of a matchday (R8.5, R8.19).
     *
     * @param 'regular'|'positionday'|'lot_draw' $matchdayType
     */
    public function update(int $id, string $date, string $matchdayType): void;

    /**
     * Deletes a matchday (R8).
     */
    public function delete(int $id): void;

    /**
     * Returns a matchday or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array;

    /**
     * Returns all matchdays of a season in chronological order; basis for the
     * uniqueness check of the matchday number (R8.4).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForSeason(int $seasonId): array;
}
