<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Security\PasswordHistoryStore;

/**
 * Raw data repository for the hashes of earlier passwords (table
 * `password_history`, R23.18).
 */
final class PasswordHistoryRepository extends AbstractRepository implements PasswordHistoryStore
{
    public function recent(int $accountId, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return array_map('strval', array_column($this->findAll(
            'SELECT password_hash FROM password_history WHERE account_id = :account ORDER BY id DESC LIMIT ' . $limit,
            ['account' => $accountId]
        ), 'password_hash'));
    }

    public function add(int $accountId, string $passwordHash, \DateTimeImmutable $at, int $keep): void
    {
        $this->transactional('save_password_history', function () use ($accountId, $passwordHash, $at, $keep): void {
            $this->perform(
                'INSERT INTO password_history (account_id, password_hash, created_at) VALUES (:account, :hash, :created)',
                ['account' => $accountId, 'hash' => $passwordHash, 'created' => $at->format('Y-m-d H:i:s')]
            );

            $kept = array_map('intval', array_column($this->findAll(
                'SELECT id FROM password_history WHERE account_id = :account ORDER BY id DESC LIMIT ' . max(1, $keep),
                ['account' => $accountId]
            ), 'id'));
            $this->perform(
                'DELETE FROM password_history WHERE account_id = :account AND id < :oldest',
                ['account' => $accountId, 'oldest' => min($kept)]
            );
        });
    }
}
