<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Symmetric encryption of an admin account's TOTP secret (R23.9).
 *
 * The TOTP secret may only appear in plaintext once during setup (for
 * transfer into the authenticator app, R23.6); afterwards it is only stored
 * encrypted (R23.9). Encryption happens via libsodium
 * ({@see \sodium_crypto_secretbox()}) with a server-side key provided from
 * outside the database (injected via the constructor, not hard-coded).
 *
 * The ciphertext consists of a nonce generated randomly per encryption and
 * the actual secretbox result (the nonce is prepended to the ciphertext), so
 * that decryption works without storing the nonce separately. secretbox
 * provides authenticated encryption; tampering with the ciphertext is
 * detected on decryption.
 *
 * Pure service without persistence. Sensitive values are removed from memory
 * after use – as far as libsodium allows – and are not logged.
 */
final class TotpSecretEncryption
{
    /**
     * @param string $key Server-side key outside the DB (R23.9). Must be
     *                    exactly SODIUM_CRYPTO_SECRETBOX_KEYBYTES bytes long.
     *
     * @throws TotpSecretEncryptionException if libsodium is not available or
     *         the key has an invalid length.
     */
    public function __construct(private readonly string $key)
    {
        if (! self::isAvailable()) {
            throw new TotpSecretEncryptionException(
                'The libsodium extension is not available; the TOTP secret '
                . 'cannot be encrypted.'
            );
        }

        if (strlen($this->key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new TotpSecretEncryptionException(sprintf(
                'The encryption key must be exactly %d bytes long.',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            ));
        }
    }

    /**
     * Checks whether the libsodium extension needed for encryption is available
     * at runtime (R23.9).
     */
    public static function isAvailable(): bool
    {
        return function_exists('sodium_crypto_secretbox')
            && defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES')
            && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES');
    }

    /**
     * Generates a random key suitable for secretbox.
     *
     * Intended as a helper to store the server-side key outside the database
     * (e.g. in a protected configuration file/environment) (R23.9). The key
     * must not be hard-coded.
     */
    public static function generateKey(): string
    {
        return sodium_crypto_secretbox_keygen();
    }

    /**
     * Encrypts a TOTP secret; the result (nonce + ciphertext) can be stored as
     * VARBINARY (R23.9).
     *
     * @throws TotpSecretEncryptionException on an encryption error.
     */
    public function encrypt(string $plaintextSecret): string
    {
        try {
            $nonce   = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plaintextSecret, $nonce, $this->key);

            // Prepend the nonce to the ciphertext, so that no separate storage
            // is needed. The nonce need not be secret, but must be unique per message
            // – random_bytes() ensures that.
            return $nonce . $ciphertext;
        } catch (\SodiumException $exception) {
            throw new TotpSecretEncryptionException(
                'The TOTP secret could not be encrypted.',
                0,
                $exception
            );
        } finally {
            // Remove the plaintext secret from memory (no logging).
            sodium_memzero($plaintextSecret);
        }
    }

    /**
     * Decrypts a ciphertext previously created with {@see encrypt()} and
     * returns the TOTP secret in plaintext (only for the code check on login,
     * R23.7).
     *
     * Tampering with the ciphertext or a wrong key leads to an exception
     * (authenticated encryption).
     *
     * @throws TotpSecretEncryptionException if decryption fails
     *         (invalid/tampered ciphertext, wrong key).
     */
    public function decrypt(string $ciphertextWithNonce): string
    {
        $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

        if (strlen($ciphertextWithNonce) <= $nonceLength) {
            throw new TotpSecretEncryptionException(
                'The encrypted TOTP secret is incomplete or invalid.'
            );
        }

        $nonce    = substr($ciphertextWithNonce, 0, $nonceLength);
        $ciphertext = substr($ciphertextWithNonce, $nonceLength);

        try {
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        } catch (\SodiumException $exception) {
            throw new TotpSecretEncryptionException(
                'The TOTP secret could not be decrypted.',
                0,
                $exception
            );
        }

        if ($plaintext === false) {
            throw new TotpSecretEncryptionException(
                'The TOTP secret could not be decrypted '
                . '(invalid or manipulated ciphertext).'
            );
        }

        return $plaintext;
    }
}
