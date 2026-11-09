<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Schedule;

use Bowlingliga\Application\Schedule\MatchdayScheduleStore;

/** In-memory fake of the {@see MatchdayScheduleStore} port with one season. */
final class InMemoryMatchdaySchedule implements MatchdayScheduleStore
{
    public const SEASON = 1;

    /** @var array<int, array{id: int, season_id: int, number: int, date: string, type: string, is_cancelled: bool, has_results: bool, is_complete: bool}> */
    public array $matchdays = [];

    public string $seasonEnd = '2027-06-30';

    /** Adds weekly matchdays 1…n from the start date; ids equal the numbers. */
    public static function weekly(int $count, string $start = '2026-09-02'): self
    {
        $store = new self();
        for ($number = 1; $number <= $count; $number++) {
            $store->matchdays[$number] = [
                'id'           => $number,
                'season_id'    => self::SEASON,
                'number'       => $number,
                'date'         => (new \DateTimeImmutable($start))->modify('+' . (7 * ($number - 1)) . ' days')->format('Y-m-d'),
                'type'         => 'regular',
                'is_cancelled' => false,
                'has_results'  => false,
                'is_complete'  => false,
            ];
        }

        return $store;
    }

    public function matchdays(int $seasonId): array
    {
        $list = array_values(array_filter($this->matchdays, static fn (array $m): bool => $m['season_id'] === $seasonId));
        usort($list, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);

        return $list;
    }

    public function seasonOf(int $matchdayId): ?int
    {
        return $this->matchdays[$matchdayId]['season_id'] ?? null;
    }

    public function seasonEnd(int $seasonId): ?string
    {
        return $seasonId === self::SEASON ? $this->seasonEnd : null;
    }

    public function setCancelled(int $matchdayId, bool $cancelled): void
    {
        $this->matchdays[$matchdayId]['is_cancelled'] = $cancelled;
    }

    public function moveDates(int $seasonId, array $dates, string $seasonEnd): void
    {
        foreach ($dates as $id => $date) {
            $this->matchdays[$id]['date'] = $date;
        }
        $this->seasonEnd = $seasonEnd;
    }
}
