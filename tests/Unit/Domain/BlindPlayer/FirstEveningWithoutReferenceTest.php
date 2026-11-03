<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\BlindPlayer;

use Bowlingliga\Domain\DeviationCheck;
use Bowlingliga\Domain\BlindPlayerService;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the first evening special case of the blind player deviation
 * check (task 9.6).
 *
 * Validates: R27.16
 *
 * R27.16: if the substitute player has neither a net average nor a
 * handicap yet (first evening special case), no derived reference value is
 * calculated. In this case {@see BlindPlayerService::checkDeviation()} returns
 * a {@see DeviationCheck} result without reference: no deviation, no review
 * needed; the sheet value is taken as the authoritative stored net value
 * without additional confirmation (R27.10).
 */
final class FirstEveningWithoutReferenceTest extends TestCase
{
    public function testCheckDeviationWithoutReferenceFlagsNoReview(): void
    {
        $sheetValue = 187;

        $result = (new BlindPlayerService())->checkDeviation($sheetValue, null);

        self::assertInstanceOf(DeviationCheck::class, $result);
        self::assertFalse(
            $result->referenceValueExists,
            'Without a reference value none may count as present (R27.16).'
        );
        self::assertFalse(
            $result->needsReview,
            'The first evening special case needs no review (R27.16).'
        );
        self::assertNull(
            $result->referenceValue,
            'Without a reference value it stays null (R27.16).'
        );
        self::assertSame(
            $sheetValue,
            $result->storedNet(),
            'The stored net value stays the sheet value (R27.10).'
        );
    }
}
