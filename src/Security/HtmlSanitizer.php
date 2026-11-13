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
     * Deliberately kept narrow to simple text markup, links and images; it
     * contains no elements through which executable code could be injected
     * (no `script`, `iframe`, `object`, `embed`, `style`, no `style`
     * attribute). Images may only come from the website's own image store
     * ({@see LocalImageUriFilter}) and are placed by the classes of
     * {@see IMAGE_CLASSES} (R42.4). The editor of the admin area offers
     * exactly these elements (data-allowed of its text area).
     */
    public const ALLOWED_ELEMENTS =
        'p,br,b,strong,i,em,u,ul,ol,li,h1,h2,h3,h4,blockquote,a[href|title],'
        . 'img[src|alt|width|height|class],figure[class],figcaption';

    /**
     * Classes an image (or its figure with caption) may carry: the placement
     * chosen in the editor; `image` marks a figure with caption (R42.5).
     */
    public const IMAGE_CLASSES = ['image', 'image--left', 'image--right', 'image--center', 'image--full'];

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
     * Number of images in the raw input without a (non-empty) alternative
     * text; such a text is not saved (R42.6). HTML Purifier would invent one
     * from the file name otherwise.
     */
    public function imagesWithoutAlt(string $rawInput): int
    {
        return count(array_filter($this->images($rawInput), static fn (array $image): bool => trim($image['alt']) === ''));
    }

    /**
     * Source and description of every image in the raw input, in order
     * (R42.4, R42.6).
     *
     * @return list<array{src: string, alt: string}>
     */
    public function images(string $rawInput): array
    {
        if (stripos($rawInput, '<img') === false) {
            return [];
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $rawInput . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $images = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            $images[] = ['src' => $image->getAttribute('src'), 'alt' => $image->getAttribute('alt')];
        }

        return $images;
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
        $config->set('Attr.AllowedClasses', self::IMAGE_CLASSES);

        // HTML Purifier knows HTML 4 only: figure and figcaption are added.
        $config->set('HTML.DefinitionID', 'bowlingliga-content');
        $config->set('HTML.DefinitionRev', 1);
        $config->set('URI.DefinitionID', 'bowlingliga-content');
        $config->set('URI.DefinitionRev', 1);
        $definition = $config->maybeGetRawHTMLDefinition();
        if ($definition !== null) {
            $definition->addElement('figure', 'Block', 'Optional: (figcaption, Flow) | (Flow, figcaption) | Flow', 'Common');
            $definition->addElement('figcaption', 'Inline', 'Flow', 'Common');
        }
        $uriDefinition = $config->maybeGetRawURIDefinition();
        if ($uriDefinition !== null) {
            $uriDefinition->addFilter(new LocalImageUriFilter(), $config);
        }

        return $this->purifier = new HTMLPurifier($config);
    }
}
