<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Port for the session handling of the auth module (R5.2, R5.5, R5.7).
 *
 * This narrow outlet decouples the {@see AuthModule} flow from the concrete
 * session technology (PHP `$_SESSION`). The domain only knows the
 * capabilities needed for an authenticated session: store/read/remove values,
 * end the session completely and regenerate the session id after login
 * (protection against session fixation).
 *
 * The port is implemented by {@see PhpSessionStore}; in tests a slim
 * in-memory fake is used, so that the login flow can be checked without a
 * real PHP session.
 */
interface SessionStore
{
    /**
     * Stores a value under a key in the session.
     */
    public function set(string $key, int|string $value): void;

    /**
     * Returns the value stored under a key, or null if there is none.
     */
    public function retrieve(string $key): int|string|null;

    /**
     * Removes a single value from the session.
     */
    public function remove(string $key): void;

    /**
     * Regenerates the session id while keeping the existing session data
     * (protection against session fixation after a successful login, R5.2).
     */
    public function regenerateId(): void;

    /**
     * Ends the session completely and discards all session data (R5.5).
     */
    public function end(): void;
}
