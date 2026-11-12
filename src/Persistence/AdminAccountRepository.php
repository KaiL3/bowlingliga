<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

/**
 * Raw data repository for admin accounts (table `admin_account`, R23).
 *
 * Stores per account the unique username (R23.1–2), the password hash
 * (R23.3), the encrypted TOTP secret (R23.9), the lock status
 * (`locked_until`) and the failed attempts counter (`failed_attempts`, R5.6). The
 * TOTP secret is only stored as ciphertext (VARBINARY); the plaintext never
 * reaches the database (R23.9).
 *
 * Write operations run transactionally (R4.3) via prepared statements (R4.1);
 * reads return the persisted records unchanged (R4.5). Encrypted secrets are
 * bound as binary data via the PDO parameter type {@see \PDO::PARAM_LOB}.
 *
 * The class is deliberately not `final`, so that AccountManagement can be
 * tested in unit tests with an in-memory fake (without a database).
 */
class AdminAccountRepository extends AbstractRepository
{
    /**
     * Creates an admin account and returns its generated id (R23.1).
     *
     * The username is unique via a unique constraint (R23.2); a violation leads
     * (after rollback) to a {@see RepositoryException}.
     *
     * @param string      $username            Unique username (R23.2).
     * @param string      $passwordHash        Hash generated via password_hash() (R23.3).
     * @param string|null $totpSecretEncrypted Encrypted TOTP secret or null if
     *                                         2FA is not set up yet (R23.9).
     */
    public function create(
        string $username,
        string $passwordHash,
        ?string $totpSecretEncrypted = null
    ): int {
        return $this->transactional(
            'Adminkonto anlegen',
            function ($pdo) use ($username, $passwordHash, $totpSecretEncrypted): int {
                $statement = $pdo->prepare(
                    'INSERT INTO admin_account
                        (username, password_hash, totp_secret_encrypted, failed_attempts)
                     VALUES
                        (:username, :password_hash, :totp_secret, 0)'
                );
                $statement->bindValue('username', $username);
                $statement->bindValue('password_hash', $passwordHash);
                $this->bindSecret($statement, ':totp_secret', $totpSecretEncrypted);
                $statement->execute();

                return (int) $pdo->lastInsertId();
            }
        );
    }

