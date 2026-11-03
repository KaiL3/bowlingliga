<?php

declare(strict_types=1);

namespace Bowlingliga\I18n;

/**
 * The languages of the user interface: one per catalog `lang/<code>.php`.
 * A catalog describes itself – the file name is the two-letter code, the
 * key `language.name` the name of the language in itself ("Deutsch",
 * "English"). Adding a language therefore needs no code; see
 * docs/contribute.md. German is the default and the fallback for keys a
 * catalog lacks.
 *
 * Only two-letter file names count, so a code from a cookie or a form can
 * never point anywhere else.
 */
final class Languages
{
    public const DEFAULT = 'de';

    /** @var array<string, string>|null code => name, read once per request */
    private static ?array $names = null;

    /**
     * Code => name of the language in itself, the default first, then by
     * code.
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        if (self::$names === null) {
            $names = [];
            foreach (glob(dirname(__DIR__, 2) . '/lang/*.php') ?: [] as $file) {
                $code = basename($file, '.php');
                if (preg_match('/^[a-z]{2}$/', $code) !== 1) {
                    continue;
                }
                $catalog = require $file;
                $names[$code] = is_array($catalog) && is_string($catalog['language.name'] ?? null) ? $catalog['language.name'] : $code;
            }
            uksort($names, static fn (string $a, string $b): int => [$a !== self::DEFAULT, $a] <=> [$b !== self::DEFAULT, $b]);
            self::$names = $names;
        }

        return self::$names;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::names());
    }

    public static function isSupported(mixed $code): bool
    {
        return is_string($code) && isset(self::names()[$code]);
    }
}
