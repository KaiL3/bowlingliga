<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use Bowlingliga\Persistence\AdminAccountRepository;
use OTPHP\TOTP;

/**
 * Management of the admin accounts of the auth module (R23.1–6, R23.9).
 *
 * This component only covers the account management and the cryptographic
 * basic operations for accounts (task 20.1). The actual login, 2FA and
 * session flow (login with password and TOTP check, session, account lock)
 * is deliberately not part of this class (task 20.2).
 *
 * Responsibilities:
 * - Create an account with a unique username (R23.1–2); the password is
 *   stored as salt+hash (R23.3, {@see PasswordHasher}).
 * - Remove an account, always keeping at least one account (R23.4–5).
 * - Change the password (R23.3).
 * - TOTP setup: generate a secret, provide it once in plaintext to the
 *   administrator for transfer into the authenticator app (R23.6) and store
 *   only the encrypted form (R23.9, {@see TotpSecretEncryption}).
 *
 * Sensitive values (plaintext password, plaintext secret) are not logged.
 */
final class AccountManagement
{
    private const USERNAME_MIN = 1;
    private const USERNAME_MAX = 100;

    /**
     * @param string $issuer Issuer shown in the authenticator app: the league
     *                       name (R38.4); the default is that of a new
     *                       installation (R38.2).
     */
    public function __construct(
        private readonly AdminAccountRepository $accounts,
        private readonly PasswordHasher $passwordHasher,
        private readonly TotpSecretEncryption $totpEncryption,
        private readonly TotpVerifier $totpVerifier = new TotpVerifier(),
        private readonly string $issuer = 'Bowlingliga',
    ) {
    }

    /**
     * Creates an admin account with a unique username (R23.1–3).
     *
     * The username is checked for length (R23.1–2) and uniqueness (R23.2); the
     * password is only stored as hash (R23.3). An account is created without
     * active 2FA; the TOTP setup happens separately via {@see setUpTotp()}
     * (R23.6).
     *
     * @return int The generated account id.
     *
     * @throws AccountException if the username is invalid or already taken.
     */
    public function createAccount(string $username, string $plaintextPassword): int
    {
        $username = $this->checkUsername($username);

        if ($this->accounts->usernameExists($username)) {
            throw AccountException::usernameTaken($username);
        }

        if (mb_strlen($plaintextPassword) < self::PASSWORD_MIN) {
            throw AccountException::passwordTooShort(self::PASSWORD_MIN);
        }

        $hash = $this->passwordHasher->hash($plaintextPassword);

        return $this->accounts->create($username, $hash, null);
    }

    /**
     * Removes an admin account; at least one account always remains
     * (R23.4–5).
     *
     * If the given account is the last remaining one, the deletion is refused
     * (R23.5) and the account stays unchanged.
     *
     * @throws AccountException if the account does not exist or is the last
     *         remaining account.
     */
    public function removeAccount(int $id): void
    {
        if ($this->accounts->find($id) === null) {
            throw AccountException::accountNotFound($id);
        }

        // R23.5: at least one admin account must always remain.
        if ($this->accounts->countItems() <= 1) {
            throw AccountException::lastAccount();
        }

        $this->accounts->delete($id);
    }

    /**
     * Changes the password of an admin account (R23.3).
     *
     * The new password is only stored as hash; the plaintext is not persisted.
     *
     * @throws AccountException if the account does not exist.
     */
    public function changePassword(int $id, string $newPlaintextPassword): void
    {
        if ($this->accounts->find($id) === null) {
            throw AccountException::accountNotFound($id);
        }

        $hash = $this->passwordHasher->hash($newPlaintextPassword);
        $this->accounts->updatePasswordHash($id, $hash);
    }

    /**
     * Minimum length of a new password chosen by the user.
     */
    public const PASSWORD_MIN = 8;

