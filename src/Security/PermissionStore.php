<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Port for the permissions of the accounts (R23.10).
 *
 * Stores the permission keys ({@see Permission} values) per account.
 * Implemented by {@see \Bowlingliga\Persistence\AccountPermissionRepository};
 * the unit tests use an in-memory fake.
 */
interface PermissionStore
{
    /**
     * Permission keys of an account.
     *
     * @return list<string>
     */
    public function permissionsOf(int $accountId): array;

    /**
     * Replaces the permissions of an account.
     *
     * @param list<string> $permissions
     */
    public function replace(int $accountId, array $permissions): void;

    /**
     * Ids of the accounts that hold a permission.
     *
     * @return list<int>
     */
    public function holders(string $permission): array;
}