    /**
     * Updates the password hash of an admin account (R23.3) and raises its
     * session version, so all sessions opened with the old password end.
     */
    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $this->transactional('update_password_hash', function () use ($id, $passwordHash): void {
            $this->perform(
                'UPDATE admin_account SET password_hash = :password_hash, session_version = session_version + 1 WHERE id = :id',
                ['password_hash' => $passwordHash, 'id' => $id]
            );
        });
    }

    /**
     * Records when the password was set, its strength and whether its owner
     * has to replace it at the next login (R23.17).
     */
    public function recordPasswordChange(int $id, int $bits, \DateTimeImmutable $changedAt, bool $mustChange): void
    {
        $this->transactional('record_password_change', function () use ($id, $bits, $changedAt, $mustChange): void {
            $this->perform(
                'UPDATE admin_account SET password_changed_at = :changed_at, password_bits = :bits,
                    must_change_password = :must_change WHERE id = :id',
                [
                    'changed_at'  => $changedAt->format('Y-m-d H:i:s'),
                    'bits'        => $bits,
                    'must_change' => $mustChange ? 1 : 0,
                    'id'          => $id,
                ]
            );
        });
    }

    /**
     * Sets the failed attempts counter of an admin account (R5.6).
     *
     * The counter is updated on login: increased on a failed attempt, reset to 0
     * on a successful login. It is the basis for the temporary account lock
     * after 5 consecutive failed attempts.
     */
    public function updateFailedAttempts(int $id, int $failedAttempts): void
    {
        $this->transactional('update_failed_attempts', function () use ($id, $failedAttempts): void {
            $this->perform(
                'UPDATE admin_account SET failed_attempts = :failed_attempts WHERE id = :id',
                ['failed_attempts' => $failedAttempts, 'id' => $id]
            );
        });
    }

    /**
     * Sets or removes the lock status of an admin account (R5.6).
     *
     * A set lock end (`locked_until`) marks the account as locked until that
     * point in time; the value null lifts an existing lock. The point in time is
     * bound in the DB format `Y-m-d H:i:s`.
     */
    public function setLockedUntil(int $id, ?\DateTimeImmutable $lockedUntil): void
    {
        $this->transactional('update_lock_state', function () use ($id, $lockedUntil): void {
            $this->perform(
                'UPDATE admin_account SET locked_until = :locked_until WHERE id = :id',
                [
                    'locked_until' => $lockedUntil?->format('Y-m-d H:i:s'),
                    'id'           => $id,
                ]
            );
        });
    }

    /**
     * Stores the encrypted TOTP secret of an admin account (R23.9).
     *
     * Only the ciphertext is stored; the plaintext of the secret never reaches
     * the database. The value null removes a previously set up 2FA again.
     * Raises the session version like a password change.
     */
    public function saveTotpSecret(int $id, ?string $totpSecretEncrypted): void
    {
        $this->transactional('save_totp_secret', function ($pdo) use ($id, $totpSecretEncrypted): void {
            $statement = $pdo->prepare(
                // New secret: the remembered time step belongs to the old one.
                'UPDATE admin_account SET totp_secret_encrypted = :totp_secret, totp_last_time_step = NULL,
                    session_version = session_version + 1 WHERE id = :id'
            );
            $this->bindSecret($statement, ':totp_secret', $totpSecretEncrypted);
            $statement->bindValue('id', $id, \PDO::PARAM_INT);
            $statement->execute();
        });
    }

    /**
     * Raises the session version of an admin account: every session that
     * stored the previous value ends on its next request.
     */
    public function raiseSessionVersion(int $id): void
    {
        $this->transactional('raise_session_version', function () use ($id): void {
            $this->perform(
                'UPDATE admin_account SET session_version = session_version + 1 WHERE id = :id',
                ['id' => $id]
            );
        });
    }

    /**
     * Remembers the time step of the last accepted TOTP code, so that the same
     * code is not valid again (R23.8).
     */
    public function setTotpTimeStep(int $id, int $timeStep): void
    {
        $this->transactional('save_totp_time_step', function () use ($id, $timeStep): void {
            $this->perform(
                'UPDATE admin_account SET totp_last_time_step = :step WHERE id = :id',
                ['step' => $timeStep, 'id' => $id]
            );
        });
    }

    /**
     * Removes an admin account (R23.4).
     *
     * The domain rule that at least one account must remain (R23.5) is checked
     * beforehand in AccountManagement; this repository only performs the
     * deletion.
     */
    public function delete(int $id): void
    {
        $this->transactional('delete_admin_account', function () use ($id): void {
            $this->perform('DELETE FROM admin_account WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Returns an admin account by its id, or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, username, password_hash, totp_secret_encrypted, totp_last_time_step, locked_until, failed_attempts, session_version,
                    password_changed_at, password_bits, must_change_password
             FROM admin_account WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Returns an admin account by its unique username, or null (R23.2, R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        return $this->findOne(
            'SELECT id, username, password_hash, totp_secret_encrypted, totp_last_time_step, locked_until, failed_attempts, session_version,
                    password_changed_at, password_bits, must_change_password
             FROM admin_account WHERE username = :username',
            ['username' => $username]
        );
    }

    /**
     * Like {@see find()}, but locks the account row until the surrounding
     * transaction ends: login attempts on the same account run one after the
     * other, so the lock status read here is current and every failed attempt
     * is counted (R5.6). Only meaningful inside a transaction.
     *
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, username, password_hash, totp_secret_encrypted, totp_last_time_step, locked_until, failed_attempts, session_version,
                    password_changed_at, password_bits, must_change_password
             FROM admin_account WHERE id = :id FOR UPDATE',
            ['id' => $id]
        );
    }

    /**
     * Like {@see findByUsername()}, but locks the account row until the
     * surrounding transaction ends (see {@see findForUpdate()}).
     *
     * @return array<string, mixed>|null
     */
    public function findByUsernameForUpdate(string $username): ?array
    {
        return $this->findOne(
            'SELECT id, username, password_hash, totp_secret_encrypted, totp_last_time_step, locked_until, failed_attempts, session_version,
                    password_changed_at, password_bits, must_change_password
             FROM admin_account WHERE username = :username FOR UPDATE',
            ['username' => $username]
        );
    }

    /**
     * Checks whether a username is already taken (R23.2).
     */
    public function usernameExists(string $username): bool
    {
        return $this->findByUsername($username) !== null;
    }

    /**
     * Returns the total number of existing admin accounts.
     *
     * Basis for the rule that at least one account remains (R23.5).
     */
    public function countItems(): int
    {
        $row = $this->findOne('SELECT COUNT(*) AS quantity FROM admin_account');

        return (int) ($row['quantity'] ?? 0);
    }

    /**
     * Returns all admin accounts (R4.5).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllAccounts(): array
    {
        return $this->findAll(
            'SELECT id, username, password_hash, totp_secret_encrypted, totp_last_time_step, locked_until, failed_attempts, session_version,
                    password_changed_at, password_bits, must_change_password
             FROM admin_account ORDER BY username'
        );
    }

    /**
     * Binds a (potentially binary) encrypted secret to a prepared statement.
     *
     * The ciphertext from {@see \sodium_crypto_secretbox()} contains arbitrary
     * bytes; it is therefore bound as LOB. If there is no secret, NULL is
     * bound.
     */
    private function bindSecret(\PDOStatement $statement, string $placeholder, ?string $secret): void
    {
        if ($secret === null) {
            $statement->bindValue($placeholder, null, \PDO::PARAM_NULL);

            return;
        }

        $statement->bindValue($placeholder, $secret, \PDO::PARAM_LOB);
    }
}
