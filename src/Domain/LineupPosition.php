<?php

declare(strict_types=1);

namespace Bowlingliga\Domain;

/**
 * A single position (1–4) of a team lineup (R21.2–3, R27.1, R27.17).
 *
 * A position is either filled by a real roster player (lined up regularly or
 * as a replacement for an absent player, R27.17) or kept as a blind player
 * position with the substitute player (R27.4). Blind marking happens per game
 * in the domain (R27.5); this object holds the position level of the filling.
 *
 * Pure, immutable value object without side effects.
 */
final class LineupPosition
{
    /**
     * @param int      $position       Position number 1–4 (R27.1)
     * @param int      $playerId       real or substitute player (R27.4)
     * @param bool     $isBlind        true = blind player position (R27.1)
     * @param int|null $gamesPlayedRef games played by the substitute player
     *        for blind positions (R27.4); null for real players
     */
    public function __construct(
        public readonly int $position,
        public readonly int $playerId,
        public readonly bool $isBlind,
        public readonly ?int $gamesPlayedRef = null
    ) {
    }

    /**
     * Real player position, filled regularly or as replacement (R21.2, R27.17).
     */
    public static function real(int $position, int $playerId): self
    {
        return new self($position, $playerId, false, null);
    }

    /**
     * Blind player position with the substitute roster player (R27.1, R27.4).
     */
    public static function blind(int $position, int $playerId, ?int $gamesPlayedRef = null): self
    {
        return new self($position, $playerId, true, $gamesPlayedRef);
    }
}
