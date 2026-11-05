<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Honours;

/**
 * Immutable value object for a single place (1, 2 or 3) of one honour category
 * and one gender (R13.1).
 *
 * A place carries its rank ({$rank}, 1 to 3) and the placements scored on
 * that rank:
 *
 * - If several results have the same value and compete for the same place,
 *   they share that place; all affected placements are contained in
 *   {$placements} (tie, R13.5).
 * - If there are fewer than 3 countable results, the place stays vacant;
 *   {$placements} is then empty (R13.6).
 *
 * The following places skipped because of a shared place are taken into
 * account by the {@see HonoursModule} when assigning and do not appear as a
 * separate HonourPlace (R13.5).
 */
final class HonourPlace
{
    public readonly int $rank;

    /** @var list<HonourPlacement> */
    public readonly array $placements;

    /**
     * @param int                   $rank       Rank of the place in the range 1
     *                                          to 3 (R13.1).
     * @param list<HonourPlacement> $placements The placements scored on this
     *                                          place; more than one on a tie
     *                                          (R13.5), empty for a vacant place
     *                                          (R13.6).
     *
     * @throws \InvalidArgumentException If the rank is outside 1 to 3.
     */
    public function __construct(int $rank, array $placements = [])
    {
        if ($rank < 1 || $rank > 3) {
            throw new \InvalidArgumentException(
                'The rank of an honours place must be between 1 and 3, given: ' . $rank
            );
        }

        $this->rank = $rank;
        $this->placements = array_values($placements);
    }

    /**
     * Tells whether this place is vacant because there were fewer than 3
     * countable results (R13.6).
     */
    public function isVacant(): bool
    {
        return $this->placements === [];
    }

    /**
     * Tells whether several results share this place because of a tie (R13.5).
     */
    public function isShared(): bool
    {
        return count($this->placements) > 1;
    }
}
