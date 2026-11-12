<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\PasswordStrength;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Strength of a password in bits (R23.16): patterns count little, random
 * characters by their character set, comparable to KeePass.
 */
final class PasswordStrengthTest extends TestCase
{
    private static PasswordStrength $strength;

    public static function setUpBeforeClass(): void
    {
        self::$strength = new PasswordStrength();
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2: int}>
     */
    public static function knownPasswords(): iterable
    {
        yield 'common password'           => ['password1', 0, 15];
        yield 'keyboard row'              => ['qwertzuiop', 0, 20];
        yield 'word with digits'          => ['geheim123', 0, 25];
        yield 'l33t word with suffix'     => ['Tr0ub4dor&3', 45, 60];
        yield 'random, 12 characters'     => ['kX9#mP2$vL7q', 75, 80];
        yield 'four common words'         => ['correct horse battery staple', 70, 90];
        yield 'random, 24 characters'     => ['xY7!pQ2#zR9$wL4&kM8@vN3%', 150, 160];
    }

    #[DataProvider('knownPasswords')]
    public function testKnownPasswordsLandInTheirRange(string $password, int $min, int $max): void
    {
        $bits = self::$strength->bits($password);

        self::assertGreaterThanOrEqual($min, $bits, $password);
        self::assertLessThanOrEqual($max, $bits, $password);
    }

    public function testRandomCharactersCountWithTheirCharacterSet(): void
    {
        // 12 random characters of all four ASCII classes: 12 * log2(95) ≈ 78,
        // where zxcvbn alone would say ≈ 40.
        self::assertSame(78, self::$strength->bits('kX9#mP2$vL7q'));
        self::assertLessThan(self::$strength->bits('kX9#mP2$vL7q'), self::$strength->bits('kx9xmp2xvl7q'));
    }

    public function testPersonalWordsCountAsKnown(): void
    {
        $alone    = self::$strength->bits('Kegelfreunde2026');
        $personal = self::$strength->bits('Kegelfreunde2026', ['Kegelfreunde']);

        self::assertLessThan($alone - 20, $personal);
    }

    public function testEmptyPasswordHasNoStrength(): void
    {
        self::assertSame(0, self::$strength->bits(''));
    }

    public function testCharactersBeyondThePatternSearchAddTheirCharacterSet(): void
    {
        $base   = str_repeat('kX9#mP2$', 8);
        $longer = $base . 'v#7Q';

        self::assertSame(64, mb_strlen($base));
        // Four characters of all four classes add 4 * log2(95) ≈ 26.3 bits;
        // rounding down both values leaves 26 or 27.
        $added = self::$strength->bits($longer) - self::$strength->bits($base);
        self::assertGreaterThanOrEqual(26, $added);
        self::assertLessThanOrEqual(27, $added);
    }
}
