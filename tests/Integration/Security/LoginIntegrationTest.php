<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\Security;

use Bowlingliga\Persistence\AdminAccountRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\PdoTransaction;
use Bowlingliga\Security\AuthModule;
use Bowlingliga\Security\LoginStatus;
use Bowlingliga\Security\PasswordHasher;
use Bowlingliga\Security\SessionStore;
use Bowlingliga\Security\TotpSecretEncryption;
use Bowlingliga\Security\TotpVerifier;
use Bowlingliga\Tests\GermanText;
use OTPHP\TOTP;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Integration test of the complete login flow of the auth module against a
 * local MySQL test database (task 20.3).
 *
 * Validates: R5.3, R5.6, R23.7, R23.8
 *
 * - R23.7: with two-factor authentication enabled, a session is
 *   only set up after successfully checking the password AND a valid TOTP
 *   code.
 * - R23.8: an invalid/expired TOTP code denies access with a
 *   specific note about the second factor.
 * - R5.3: invalid credentials are rejected generically, without
 *   revealing whether the username or the password is wrong.
 * - R5.6: after 5 consecutive failed attempts the account is
 *   locked temporarily; the failed attempts counter and the lock status are
 *   really updated in the database.
 *
 * Unlike the pure unit test (which reproduces repository and session in
 * memory) this test uses the real {@see AdminAccountRepository} against MySQL,
 * so that the transactional write paths for the failed attempts counter and
 * lock status are checked as well. The TOTP check runs deterministically with
 * a fixed time.
 *
 * The test needs a reachable local MySQL test database, the PDO MySQL driver
 * and the extensions libsodium (TOTP secret) and otphp. If any of them is
 * missing, the test is skipped in a controlled way (markTestSkipped) instead
 * of failing. The connection parameters are read only from environment
 * variables (no credentials in the source code):
 *
 *   BOWLINGLIGA_TEST_DB_HOST     (default: 127.0.0.1)
 *   BOWLINGLIGA_TEST_DB_PORT     (default: 3306)
 *   BOWLINGLIGA_TEST_DB_NAME     (default: bowlingliga_test)
 *   BOWLINGLIGA_TEST_DB_USER     (default: root)
 *   BOWLINGLIGA_TEST_DB_PASSWORD (default: empty)
 *   BOWLINGLIGA_TEST_DB_CHARSET  (default: utf8mb4)
 *
 * The table `admin_account` is created per test and removed afterwards, so that
 * the test is repeatable and runs without migration prerequisites.
 */
final class LoginIntegrationTest extends TestCase
{
    private const PASSWORD = 'super-geheimes-passwort';

    private Database $database;
    private PDO $pdo;
    private AdminAccountRepository $accounts;
    private PasswordHasher $hasher;
    private TotpSecretEncryption $encryption;
    private \DateTimeImmutable $currentTime;

