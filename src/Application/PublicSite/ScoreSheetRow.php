<?php

declare(strict_types=1);

namespace Bowlingliga\Application\PublicSite;

/**
 * A row in the public, read-only score sheet view of a matchday detail
 * (R15.1, R2.1): a player (also as blind player) or a ghost team row.
 *
 * Carries the player name (or null for the ghost team) and, keyed by game
 * number, the net values and the gross values formed from them with the
 * row's handicap. A substituted player only has the games they played
 * (R21.2). The sums are precalculated for display.
 *
 * Pure, immutable value object; no calculation logic except the simple
 * display sums.
 */
final class ScoreSheetRow
{
    /**
     * @param string|null      $playerName Display name or null
     * @param bool             $isBlind    true for a blind player row (R27.4)
     * @param array<int, int>  $net        Net values by game number (1–3)
     * @param array<int, int>  $gross      Gross values by game number (1–3)
     * @param array<int, bool> $blindGames games of a player marked blind
     *        individually (R27.5), by game number
     */
    public function __construct(
        public readonly ?string $playerName,
        public readonly bool $isBlind,
        public readonly array $net,
        public readonly array $gross,
        public readonly array $blindGames = [],
    ) {
    }

    public function sumNet(): int
    {
        return array_sum($this->net);
    }

    public function sumGross(): int
    {
        return array_sum($this->gross);
    }

    /**
     * Handicap of the row (gross − net of a game played), 0 without games.
     */
    public function handicap(): int
    {
        foreach ($this->net as $game => $net) {
            return ($this->gross[$game] ?? $net) - $net;
        }

        return 0;
    }
}
