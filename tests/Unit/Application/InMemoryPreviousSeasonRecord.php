<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\PreviousSeasonRecordStore;
use Bowlingliga\Application\PreviousSeasonContext;

/**
 * In-memory fake of the previous season record store and the previous season
 * context for the use case tests, without a database.
 *
 * Reproduces the upsert per player and season (R29.8, R29.11) and the
 * switchable state question "season started" (R29.9) per season.
 */
final class InMemoryPreviousSeasonRecord implements PreviousSeasonRecordStore, PreviousSeasonContext
{
    /** @var array<string, array{game_count: int, net_pins_total: int}> */
    private array $records = [];

    /**
     * @param list<int> $startedSeasons Seasons that have reached their start date.
     */
    public function __construct(public array $startedSeasons = [])
    {
    }

    public function save(int $seasonId, int $playerId, int $numberOfGames, int $netPinsTotal): void
    {
        $this->records["$seasonId:$playerId"] = [
            'game_count'     => $numberOfGames,
            'net_pins_total' => $netPinsTotal,
        ];
    }

    public function delete(int $seasonId, int $playerId): void
    {
        unset($this->records["$seasonId:$playerId"]);
    }

    public function isSeasonStarted(int $seasonId): bool
    {
        return in_array($seasonId, $this->startedSeasons, true);
    }

    /**
     * @return array{game_count: int, net_pins_total: int}|null
     */
    public function find(int $seasonId, int $playerId): ?array
    {
        return $this->records["$seasonId:$playerId"] ?? null;
    }

    public function quantity(): int
    {
        return count($this->records);
    }
}
