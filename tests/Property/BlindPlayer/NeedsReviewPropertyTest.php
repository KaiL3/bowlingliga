<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\BlindPlayer;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\BlindPlayerService;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the review need of a game marked blind.
 *
 * Feature: bowlingliga-website, Property 15: Needs review only on deviation
 *
 * Validates: R27.11
 *
 * Domain background: for a game marked blind the system checks the entered
 * sheet value against the derived blind reference value:
 *   - Without a reference value (first evening special case, R27.16) the game
 *     is not flagged as needing review and the sheet value is taken without
 *     additional confirmation.
 *   - With a reference value the game needs review exactly when the sheet
 *     value differs from the reference value; if they match, it is saved
 *     without additional confirmation (R27.11).
 *
 * The pure calculation service
 * {@see \Bowlingliga\Domain\BlindPlayerService::checkDeviation()} is tested.
 */
final class NeedsReviewPropertyTest extends TestCase
{
    use TestTrait;

    /** Lower bound of the allowed net/gross value range (R27.8). */
    private const MIN_VALUE = 0;

    /** Upper bound of the allowed net/gross value range (R27.9). */
    private const MAX_VALUE = 300;

    /**
     * Property 15 (needs review only on deviation): for every sheet value and
     * every reference value: without a reference value (first evening special
     * case, R27.16) the game does not need review; with a reference value the
     * game needs review exactly when sheet value and reference value differ
     * (R27.11).
     *
     * Feature: bowlingliga-website, Property 15: Needs review only on deviation
     *
     * Validates: R27.11
     *
     * At least 100 inputs are generated (limitTo(100)). Sheet value and
     * reference value are generated over the full allowed range 0–300. An
     * additional flag forces three domain-relevant classes in sufficient
     * numbers: a missing reference value (first evening), a reference value
     * exactly equal to the sheet value (no deviation) and an independently
     * drawn reference value (mostly differing).
     */
    public function testNeedsReviewOnlyOnDeviation(): void
    {
        $service = new BlindPlayerService();

        // 0 = no reference value (first evening special case, R27.16)
        // 1 = reference value exactly equal to the sheet value (no deviation)
        // 2 = independently drawn reference value (mostly differing)
        $scenarioGenerator = Generators::choose(0, 2);

        $this
            ->limitTo(100)
            ->forAll(
                Generators::choose(self::MIN_VALUE, self::MAX_VALUE),
                Generators::choose(self::MIN_VALUE, self::MAX_VALUE),
                $scenarioGenerator
            )
            ->then(function (int $sheetValue, int $drawnReferenceValue, int $scenario) use ($service): void {
                $referenceValue = match ($scenario) {
                    0       => null,
                    1       => $sheetValue,
                    default => $drawnReferenceValue,
                };

                $result = $service->checkDeviation($sheetValue, $referenceValue);

                // The stored net value always remains the
                // sheet value (R27.10).
                self::assertSame(
                    $sheetValue,
                    $result->sheetValue,
                    'The stored sheet value must not be changed (R27.10).'
                );

                if ($referenceValue === null) {
                    // First evening special case (R27.16): no reference value, never
                    // needs review, no additional confirmation needed.
                    self::assertFalse(
                        $result->referenceValueExists,
                        'Without a reference value none may be reported as present (R27.16).'
                    );
                    self::assertNull(
                        $result->referenceValue,
                        'Without a reference value it must stay null (R27.16).'
                    );
                    self::assertFalse(
                        $result->needsReview,
                        'Without a reference value the single game must not need review (R27.16).'
                    );

                    return;
                }

                // Regular case with a reference value (R27.11).
                self::assertTrue(
                    $result->referenceValueExists,
                    'With a reference value it must be reported as present (R27.11).'
                );
                self::assertSame(
                    $referenceValue,
                    $result->referenceValue,
                    'The given reference value must be taken over unchanged (R27.11).'
                );

                // Needs review exactly when the sheet value differs from the reference value
                // (R27.11).
                self::assertSame(
                    $sheetValue !== $referenceValue,
                    $result->needsReview,
                    sprintf(
                        'A review is needed exactly on a deviation (sheet value %d, reference value %d) (R27.11).',
                        $sheetValue,
                        $referenceValue
                    )
                );
            });
    }
}
