<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Honours;

/**
 * Immutable value object for a single scored result of an honour category
 * (R13.1).
 *
 * A placement links a player with the value determined for them in the
 * respective category – for series categories the sum of exactly three
 * regular, personally played games of one evening (R13.2), for game
 * categories the value of a single regular, personally played game. Depending
 * on the category, {$value} is a net or a gross value (gross = net + handicap,
 * R13.3).
 *
 * Several placements with the same value share one {@see HonourPlace}
 * (R13.5).
 */
final class HonourPlacement
{
    /**
     * @param int         $playerId   Unique player id of the scored result.
     * @param int         $value      Value relevant for the category (net or
     *                                gross value, R13.2, R13.3).
     * @param string|null $playerName Optional display name of the player; no
     *                                domain meaning for the scoring.
     */
    public function __construct(
        public readonly int $playerId,
        public readonly int $value,
        public readonly ?string $playerName = null,
    ) {
    }
}
