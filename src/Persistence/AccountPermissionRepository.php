<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Security\PermissionStore;

/**
 * Raw data repository for the permissions of the accounts (table
 * `account_permission`, R23.10).
 */
final class AccountPermissionRepository extends AbstractRepository implements PermissionStore
{
    public function permissionsOf(int $accountId): array
    {
        return array_map('strval', array_column($this->findAll(
            'SELECT permission FROM account_permission WHERE account_id = :account ORDER BY permission',
            ['account' => $accountId]
        ), 'permission'));
    }

    public function replace(int $accountId, array $permissions): void
    {
        $this->transactional('save_account_permissions', function () use ($accountId, $permissions): void {
            $this->perform('DELETE FROM account_permission WHERE account_id = :account', ['account' => $accountId]);
            foreach (array_unique($permissions) as $permission) {
                $this->perform(
                    'INSERT INTO account_permission (account_id, permission) VALUES (:account, :permission)',
                    ['account' => $accountId, 'permission' => $permission]
                );
            }
        });
    }

    public function holders(string $permission): array
    {
        return array_map('intval', array_column($this->findAll(
            'SELECT account_id FROM account_permission WHERE permission = :permission ORDER BY account_id',
            ['permission' => $permission]
        ), 'account_id'));
    }
}
