<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\I18n;

use Bowlingliga\I18n\Languages;
use Bowlingliga\I18n\Translator;
use PHPUnit\Framework\TestCase;

/**
 * The text catalogs in `lang/` belong together: every language has a
 * catalog with exactly the keys of the default language, the same
 * placeholders per key and no empty texts. A text missing in one language
 * would otherwise appear in German or as its key.
 */
final class CatalogTest extends TestCase
{
    public function testEverySupportedLanguageHasACatalogFile(): void
    {
        self::assertContains(Languages::DEFAULT, Languages::codes());
        foreach (Languages::codes() as $language) {
            self::assertFileExists(dirname(__DIR__, 3) . '/lang/' . $language . '.php');
            self::assertNotSame([], Translator::catalog($language), $language);
            self::assertNotSame($language, Languages::names()[$language], "$language: language.name names the language");
        }
    }

    public function testAllCatalogsHaveTheSameKeys(): void
    {
        $default = array_keys(Translator::catalog(Languages::DEFAULT));
        sort($default);
        foreach (Languages::codes() as $language) {
            $keys = array_keys(Translator::catalog($language));
            sort($keys);
            self::assertSame([], array_values(array_diff($default, $keys)), "Missing in $language");
            self::assertSame([], array_values(array_diff($keys, $default)), "Only in $language");
        }
    }

    public function testPlaceholdersMatchAndNoTextIsEmpty(): void
    {
        $placeholders = static function (string $text): array {
            preg_match_all('/\{([a-z_]+)\}/', $text, $matches);
            $names = array_unique($matches[1]);
            sort($names);

            return $names;
        };

        foreach (Translator::catalog(Languages::DEFAULT) as $key => $text) {
            foreach (Languages::codes() as $language) {
                $translated = Translator::catalog($language)[$key] ?? '';
                self::assertArrayHasKey($key, Translator::catalog($language), "$language: $key is missing");
                // An alias list of the import may be empty: a language need not have synonyms.
                if (! str_ends_with($key, '.aliases')) {
                    self::assertNotSame('', trim($translated), "$language: $key is empty");
                }
                if ($key === 'date.pattern') {
                    // The Formatter offers every part of a date; each language picks its own.
                    self::assertSame([], array_diff($placeholders($translated), ['d', 'dd', 'mm', 'month', 'yyyy']), "$language: $key");
                    continue;
                }
                self::assertSame($placeholders($text), $placeholders($translated), "$language: placeholders of $key");
            }
        }
    }
}
