<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * A team in the virtual score sheet with the stored rows of the evening
 * (R30.5).
 *
 * Pure, immutable value object.
 */
final class ScoreSheetTeam
{
    /**
     * @param int                 $teamSeasonId Team (season) id
     * @param string|null         $teamLabel    Display name of the team
     * @param list<ScoreSheetRow> $rows         rows in sheet order
     */
    public function __construct(
        public readonly int $teamSeasonId,
        public readonly ?string $teamLabel,
        public readonly array $rows,
    ) {
    }
}
