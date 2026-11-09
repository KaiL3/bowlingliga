<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Schedule;

/**
 * Port for cancelling and postponing matchdays (R8.20, R8.21).
 *
 * Implemented by {@see \Bowlingliga\Persistence\Schedule\MatchdayScheduleRepository};
 * in tests an in-memory fake is enough.
 */
interface MatchdayScheduleStore
{
    /**
     * The matchdays of the season, ascending by number; `has_results` is true
     * once a fixture has a draft or results, `is_complete` once all its
     * fixtures are complete.
     *
     * @return list<array{id: int, season_id: int, number: int, date: string, type: string, is_cancelled: bool, has_results: bool, is_complete: bool}>
     */
    public function matchdays(int $seasonId): array;

    /** Season id of a matchday, or null if it does not exist. */
    public function seasonOf(int $matchdayId): ?int;

    /** End date (`YYYY-MM-DD`) of the season, or null. */
    public function seasonEnd(int $seasonId): ?string;

    public function setCancelled(int $matchdayId, bool $cancelled): void;

    /**
     * Saves new dates of matchdays and the new end date of the season.
     *
     * @param array<int, string> $dates matchday id => `YYYY-MM-DD`
     */
    public function moveDates(int $seasonId, array $dates, string $seasonEnd): void;
}
