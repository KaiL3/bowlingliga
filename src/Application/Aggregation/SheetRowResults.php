<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Aggregation;

/**
 * The game results of one row of a team's score sheet as raw data of the
 * table aggregation (R17.2).
 *
 * A row is a player (also as blind player) or a ghost team row. Since
 * players can be substituted between games (R21.2, R27.17), a row holds 1 to
 * 3 games, keyed by game number. The handicap valid for the row
 * ({@see $handicap}) is used to form the gross (net + HDC, R17.2). For the
 * ghost team the handicap is 0 (R20.2); a blind player's blind games carry
 * their own handicap (R27.7). Without a handicap 0 is used, since the team
 * gross sum must always be defined for awarding points.
 *
 * Pure, immutable value object.
 */
final class SheetRowResults
{
    /**
     * @param array<int, int> $net      Net values by game number (1–3) of the games of this row
     * @param int             $handicap Handicap for the gross (R17.2); 0 for the ghost team
     * @param list<int>       $blind    Game numbers of this row that are blind games (R27.12); only
     *                                  the team page needs them (R41.5), the table counts them normally
     */
    public function __construct(
        public readonly array $net,
        public readonly int $handicap,
        public readonly array $blind = [],
    ) {
    }

    /**
     * Net values by game number of the games actually played, without blind
     * games (R27.13, R41.5).
     *
     * @return array<int, int>
     */
    public function playedNet(): array
    {
        return array_diff_key($this->net, array_flip($this->blind));
    }

    /**
     * Gross values by game number: net + handicap per game (R17.2, R12.11).
     *
     * @return array<int, int>
     */
    public function gross(): array
    {
        return array_map(fn (int $net): int => $net + $this->handicap, $this->net);
    }

    /**
     * Number of games of this row.
     */
    public function gameCount(): int
    {
        return count($this->net);
    }

    /**
     * Sum of the net values of this row.
     */
    public function sumNet(): int
    {
        return array_sum($this->net);
    }

    /**
     * Sum of the gross values of this row.
     */
    public function sumGross(): int
    {
        return $this->sumNet() + $this->handicap * $this->gameCount();
    }
}
