<?php

declare(strict_types=1);

namespace Bowlingliga\Application\PublicSite;

/**
 * The read-only score sheet view of a team within a fixture (R15.1, R2.1,
 * R22.1).
 *
 * Bundles the team name, the rows of the score sheet with their game results
 * (see {@see ScoreSheetRow}) and the precalculated net and gross team sums
 * over all rows. This is the digital, read-only counterpart of a team's
 * filled-in score sheet.
 *
 * Pure, immutable value object.
 */
final class ScoreSheetTeamView
{
    /**
     * @param string|null         $teamName Display name of the team
     * @param list<ScoreSheetRow> $rows     rows in sheet order
     */
    public function __construct(
        public readonly ?string $teamName,
        public readonly array $rows,
    ) {
    }

    public function sumNet(): int
    {
        $sum = 0;
        foreach ($this->rows as $row) {
            $sum += $row->sumNet();
        }

        return $sum;
    }

    public function sumGross(): int
    {
        $sum = 0;
        foreach ($this->rows as $row) {
            $sum += $row->sumGross();
        }

        return $sum;
    }
}
