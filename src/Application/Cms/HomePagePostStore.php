<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Cms;

/**
 * Port for persisting the home page posts (R33.1–R33.4).
 *
 * Decouples {@see HomePagePostManagement} from the concrete persistence
 * technology: the use case only knows the domain write operations (create,
 * change, remove). The port is implemented by an adapter based on the
 * `home_page_post` table; in tests an in-memory fake is enough.
 */
interface HomePagePostStore
{
    /**
     * Creates a home page post (already validated title + sanitized text
     * content) and returns its id (R33.1, R33.4).
     */
    public function create(string $title, string $text): int;

    /**
     * Updates title and text content of an existing home page post (R33.2,
     * R33.4).
     */
    public function update(int $id, string $title, string $text): void;

    /**
     * Removes a home page post (R33.3, R33.4).
     */
    public function delete(int $id): void;
}
