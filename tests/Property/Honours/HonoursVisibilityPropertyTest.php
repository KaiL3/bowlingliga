<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Honours;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\Honours\Honours;
use Bowlingliga\Domain\Honours\HonourCategory;
use Bowlingliga\Domain\Honours\HonourPlace;
use Bowlingliga\Domain\Honours\Gender;
use Bowlingliga\Domain\Honours\CategoryResult;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the time and role dependent visibility of the honours.
 *
 * Feature: bowlingliga-website, Property 21: Visibility of the honours after the end date
 *
 * Validates: R13.8, R13.9, R13.10
 *
 * Domain background: the calculated honours of a season follow a fixed
 * visibility rule that depends only on whether the season's end date has
 * passed and on the requesting role:
 *   - An anonymous visitor may only see the honours after the end date has
 *     passed; before that they stay hidden (R13.8, R13.10).
 *   - An administrator may see the honours at any time – i.e. also before the
 *     end date – in the admin area (R13.9).
 *
 * The {@see Honours} object represents this rule deterministically via the
 * flag {$isAfterEndDate}; the pure queries {@see Honours::isPubliclyVisible()}
 * and {@see Honours::isVisibleFor()} are tested.
 */
final class HonoursVisibilityPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 21 (visibility): for every combination of passed or not passed
     * end date and admin or anonymous role, the visibility of the honours
     * follows the visibility rule exactly:
     *   - isPubliclyVisible() holds exactly when the end date has passed
     *     (R13.8, R13.10).
     *   - isVisibleFor(isAdmin) holds exactly when the request comes from an
     *     administrator or the end date has passed (R13.8, R13.9,
     *     13.10).
     *
     * Feature: bowlingliga-website, Property 21: Visibility of the honours after the end date
     *
     * Validates: R13.8, R13.9, R13.10
     *
     * At least 100 inputs are generated (limitTo(100)). The input range covers
     * all four combinations of {isAfterEndDate} and {isAdmin} as well as a
     * varying number of category results, so that the visibility is proven to
     * be independent of the content of the honours.
     */
    public function testVisibilityOfHonoursFollowsEndDateAndRole(): void
    {
        $this
            ->limitTo(100)
            ->forAll(
                Generators::bool(),
                Generators::bool(),
                Generators::choose(0, 8)
            )
            ->then(function (bool $isAfterEndDate, bool $isAdmin, int $resultCount): void {
                $honours = new Honours(
                    $this->generateCategoryResults($resultCount),
                    $isAfterEndDate
                );

                // Public visibility (anonymous visitor) holds exactly when
                // the end date has passed (R13.8, R13.10).
                self::assertSame(
                    $isAfterEndDate,
                    $honours->isPubliclyVisible(),
                    sprintf(
                        'The public visibility must follow the end date state (isAfterEndDate=%s; R13.8/R13.10).',
                        $isAfterEndDate ? 'true' : 'false'
                    )
                );

                // Visibility for the requesting user: an administrator
                // sees the honours at any time (R13.9), an anonymous visitor
                // only after the end date has passed (R13.8, R13.10).
                $expected = $isAdmin || $isAfterEndDate;
                self::assertSame(
                    $expected,
                    $honours->isVisibleFor($isAdmin),
                    sprintf(
                        'The visibility must be (isAdmin || isAfterEndDate) (isAdmin=%s, isAfterEndDate=%s; R13.8/R13.9/R13.10).',
                        $isAdmin ? 'true' : 'false',
                        $isAfterEndDate ? 'true' : 'false'
                    )
                );

                // An administrator always sees the honours regardless of the end date
                // (R13.9).
                self::assertTrue(
                    $honours->isVisibleFor(true),
                    'An administrator must see the honours at any time (R13.9).'
                );

                // For an anonymous visitor the personal visibility
                // matches the public visibility (R13.8, R13.10).
                self::assertSame(
                    $honours->isPubliclyVisible(),
                    $honours->isVisibleFor(false),
                    'For an anonymous visitor the visibility must equal the public one (R13.8, R13.10).'
                );
            });
    }

    /**
     * Creates a list of minimal, vacant category results, to show that the
     * visibility is determined solely by {isAfterEndDate}, regardless of the
     * content of the honours (R13.8, R13.9, R13.10).
     *
     * @return list<CategoryResult>
     */
    private function generateCategoryResults(int $quantity): array
    {
        $categories = HonourCategory::cases();
        $genders = Gender::cases();

        $results = [];
        for ($i = 0; $i < $quantity; $i++) {
            $category = $categories[$i % count($categories)];
            $gender = $genders[$i % count($genders)];
            $results[] = new CategoryResult(
                $category,
                $gender,
                [new HonourPlace(1)]
            );
        }

        return $results;
    }
}
