<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Port for the trusted devices of the accounts (R5.10).
 *
 * A device is identified by the SHA-256 hash of the token in its cookie; the
 * token itself is never stored. One token can stand for several accounts
 * (one record each), e.g. a browser shared by two people.
 *
 * Implemented by {@see \Bowlingliga\Persistence\TrustedDeviceRepository}; the
 * unit tests use an in-memory fake.
 */
interface TrustedDeviceStore
{
    /**
     * The device record of a token hash for an account, or null.
     *
     * @return array{id: int, session_version: int, failed_attempts: int, expires_at: string}|null
     */
    public function find(string $tokenHash, int $accountId): ?array;

    /**
     * Trusts the device for the account until $expiresAt: creates the record
     * or renews it (session version, expiry, failed attempts back to 0).
     */
    public function save(
        string $tokenHash,
        int $accountId,
        int $sessionVersion,
        \DateTimeImmutable $now,
        \DateTimeImmutable $expiresAt,
    ): void;

    /** Stores the failed attempts made from a trusted device. */
    public function updateFailedAttempts(int $id, int $failedAttempts): void;

    /** Forgets a trusted device. */
    public function delete(int $id): void;

    /**
     * Removes the account's devices that expired or belong to an earlier
     * session version, and of the rest all but the $keep most recently used.
     */
    public function prune(int $accountId, int $sessionVersion, \DateTimeImmutable $now, int $keep): void;
}
