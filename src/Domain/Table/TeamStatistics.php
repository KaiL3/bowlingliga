<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Table;

/**
 * Immutable value object with the raw key figures already aggregated per team
 * as input to the table service (R16.1).
 *
 * The table service is a pure calculation unit without side effects: it does
 * not query a database but receives per team the key figures already summed
 * up over the matchdays to include. For the league table these are all played
 * matchdays of the season (R16.6, R24.3), for the structurally identical
 * matchday table only the one matchday in question (R15.3). Which matchdays
 * are included is therefore decided by the calling layer during aggregation,
 * not by this service.
 *
 * The values contained deliberately cover only the four raw sums needed for
 * sorting, rank and total row (points, net pins, gross pins, number of games
 * played). The net/gross averages to display (R16.1) are derived values and
 * are only calculated from these sums in the {@see TableRow}, so that the
 * input stays free of redundancy.
 *
 * The ghost team according to R20 is passed as its own team
 * statistic via {$isGhostTeam}; it gets its own table row, is sorted by the
 * same criteria and included in the total row (R16.10, R16.11, R20.8). For the
 * ghost team net pins and gross pins are equal because it gets no handicap
 * (R16.11, R20.2); this is ensured by the aggregating layer and not enforced
 * here.
 */
final class TeamStatistics
{
    /**
     * @param string $teamId        Stable technical id of the team; lets the
     *                              caller attribute the row.
     * @param string $teamName      The display name of the team defined per
     *                              season (R16.1).
     * @param int    $points        Sum of the points achieved over the included
     *                              matchdays (base and bonus points, R16.1,
     *                              R17). Not negative.
     * @param int    $netPins       Sum of the net results of the games played
     *                              (R16.1, R16.4). Not negative.
     * @param int    $grossPins     Sum of the gross results of the games played;
     *                              also the gross of the games played relevant
     *                              for sorting (R16.1, R16.3). Not negative.
     * @param int    $numberOfGames Number of games played (R16.1). Not negative;
     *                              0 in a season not played yet (R16.7).
     * @param bool   $isGhostTeam   True if this statistic describes the ghost
     *                              team according to R20 (R16.10).
     *
     * @throws \InvalidArgumentException If the team name is empty or one of the
     *                                   sums is negative.
     */
    public function __construct(
        public readonly string $teamId,
        public readonly string $teamName,
        public readonly int $points,
        public readonly int $netPins,
        public readonly int $grossPins,
        public readonly int $numberOfGames,
        public readonly bool $isGhostTeam = false,
    ) {
        if ($teamName === '') {
            throw new \InvalidArgumentException('Der Teamname einer Team-Statistik darf nicht leer sein.');
        }

        if ($points < 0) {
            throw new \InvalidArgumentException('Die Punkte einer Team-Statistik dürfen nicht negativ sein, gegeben: ' . $points);
        }

        if ($netPins < 0) {
            throw new \InvalidArgumentException('Die Pins Netto einer Team-Statistik dürfen nicht negativ sein, gegeben: ' . $netPins);
        }

        if ($grossPins < 0) {
            throw new \InvalidArgumentException('Die Pins Brutto einer Team-Statistik dürfen nicht negativ sein, gegeben: ' . $grossPins);
        }

        if ($numberOfGames < 0) {
            throw new \InvalidArgumentException('Die Anzahl geleisteter Spiele einer Team-Statistik darf nicht negativ sein, gegeben: ' . $numberOfGames);
        }
    }
}
