<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Printing;

/**
 * A team on the printed score sheet with its roster (R19.2).
 *
 * Pure, immutable value object.
 */
final class PrintScoreSheetTeam
{
    /**
     * @param string                      $teamLabel Team name of the season (R19.2)
     * @param list<PrintScoreSheetPlayer> $roster    Roster players of the team (R19.2)
     * @param string|null                 $entryQr   QR code (image data URI) of the team's result entry (R37.2)
     */
    public function __construct(
        public readonly string $teamLabel,
        public readonly array $roster,
        public readonly ?int $ghostTargetScore = null,
        public readonly ?string $entryQr = null,
    ) {
    }

    public function withEntryQr(?string $entryQr): self
    {
        return new self($this->teamLabel, $this->roster, $this->ghostTargetScore, $entryQr);
    }
}
