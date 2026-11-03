<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Result of the one-time TOTP setup of an admin account (R23.6).
 *
 * When setting up two-factor authentication a secret is generated and shown
 * to the administrator once in plaintext (or as provisioning URI for the
 * authenticator app / QR code) (R23.6). Only the encrypted form is stored
 * (R23.9).
 *
 * This value object therefore carries exactly these volatile values, shown
 * once, from the auth module to the presentation layer. It is not meant to be
 * persisted or logged.
 */
final class TotpSetup
{
    /**
     * @param string $plaintextSecret The TOTP secret shown once for transfer
     *                                into the authenticator app (R23.6).
     * @param string $provisioningUri otpauth:// URI (for the QR code) for
     *                                convenient transfer into the authenticator
     *                                app (R23.6).
     * @param string $secretEncrypted The encrypted form of the secret to store
     *                                (R23.9).
     */
    public function __construct(
        public readonly string $plaintextSecret,
        public readonly string $provisioningUri,
        public readonly string $secretEncrypted
    ) {
    }
}
