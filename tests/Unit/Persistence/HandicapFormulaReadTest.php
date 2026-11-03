<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Persistence;

use Bowlingliga\Persistence\ResultEntry\HandicapFreezingRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for reading the stored handicap formula (task 35.3).
 *
 * Validates: R12.2, R12.3, R12.13
 */
final class HandicapFormulaReadTest extends TestCase
{
    public function testIdentifierDefault(): void
    {
        self::assertSame(0.8, HandicapFreezingRepository::readFormula('standard')?->factor());
    }

    public function testNotationWithFactor(): void
    {
        self::assertSame(0.8, HandicapFreezingRepository::readFormula('HDC = (Zielwert - Durchschnitt) * 0.8')?->factor());
        self::assertSame(0.9, HandicapFreezingRepository::readFormula('(Zielwert - Durchschnitt) × 0,9')?->factor());
    }

    public function testMissingOrUnreadableFormula(): void
    {
        self::assertNull(HandicapFreezingRepository::readFormula(null));
        self::assertNull(HandicapFreezingRepository::readFormula(''));
        self::assertNull(HandicapFreezingRepository::readFormula('Durchschnitt / 2'));
    }
}
