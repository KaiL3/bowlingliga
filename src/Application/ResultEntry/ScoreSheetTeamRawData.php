<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * Raw data of a team for building the virtual score sheet (R30.5, R30.17).
 *
 * Bundles the master data of a fixture's team needed for rendering: team
 * id/name and the stored rows of the score sheet in their order, each with
 * player, blind flag of the row and the game results already stored (for resuming
 * a draft, R30.17).
 *
 * Pure value holder supplied by the {@see ScoreSheetReader}.
 */
final class ScoreSheetTeamRawData
{
    /**
     * @param int         $teamSeasonId Team id
     * @param string|null $teamLabel    Display name
     * @param list<array{player_id: int|null, name: string|null, is_blind: bool,
     *              results: array<int, array{net: int, is_blind: bool}>}> $rows
     *        stored rows in sheet order; results indexed by game number
     */
    public function __construct(
        public readonly int $teamSeasonId,
        public readonly ?string $teamLabel,
        public readonly array $rows,
    ) {
    }
}
