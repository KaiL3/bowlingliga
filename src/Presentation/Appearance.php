<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

/**
 * Appearance of the website chosen by the visitor: style and colour mode.
 *
 * - Style `lane` (clear, signal orange) or `bento` (tiles, blue),
 * - mode `auto` (follows the device setting), `light` or `dark`.
 *
 * The choice lives in two cookies that contain only these display values (no
 * personal data). The {@see Layout} sets them as `data-style`/`data-mode` on
 * the `<html>` element, so that the page appears in the chosen design without
 * flashing. Unknown values fall back to the default.
 */
final class Appearance
{
    public const COOKIE_STYLE  = 'bl_style';
    public const COOKIE_MODE = 'bl_mode';

    /** Validity of the cookies: one year. */
    public const COOKIE_LIFETIME_SECONDS = 365 * 24 * 60 * 60;

    /** @var array<string, string> style → its name (a proper name, not translated) */
    public const STYLES = ['lane' => 'Lane', 'bento' => 'Bento'];

    /** @var array<string, string> mode → catalog key of its label */
    public const MODES = ['auto' => 'appearance.mode.auto', 'light' => 'appearance.mode.light', 'dark' => 'appearance.mode.dark'];

    private function __construct(
        public readonly string $style,
        public readonly string $mode,
    ) {
    }

    public static function standard(): self
    {
        return new self('lane', 'auto');
    }

    /**
     * Builds the appearance from arbitrary input (cookies or form); invalid or
     * missing values give the default.
     */
    public static function from(mixed $style, mixed $mode): self
    {
        $standard = self::standard();

        return new self(
            is_string($style) && isset(self::STYLES[$style]) ? $style : $standard->style,
            is_string($mode) && isset(self::MODES[$mode]) ? $mode : $standard->mode,
        );
    }

    /**
     * @param array<string, mixed> $cookies usually `$_COOKIE`
     */
    /**
     * Local return target after choosing the appearance, otherwise "/" (no
     * open redirect). Only a path on the own site counts: it starts with a
     * single "/" and contains no backslash and no control characters –
     * browsers drop tabs and line breaks from addresses, so "/\t/evil.example"
     * would become "//evil.example", a foreign site.
     */
    public static function returnTarget(mixed $value): string
    {
        return is_string($value) && preg_match('#^/(?!/)[^\x00-\x1F\x7F\\\\]*$#', $value) === 1 ? $value : '/';
    }

    public static function fromCookies(array $cookies): self
    {
        return self::from($cookies[self::COOKIE_STYLE] ?? null, $cookies[self::COOKIE_MODE] ?? null);
    }
}
