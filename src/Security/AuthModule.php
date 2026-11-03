<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use Bowlingliga\Application\Transaction;
use Bowlingliga\Persistence\AdminAccountRepository;

/**
 * Login, 2FA and session flow of the auth module (R5.1–5.7, R23.7, R23.8,
 * R28.8, R28.9).
 *
 * This service orchestrates the authentication: it checks the password and –
 * with two-factor authentication enabled – the TOTP code, sets up an
 * authenticated session on success (regenerating the session id against
 * session fixation), limits the session to 30 minutes of inactivity and to
 * 8 hours in total and locks an account for 15 minutes after 5 consecutive
 * failed attempts. A session also ends as soon as the session version of its
 * account changes (password, two-factor login, "log out all other sessions").
 *
 * The cryptographic basic operations live in the existing services
 * ({@see PasswordHasher}, {@see TotpSecretEncryption}, {@see TotpVerifier});
 * the account raw data (including failed attempts counter and lock status) is
 * managed by the {@see AdminAccountRepository}. The session is accessed via
 * the {@see SessionStore} port, so that the flow stays testable without a
 * real PHP session. The point in time of the check can be injected (clock),
 * so that timeout and lock window can be tested deterministically.
 *
 * Security principles:
 * - Generic error message for invalid credentials, without revealing whether
 *   the username or the password is wrong (R5.3).
 * - A specific note only about an invalid second factor, once the password is
 *   correct and 2FA is active (R23.8).
 * - Even for an unknown username a password check against a dummy hash is
 *   run, to make timing inferences harder.
 */
final class AuthModule implements WriteAccess, SessionRenewal
{
    /** Session key: id of the logged-in account. */
    public const SESSION_ACCOUNT_ID = 'auth_account_id';

    /** Session key: timestamp of the last activity (for the inactivity timeout). */
    public const SESSION_LAST_ACTIVITY = 'auth_last_activity';

    /** Maximum inactivity of a session in seconds (30 minutes, R5.2/R5.7). */
    public const SESSION_TIMEOUT_SECONDS = 30 * 60;

    /** Session key: timestamp of the login (for the maximum session duration). */
    public const SESSION_LOGGED_IN_AT = 'auth_logged_in_at';

    /** Session key: session version of the account at login. */
    public const SESSION_VERSION = 'auth_session_version';

    /**
     * Maximum duration of a session regardless of activity (8 hours): a stolen
     * session cannot be kept alive forever by regular requests (R5.8).
     */
    public const SESSION_MAX_SECONDS = 8 * 60 * 60;

    /** Number of consecutive failed attempts from which the account is locked (R5.6). */
    public const MAX_FAILED_ATTEMPTS = 5;

    /** Duration of the temporary account lock in seconds (15 minutes, R5.6). */
    public const LOCK_DURATION_SECONDS = 15 * 60;

    /** Account whose password has been checked and that is waiting for the code (R23.7). */
    public const SESSION_PENDING_ACCOUNT_ID = 'auth_pending_account_id';
    public const SESSION_PENDING_SINCE = 'auth_pending_since';

    /** Time for entering the code after a successful password check. */
    public const SECOND_FACTOR_TIMEOUT_SECONDS = 5 * 60;

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /** Dummy hash for timing equalisation, generated once per process ({@see dummyHash()}). */
    private static ?string $dummyHash = null;

