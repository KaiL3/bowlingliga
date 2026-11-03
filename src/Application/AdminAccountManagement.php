<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Security\AccountManagement;
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
 */
final class AdminAccountManagement
{
    public function __construct(
        private readonly AccountManagement $accountManagement,
        private readonly WriteAccess $writeAccess,
        private readonly ?SessionRenewal $sessions = null,
    ) {
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

        return $this->accountManagement->createAccount($username, $plaintextPassword);
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

        $this->accountManagement->removeAccount($id);
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

        $this->accountManagement->changePassword($id, $newPlaintextPassword);
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
}
