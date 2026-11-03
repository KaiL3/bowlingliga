<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\EnteredResults;
use Bowlingliga\Application\ResultEntry\ResultStore;

/**
 * In-memory fake of the {@see ResultStore} port for the unit tests.
 */
final class InMemoryResultStore implements ResultStore
{
    /** @var array<int, EnteredResults> per fixture the results saved last */
    public array $saved = [];

    /** @var list<int> Teampaarungen, deren Ergebnisse entfernt wurden */
    public array $removed = [];

    /** Controls the return value of hasResults() after removing. */
    public bool $remainingResultsExist = false;

    /** @var array<string, list<int>> "fixture-team" → stored blind players */
    public array $storedBlindPlayers = [];

    public function saveResults(EnteredResults $results): void
    {
        $this->saved[$results->fixtureId] = $results;
    }

    public function findBlindPlayers(int $fixtureId, int $teamSeasonId): array
    {
        return $this->storedBlindPlayers[$fixtureId . '-' . $teamSeasonId] ?? [];
    }

    public function removeResults(int $fixtureId): void
    {
        $this->removed[] = $fixtureId;
        unset($this->saved[$fixtureId]);
    }

    public function hasResults(int $fixtureId): bool
    {
        return $this->remainingResultsExist;
    }

    public function findGames(int $fixtureId): array
    {
        return array_values(array_filter(
            ($this->saved[$fixtureId] ?? null)?->games ?? [],
            static fn ($game): bool => $game->isPlayerRow(),
        ));
    }
}
