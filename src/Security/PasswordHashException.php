<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use RuntimeException;

/**
 * Signals that hashing a password failed (R23.3).
 *
 * Thrown if {@see \password_hash()} returns no usable hash (e.g. because the
 * chosen algorithm is not available at runtime). Without a valid hash no
 * admin account may be stored, so that a password is never stored in
 * plaintext or with an invalid hash.
 */
final class PasswordHashException extends RuntimeException
{
}