    /**
     * @param Transaction $transaction Every login step runs in a transaction
     *        that locks the account row: parallel attempts on the same account
     *        wait for each other, so none of them gets past the lock check
     *        while another one is about to lock the account (R5.6).
     * @param callable(): \DateTimeImmutable|null $clock Optional clock for
     *        deterministic tests; without it the current time is used.
     */
    public function __construct(
        private readonly AdminAccountRepository $accounts,
        private readonly PasswordHasher $passwordHasher,
        private readonly TotpSecretEncryption $totpEncryption,
        private readonly TotpVerifier $totpVerifier,
        private readonly SessionStore $session,
        private readonly Transaction $transaction,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Runs a login attempt (R5.2, R5.3, R5.6, R23.7, R23.8).
     *
     * Flow:
     * 1. Load the account by username; if it is unknown, a password check is
     *    still run (timing equalisation) and the login rejected generically
     *    (R5.3).
     * 2. If the account is currently locked, it is rejected without a
     *    password check (R5.6).
     * 3. Check the password; on failure count a failed attempt, lock if
     *    needed and reject generically (R5.3/R5.6).
     * 4. With active 2FA check the TOTP code; on failure count a failed
     *    attempt, lock if needed and point specifically to the second factor
     *    (R23.7/R23.8).
     * 5. On success reset the failed attempts counter, regenerate the session
     *    id and set up the authenticated session (R5.2).
     *
     * Steps 1–5 run in one transaction with the account row locked (see the
     * constructor).
     *
     * @param string      $username Submitted username.
     * @param string      $password Submitted plaintext password.
     * @param string|null $totpCode Submitted TOTP code (required with active
     *                              2FA).
     * @param int|null    $totpTime Optional Unix timestamp for the TOTP check
     *                              (deterministic tests).
     */
    public function login(
        string $username,
        string $password,
        ?string $totpCode = null,
        ?int $totpTime = null,
    ): LoginResult {
        return $this->transaction->perform(
            fn (): LoginResult => $this->attemptLogin($username, $password, $totpCode, $totpTime)
        );
    }

    /**
     * Steps of {@see login()}; runs inside its transaction.
     */
    private function attemptLogin(string $username, string $password, ?string $totpCode, ?int $totpTime): LoginResult
    {
        $account = $this->accounts->findByUsernameForUpdate($username);

        if ($account === null) {
            // Timing equalisation: password check against a dummy hash, so that an
            // unknown username cannot be told from the response time.
            $this->passwordHasher->check($password, $this->dummyHash());

            return LoginResult::invalidCredentials();
        }

        $accountId = (int) $account['id'];

        // R5.6: a currently locked account is rejected without further checks.
        if ($this->isLocked($account)) {
            return LoginResult::accountLocked();
        }

        // R5.3: password check; a failure counts as failed attempt.
        if (! $this->passwordHasher->check($password, (string) $account['password_hash'])) {
            return $this->processFailedAttempt($accountId, $account, LoginResult::invalidCredentials());
        }

        // R23.7/R23.8: with active 2FA a valid TOTP code is required as well.
        if ($this->isSecondFactorActive($account)) {
            // Two-step: without a code it is asked for in the second step. That is
            // no failed attempt – the password was correct.
            if ($totpCode === null || trim($totpCode) === '') {
                $this->session->regenerateId();
                $this->session->set(self::SESSION_PENDING_ACCOUNT_ID, $accountId);
                $this->session->set(self::SESSION_PENDING_SINCE, $this->currentTime()->getTimestamp());

                return LoginResult::secondFactorRequired();
            }
            if (! $this->checkSecondFactor($account, $totpCode, $totpTime)) {
                return $this->processFailedAttempt($accountId, $account, LoginResult::invalidSecondFactor());
            }
        }

        // Success: reset failed attempts and set up the session (R5.2).
        $this->resetFailedAttempts($accountId, $account);
        $this->setUpSession($accountId, $account);

        return LoginResult::successful();
    }

    /**
     * Ends the authenticated session and requires a new login for subsequent
     * writing functions (R5.5).
     */
    /**
     * Second step of the login: checks the code for the account whose password
     * was checked before (R23.7, R23.8). A wrong code counts as failed attempt
     * (R5.6); after the time limit the login starts over.
     */
    public function confirmSecondFactor(string $totpCode, ?int $totpTime = null): LoginResult
    {
        return $this->transaction->perform(fn (): LoginResult => $this->attemptSecondFactor($totpCode, $totpTime));
    }

    /**
     * Steps of {@see confirmSecondFactor()}; runs inside its transaction with
     * the account row locked.
     */
    private function attemptSecondFactor(string $totpCode, ?int $totpTime): LoginResult
    {
        $accountId = $this->pendingAccount();
        $account   = $accountId !== null ? $this->accounts->findForUpdate($accountId) : null;
        if ($accountId === null || $account === null || ! $this->isSecondFactorActive($account)) {
            $this->cancelSecondFactor();

            return LoginResult::loginExpired();
        }

        if ($this->isLocked($account)) {
            $this->cancelSecondFactor();

            return LoginResult::accountLocked();
        }

        if (! $this->checkSecondFactor($account, $totpCode, $totpTime)) {
            $result = $this->processFailedAttempt($accountId, $account, LoginResult::invalidSecondFactor());
            if ($result->status === LoginStatus::ACCOUNT_LOCKED) {
                $this->cancelSecondFactor();
            }

            return $result;
        }

        $this->cancelSecondFactor();
        $this->resetFailedAttempts($accountId, $account);
        $this->setUpSession($accountId, $account);

        return LoginResult::successful();
    }

    /**
     * Tells whether a login is waiting for the code (password checked, time
     * limit not expired).
     */
    public function awaitsSecondFactor(): bool
    {
        return $this->pendingAccount() !== null;
    }

    /**
     * Discards a login waiting for the code.
     */
    public function cancelSecondFactor(): void
    {
        $this->session->remove(self::SESSION_PENDING_ACCOUNT_ID);
        $this->session->remove(self::SESSION_PENDING_SINCE);
    }

    public function logout(): void
    {
        $this->cancelSecondFactor();
        $this->session->remove(self::SESSION_ACCOUNT_ID);
        $this->session->remove(self::SESSION_LAST_ACTIVITY);
        $this->session->remove(self::SESSION_LOGGED_IN_AT);
        $this->session->remove(self::SESSION_VERSION);
        $this->session->end();
    }

    /**
     * Checks whether write access is currently allowed (middleware, R5.1,
     * R5.4, R5.7).
     *
     * Write access is only allowed with an existing, not expired authenticated
     * session. If the session has expired (more than 30 minutes of
     * inactivity or more than 8 hours since the login), it is ended and access
     * denied (R5.7). The same happens if the account was deleted or its
     * session version changed in the meantime. If access is allowed, the
     * timestamp of the last activity is updated (sliding timeout, R5.2).
     * Reading functions do not need this check (R5.4).
     *
     * @return bool true if write access is allowed.
     */
    public function checkWriteAccess(): bool
    {
        $accountId = $this->session->retrieve(self::SESSION_ACCOUNT_ID);

        if ($accountId === null) {
            return false;
        }

        $lastActivity = $this->session->retrieve(self::SESSION_LAST_ACTIVITY);
        $loggedInAt   = $this->session->retrieve(self::SESSION_LOGGED_IN_AT);
        $currentTime            = $this->currentTime()->getTimestamp();

        // R5.7: end the session after 30 minutes of inactivity, and in any case
        // after the maximum duration. Sessions without a login time (from before
        // the maximum duration existed) end as well.
        if (! is_int($lastActivity) || ($currentTime - $lastActivity) > self::SESSION_TIMEOUT_SECONDS
            || ! is_int($loggedInAt) || ($currentTime - $loggedInAt) > self::SESSION_MAX_SECONDS) {
            $this->logout();

            return false;
        }

        // An account deleted in the meantime loses its open session immediately,
        // as does a session opened before the account's session version changed (R5.9).
        $account = $this->accounts->find((int) $accountId);
        if ($account === null || $this->session->retrieve(self::SESSION_VERSION) !== self::sessionVersion($account)) {
            $this->logout();

            return false;
        }

        // Sliding timeout: update the last activity (R5.2).
        $this->session->set(self::SESSION_LAST_ACTIVITY, $currentTime);

        return true;
    }

    /**
     * Id of the currently logged-in account, or null if there is no (valid)
     * write access.
     */
    public function loggedInAccount(): ?int
    {
        if (! $this->checkWriteAccess()) {
            return null;
        }

        $accountId = $this->session->retrieve(self::SESSION_ACCOUNT_ID);

        return is_int($accountId) ? $accountId : null;
    }

    /**
     * Keeps the current session logged in after its own account's session
     * version was raised (password, two-factor login, "log out all other
     * sessions"): takes over the new value and regenerates the session id.
     * Does nothing if the session belongs to another account, so an admin
     * changing someone else's password is not affected.
     */
    public function renewSession(int $accountId): void
    {
        if ($this->session->retrieve(self::SESSION_ACCOUNT_ID) !== $accountId) {
            return;
        }

        $account = $this->accounts->find($accountId);
        if ($account === null) {
            return;
        }

        $this->session->regenerateId();
        $this->session->set(self::SESSION_VERSION, self::sessionVersion($account));
    }

    /**
     * Account waiting for the code, or null (also after the time limit).
     */
    private function pendingAccount(): ?int
    {
        $accountId = $this->session->retrieve(self::SESSION_PENDING_ACCOUNT_ID);
        $since    = $this->session->retrieve(self::SESSION_PENDING_SINCE);
        if (! is_int($accountId) || ! is_int($since)) {
            return null;
        }

        if ($this->currentTime()->getTimestamp() - $since > self::SECOND_FACTOR_TIMEOUT_SECONDS) {
            $this->cancelSecondFactor();

            return null;
        }

        return $accountId;
    }

    /**
     * Sets up the authenticated session after a successful login (R5.2):
     * regenerate the session id (session fixation), store the account id, the
     * login time, the timestamp of the last activity and the account's session
     * version.
     *
     * @param array<string, mixed> $account
     */
    private function setUpSession(int $accountId, array $account): void
    {
        $currentTime = $this->currentTime()->getTimestamp();

        $this->cancelSecondFactor();
        $this->session->regenerateId();
        $this->session->set(self::SESSION_ACCOUNT_ID, $accountId);
        $this->session->set(self::SESSION_LOGGED_IN_AT, $currentTime);
        $this->session->set(self::SESSION_LAST_ACTIVITY, $currentTime);
        $this->session->set(self::SESSION_VERSION, self::sessionVersion($account));
    }

    /**
     * Session version of an account (1 before it was ever raised).
     *
     * @param array<string, mixed> $account
     */
    private static function sessionVersion(array $account): int
    {
        return (int) ($account['session_version'] ?? 1);
    }

    /**
     * Processes a failed attempt: increases the counter, locks the account for
     * 15 minutes when the maximum is reached and returns the matching result
     * (R5.6).
     *
     * @param array<string, mixed> $account
     */
    private function processFailedAttempt(int $accountId, array $account, LoginResult $defaultResult): LoginResult
    {
        $failedAttempts = (int) ($account['failed_attempts'] ?? 0) + 1;

        if ($failedAttempts >= self::MAX_FAILED_ATTEMPTS) {
            // R5.6: after 5 failed attempts lock for 15 minutes and reset the counter.
            $lockedUntil = $this->currentTime()->add(new \DateInterval('PT' . self::LOCK_DURATION_SECONDS . 'S'));
            $this->accounts->setLockedUntil($accountId, $lockedUntil);
            $this->accounts->updateFailedAttempts($accountId, 0);

            return LoginResult::accountLocked();
        }

        $this->accounts->updateFailedAttempts($accountId, $failedAttempts);

        return $defaultResult;
    }

    /**
     * Resets the failed attempts counter after a successful login and lifts a
     * possibly expired lock (R5.6).
     *
     * @param array<string, mixed> $account
     */
    private function resetFailedAttempts(int $accountId, array $account): void
    {
        if ((int) ($account['failed_attempts'] ?? 0) !== 0) {
            $this->accounts->updateFailedAttempts($accountId, 0);
        }

        if (($account['locked_until'] ?? null) !== null) {
            $this->accounts->setLockedUntil($accountId, null);
        }
    }

    /**
     * Checks whether the account is locked at the current point in time (R5.6).
     *
     * @param array<string, mixed> $account
     */
    private function isLocked(array $account): bool
    {
        $lockedUntil = $account['locked_until'] ?? null;

        if (! is_string($lockedUntil) || $lockedUntil === '') {
            return false;
        }

        $locking = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lockedUntil);

        if ($locking === false) {
            // Treat an unreadable lock end defensively as not locked.
            return false;
        }

        return $this->currentTime() < $locking;
    }