    /**
     * Changes the own password after checking the current password (R23.3).
     *
     * @throws AccountException for a wrong current password, a too short new
     *         password or a differing repetition.
     */
    public function changeOwnPassword(int $id, string $current, string $fresh, string $repetition): void
    {
        $account = $this->accounts->find($id) ?? throw AccountException::accountNotFound($id);

        if (! $this->passwordHasher->check($current, (string) $account['password_hash'])) {
            throw AccountException::passwordWrong();
        }
        if (mb_strlen($fresh) < self::PASSWORD_MIN) {
            throw AccountException::passwordTooShort(self::PASSWORD_MIN);
        }
        if (! hash_equals($fresh, $repetition)) {
            throw AccountException::passwordsMismatch();
        }

        $this->accounts->updatePasswordHash($id, $this->passwordHasher->hash($fresh));
    }

    /**
     * Username of an account (label of the authenticator app).
     *
     * @throws AccountException if the account does not exist.
     */
    /**
     * Ends all sessions of an account by raising its session version; the
     * caller keeps its own session via {@see SessionRenewal}.
     *
     * @throws AccountException if the account does not exist.
     */
    public function endSessions(int $id): void
    {
        if ($this->accounts->find($id) === null) {
            throw AccountException::accountNotFound($id);
        }

        $this->accounts->raiseSessionVersion($id);
    }

    public function username(int $id): string
    {
        $account = $this->accounts->find($id) ?? throw AccountException::accountNotFound($id);

        return (string) $account['username'];
    }

    /**
     * Tells whether two-factor authentication is active for the account.
     */
    public function isTotpActive(int $id): bool
    {
        $account = $this->accounts->find($id) ?? throw AccountException::accountNotFound($id);

        return ($account['totp_secret_encrypted'] ?? null) !== null;
    }

    /**
     * Generates a new TOTP secret for setup **without** storing it (R23.6). It
     * is only activated with {@see activateTotp()} after a valid code from the
     * app has been entered – so nobody locks themselves out with a secret that
     * was not transferred.
     *
     * @throws AccountException if the account does not exist.
     */
    public function generateTotpProposal(int $id, string $username): TotpSetup
    {
        if ($this->accounts->find($id) === null) {
            throw AccountException::accountNotFound($id);
        }

        [$plaintextSecret, $provisioningUri] = $this->generateTotpSecret($username);

        return new TotpSetup($plaintextSecret, $provisioningUri, $this->totpEncryption->encrypt($plaintextSecret));
    }

    /**
     * Returns the setup data of a not yet activated, encrypted cached secret
     * again (e.g. after a wrong code).
     */
    public function totpProposalFrom(string $secretEncrypted, string $username): TotpSetup
    {
        $plaintext = $this->totpEncryption->decrypt($secretEncrypted);

        if (class_exists(TOTP::class)) {
            $totp = TOTP::createFromSecret($plaintext);
            $totp->setLabel($username);
            $totp->setIssuer($this->issuer);
            $uri = $totp->getProvisioningUri();
        } else {
            $uri = sprintf('otpauth://totp/%s?secret=%s&issuer=%s', rawurlencode($username), $plaintext, rawurlencode($this->issuer));
        }

        return new TotpSetup($plaintext, $uri, $secretEncrypted);
    }

    /**
     * Activates two-factor authentication with a previously generated secret
     * as soon as a valid code from the app is given (R23.6, R23.9).
     *
     * @throws AccountException for an invalid code or an unknown account.
     */
    public function activateTotp(int $id, string $secretEncrypted, string $code): void
    {
        if ($this->accounts->find($id) === null) {
            throw AccountException::accountNotFound($id);
        }

        $plaintext = $this->totpEncryption->decrypt($secretEncrypted);
        if (! $this->totpVerifier->check($plaintext, trim($code))) {
            throw AccountException::totpCodeInvalid();
        }

        $this->accounts->saveTotpSecret($id, $secretEncrypted);
    }

