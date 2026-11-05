<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Lane;

use InvalidArgumentException;

/**
 * A lane pair of two adjacent lanes on which a fixture plays in alternating
 * lane mode (R18.1).
 *
 * With an even lane count ≥ 2 (master data rule R8.11) there are exactly
 * lane count / 2 lane pairs. A lane pair is identified by its 1-based index
 * within the season and carries the two adjacent lane numbers (left and right
 * lane) needed for the printed score sheet according to R19.1.
 *
 * Pure, immutable value object without side effects.
 */
final class LanePair
{
    /**
     * @param int $index     1-based index of the lane pair within the season.
     * @param int $leftLane  Number of the left (lower) lane of the pair.
     * @param int $rightLane Number of the right (higher) lane of the pair.
     *
     * @throws InvalidArgumentException for a non-positive index or if the two
     *         lane numbers do not form an ascending adjacent pair.
     */
    public function __construct(
        public readonly int $index,
        public readonly int $leftLane,
        public readonly int $rightLane
    ) {
        if ($index < 1) {
            throw new InvalidArgumentException('The lane pair index must be at least 1.');
        }
        if ($leftLane < 1 || $rightLane < 1) {
            throw new InvalidArgumentException('Lane numbers must be positive.');
        }
        if ($rightLane !== $leftLane + 1) {
            throw new InvalidArgumentException('A lane pair must consist of two neighbouring lanes.');
        }
    }

    /**
     * Forms the lane pair with the given 1-based index; the two adjacent lane
     * numbers are derived consecutively from the index (lane pair 1 → lanes
     * 1/2, lane pair 2 → lanes 3/4, …).
     *
     * @throws InvalidArgumentException for a non-positive index.
     */
    public static function fromIndex(int $index): self
    {
        if ($index < 1) {
            throw new InvalidArgumentException('The lane pair index must be at least 1.');
        }

        $leftLane = ($index - 1) * 2 + 1;

        return new self($index, $leftLane, $leftLane + 1);
    }
}
