<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Persistence\AdminAccountRepository;

/**
 * In-memory fake of the {@see AdminAccountRepository} for the auth test.
 *
 * In addition to create/find it reproduces the write paths needed for login
 * for the failed attempts counter and lock status, without a database. The
 * base constructor is deliberately not called.
 */
final class InMemoryAuthAccountRepository extends AdminAccountRepository
{
    /** @var array<int, array<string, mixed>> */
    private array $accounts = [];

    private int $nextId = 1;

    /** @phpstan-ignore-next-line: base constructor deliberately not called (no DB access in the test). */
    public function __construct()
    {
    }

    public function create(
        string $username,
        string $passwordHash,
        ?string $totpSecretEncrypted = null
    ): int {
        $id = $this->nextId++;
        $this->accounts[$id] = [
            'id'                          => $id,
            'username'                => $username,
            'password_hash'               => $passwordHash,
            'totp_secret_encrypted'  => $totpSecretEncrypted,
            'locked_until'                => null,
            'failed_attempts'                => 0,
            'session_version'          => 1,
        ];

        return $id;
    }

    public function updateFailedAttempts(int $id, int $failedAttempts): void
    {
        if (isset($this->accounts[$id])) {
            $this->accounts[$id]['failed_attempts'] = $failedAttempts;
        }
    }

    public function setTotpTimeStep(int $id, int $timeStep): void
    {
        if (isset($this->accounts[$id])) {
            $this->accounts[$id]['totp_last_time_step'] = $timeStep;
        }
    }

    public function setLockedUntil(int $id, ?\DateTimeImmutable $lockedUntil): void
    {
        if (isset($this->accounts[$id])) {
            $this->accounts[$id]['locked_until'] = $lockedUntil?->format('Y-m-d H:i:s');
        }
    }

    public function raiseSessionVersion(int $id): void
    {
        if (isset($this->accounts[$id])) {
            $this->accounts[$id]['session_version']++;
        }
    }

    public function find(int $id): ?array
    {
        return $this->accounts[$id] ?? null;
    }

    public function findForUpdate(int $id): ?array
    {
        return $this->find($id);
    }

    public function findByUsernameForUpdate(string $username): ?array
    {
        return $this->findByUsername($username);
    }

    public function delete(int $id): void
    {
        unset($this->accounts[$id]);
    }

    public function findByUsername(string $username): ?array
    {
        foreach ($this->accounts as $account) {
            if ($account['username'] === $username) {
                return $account;
            }
        }

        return null;
    }
}
