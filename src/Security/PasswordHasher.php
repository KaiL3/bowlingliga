<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Password hashing and verification for admin accounts (R23.3).
 *
 * Passwords are only stored as salt+hash via the PHP standard function
 * {@see \password_hash()}, never in plaintext (R23.3). Verification happens
 * via {@see \password_verify()}, which checks against the stored hash in
 * constant time.
 *
 * The algorithm Argon2id (`PASSWORD_ARGON2ID`) is preferred if the PHP
 * installation provides it; otherwise bcrypt (`PASSWORD_BCRYPT`) is used.
 * Both methods salt automatically; the salt is part of the returned hash
 * string.
 *
 * Pure service without persistence and without hidden state.
 */
final class PasswordHasher
{
    /**
     * Generates a secure hash for the given plaintext password (R23.3).
     *
     * Uses Argon2id if available, otherwise bcrypt. The plaintext is neither
     * stored nor logged; only the salted hash string is returned, which can be
     * stored in the database as is.
     *
     * @throws PasswordHashException if hashing fails.
     */
    public function hash(string $plaintextPassword): string
    {
        $hash = password_hash($plaintextPassword, $this->preferredAlgorithm());

        // password_hash() returns an unusable value in error cases (e.g. an
        // unavailable algorithm); this is guarded against,
        // so that an invalid hash is never stored.
        if (! is_string($hash) || $hash === '') {
            throw new PasswordHashException(
                'Das Passwort konnte nicht gehasht werden.'
            );
        }

        return $hash;
    }

    /**
     * Checks whether a plaintext password matches the stored hash (R23.3).
     *
     * The check runs via {@see \password_verify()} in constant time, so that
     * no inferences from the execution time are possible.
     */
    public function check(string $plaintextPassword, string $storedHash): bool
    {
        return password_verify($plaintextPassword, $storedHash);
    }

    /**
     * Tells whether a stored hash was generated with an outdated method or
     * weaker parameters and should be rehashed on the next successful login
     * (defensive hardening, R23.3).
     */
    public function needsRehash(string $storedHash): bool
    {
        return password_needs_rehash($storedHash, $this->preferredAlgorithm());
    }

    /**
     * Returns the preferred password algorithm: Argon2id if the installation
     * provides it, otherwise bcrypt (R23.3).
     *
     * @return string|int PHP algorithm identifier for password_hash().
     */
    private function preferredAlgorithm(): string|int
    {
        if (defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
            return PASSWORD_ARGON2ID;
        }

        return PASSWORD_BCRYPT;
    }
}
