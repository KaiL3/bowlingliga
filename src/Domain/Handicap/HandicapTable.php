<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Handicap;

/**
 * Player-independent overview of the handicap for every whole-pin average of
 * a season.
 *
 * The rows run in ascending order of the average. The first row holds the
 * largest average that still gets the maximum handicap, the last row the
 * smallest average with handicap 0 – every lower or higher average gets the
 * same handicap as that row. The handicap calculation rounds every average to
 * whole pins, so the table is exact for every player.
 */
final class HandicapTable
{
    /**
     * @param list<HandicapTableRow> $rows ascending by average
     */
    public function __construct(
        public readonly int $targetScore,
        public readonly float $factor,
        public readonly int $maxHandicap,
        public readonly array $rows,
    ) {
    }

    /** Whether the first row also applies to all lower averages. */
    public function isOpenBelow(): bool
    {
        return $this->rows !== [] && $this->rows[0]->handicap === $this->maxHandicap;
    }

    /** Whether the last row also applies to all higher averages. */
    public function isOpenAbove(): bool
    {
        return $this->rows !== [] && $this->rows[array_key_last($this->rows)]->handicap === 0;
    }
}
