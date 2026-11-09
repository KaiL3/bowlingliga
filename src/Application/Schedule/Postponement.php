<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Schedule;

/**
 * Preview of a postponement (R8.21): the postponed matchday and all later
 * ones move by the same number of days, and so does the season end.
 */
final class Postponement
{
    /**
     * @param list<array{id: int, number: int, old: string, new: string, is_cancelled: bool}> $moves
     */
    public function __construct(
        public readonly int $seasonId,
        public readonly int $matchdayId,
        public readonly int $number,
        public readonly int $days,
        public readonly array $moves,
        public readonly string $oldSeasonEnd,
        public readonly string $newSeasonEnd,
    ) {
    }

    /** @return array<int, string> matchday id => new date */
    public function newDates(): array
    {
        $dates = [];
        foreach ($this->moves as $move) {
            $dates[$move['id']] = $move['new'];
        }

        return $dates;
    }
}
