<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Translatable;

/**
 * Signals a failed CSRF token check for a writing form (R22.5).
 *
 * Following the synchronizer token pattern, every writing form must send a
 * valid, session-bound token. If the token is missing, does not match the
 * value stored in the session or no token has been generated yet, processing
 * is aborted with this exception so that the operation is not executed.
 */
final class CsrfTokenException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    /**
     * Creates an exception for a missing or invalid CSRF token.
     */
    public static function invalid(): self
    {
        return new self('error.csrf');
    }
}
