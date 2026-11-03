<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use RuntimeException;

/**
 * Signals an error while encrypting or decrypting the TOTP secret (R23.9).
 *
 * Thrown among others if the libsodium extension is not available, the
 * server-side key has an invalid length or a ciphertext cannot be decrypted
 * (invalid, tampered or with a wrong key). Without an encryption that can be
 * performed no TOTP secret may be stored, so that it never ends up in the
 * database in plaintext.
 */
final class TotpSecretEncryptionException extends RuntimeException
{
}
