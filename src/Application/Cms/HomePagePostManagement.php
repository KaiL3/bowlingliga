<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Cms;

use Bowlingliga\Security\HtmlSanitizer;
use Bowlingliga\Security\WriteAccess;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\AccessDeniedException;

/**
 * Writing use case: managing the home page posts of the minimalist CMS
 * (R33.1–R33.6, R33.10, R33.11).
 *
 * Bundles creating, changing and removing home page posts. The following
 * applies:
 * - The title is checked for 1–200 characters (R33.5), the text content for
 *   1–5000 characters (R33.6); for invalid values nothing is saved and a
 *   field-related exception is thrown.
 * - Before persisting, potentially executable malicious code is removed
 *   (R33.11): the title is escaped as plain text, the text content is
 *   sanitized whitelist-based via the {@see HtmlSanitizer}. This way the
 *   content shown in the public area contains no executable code.
 * - Every writing call is protected by {@see WriteAccess} (R5.1, R33.10):
 *   without a valid session the action is skipped and the content stays
 *   unchanged.
 *
 * The public display is read-only (R33.7) and happens via a separate view;
 * this use case is write-only.
 */
final class HomePagePostManagement
{
    private const TITLE_MIN = 1;
    private const TITLE_MAX = 200;
    private const TEXT_MIN = 1;
    private const TEXT_MAX = 5000;

    public function __construct(
        private readonly HomePagePostStore $store,
        private readonly Validator $validator,
        private readonly HtmlSanitizer $sanitizer,
        private readonly WriteAccess $writeAccess,
        ?TextImages $images = null,
    ) {
        $this->images = $images ?? new TextImages($sanitizer);
    }

    /** Checks description and origin of the images of a text (R42.4, R42.6). */
    private readonly TextImages $images;

    /**
     * Creates a home page post and returns its id (R33.1, R33.4).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\ValidationException for an invalid title
     *         (R33.5) or text content (R33.6).
     */
    public function add(string $title, string $text): int
    {
        $this->ensureWriteAccess();
        [$title, $text] = $this->checkAndSanitize($title, $text);

        return $this->store->create($title, $text);
    }

    /**
     * Changes title and text content of an existing home page post (R33.2,
     * R33.4).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\ValidationException for an invalid title
     *         (R33.5) or text content (R33.6).
     */
    public function change(int $id, string $title, string $text): void
    {
        $this->ensureWriteAccess();
        [$title, $text] = $this->checkAndSanitize($title, $text);

        $this->store->update($id, $title, $text);
    }

    /**
     * Removes a home page post (R33.3, R33.4).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     */
    public function remove(int $id): void
    {
        $this->ensureWriteAccess();

        $this->store->delete($id);
    }

    /**
     * Checks title (R33.5) and text content (R33.6) and sanitizes both before
     * persisting (R33.11): escape the title as plain text, sanitize the text
     * content whitelist-based.
     *
     * The length check runs on the raw value before sanitizing, so that the
     * domain limit applies to the input and is not shifted by the sanitizing.
     *
     * @return array{0: string, 1: string} sanitized title and text content
     */
    private function checkAndSanitize(string $title, string $text): array
    {
        $title = $this->validator->checkCmsTextWithMin($title, self::TITLE_MIN, self::TITLE_MAX, 'field.title');
        $text  = $this->validator->checkCmsTextWithMin($text, self::TEXT_MIN, self::TEXT_MAX, 'field.text_content');
        // R42.4, R42.6: images with a description, only from the own store.
        $this->images->check($text, 'field.text_content');

        // R33.11: escape the title as plain text, sanitize the text content
        // whitelist-based, so that no executable parts are stored.
        return [
            $this->sanitizer->escapeText($title),
            $this->sanitizer->sanitize($text),
        ];
    }

    private function ensureWriteAccess(): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
    }
}
