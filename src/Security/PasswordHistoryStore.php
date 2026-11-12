<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Port: hashes of the earlier passwords of an account (R23.18). Only hashes
 * are kept, never a plaintext.
 */
interface PasswordHistoryStore
{
    /**
     * The latest hashes of the account, newest first.
     *
     * @return list<string>
     */
    public function recent(int $accountId, int $limit): array;

    /**
     * Adds the hash of a new password and keeps only the newest $keep entries
     * of the account.
     */
    public function add(int $accountId, string $passwordHash, \DateTimeImmutable $at, int $keep): void;
}
