<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

/**
 * Result of a writing action in the admin area.
 *
 * Carries either a success message or an error message phrased for the user,
 * so that the calling front controller can show the matching feedback after
 * processing. Optionally a successful action names a follow-up page (local
 * path) to which the front controller redirects – for instance the preview of
 * the handicap chain after a correction. Immutable value object without side
 * effects.
 */
final class ActionResult
{
    private function __construct(
        public readonly bool $successful,
        public readonly string $message,
        public readonly ?string $redirect = null,
    ) {
    }

    /**
     * Successful action with a confirmation message and an optional follow-up
     * page.
     */
    public static function success(string $message, ?string $redirect = null): self
    {
        return new self(true, $message, $redirect);
    }

    /**
     * Failed action with an error message phrased for the user; the
     * underlying data stays unchanged.
     */
    public static function error(string $message): self
    {
        return new self(false, $message);
    }
}
