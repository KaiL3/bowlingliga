<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Whitelist-based HTML sanitizing for CMS content (R33.11, R34.8).
 *
 * Home page posts (title, text content) and rules are entered by an
 * administrator and shown read-only in the public area. On saving,
 * potentially executable malicious code must be removed, so that the publicly
 * shown content contains no executable code (R33.11: home page posts; R34.8:
 * rules).
 *
 * Sanitizing happens server-side with HTML Purifier via a positive list
 * (whitelist) of allowed elements and attributes. Everything not explicitly
 * allowed (e.g. `<script>`, event handlers like `onerror`, `javascript:`
 * URLs, `<iframe>`) is reliably removed.
 *
 * For text output outside rich text contexts (e.g. the title of a home page
 * post) context-aware escaping is available as well (R33.11, R34.8: "on
 * output the content is additionally escaped context-aware").
 *
 * Pure service without persistence: equal inputs always give equal results.
 */
final class HtmlSanitizer
{
    /**
     * Allowed HTML elements including attributes (whitelist) for rich text
     * content.
     *
     * Deliberately kept narrow to simple text markup and links; it contains no
     * elements through which executable code could be injected (no `script`,
     * `iframe`, `object`, `embed`, `style`). The editor of the admin area
     * offers exactly these elements (data-allowed of its text area).
     */
    public const ALLOWED_ELEMENTS =
        'p,br,b,strong,i,em,u,ul,ol,li,h1,h2,h3,h4,blockquote,a[href|title]';

    private ?HTMLPurifier $purifier = null;

    /**
     * Checks whether the HTML Purifier library is available at runtime.
     *
     * Lets callers (and tests) detect whether the whitelist-based sanitizing
     * can actually be performed. If the library is not installed (e.g. missing
     * `vendor/`), the method returns false.
     */
    public function isAvailable(): bool
    {
        return class_exists(HTMLPurifier::class)
            && class_exists(HTMLPurifier_Config::class);
    }

    /**
     * Sanitizes rich text content (CMS text content) whitelist-based (R33.11,
     * R34.8).
     *
     * Allowed markup is kept; executable or dangerous code (scripts, event
     * handlers, `javascript:` URLs, embedded frames) is removed. The returned
     * output contains no executable code.
     *
     * @throws HtmlSanitizerUnavailableException if HTML Purifier is missing
     */
    public function sanitize(string $rawInput): string
    {
        if (! $this->isAvailable()) {
            throw new HtmlSanitizerUnavailableException(
                'HTML Purifier is not available; the HTML cleaning cannot '
                . 'be done.'
            );
        }

        return $this->purifier()->purify($rawInput);
    }

    /**
     * Sanitizes a plain text value (e.g. the title of a home page post) by
     * context-aware escaping (R33.11).
     *
     * For values output as plain text a whitelist is unnecessary: all HTML
     * special characters are escaped, so that no markup at all – and thus no
     * executable code – is interpreted. This method does not need HTML
     * Purifier and is always available.
     */
    public function escapeText(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );
    }

    /**
     * Creates the configured HTML Purifier instance (once).
     *
     * Configuration:
     * - positive list of allowed elements/attributes (see ALLOWED_ELEMENTS);
     * - UTF-8 as character set;
     * - no caching on disk (no write access needed, deterministic).
     */
    private function purifier(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Allowed', self::ALLOWED_ELEMENTS);
        // No disk cache: no side effects, deterministic.
        $config->set('Cache.DefinitionImpl', null);
        // Only allow safe URI schemes for links; this rules out
        // javascript:.
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

        return $this->purifier = new HTMLPurifier($config);
    }
}
