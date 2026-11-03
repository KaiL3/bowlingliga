<?php

declare(strict_types=1);

namespace Bowlingliga\I18n;

/**
 * Chooses the language of a request: the visitor's own choice (cookie)
 * first; without one the browser's preference from the `Accept-Language`
 * header, the best supported language by its weight; otherwise the default
 * language of the installation.
 */
final class LanguageNegotiator
{
    public const COOKIE = 'bl_lang';

    public static function choose(mixed $cookie, mixed $acceptLanguage, string $default = Languages::DEFAULT): string
    {
        if (Languages::isSupported($cookie)) {
            return $cookie;
        }

        $fromHeader = is_string($acceptLanguage) ? self::fromHeader($acceptLanguage) : null;
        if ($fromHeader !== null) {
            return $fromHeader;
        }

        return Languages::isSupported($default) ? $default : Languages::DEFAULT;
    }

    /**
     * Best supported language of an `Accept-Language` header, e.g.
     * "en-GB,en;q=0.9,de;q=0.8" → "en". Only the primary subtag counts;
     * entries with weight 0 are excluded, equal weights keep their order.
     */
    public static function fromHeader(string $header): ?string
    {
        $best = null;
        $bestWeight = 0.0;
        foreach (explode(',', $header) as $part) {
            $pieces = explode(';', trim($part));
            $code = strtolower(trim(explode('-', $pieces[0])[0]));
            $weight = 1.0;
            foreach (array_slice($pieces, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $parameter, $match) === 1) {
                    $weight = (float) $match[1];
                }
            }
            if ($weight > $bestWeight && Languages::isSupported($code)) {
                $best = $code;
                $bestWeight = $weight;
            }
        }

        return $best;
    }
}
