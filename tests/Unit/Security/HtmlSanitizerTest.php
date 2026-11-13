<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit/snapshot test for the CMS HTML sanitizing with XSS payloads (task 19.1).
 *
 * Validates: R33.11, R34.8
 *
 * R33.11: when saving a home page post, potentially executable
 * malicious code is removed from title and text content, so that the content
 * shown in the public area contains no executable code.
 *
 * R34.8: when saving the text content of the rules, potentially
 * executable malicious code is removed, so that the publicly shown rules
 * contain no executable code.
 *
 * Using curated XSS payloads the tests check that the sanitized output no
 * longer contains executable code (script elements, event handlers,
 * javascript: URLs, embedded frames/objects).
 *
 * If HTML Purifier is not installed at runtime (missing vendor/), the
 * whitelist-based tests are skipped instead of aborting with a fatal error;
 * the escaping behaviour is still checked.
 */
final class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizer();
    }

    /**
     * Kuratierte XSS-Payloads (R33.11, R34.8).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function xssPayloads(): iterable
    {
        yield 'script-Tag'            => ['<script>alert(1)</script>'];
        yield 'script-mit-Text'       => ['Hallo <script>alert(document.cookie)</script> Welt'];
        yield 'img-onerror'           => ['<img src="x" onerror="alert(1)">'];
        yield 'javascript-URL'        => ['<a href="javascript:alert(1)">Klick</a>'];
        yield 'iframe'                => ['<iframe src="https://boese.example/evil"></iframe>'];
        yield 'svg-onload'            => ['<svg onload="alert(1)"></svg>'];
        yield 'onclick-Handler'       => ['<p onclick="stealCookies()">Text</p>'];
        yield 'object-Tag'            => ['<object data="evil.swf"></object>'];
        yield 'embed-Tag'             => ['<embed src="evil.swf">'];
        yield 'style-Ausdruck'        => ['<div style="background:url(javascript:alert(1))">x</div>'];
        yield 'body-onload'           => ['<body onload="alert(1)">'];
        yield 'kodierte-javascript-URL' => ['<a href="java&#115;cript:alert(1)">x</a>'];
    }

    /**
     * R33.11, R34.8: the sanitized output of rich text content
     * contains no executable code.
     */
    #[DataProvider('xssPayloads')]
    public function testSanitizedOutputContainsNoExecutableCode(string $payload): void
    {
        $this->skipWithoutHtmlPurifier();

        $output = $this->sanitizer->sanitize($payload);

        $this->assertNoExecutableCode($output);
    }

    /**
     * R33.11, R34.8: harmless, allowed markup keeps its content when
     * sanitizing (the whitelist only removes dangerous parts).
     */
    public function testHarmlessMarkupIsKept(): void
    {
        $this->skipWithoutHtmlPurifier();

        $output = $this->sanitizer->sanitize('<p>Ein <strong>fetter</strong> Absatz.</p>');

        self::assertStringContainsString('fetter', $output);
        self::assertStringContainsString('<strong>', $output);
        $this->assertNoExecutableCode($output);
    }

    /**
     * R33.11: plain text values (e.g. the title of a home page post)
     * are escaped context-aware on output, so that no markup is interpreted.
     * Escaping does not need HTML Purifier.
     */
    public function testTextEscapingNeutralisesMarkup(): void
    {
        $output = $this->sanitizer->escapeText('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $output);
        self::assertStringContainsString('&lt;script&gt;', $output);
        $this->assertNoExecutableCode($output);
    }

    /**
     * Skips whitelist-based tests if HTML Purifier is not installed – so that
     * the test suite without vendor/ does not abort with a fatal error but
     * reports the absence cleanly.
     */
    private function skipWithoutHtmlPurifier(): void
    {
        if (! $this->sanitizer->isAvailable()) {
            self::markTestSkipped(
                'HTML Purifier is not installed; whitelist sanitizing cannot '
                . 'be tested (Composer dependencies missing).'
            );
        }
    }

    /**
     * Central check: the output contains no executable code (requirements
     * 33.11, 34.8).
     */
    private function assertNoExecutableCode(string $output): void
    {
        $normalised = strtolower($output);

        self::assertStringNotContainsString('<script', $normalised, 'No script element allowed.');
        self::assertStringNotContainsString('</script', $normalised, 'No script element allowed.');
        self::assertStringNotContainsString('<iframe', $normalised, 'No embedded frame allowed.');
        self::assertStringNotContainsString('<object', $normalised, 'No object element allowed.');
        self::assertStringNotContainsString('<embed', $normalised, 'No embed element allowed.');
        self::assertStringNotContainsString('javascript:', $normalised, 'No javascript: URL allowed.');

        // No inline event handler (e.g. onerror=, onclick=, onload=).
        self::assertDoesNotMatchRegularExpression(
            '/\bon[a-z]+\s*=/i',
            $output,
            'No inline event handlers allowed.'
        );
    }

    /**
     * R42.4, R42.5: images stay with their placement, but only from the
     * website's own image store.
     */
    public function testImagesOnlyFromTheOwnStoreWithTheirPlacement(): void
    {
        $hash = str_repeat('a', 64);
        $kept = $this->sanitizer->sanitize(
            '<figure class="image"><img src="/media/' . $hash . '.jpg" alt="Team" width="300" height="200" class="image--left" style="float:right" onerror="x()">'
            . '<figcaption>Das <b>Team</b></figcaption></figure>'
        );

        self::assertSame(
            '<figure class="image"><img src="/media/' . $hash . '.jpg" alt="Team" width="300" height="200" class="image--left" /><figcaption>Das <b>Team</b></figcaption></figure>',
            $kept,
        );

        foreach (['https://example.org/' . $hash . '.jpg', 'data:image/png;base64,AAAA', '/admin/logout', '/media/' . $hash . '.gif', '/media/../config.jpg', '//example.org/media/' . $hash . '.jpg'] as $source) {
            self::assertStringNotContainsString('<img', $this->sanitizer->sanitize('<p><img src="' . $source . '" alt="x"></p>'), $source);
        }
        self::assertSame('<p>Text</p>', $this->sanitizer->sanitize('<p class="image--left evil">Text</p>'), 'Classes only on images and figures.');
        self::assertSame('<img src="/media/' . $hash . '.png" alt="x" />', $this->sanitizer->sanitize('<img src="/media/' . $hash . '.png" alt="x" class="evil">'), 'Only the placement classes.');
    }

    /**
     * R42.6: images without a description are counted before saving.
     */
    public function testImagesWithoutDescriptionAreCounted(): void
    {
        self::assertSame(0, $this->sanitizer->imagesWithoutAlt('<p>Ohne Bild</p>'));
        self::assertSame(0, $this->sanitizer->imagesWithoutAlt('<img src="a" alt="Ein Bild">'));
        self::assertSame(2, $this->sanitizer->imagesWithoutAlt('<p><img src="a"><IMG SRC="b" ALT="  "><img src="c" alt="Ok"></p>'));
    }
}
