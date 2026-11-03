<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Handicap;

/**
 * One row of the {@see HandicapTable}: handicap for a whole-pin average.
 */
final class HandicapTableRow
{
    public function __construct(
        public readonly int $average,
        public readonly int $handicap,
    ) {
    }
}
