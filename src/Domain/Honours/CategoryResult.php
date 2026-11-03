<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Honours;

/**
 * Immutable value object for the result of one honour category and one
 * gender: places 1 to 3 (R13.1).
 *
 * A CategoryResult bundles exactly the three places of a combination of
 * {@see HonourCategory} and {@see Gender}. The places are in ascending rank
 * order (rank 1 at index 0). Because of ties there may be fewer than three
 * HonourPlace objects, since shared places skip the following places (R13.5);
 * places not taken are marked as vacant (R13.6).
 */
final class CategoryResult
{
    /** @var list<HonourPlace> */
    public readonly array $places;

    /**
     * @param HonourCategory    $category The scored category (R13.1).
     * @param Gender            $gender   The gender the places were determined
     *                                    for (R13.1).
     * @param list<HonourPlace> $places   The taken and vacant places in ascending
     *                                    rank order (R13.5, R13.6).
     */
    public function __construct(
        public readonly HonourCategory $category,
        public readonly Gender $gender,
        array $places,
    ) {
        $this->places = array_values($places);
    }
}
