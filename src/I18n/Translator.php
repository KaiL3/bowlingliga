<?php

declare(strict_types=1);

namespace Bowlingliga\I18n;

use Bowlingliga\Domain\Message;
use Bowlingliga\Domain\Translatable;

/**
 * Texts of the user interface in one language.
 *
 * Every text has a key (`nav.matchdays`) and lives in the catalog
 * `lang/<language>.php`, a PHP file returning key => text. Placeholders are
 * written `{name}` and replaced by {@see t()}. A key missing in the chosen
 * language falls back to the default language, a key missing there as well
 * is returned as it is, so that a forgotten text is visible instead of
 * breaking the page. The catalog test makes sure this does not happen.
 *
 * Catalogs are loaded once per request.
 */
final class Translator
{
    /** @var array<string, array<string, string>> language => catalog */
    private static array $catalogs = [];

    private readonly string $language;

    public function __construct(string $language = Languages::DEFAULT)
    {
        $this->language = Languages::isSupported($language) ? $language : Languages::DEFAULT;
    }

    public function language(): string
    {
        return $this->language;
    }

    /**
     * The text of a key with its placeholders replaced.
     *
     * @param array<string, string|int|float> $params placeholder name => value (not escaped)
     */
    public function t(string $key, array $params = []): string
    {
        $text = self::catalog($this->language)[$key]
            ?? self::catalog(Languages::DEFAULT)[$key]
            ?? $key;
        if ($params === []) {
            return $text;
        }

        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }

        return strtr($text, $replace);
    }

    /**
     * A {@see Message} in this language: nested messages are translated, the
     * parameter `date` (ISO) is formatted.
     */
    public function message(Message $message): string
    {
        $params = [];
        foreach ($message->params as $name => $value) {
            $params[$name] = match (true) {
                $value instanceof Message => $this->message($value),
                $name === 'date'          => (new Formatter($this))->date((string) $value),
                default                   => $value,
            };
        }

        return $this->t($message->key, $params);
    }

    /**
     * The text of an error for the user: translated for a
     * {@see Translatable}, otherwise the exception message as it is.
     */
    public function error(\Throwable $error): string
    {
        return $error instanceof Translatable ? $this->message($error->message()) : $error->getMessage();
    }

    public function has(string $key): bool
    {
        return isset(self::catalog($this->language)[$key]);
    }

    /**
     * The catalog of a language.
     *
     * @return array<string, string>
     */
    public static function catalog(string $language): array
    {
        if (! isset(self::$catalogs[$language])) {
            $file = dirname(__DIR__, 2) . '/lang/' . $language . '.php';
            $catalog = Languages::isSupported($language) && is_file($file) ? require $file : [];
            self::$catalogs[$language] = is_array($catalog) ? $catalog : [];
        }

        return self::$catalogs[$language];
    }
}
