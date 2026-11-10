<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Permissions of the accounts and the rules for changing them (R23.10–R23.13).
 *
 * - The logged-in account may only do what its permissions allow; the
 *   permissions are read afresh on every request, so a change applies at
 *   once without logging anybody out.
 * - Granting or revoking a permission needs "manage accounts" and the
 *   permission itself: nobody hands out more than they hold (R23.12).
 * - At least one account always keeps "manage accounts" (R23.11).
 *
 * Nothing in here is specific to admin accounts.
 */
final class AccountPermissions
{
    public function __construct(
        private readonly PermissionStore $store,
        private readonly LoggedInAccount $current,
    ) {
    }

    /** Id of the logged-in account, or null. */
    public function currentAccount(): ?int
    {
        return $this->current->loggedInAccount();
    }

    /** Whether the logged-in account holds the permission. */
    public function can(Permission $permission): bool
    {
        $accountId = $this->currentAccount();

        return $accountId !== null && in_array($permission, $this->of($accountId), true);
    }

    /**
     * @throws PermissionDeniedException if the logged-in account lacks the permission.
     */
    public function require(Permission $permission): void
    {
        if (! $this->can($permission)) {
            throw new PermissionDeniedException($permission);
        }
    }

    /**
     * Permissions of an account, in the order of {@see Permission::cases()};
     * unknown keys in the store are ignored.
     *
     * @return list<Permission>
     */
    public function of(int $accountId): array
    {
        $keys = $this->store->permissionsOf($accountId);

        return array_values(array_filter(Permission::cases(), static fn (Permission $p): bool => in_array($p->value, $keys, true)));
    }

    /** Whether removing the account would leave nobody who manages accounts (R23.11). */
    public function isLastManager(int $accountId): bool
    {
        return $this->store->holders(Permission::ManageAccounts->value) === [$accountId];
    }

    /** Gives a new account the permissions of a new account (R23.13). */
    public function grantNewAccount(int $accountId): void
    {
        $this->store->replace($accountId, array_map(static fn (Permission $p): string => $p->value, Permission::forNewAccount()));
    }

    /**
     * Sets the permissions of an account and returns what changed.
     *
     * @param list<Permission> $wanted
     * @return array{granted: list<Permission>, revoked: list<Permission>}
     *
     * @throws PermissionDeniedException if the logged-in account may not
     *         manage accounts or lacks a permission it changes (R23.12).
     * @throws AccountException if nobody would be left to manage accounts (R23.11).
     */
    public function change(int $accountId, array $wanted): array
    {
        $this->require(Permission::ManageAccounts);

        $before  = $this->of($accountId);
        $granted = array_values(array_filter(Permission::cases(), static fn (Permission $p): bool => in_array($p, $wanted, true) && ! in_array($p, $before, true)));
        $revoked = array_values(array_filter($before, static fn (Permission $p): bool => ! in_array($p, $wanted, true)));

        foreach ([...$granted, ...$revoked] as $permission) {
            $this->require($permission);
        }
        if (in_array(Permission::ManageAccounts, $revoked, true) && $this->isLastManager($accountId)) {
            throw AccountException::lastManager();
        }

        if ($granted !== [] || $revoked !== []) {
            $after = array_values(array_filter(Permission::cases(), static fn (Permission $p): bool => in_array($p, $wanted, true)));
            $this->store->replace($accountId, array_map(static fn (Permission $p): string => $p->value, $after));
        }

        return ['granted' => $granted, 'revoked' => $revoked];
    }
}
