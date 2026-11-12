<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Security\AccountException;
use Bowlingliga\Security\AccountLogStore;
use Bowlingliga\Security\AccountManagement;
use Bowlingliga\Security\AccountPermissions;
use Bowlingliga\Security\PasswordPolicy;
use Bowlingliga\Security\Permission;
use Bowlingliga\Security\PermissionDeniedException;
use Bowlingliga\Security\SessionRenewal;
use Bowlingliga\Security\WriteAccess;
use Bowlingliga\Security\TotpSetup;
use Bowlingliga\Security\AccessDeniedException;

/**
 * Writing use case: admin account management in the admin area (R23.1,
 * R23.2, R23.4, R23.5, R23.6).
 *
 * Wires the existing, purely domain-level {@see AccountManagement} (create
 * with unique username, remove while keeping at least one account, change
 * password, TOTP setup) with the access protection of the admin area: each of
 * these writing functions may only run with an existing, valid authenticated
 * session (R5.1). Without write access the action is skipped and the data
 * stays unchanged.
 *
 * The domain rules (unique username R23.2, keeping at least one account
 * R23.5, encrypted storage of the TOTP secret R23.9) stay in
 * {@see AccountManagement}; this use case only adds the authorisation and
 * passes results and exceptions on unchanged.
 *
 * Changing the password or the two-factor login ends all other sessions of the
 * account (the repository raises its session version); the own session stays
 * logged in via {@see SessionRenewal}.
 *
 * Creating and removing accounts, changing their permissions and acting on
 * another account need the permission "manage accounts" (R23.10); every
 * such change is logged with time and acting account (R23.14).
 */
