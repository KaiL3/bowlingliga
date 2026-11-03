<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Honours;

/**
 * The four honour categories of a season (R13.1).
 *
 * Two domain dimensions are combined:
 *
 * - Basis: the "highest series" (sum of exactly three regular, personally
 *   played games of one evening, R13.2) or the "highest game" (the highest
 *   single value of a regular, personally played game).
 * - Scoring: "net" scores the net value achieved, "gross" the value increased
 *   by the player's handicap (gross = net + handicap, R13.3).
 *
 * For each category places 1 to 3 are determined separately by gender
 * (R13.1).
 */
enum HonourCategory: string
{
    /** Highest series, scored by net value (R13.1). */
    case HIGHEST_SERIES_NET = 'highest_series_net';

    /** Highest series, scored by gross value (net + handicap, R13.1, R13.3). */
    case HIGHEST_SERIES_GROSS = 'highest_series_gross';

    /** Highest game, scored by net value (R13.1). */
    case HIGHEST_GAME_NET = 'highest_game_net';

    /** Highest game, scored by gross value (net + handicap, R13.1, R13.3). */
    case HIGHEST_GAME_GROSS = 'highest_game_gross';

    /**
     * Tells whether the category scores a series (exactly 3 regular,
     * personally played games of one evening, R13.2) instead of a single game.
     */
    public function isSeries(): bool
    {
        return $this === self::HIGHEST_SERIES_NET
            || $this === self::HIGHEST_SERIES_GROSS;
    }

    /**
     * Tells whether the category is scored by gross value (net + handicap,
     * R13.3) instead of net value.
     */
    public function isGross(): bool
    {
        return $this === self::HIGHEST_SERIES_GROSS
            || $this === self::HIGHEST_GAME_GROSS;
    }
}