    /**
     * Disables two-factor authentication; confirmed by password and current
     * code (e.g. before changing phones).
     *
     * @throws AccountException for a wrong password/code or without active 2FA.
     */
    public function deactivateTotp(int $id, string $password, string $code): void
    {
        $account = $this->accounts->find($id) ?? throw AccountException::accountNotFound($id);
        $secret = $account['totp_secret_encrypted'] ?? null;
        if ($secret === null) {
            throw AccountException::totpNotActive();
        }
        if (! $this->passwordHasher->check($password, (string) $account['password_hash'])) {
            throw AccountException::passwordWrong();
        }
        if (! $this->totpVerifier->check($this->totpEncryption->decrypt((string) $secret), trim($code))) {
            throw AccountException::totpCodeInvalid();
        }

        $this->accounts->saveTotpSecret($id, null);
    }

    /**
     * Sets up two-factor authentication for an admin account (R23.6, R23.9).
     *
     * Generates a new TOTP secret (preferably via the established otphp
     * library), stores only its encrypted form (R23.9) and returns the secret
     * once in plaintext together with the provisioning URI for transfer into
     * the authenticator app (R23.6). The plaintext of the secret is
     * deliberately only returned and neither stored nor logged.
     *
     * @param string $username Label for the authenticator app (provisioning
     *                         URI); the caller typically passes the username
     *                         of the account here.
     *
     * @throws AccountException if the account does not exist.
     */
    public function setUpTotp(int $id, string $username): TotpSetup
    {
        if ($this->accounts->find($id) === null) {
            throw AccountException::accountNotFound($id);
        }

        [$plaintextSecret, $provisioningUri] = $this->generateTotpSecret($username);

        // R23.9: store only the encrypted form.
        $encrypted = $this->totpEncryption->encrypt($plaintextSecret);
        $this->accounts->saveTotpSecret($id, $encrypted);

        // R23.6: return the secret once in plaintext for transfer.
        return new TotpSetup($plaintextSecret, $provisioningUri, $encrypted);
    }

    /**
     * Generates a TOTP secret together with the provisioning URI (R23.6).
     *
     * The established otphp library is preferred (correct RFC 6238
     * implementation). If it is not available at runtime, a Base32-encoded
     * random secret is generated as fallback, so that the setup remains
     * possible in principle even without the dependency installed.
     *
     * @return array{0: string, 1: string} [plaintext secret, provisioning URI]
     */
    private function generateTotpSecret(string $username): array
    {
        if (class_exists(TOTP::class)) {
            // otphp v11: TOTP::generate() creates an object with a random secret.
            $totp = method_exists(TOTP::class, 'generate')
                ? TOTP::generate()
                : TOTP::create();
            $totp->setLabel($username);
            $totp->setIssuer($this->issuer);

            return [$totp->getSecret(), $totp->getProvisioningUri()];
        }

        // Fallback without otphp: Base32-encoded random secret (20 bytes, usual for RFC 6238).
        $plaintextSecret  = $this->generateBase32Secret();
        $provisioningUri = sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s',
            rawurlencode($username),
            $plaintextSecret,
            rawurlencode($this->issuer)
        );

        return [$plaintextSecret, $provisioningUri];
    }

    /**
     * Generates a Base32-encoded random secret (fallback without otphp).
     */
    private function generateBase32Secret(int $byteCount = 20): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $rawBytes = random_bytes($byteCount);

        $bits = '';
        foreach (str_split($rawBytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $secret = '';
        foreach (str_split($bits, 5) as $fiveBits) {
            $fiveBits = str_pad($fiveBits, 5, '0', STR_PAD_RIGHT);
            $secret   .= $alphabet[bindec($fiveBits)];
        }

        return $secret;
    }

    /**
     * Checks and normalises a username (R23.1–2).
     *
     * @throws AccountException if the username is empty after trimming or too
     *         long.
     */
    private function checkUsername(string $username): string
    {
        $username = trim($username);
        $length       = mb_strlen($username);

        if ($length < self::USERNAME_MIN || $length > self::USERNAME_MAX) {
            throw AccountException::usernameInvalid();
        }

        return $username;
    }
}
