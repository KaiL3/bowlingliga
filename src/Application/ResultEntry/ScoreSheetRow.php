<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * A row of a team in the virtual score sheet with its three game fields
 * (R30.5): a roster player (also as blind player) or a ghost team row.
 *
 * A row of a substituted player has empty fields for the games they did not
 * play. Pure, immutable value object. Contains exactly three
 * {@see ScoreSheetField} (games 1–3).
 */
final class ScoreSheetRow
{
    /**
     * @param int|null              $playerId    Player of the row, null for the ghost team
     * @param string|null           $playerLabel Name of the player or null
     * @param bool                  $isBlindRow  true if the player serves as blind player (R27.4)
     * @param list<ScoreSheetField> $fields      exactly 3 game fields
     */
    public function __construct(
        public readonly ?int $playerId,
        public readonly ?string $playerLabel,
        public readonly bool $isBlindRow,
        public readonly array $fields,
    ) {
    }
}
