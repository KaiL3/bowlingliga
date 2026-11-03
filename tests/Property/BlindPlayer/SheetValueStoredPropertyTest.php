<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\BlindPlayer;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\BlindPlayerService;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the net value entered on the score sheet (sheet value)
 * always being the authoritative stored blind net value.
 *
 * Feature: bowlingliga-website, Property 14: Sheet value as authoritative stored blind net value
 *
 * Validates: R27.10, R22.2, R3.10
 *
 * Domain background: for a game marked blind a derived blind reference value
 * is only used as plausibility reference. The net value actually stored,
 * however, is always the entered sheet value – regardless of
 *   - whether a reference value exists at all (first evening special case, no
 *     reference value), and
 *   - whether and how much the sheet value differs from the reference value.
 * The system thus never replaces the entered value with a derived blind value
 * (R27.10), takes the net value entered via the result entry
 * (R22.2) and keeps it as the authoritative value (requirement
 * 3.10).
 *
 * The pure calculation service
 * {@see \Bowlingliga\Domain\BlindPlayerService::checkDeviation()} is tested
 * via the stored net value of the result object
 * {@see \Bowlingliga\Domain\DeviationCheck::storedNet()}.
 */
final class SheetValueStoredPropertyTest extends TestCase
{
    use TestTrait;

    /** Lower bound for valid net values (0–300, R22.2). */
    private const MIN_VALUE = 0;

    /** Upper bound for valid net values (0–300, R22.2). */
    private const MAX_VALUE = 300;

    /**
     * Property 14: for every entered sheet value (0–300) and every possible
     * reference value – whether null (first evening special case) or a valid
     * value (0–300) – the stored net value is always exactly the sheet value.
     * Neither the presence of a reference value nor a deviation between sheet
     * value and reference value changes the stored value.
     *
     * Feature: bowlingliga-website, Property 14: Sheet value as authoritative stored blind net value
     *
     * Validates: R27.10, R22.2, R3.10
     *
     * At least 100 inputs are generated (limitTo(100)). The sheet value covers
     * the whole valid range 0–300; the reference value is generated as an
     * optional value (null or 0–300), so that both the first evening special
     * case without reference and the regular case with differing and matching
     * reference values are checked.
     */
    public function testStoredNetIsAlwaysTheSheetValue(): void
    {
        $service = new BlindPlayerService();

        // Reference value as optional value: -1 encodes "no reference value"
        // (first evening special case), 0..300 a valid derived
        // blind reference value.
        $referenceValueGenerator = Generators::map(
            static fn (int $value): ?int => $value < self::MIN_VALUE ? null : $value,
            Generators::choose(-1, self::MAX_VALUE)
        );

        $this
            ->limitTo(100)
            ->forAll(
                Generators::choose(self::MIN_VALUE, self::MAX_VALUE),
                $referenceValueGenerator
            )
            ->then(function (int $sheetValue, ?int $referenceValue) use ($service): void {
                $verification = $service->checkDeviation($sheetValue, $referenceValue);

                // The stored net value is always the sheet value –
                // regardless of the reference value or a deviation
                // (R27.10, R22.2, R3.10).
                self::assertSame(
                    $sheetValue,
                    $verification->storedNet(),
                    sprintf(
                        'The stored net value must be the sheet value %d, regardless of the reference value %s (R27.10/22.2/3.10).',
                        $sheetValue,
                        $referenceValue === null ? 'null' : (string) $referenceValue
                    )
                );

                // The directly accessible sheet value field also stays the
                // entered value and is never replaced by the reference value
                // (R27.10).
                self::assertSame(
                    $sheetValue,
                    $verification->sheetValue,
                    sprintf(
                        'The entered sheet value %d must not be replaced by a derived blind value (R27.10).',
                        $sheetValue
                    )
                );
            });
    }
}
