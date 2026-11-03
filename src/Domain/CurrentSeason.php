<?php

declare(strict_types=1);

namespace Bowlingliga\Domain;

/**
 * Which season counts as the current one when nobody chose a season (R28.18).
 *
 * 1. The running season: today lies between its start and end date
 *    (both inclusive). If seasons overlap, the one that started last.
 * 2. Otherwise – summer break, or after the last season – the season that
 *    ended last, so its final table and honours stay visible until the next
 *    one starts.
 * 3. Otherwise – before the first season – the one that starts next.
 *
 * Creating next year's season early therefore changes nothing until it
 * starts. Pure function, no I/O.
 */
final class CurrentSeason
{
    /**
     * @param list<array{id: int|string, start_date: string, end_date: string}> $seasons
     *
     * @return int|null id of the current season, null without seasons
     */
    public static function of(array $seasons, \DateTimeImmutable $today): ?int
    {
        $day = $today->format('Y-m-d');

        $running = null;
        $endedLast = null;
        $startsNext = null;
        foreach ($seasons as $season) {
            $start = (string) $season['start_date'];
            $end   = (string) $season['end_date'];

            if ($start <= $day && $day <= $end) {
                if ($running === null || $start > (string) $running['start_date']) {
                    $running = $season;
                }
            } elseif ($end < $day) {
                if ($endedLast === null || $end > (string) $endedLast['end_date']) {
                    $endedLast = $season;
                }
            } elseif ($startsNext === null || $start < (string) $startsNext['start_date']) {
                $startsNext = $season;
            }
        }

        $current = $running ?? $endedLast ?? $startsNext;

        return $current !== null ? (int) $current['id'] : null;
    }
}