    /**
     * Tells whether two-factor authentication is active for the account
     * (encrypted TOTP secret stored, R23.7).
     *
     * @param array<string, mixed> $account
     */
    private function isSecondFactorActive(array $account): bool
    {
        $secret = $account['totp_secret_encrypted'] ?? null;

        return $secret !== null && $secret !== '';
    }

    /**
     * Checks the second factor: decrypts the secret and verifies the submitted
     * TOTP code (R23.7, R23.8).
     *
     * @param array<string, mixed> $account
     */
    private function checkSecondFactor(array $account, ?string $totpCode, ?int $totpTime): bool
    {
        if ($totpCode === null || trim($totpCode) === '') {
            return false;
        }

        try {
            $plaintextSecret = $this->totpEncryption->decrypt(
                (string) $account['totp_secret_encrypted']
            );
        } catch (TotpSecretEncryptionException) {
            // A secret that cannot be decrypted cannot be checked;
            // the second factor counts as invalid (R23.8).
            return false;
        }

        $step = $this->totpVerifier->timeStep($plaintextSecret, $totpCode, $totpTime);
        if ($step === null) {
            return false;
        }

        // Every code is valid only once: codes of the same or an older
        // time step than the last accepted one are rejected (R23.8).
        $lastOne = $account['totp_last_time_step'] ?? null;
        if ($lastOne !== null && $step <= (int) $lastOne) {
            return false;
        }

        $this->accounts->setTotpTimeStep((int) $account['id'], $step);

        return true;
    }

    /**
     * Returns the current point in time of the check via the injected clock.
     */
    private function currentTime(): \DateTimeImmutable
    {
        return ($this->clock)();
    }

    /**
     * Returns a dummy hash for timing equalisation with an unknown username
     * (R5.3). It is generated once per process with the same method as real
     * passwords, so that checking it takes equally long; it matches no known
     * password.
     */
    private function dummyHash(): string
    {
        return self::$dummyHash ??= $this->passwordHasher->hash(bin2hex(random_bytes(16)));
    }
}
