<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Cms;

use Bowlingliga\Security\HtmlSanitizer;
use Bowlingliga\Security\WriteAccess;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\AccessDeniedException;

/**
 * Writing use case: maintaining the rules text (R34.3–R34.5, R34.7, R34.8).
 *
 * The rules content is a single text of 0 to 20000 characters (R34.3); empty
 * content is allowed (lower bound 0). Before persisting, potentially
 * executable malicious code is removed whitelist-based via the
 * {@see HtmlSanitizer} (R34.8), so that the publicly shown rules contain no
 * executable code. Every writing call is protected by {@see WriteAccess}
 * (R5.1, R34.7).
 *
 * The public display is read-only (R34.2) and happens via a separate view
 * with a note for missing content (R34.6).
 */
final class RulesManagement
{
    private const TEXT_MAX = 20000;

    public function __construct(
        private readonly RulesStore $store,
        private readonly Validator $validator,
        private readonly HtmlSanitizer $sanitizer,
        private readonly WriteAccess $writeAccess,
    ) {
    }

    /**
     * Saves the text content of the rules (R34.3, R34.4).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\ValidationException if the text content is
     *         longer than 20000 characters (R34.5).
     */
    public function save(string $text): void
    {
        $this->ensureWriteAccess();

        // R34.5: maximum length 20000, lower bound 0 (empty content allowed).
        $text = $this->validator->checkCmsText($text, self::TEXT_MAX, 'field.text_content');
        // R42.6: every image describes itself for screen readers.
        if ($this->sanitizer->imagesWithoutAlt($text) > 0) {
            throw ValidationException::forField('field.text_content', 'validation.image_alt');
        }

        // R34.8: whitelist-based sanitizing; empty text stays empty.
        $sanitized = $text === '' ? '' : $this->sanitizer->sanitize($text);

        $this->store->save($sanitized);
    }

    private function ensureWriteAccess(): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
    }
}
