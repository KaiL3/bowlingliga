<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * A position of a game filled by a blind value (R21.3, R27.4–R27.7, R27.18),
 * determined by {@see BlindFilling}.
 *
 * - `own`: a player who played before and drops out without a substitute;
 *   his own average applies.
 * - `substitute`: a roster player who does not play that evening serves as
 *   blind player (order by games played, R27.4); a new one without an
 *   average with the ghost score.
 * - `slot`: the roster is used up (fewer than 4 players); a blind slot without
 *   a player of its own plays like a ghost player – the season's ghost score,
 *   HDC 0 (R27.18).
 *
 * `net` is null as long as no value can be derived yet (a new player without
 * own games entered that evening). `fromEvening` marks a value derived from
 * the average of this evening, because the player is new; it follows the
 * entered results until the end. `ghostValue` marks the ghost score as value.
 */
final class BlindPosition
{
    public const OWN        = 'own';
    public const SUBSTITUTE = 'substitute';
    public const SLOT       = 'slot';

    public function __construct(
        public readonly string $kind,
        public readonly ?int $playerId,
        public readonly int $slot,
        public readonly int $gameNumber,
        public readonly ?int $net,
        public readonly bool $fromEvening = false,
        public readonly bool $ghostValue = false,
    ) {
    }

    /** The position as an entered game of the team (only with a value). */
    public function toEnteredGame(int $teamSeasonId): ?EnteredGame
    {
        if ($this->net === null) {
            return null;
        }

        return $this->kind === self::SLOT
            ? EnteredGame::blindSlot($teamSeasonId, $this->slot, $this->gameNumber, $this->net)
            : EnteredGame::player($teamSeasonId, (int) $this->playerId, $this->gameNumber, $this->net, true);
    }
}
