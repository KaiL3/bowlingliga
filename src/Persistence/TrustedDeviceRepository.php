<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Security\TrustedDeviceStore;

/**
 * Raw data repository for trusted devices (table `trusted_device`, R5.10).
 */
final class TrustedDeviceRepository extends AbstractRepository implements TrustedDeviceStore
{
    public function find(string $tokenHash, int $accountId): ?array
    {
        $row = $this->findOne(
            'SELECT id, session_version, failed_attempts, expires_at
             FROM trusted_device WHERE token_hash = :hash AND account_id = :account',
            ['hash' => $tokenHash, 'account' => $accountId]
        );

        return $row === null ? null : [
            'id'              => (int) $row['id'],
            'session_version' => (int) $row['session_version'],
            'failed_attempts' => (int) $row['failed_attempts'],
            'expires_at'      => (string) $row['expires_at'],
        ];
    }

    public function save(
        string $tokenHash,
        int $accountId,
        int $sessionVersion,
        \DateTimeImmutable $now,
        \DateTimeImmutable $expiresAt,
    ): void {
        $this->transactional('save_trusted_device', function () use ($tokenHash, $accountId, $sessionVersion, $now, $expiresAt): void {
            $this->perform(
                'INSERT INTO trusted_device (account_id, token_hash, session_version, failed_attempts, created_at, last_used_at, expires_at)
                 VALUES (:account, :hash, :version, 0, :created, :used, :expires)
                 ON DUPLICATE KEY UPDATE session_version = VALUES(session_version), failed_attempts = 0,
                     last_used_at = VALUES(last_used_at), expires_at = VALUES(expires_at)',
                [
                    'account' => $accountId,
                    'hash'    => $tokenHash,
                    'version' => $sessionVersion,
                    'created' => $now->format('Y-m-d H:i:s'),
                    'used'    => $now->format('Y-m-d H:i:s'),
                    'expires' => $expiresAt->format('Y-m-d H:i:s'),
                ]
            );
        });
    }

    public function updateFailedAttempts(int $id, int $failedAttempts): void
    {
        $this->transactional('update_trusted_device_failures', function () use ($id, $failedAttempts): void {
            $this->perform('UPDATE trusted_device SET failed_attempts = :attempts WHERE id = :id', ['attempts' => $failedAttempts, 'id' => $id]);
        });
    }

    public function delete(int $id): void
    {
        $this->transactional('delete_trusted_device', function () use ($id): void {
            $this->perform('DELETE FROM trusted_device WHERE id = :id', ['id' => $id]);
        });
    }

    public function prune(int $accountId, int $sessionVersion, \DateTimeImmutable $now, int $keep): void
    {
        $this->transactional('prune_trusted_devices', function () use ($accountId, $sessionVersion, $now, $keep): void {
            $this->perform(
                'DELETE FROM trusted_device WHERE account_id = :account AND (expires_at <= :now OR session_version <> :version)',
                ['account' => $accountId, 'now' => $now->format('Y-m-d H:i:s'), 'version' => $sessionVersion]
            );

            $keepIds = array_map('intval', array_column($this->findAll(
                'SELECT id FROM trusted_device WHERE account_id = :account ORDER BY last_used_at DESC, id DESC LIMIT ' . max(0, $keep),
                ['account' => $accountId]
            ), 'id'));
            if ($keepIds === []) {
                return;
            }

            $this->perform(
                'DELETE FROM trusted_device WHERE account_id = :account AND id NOT IN (' . implode(',', $keepIds) . ')',
                ['account' => $accountId]
            );
        });
    }
}
