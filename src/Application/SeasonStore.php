<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

/**
 * Port for persisting season master data (R8.1, R8.5, R8.9).
 *
 * Decouples {@see SeasonAdministration} from the concrete persistence
 * technology. The port is implemented by the
 * {@see \Bowlingliga\Persistence\SeasonRepository}; in tests a slim
 * in-memory fake is enough.
 */
interface SeasonStore
{
    /**
     * Creates a season and returns its id (R8.1).
     */
    public function create(string $name, string $startDate, string $endDate, ?int $matchdayCount = null): int;

    /**
     * Updates the master data of a season (R8.5).
     */
    public function update(int $id, string $name, string $startDate, string $endDate, ?int $matchdayCount = null): void;

    /**
     * Deletes a (not yet started) season (R8.9).
     */
    public function delete(int $id): void;

    /**
     * Returns a season or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array;

    /**
     * Returns all seasons (R4.5); basis for determining a previous season
     * (R8.7, R8.15, R12.4).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllSeasons(): array;

    /**
     * Sets the language of the printed score sheets of a season; null = the
     * default language of the installation.
     */
    public function setScoreSheetLanguage(int $id, ?string $language): void;
}