final class AdminAccountManagement
{
    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /**
     * @param callable(): \DateTimeImmutable|null $clock Optional clock for tests.
     */
    public function __construct(
        private readonly AccountManagement $accountManagement,
        private readonly WriteAccess $writeAccess,
        private readonly AccountPermissions $permissions,
        private readonly AccountLogStore $log,
        private readonly Transaction $transaction,
        private readonly ?SessionRenewal $sessions = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Creates an admin account with a unique username and returns its id
     * (R23.1, R23.2).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\AccountException if the username is
     *         invalid or already taken (R23.2).
     */
    public function createAccount(string $username, string $plaintextPassword): int
    {
        $this->ensureWriteAccess();
        $this->permissions->require(Permission::SystemAdmin);

        return $this->transaction->perform(function () use ($username, $plaintextPassword): int {
            // The creator knows the password: the owner replaces it at the first login (R23.17).
            $id = $this->accountManagement->createAccount($username, $plaintextPassword, mustChange: true);
            // A new account may do everything except managing accounts (R23.13).
            $this->permissions->grantNewAccount($id);
            $this->record($this->accountManagement->username($id), AccountLogStore::CREATED);

            return $id;
        });
    }

    /**
     * Removes an admin account; at least one account always remains (R23.4,
     * R23.5).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\AccountException if the account does not
     *         exist or is the last remaining account (R23.5).
     */
    public function removeAccount(int $id): void
    {
        $this->ensureWriteAccess();
        $this->permissions->require(Permission::SystemAdmin);

        $this->transaction->perform(function () use ($id): void {
            // R23.11: somebody must be left to manage the accounts.
            if ($this->permissions->isLastSystemAdmin($id)) {
                throw AccountException::lastSystemAdmin();
            }
            $username = $this->accountManagement->username($id);
            $this->accountManagement->removeAccount($id);
            $this->record($username, AccountLogStore::REMOVED);
        });
    }

    /**
     * Sets the permissions of an account (R23.10–R23.12) and logs each change
     * (R23.14). Unknown permission keys are ignored.
     *
     * @param list<mixed> $permissionKeys
     * @return int Number of changed permissions.
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws PermissionDeniedException if the own account lacks a permission it changes.
     * @throws AccountException if the account does not exist or nobody would
     *         be left to manage accounts.
     */
    public function changePermissions(int $id, array $permissionKeys): int
    {
        $this->ensureWriteAccess();
        $this->permissions->require(Permission::SystemAdmin);

        return $this->transaction->perform(function () use ($id, $permissionKeys): int {
            $username = $this->accountManagement->username($id);
            $wanted   = [];
            foreach ($permissionKeys as $key) {
                $permission = is_string($key) ? Permission::tryFrom($key) : null;
                if ($permission !== null) {
                    $wanted[] = $permission;
                }
            }

            $changes = $this->permissions->change($id, $wanted);
            foreach ($changes['granted'] as $permission) {
                $this->record($username, AccountLogStore::GRANTED, $permission);
            }
            foreach ($changes['revoked'] as $permission) {
                $this->record($username, AccountLogStore::REVOKED, $permission);
            }

            return count($changes['granted']) + count($changes['revoked']);
        });
    }

    /**
     * Whether the logged-in account may manage accounts (R23.10).
     */
    public function isSystemAdmin(): bool
    {
        return $this->writeAccess->checkWriteAccess() && $this->permissions->can(Permission::SystemAdmin);
    }

    /**
     * Id of the logged-in account, or null.
     */
    public function currentAccount(): ?int
    {
        return $this->permissions->currentAccount();
    }

    /**
     * Permissions of an account (R23.10).
     *
     * @return list<Permission>
     * @throws PermissionDeniedException without "manage accounts".
     */
    public function permissionsOf(int $id): array
    {
        $this->ensureWriteAccess();
        $this->permissions->require(Permission::SystemAdmin);

        return $this->permissions->of($id);
    }

    /**
     * The latest changes to accounts and permissions, newest first (R23.14).
     *
     * @return list<array{created_at: string, actor_name: string|null, target_name: string, action: string, permission: string|null}>
     * @throws PermissionDeniedException without "manage accounts".
     */
    public function recentChanges(int $limit = 20): array
    {
        $this->ensureWriteAccess();
        $this->permissions->require(Permission::SystemAdmin);

        return $this->log->recent($limit);
    }

    /**
     * Changes the password of an admin account (R23.3).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\AccountException if the account does not
     *         exist.
     */
    public function changePassword(int $id, string $newPlaintextPassword): void
    {
        $this->ensureWriteAccess();
        $this->ensureOwnOrManaging($id);

        // Set for somebody else: the owner replaces it at the next login (R23.17).
        $this->accountManagement->changePassword($id, $newPlaintextPassword, $id !== $this->permissions->currentAccount());
        $this->sessions?->renewSession($id);
    }

    /**
     * Sets up two-factor authentication for an admin account and returns the
     * secret, shown once, for transfer into the authenticator app (R23.6).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\AccountException if the account does not
     *         exist.
     */
    public function setUpTotp(int $id, string $username): TotpSetup
    {
        $this->ensureWriteAccess();
        $this->ensureOwnOrManaging($id);

        $setup = $this->accountManagement->setUpTotp($id, $username);
        $this->sessions?->renewSession($id);

        return $setup;
    }

    /**
     * Changes the own password after checking the current one (R23.3).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\AccountException for a wrong current, too
     *         short or not repeated new password.
     */
    public function changeOwnPassword(int $id, string $current, string $fresh, string $repetition): void
    {
        $this->ensureWriteAccess();
        $this->accountManagement->changeOwnPassword($id, $current, $fresh, $repetition);
        $this->sessions?->renewSession($id);
    }

    /**
     * Password policy of the admin accounts, or null without one (R23.15).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     */
    public function passwordPolicy(): ?PasswordPolicy
    {
        $this->ensureWriteAccess();

        return $this->accountManagement->passwordPolicy();
    }

    /**
     * @throws AccessDeniedException if there is no write access (R5.1).
     */
    public function username(int $id): string
    {
        $this->ensureWriteAccess();

        return $this->accountManagement->username($id);
    }

    /**
     * @throws AccessDeniedException if there is no write access (R5.1).
     */
    public function isTotpActive(int $id): bool
    {
        $this->ensureWriteAccess();

        return $this->accountManagement->isTotpActive($id);
    }

    /**
     * First step of the 2FA setup: generate a new secret, do not activate it
     * yet (R23.6).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     */
    public function generateTotpProposal(int $id, string $username): TotpSetup
    {
        $this->ensureWriteAccess();

        return $this->accountManagement->generateTotpProposal($id, $username);
    }

    /**
     * Shows the setup data of a cached, not yet activated secret again.
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     */
    public function totpProposalFrom(string $secretEncrypted, string $username): TotpSetup
    {
        $this->ensureWriteAccess();

        return $this->accountManagement->totpProposalFrom($secretEncrypted, $username);
    }

    /**
     * Second step: activate as soon as a valid code is given (R23.6).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\AccountException for an invalid code.
     */
    public function activateTotp(int $id, string $secretEncrypted, string $code): void
    {
        $this->ensureWriteAccess();
        $this->accountManagement->activateTotp($id, $secretEncrypted, $code);
        $this->sessions?->renewSession($id);
    }

    /**
     * Disables 2FA after confirmation by password and code.
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\AccountException for a wrong password/code.
     */
    public function deactivateTotp(int $id, string $password, string $code): void
    {
        $this->ensureWriteAccess();
        $this->accountManagement->deactivateTotp($id, $password, $code);
        $this->sessions?->renewSession($id);
    }

    /**
     * Logs out all other sessions of the account, e.g. after using a foreign
     * device; the own session stays logged in.
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\AccountException if the account does not
     *         exist.
     */
    public function endOtherSessions(int $id): void
    {
        $this->ensureWriteAccess();
        $this->accountManagement->endSessions($id);
        $this->sessions?->renewSession($id);
    }

    private function ensureWriteAccess(): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
    }

    /** Another account may only be changed with "manage accounts" (R23.10). */
    private function ensureOwnOrManaging(int $id): void
    {
        if ($id !== $this->permissions->currentAccount()) {
            $this->permissions->require(Permission::SystemAdmin);
        }
    }

    private function record(string $targetName, string $action, ?Permission $permission = null): void
    {
        $actorId = $this->permissions->currentAccount();

        $this->log->record(
            ($this->clock)(),
            $actorId,
            $actorId !== null ? $this->accountManagement->username($actorId) : null,
            $targetName,
            $action,
            $permission?->value,
        );
    }
}
