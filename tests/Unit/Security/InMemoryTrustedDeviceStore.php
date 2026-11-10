<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\TrustedDeviceStore;

/** In-memory fake of the {@see TrustedDeviceStore} port. */
final class InMemoryTrustedDeviceStore implements TrustedDeviceStore
{
    /** @var array<int, array{id: int, account_id: int, token_hash: string, session_version: int, failed_attempts: int, last_used_at: string, expires_at: string}> */
    public array $devices = [];

    private int $nextId = 1;

    public function find(string $tokenHash, int $accountId): ?array
    {
        foreach ($this->devices as $device) {
            if ($device['token_hash'] === $tokenHash && $device['account_id'] === $accountId) {
                return [
                    'id'              => $device['id'],
                    'session_version' => $device['session_version'],
                    'failed_attempts' => $device['failed_attempts'],
                    'expires_at'      => $device['expires_at'],
                ];
            }
        }

        return null;
    }

    public function save(string $tokenHash, int $accountId, int $sessionVersion, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void
    {
        $id = $this->find($tokenHash, $accountId)['id'] ?? $this->nextId++;

        $this->devices[$id] = [
            'id'              => $id,
            'account_id'      => $accountId,
            'token_hash'      => $tokenHash,
            'session_version' => $sessionVersion,
            'failed_attempts' => 0,
            'last_used_at'    => $now->format('Y-m-d H:i:s'),
            'expires_at'      => $expiresAt->format('Y-m-d H:i:s'),
        ];
    }

    public function updateFailedAttempts(int $id, int $failedAttempts): void
    {
        $this->devices[$id]['failed_attempts'] = $failedAttempts;
    }

    public function delete(int $id): void
    {
        unset($this->devices[$id]);
    }

    public function prune(int $accountId, int $sessionVersion, \DateTimeImmutable $now, int $keep): void
    {
        $limit = $now->format('Y-m-d H:i:s');
        $own   = [];
        foreach ($this->devices as $id => $device) {
            if ($device['account_id'] !== $accountId) {
                continue;
            }
            if ($device['expires_at'] <= $limit || $device['session_version'] !== $sessionVersion) {
                unset($this->devices[$id]);
                continue;
            }
            $own[$id] = $device['last_used_at'] . sprintf('%010d', $id);
        }

        arsort($own);
        foreach (array_slice(array_keys($own), $keep) as $id) {
            unset($this->devices[$id]);
        }
    }

    /** @return list<array{id: int, account_id: int, token_hash: string, session_version: int, failed_attempts: int, last_used_at: string, expires_at: string}> */
    public function of(int $accountId): array
    {
        return array_values(array_filter($this->devices, static fn (array $d): bool => $d['account_id'] === $accountId));
    }
}
