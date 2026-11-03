<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * Data model of the virtual score sheet of a fixture (R30.5–R30.13).
 *
 * Represents exactly two teams with 4 positions each and 3 game fields each.
 * The structure is identical (R30.7) for manual entry (empty, R30.6), resuming
 * a draft (R30.17) and OCR prefilling (R30.8); only the field contents and
 * needs-review markers differ.
 *
 * Pure, immutable value object; the actual persistence happens via
 * {@see ResultRecording} (task 24.2).
 */
final class VirtualScoreSheet
{
    /**
     * @param int            $fixtureId      Fixture id
     * @param int            $matchdayId     Matchday id
     * @param ScoreSheetTeam $home           Home team
     * @param ScoreSheetTeam $away           Away team
     * @param bool           $reworkRequired Overall note that a field needing
     *        review exists (R30.9)
     */
    public function __construct(
        public readonly int $fixtureId,
        public readonly int $matchdayId,
        public readonly ScoreSheetTeam $home,
        public readonly ScoreSheetTeam $away,
        public readonly bool $reworkRequired,
    ) {
    }

    /**
     * Both teams of the score sheet.
     *
     * @return list<ScoreSheetTeam>
     */
    public function teams(): array
    {
        return [$this->home, $this->away];
    }
}