    protected function setUp(): void
    {
        if (! in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The PDO MySQL driver is not available; integration test skipped.');
        }

        if (! TotpSecretEncryption::isAvailable()) {
            self::markTestSkipped('libsodium is not available; integration test skipped.');
        }

        if (! TotpVerifier::isAvailable()) {
            self::markTestSkipped('otphp (TOTP) is not available; integration test skipped.');
        }

        $this->database = new Database($this->testConfiguration());

        try {
            $this->pdo = $this->database->pdo();
            $this->pdo->query('SELECT 1');
        } catch (Throwable $throwable) {
            self::markTestSkipped('No reachable MySQL test database: ' . $throwable->getMessage());
        }

        $this->createAdminAccountTable();

        $this->accounts           = new AdminAccountRepository($this->database);
        $this->hasher           = new PasswordHasher();
        $this->encryption = new TotpSecretEncryption(TotpSecretEncryption::generateKey());
        $this->currentTime            = new \DateTimeImmutable('2026-01-01 12:00:00');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // Suspend the FK check briefly (see createAdminAccountTable): in a
            // migrated test DB `login_attempt` references `admin_account`.
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->pdo->exec('DROP TABLE IF EXISTS admin_account');
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * R23.7: a complete login with active 2FA and a valid TOTP code sets up an
     * authenticated session.
     */
    public function testCompleteLoginWith2faAndValidCode(): void
    {
        [$id, $secret] = $this->createAccountWith2fa('admin');
        $auth          = $this->auth();

        $result = $auth->login('admin', self::PASSWORD, $this->validCode($secret), $this->currentTime->getTimestamp());

        self::assertSame(LoginStatus::SUCCESSFUL, $result->status);
        self::assertSame($id, $auth->loggedInAccount());
    }

    /**
     * R5.6: a login attempt locks the account row until it is finished, so
     * parallel attempts run one after the other and none of them gets past the
     * lock check while another one is about to lock the account. Here a second
     * connection holds the row (an attempt in progress): even with the correct
     * password – a path that writes nothing – the login waits before checking
     * anything instead of reading a lock status that is about to change.
     */
    public function testLoginWaitsForAParallelAttemptOnTheSameAccount(): void
    {
        $this->createAccountWithout2fa('admin');

        $other = (new Database($this->testConfiguration()))->pdo();
        $other->beginTransaction();
        $other->query("SELECT id FROM admin_account WHERE username = 'admin' FOR UPDATE");
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $auth = $this->auth();

        try {
            $auth->login('admin', self::PASSWORD);
            self::fail('The login must not bypass the locked account row.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('1205', $e->getMessage(), 'Expected: lock wait timeout exceeded.');
        } finally {
            $other->rollBack();
            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }

        self::assertFalse($auth->checkWriteAccess(), 'The waiting attempt has not set up a session.');

        // Once the row is free again, the login runs normally.
        self::assertTrue($this->auth()->login('admin', self::PASSWORD)->isSuccessful());
    }

    /**
     * Changing the password or the two-factor login and "log out all other
     * sessions" raise the session version in the database; an open session of
     * the account ends, a renewed one stays logged in.
     */
    public function testChangesOfTheAccountEndOpenSessions(): void
    {
        $id = $this->createAccountWithout2fa('admin');

        foreach ([
            'Passwortwechsel'  => fn () => $this->accounts->updatePasswordHash($id, $this->hasher->hash('neues-passwort')),
            '2FA-Änderung'     => fn () => $this->accounts->saveTotpSecret($id, null),
            'Sitzungen beenden' => fn () => $this->accounts->raiseSessionVersion($id),
        ] as $step => $change) {
            $password = $step === 'Passwortwechsel' ? self::PASSWORD : 'neues-passwort';
            $other    = $this->auth();
            $own      = $this->auth();
            self::assertTrue($other->login('admin', $password)->isSuccessful(), $step);
            self::assertTrue($own->login('admin', $password)->isSuccessful(), $step);

            $change();
            $own->renewSession($id);

            self::assertFalse($other->checkWriteAccess(), "$step beendet die andere Sitzung.");
            self::assertTrue($own->checkWriteAccess(), "$step lässt die eigene Sitzung angemeldet.");
        }
    }

    /**
     * R23.8: with active 2FA an invalid TOTP code denies access with a specific
     * note about the second factor.
     */
    public function testLoginWith2faAndInvalidCodeIsRejected(): void
    {
        $this->createAccountWith2fa('admin');
        $auth = $this->auth();

        $result = $auth->login('admin', self::PASSWORD, '000000', $this->currentTime->getTimestamp());

        self::assertSame(LoginStatus::INVALID_SECOND_FACTOR, $result->status);
        self::assertFalse($auth->checkWriteAccess());
    }

    /**
     * R5.3: invalid credentials are rejected generically.
     */
    public function testWrongPasswordIsRejectedGenerically(): void
    {
        $this->createAccountWithout2fa('admin');
        $auth = $this->auth();

        $result = $auth->login('admin', 'falsch');

        self::assertSame(LoginStatus::INVALID_CREDENTIALS, $result->status);
        self::assertStringNotContainsStringIgnoringCase('passwort', GermanText::of($result->message));
        self::assertStringNotContainsStringIgnoringCase('benutzer', GermanText::of($result->message));
    }

    /**
     * R5.6: after 5 consecutive failed attempts the account is locked; the
     * lock status is really stored in the database and denies access even with
     * the correct password.
     */
    public function testAccountLockAfterFiveFailedAttemptsAgainstDatabase(): void
    {
        $id   = $this->createAccountWithout2fa('admin');
        $auth = $this->auth();

        for ($i = 1; $i <= 4; $i++) {
            self::assertSame(
                LoginStatus::INVALID_CREDENTIALS,
                $auth->login('admin', 'falsch')->status,
                "Attempt$i must not lock yet."
            );
        }

        // The fifth failed attempt locks the account (R5.6).
        self::assertSame(LoginStatus::ACCOUNT_LOCKED, $auth->login('admin', 'falsch')->status);

        // The lock status is really stored in the database.
        $account = $this->accounts->find($id);
        self::assertNotNull($account);
        self::assertNotNull($account['locked_until']);

        // Even with the correct password access stays denied during the lock.
        self::assertSame(LoginStatus::ACCOUNT_LOCKED, $auth->login('admin', self::PASSWORD)->status);

        // After the 15 minute lock has expired, logging in is possible again.
        $this->currentTime = $this->currentTime->add(new \DateInterval('PT16M'));
        self::assertSame(LoginStatus::SUCCESSFUL, $this->auth()->login('admin', self::PASSWORD)->status);
    }

    /**
     * Creates the auth module with the current (fixed) test time and an
     * in-memory session store (the session is not the subject of the DB
     * integration).
     */
    private function auth(): AuthModule
    {
        return new AuthModule(
            $this->accounts,
            $this->hasher,
            $this->encryption,
            new TotpVerifier(),
            new IntegrationSessionStore(),
            new PdoTransaction($this->database),
            fn (): \DateTimeImmutable => $this->currentTime,
        );
    }

    private function createAccountWithout2fa(string $username): int
    {
        return $this->accounts->create($username, $this->hasher->hash(self::PASSWORD), null);
    }

    /**
     * @return array{0: int, 1: string} [Konto-ID, Klartext-Secret]
     */
    private function createAccountWith2fa(string $username): array
    {
        $secret        = TOTP::generate()->getSecret();
        $encrypter = $this->encryption->encrypt($secret);
        $id            = $this->accounts->create($username, $this->hasher->hash(self::PASSWORD), $encrypter);

        return [$id, $secret];
    }

    private function validCode(string $secret): string
    {
        return TOTP::createFromSecret($secret)->at($this->currentTime->getTimestamp());
    }

    private function createAdminAccountTable(): void
    {
        // Suspend the FK check briefly for dropping: in an already
        // migrated test DB the table `login_attempt` references `admin_account`
        // via a foreign key, so that a direct DROP would otherwise fail with
        // error 1451. This test runs `admin_account` in isolation.
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS admin_account');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->pdo->exec(
            'CREATE TABLE admin_account (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                username VARCHAR(100) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                totp_secret_encrypted VARBINARY(512) NULL,
                totp_last_time_step BIGINT UNSIGNED NULL,
                locked_until DATETIME NULL,
                failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                session_version INT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                CONSTRAINT uq_adminkonto_benutzername UNIQUE (username)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function testConfiguration(): array
    {
        return [
            'host'            => self::env('BOWLINGLIGA_TEST_DB_HOST', '127.0.0.1'),
            'port'            => (int) self::env('BOWLINGLIGA_TEST_DB_PORT', '3306'),
            'dbname'          => self::env('BOWLINGLIGA_TEST_DB_NAME', 'bowlingliga_test'),
            'user'            => self::env('BOWLINGLIGA_TEST_DB_USER', 'root'),
            'password'        => self::env('BOWLINGLIGA_TEST_DB_PASSWORD', ''),
            'charset'         => self::env('BOWLINGLIGA_TEST_DB_CHARSET', 'utf8mb4'),
            'connect_timeout' => 5,
        ];
    }

    private static function env(string $name, string $standard): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $standard : $value;
    }
}

/**
 * Slim in-memory session store for the integration test. The session itself
 * is not the subject of this DB integration test; what is checked are the
 * account write paths running against MySQL (failed attempts, lock status).
 */
final class IntegrationSessionStore implements SessionStore
{
    /** @var array<string, int|string> */
    private array $values = [];

    public function set(string $key, int|string $value): void
    {
        $this->values[$key] = $value;
    }

    public function retrieve(string $key): int|string|null
    {
        return $this->values[$key] ?? null;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    public function regenerateId(): void
    {
    }

    public function end(): void
    {
        $this->values = [];
    }
}
