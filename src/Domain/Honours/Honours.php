<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Honours;

/**
 * Immutable result of an honours calculation for a season (R13).
 *
 * The honours comprise, for each of the four categories
 * ({@see HonourCategory}) and each gender ({@see Gender}), a
 * {@see CategoryResult} with places 1 to 3 (R13.1). Since the honours can be
 * calculated at any time during the season (R13.7), this object is a pure
 * calculation result without any persistence dependency.
 *
 * Visibility is deliberately represented deterministically via the
 * {$isAfterEndDate} flag: whether the season's end date has passed is decided
 * by the calling layer through a date comparison and passed in as a boolean.
 * This keeps the calculation free of time side effects and fully reproducible
 * (R35):
 *
 * - Before the end date the honours are only visible to administrators in the
 *   admin area (R13.9).
 * - After the end date the honours are publicly visible (R13.10); an
 *   anonymous visitor must not see them before (R13.8).
 */
final class Honours
{
    /** @var list<CategoryResult> */
    public readonly array $categoryResults;

    /**
     * @param list<CategoryResult> $categoryResults One result with places 1 to 3
     *                                              per category and gender
     *                                              (R13.1).
     * @param bool                 $isAfterEndDate  True if the season's end date
     *                                              has passed (R13.10).
     *                                              Determined by the calling
     *                                              layer as a date comparison.
     */
    public function __construct(
        array $categoryResults,
        public readonly bool $isAfterEndDate,
    ) {
        $this->categoryResults = array_values($categoryResults);
    }

    /**
     * Returns the result of a specific category and gender, or null if there
     * is no result for it.
     */
    public function result(HonourCategory $category, Gender $gender): ?CategoryResult
    {
        foreach ($this->categoryResults as $result) {
            if ($result->category === $category && $result->gender === $gender) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Tells whether the honours are publicly visible, i.e. to an anonymous
     * visitor. This is the case exactly when the season's end date has passed
     * (R13.8, R13.10).
     */
    public function isPubliclyVisible(): bool
    {
        return $this->isAfterEndDate;
    }

    /**
     * Tells whether the honours are visible to the requesting user.
     *
     * An administrator may see the calculated honours at any time (R13.9); an
     * anonymous visitor only after the end date has passed (R13.8, R13.10).
     *
     * @param bool $isAdmin True if the request comes from an administrator in
     *                      the admin area (R13.9).
     */
    public function isVisibleFor(bool $isAdmin): bool
    {
        return $isAdmin || $this->isAfterEndDate;
    }
}
