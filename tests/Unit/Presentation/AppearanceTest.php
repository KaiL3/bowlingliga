<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Presentation\Appearance;
use PHPUnit\Framework\TestCase;

/**
 * Unit test of the appearance chosen by the visitor (style and colour mode).
 *
 * Checks defaults, reading from cookies and falling back to the default for
 * unknown or manipulated values.
 */
final class AppearanceTest extends TestCase
{
    public function testDefaultIsLaneAutomatic(): void
    {
        $appearance = Appearance::standard();

        self::assertSame('lane', $appearance->style);
        self::assertSame('auto', $appearance->mode);
    }

    public function testValidCookiesAreApplied(): void
    {
        $appearance = Appearance::fromCookies([
            Appearance::COOKIE_STYLE  => 'bento',
            Appearance::COOKIE_MODE => 'dark',
        ]);

        self::assertSame('bento', $appearance->style);
        self::assertSame('dark', $appearance->mode);
    }

    public function testInvalidValuesFallBackToDefault(): void
    {
        $appearance = Appearance::fromCookies([
            Appearance::COOKIE_STYLE  => '"><script>',
            Appearance::COOKIE_MODE => ['dark'],
        ]);

        self::assertSame('lane', $appearance->style);
        self::assertSame('auto', $appearance->mode);
    }

    public function testMissingCookiesResultInDefault(): void
    {
        $appearance = Appearance::fromCookies([]);

        self::assertSame('lane', $appearance->style);
        self::assertSame('auto', $appearance->mode);
    }

    public function testLocalReturnTargetsAreKept(): void
    {
        foreach (['/', '/rules', '/matchday/12', '/table?season=3', '/team/5#schnitt'] as $target) {
            self::assertSame($target, Appearance::returnTarget($target), $target);
        }
    }

    public function testForeignOrManipulatedReturnTargetsFallBackToHome(): void
    {
        foreach ([
            '//evil.example/',      // protocol-relative: foreign site
            "/\t/evil.example/",   // browsers drop the tab → //evil.example
            "/\n/evil.example/",
            "/\r/evil.example/",
            "/\x00/evil.example/",
            '/\\evil.example/',    // some browsers read \ as /
            '/a\\b',
            'https://evil.example/',
            'spielregeln',
            '',
            null,
            ['/rules'],
        ] as $target) {
            self::assertSame('/', Appearance::returnTarget($target), var_export($target, true));
        }
    }
}
