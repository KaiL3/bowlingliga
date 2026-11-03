<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * A single entered net result of a game (R22.1, R22.2, R27.5, R27.10).
 *
 * Describes, for one row of a team's score sheet, the net result of one of
 * the three games including the blind flag. A row is either
 * - a roster player ({@see $playerId}, {@see $slot} = 0): the games they
 *   played themselves and, if they could not continue without a substitute,
 *   their own blind games – or, as blind player for a missing player, only
 *   blind games (R27.4, R27.5) –, or
 * - a row of the ghost team ({@see $slot} 1–4, without player, R20.6), or
 * - a blind slot of a real team without a roster player of its own
 *   ({@see $slot} 1–3, always blind): no roster player was left to serve as
 *   blind player; it plays like a ghost player with the ghost score and no
 *   handicap (R27.18).
 *
 * The stored net value is always the entered sheet value (R27.10, R22.2);
 * deriving the blind reference value happens outside this value holder.
 *
 * Pure, immutable value object without side effects. The range validation
 * (0–300, R22.4) and the lineup rules are checked by the use case.
 */
final class EnteredGame
{
    /**
     * @param int      $teamSeasonId Team the row belongs to
     * @param int|null $playerId     Roster player of the row; null for the ghost team
     * @param int      $gameNumber   Game 1–3 (R22.1)
     * @param int      $net          entered net sheet value 0–300 (R22.4, R27.10)
     * @param bool     $isBlind      Blind flag of this game (R27.5)
     * @param int      $slot         0 for a player row, 1–4 for a ghost team row or a blind slot
     */
    public function __construct(
        public readonly int $teamSeasonId,
        public readonly ?int $playerId,
        public readonly int $gameNumber,
        public readonly int $net,
        public readonly bool $isBlind = false,
        public readonly int $slot = 0,
    ) {
    }

    /**
     * Game of a roster player (R21.2, R27.17).
     */
    public static function player(int $teamSeasonId, int $playerId, int $gameNumber, int $net, bool $isBlind = false): self
    {
        return new self($teamSeasonId, $playerId, $gameNumber, $net, $isBlind);
    }

    /**
     * Game of a ghost team row with the fixed ghost score (R20.1, R20.6).
     */
    public static function ghost(int $teamSeasonId, int $slot, int $gameNumber, int $net): self
    {
        return new self($teamSeasonId, null, $gameNumber, $net, false, $slot);
    }

    /**
     * Game of a blind slot without a roster player of its own (R27.18).
     */
    public static function blindSlot(int $teamSeasonId, int $slot, int $gameNumber, int $net): self
    {
        return new self($teamSeasonId, null, $gameNumber, $net, true, $slot);
    }

    /**
     * Tells whether the game belongs to a blind slot without a player of its own.
     */
    public function isBlindSlot(): bool
    {
        return $this->slot > 0 && $this->isBlind;
    }

    /**
     * Tells whether the game belongs to a row of a roster player.
     */
    public function isPlayerRow(): bool
    {
        return $this->slot === 0;
    }
}
