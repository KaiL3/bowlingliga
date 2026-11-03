<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\I18n;

use Bowlingliga\I18n\Languages;
use PHPUnit\Framework\TestCase;

/**
 * The languages come from the catalogs in `lang/`; only their two-letter
 * codes are accepted.
 */
final class LanguagesTest extends TestCase
{
    public function testLanguagesAreReadFromTheCatalogsDefaultFirst(): void
    {
        $names = Languages::names();

        self::assertSame(Languages::DEFAULT, array_key_first($names));
        self::assertSame('Deutsch', $names['de']);
        self::assertSame('English', $names['en']);
        self::assertSame(array_keys($names), Languages::codes());
    }

    public function testOnlyCodesOfExistingCatalogsAreAccepted(): void
    {
        self::assertTrue(Languages::isSupported('en'));
        foreach (['xx', 'EN', 'en-GB', '../de', 'de/../en', '', null, 1] as $code) {
            self::assertFalse(Languages::isSupported($code), var_export($code, true));
        }
    }
}
