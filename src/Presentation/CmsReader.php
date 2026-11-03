<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

/**
 * Read port for the CMS editing in the admin area (task 35.6).
 *
 * Provides the {@see CmsEditController} with the reads needed for the editing
 * interfaces (home page posts including id, rules), decoupled from the
 * concrete raw data repositories. In production the
 * {@see \Bowlingliga\Persistence\Cms\RepositoryCmsReader} implements this
 * port; in tests an in-memory fake is enough.
 *
 * All return values are the persisted (already sanitized) values unchanged
 * (R4.5).
 */
interface CmsReader
{
    /**
     * Returns all home page posts, newest first.
     *
     * @return list<array{id: int, title: string, text: string}>
     */
    public function allHomePagePosts(): array;

    /**
     * Returns the rules text, or null if none has been entered yet.
     */
    public function rulesText(): ?string;
}
