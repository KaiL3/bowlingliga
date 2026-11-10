<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * A permission an account can hold (R23.10).
 *
 * The permissions are fixed in the code; the table `account_permission`
 * records which account holds which. A new permission is a new case here
 * plus a migration that grants it to all existing accounts, so that nobody
 * silently loses anything; afterwards it can be revoked where wanted.
 */
enum Permission: string
{
    /** Create and remove admin accounts, grant and revoke permissions. */
    case ManageAccounts = 'accounts.manage';

    /** Catalog key of the name shown in the admin area. */
    public function label(): string
    {
        return 'permission.' . $this->value;
    }

    /**
     * Permissions of a newly created account: everything except managing
     * accounts (R23.13).
     *
     * @return list<self>
     */
    public static function forNewAccount(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $p): bool => $p !== self::ManageAccounts));
    }
}
