<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\I18n;

use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\LanguageNegotiator;
use Bowlingliga\I18n\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Texts, choice of the language and formatting of dates and numbers.
 */
final class TranslatorTest extends TestCase
{
    public function testTextsWithPlaceholdersInBothLanguages(): void
    {
        self::assertSame('Abmelden', (new Translator('de'))->t('layout.log_out'));
        self::assertSame('Log out', (new Translator('en'))->t('layout.log_out'));
        self::assertSame('2026/27 (current)', (new Translator('en'))->t('layout.season_current', ['season' => '2026/27']));
    }

    public function testUnknownLanguageAndUnknownKeyFallBack(): void
    {
        $translator = new Translator('fr');
        self::assertSame('de', $translator->language());
        self::assertSame('no.such.key', $translator->t('no.such.key'), 'A missing text shows its key.');
    }

    /**
     * @return iterable<string, array{0: mixed, 1: mixed, 2: string, 3: string}>
     */
    public static function requests(): iterable
    {
        yield 'own choice wins'         => ['en', 'de-DE,de;q=0.9', 'de', 'en'];
        yield 'invalid cookie ignored'  => ['xx', 'en-GB,en;q=0.9', 'de', 'en'];
        yield 'browser preference'      => [null, 'fr-FR,fr;q=0.9,en;q=0.8,de;q=0.7', 'de', 'en'];
        yield 'weights decide'          => [null, 'de;q=0.5,en;q=0.9', 'de', 'en'];
        yield 'weight 0 excluded'       => [null, 'en;q=0,fr', 'de', 'de'];
        yield 'nothing supported'       => [null, 'fr,it', 'en', 'en'];
        yield 'no header'               => [null, null, 'en', 'en'];
        yield 'invalid default'         => [null, null, 'xx', 'de'];
    }

    /** @dataProvider requests */
    public function testLanguageOfARequest(mixed $cookie, mixed $header, string $default, string $expected): void
    {
        self::assertSame($expected, LanguageNegotiator::choose($cookie, $header, $default));
    }

    public function testDatesAndNumbers(): void
    {
        $german = new Formatter(new Translator('de'));
        $english = new Formatter(new Translator('en'));

        self::assertSame('02.09.2026', $german->date('2026-09-02'));
        self::assertSame('Mi 02.09.2026', $german->date('2026-09-02', true));
        self::assertSame('Wed 2 Sep 2026', $english->date('2026-09-02', true));
        self::assertSame('kein Datum', $german->date('kein Datum'), 'Unreadable values stay as they are.');
        self::assertSame('181,56', $german->number(181.5625, 2));
        self::assertSame('181.56', $english->number(181.5625, 2));
    }
}
