<?php

declare(strict_types=1);

namespace Bowlingliga\Application\LotDraw;

/**
 * Read port for entering the fixtures of a lot draw matchday.
 *
 * Implemented by {@see \Bowlingliga\Persistence\LotDraw\LotDrawRepository};
 * in tests a fake with fixed values is enough.
 */
interface LotDrawReader
{
    /**
     * @return array{season_id: int, number: int, type: string}|null
     */
    public function matchday(int $matchdayId): ?array;

    /**
     * All teams of the season including the ghost team.
     *
     * @return list<array{id: int, name: string}>
     */
    public function teams(int $seasonId): array;

    /** Whether results were already entered for the matchday. */
    public function hasResults(int $matchdayId): bool;
}
