<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Persistence\AdminAccountRepository;
use Bowlingliga\Security\AccountException;
use Bowlingliga\Security\AccountManagement;
use Bowlingliga\Security\PasswordHasher;
use Bowlingliga\Security\TotpSecretEncryption;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the account management of the auth module (task 20.1).
 *
 * Validates: R23.1, R23.2, R23.3, R23.4, R23.5, R23.6, R23.9
 *
 * R23.1–R23.2: create accounts with a unique username.
 * R23.3: store passwords only as hash.
 * R23.4–R23.5: remove accounts, but keep at least one account.
 * R23.6, R23.9: the TOTP setup returns the plaintext secret once and
 * stores only the encrypted form.
 *
 * Instead of a real database an in-memory repository is used that reproduces
 * the repository methods relevant for the account management.
 */
final class AccountManagementTest extends TestCase
{
    private InMemoryAdminAccountRepository $accounts;
    private AccountManagement $management;

    protected function setUp(): void
    {
        if (! TotpSecretEncryption::isAvailable()) {
            self::markTestSkipped(
                'The libsodium extension is not installed; the '
                . 'TOTP setup cannot be tested.'
            );
        }

        $this->accounts     = new InMemoryAdminAccountRepository();
        $this->management = new AccountManagement(
            $this->accounts,
            new PasswordHasher(),
            new TotpSecretEncryption(TotpSecretEncryption::generateKey())
        );
    }

    /**
     * R23.1–R23.3: a created account has the username and a hash (not
     * the plaintext password) stored.
     */
    public function testTooShortPasswordIsRejectedOnCreate(): void
    {
        $this->expectException(AccountException::class);
        $this->expectExceptionMessage('error.account.password_too_short');
        $this->management->createAccount('admin', 'kurz');
    }

    public function testAccountIsCreatedWithHashedPassword(): void
    {
        $id = $this->management->createAccount('admin', 'super-geheim');

        $account = $this->accounts->find($id);
        self::assertNotNull($account);
        self::assertSame('admin', $account['username']);
        self::assertNotSame('super-geheim', $account['password_hash']);
        self::assertTrue((new PasswordHasher())->check('super-geheim', $account['password_hash']));
    }

    /**
     * R23.2: a username already taken is rejected.
     */
    public function testDuplicateUsernameIsRejected(): void
    {
        $this->management->createAccount('admin', 'passwort-1');

        $this->expectException(AccountException::class);
        $this->management->createAccount('admin', 'passwort-2');
    }

    /**
     * R23.1–R23.2: an empty username is rejected.
     */
    public function testEmptyUsernameIsRejected(): void
    {
        $this->expectException(AccountException::class);
        $this->management->createAccount('   ', 'passwort');
    }

    /**
     * R23.4: if more than one account exists, an account can be
     * removed.
     */
    public function testAccountCanBeRemovedWhenSeveralExist(): void
    {
        $firstId  = $this->management->createAccount('admin1', 'passwort-1');
        $this->management->createAccount('admin2', 'passwort-2');

        $this->management->removeAccount($firstId);

        self::assertNull($this->accounts->find($firstId));
        self::assertSame(1, $this->accounts->countItems());
    }

    /**
     * R23.5: the last remaining account must not be removed; it
     * stays unchanged.
     */
    public function testLastAccountCannotBeRemoved(): void
    {
        $id = $this->management->createAccount('admin', 'passwort');

        try {
            $this->management->removeAccount($id);
            self::fail('Removing the last account should have been rejected.');
        } catch (AccountException) {
            // expected
        }

        self::assertNotNull($this->accounts->find($id));
        self::assertSame(1, $this->accounts->countItems());
    }

    /**
     * R38.4: the authenticator app shows the league name as issuer, also when
     * a proposal is shown again; without a name the default of a new
     * installation (R38.2).
     */
    public function testTotpIssuerIsTheLeagueName(): void
    {
        $management = new AccountManagement(
            $this->accounts,
            new PasswordHasher(),
            new TotpSecretEncryption(TotpSecretEncryption::generateKey()),
            issuer: 'Pin & Spare Liga',
        );
        $id = $management->createAccount('admin', 'passwort');

        $proposal = $management->generateTotpProposal($id, 'admin');

        self::assertStringContainsString('issuer=Pin%20%26%20Spare%20Liga', $proposal->provisioningUri);
        self::assertStringContainsString('issuer=Pin%20%26%20Spare%20Liga', $management->totpProposalFrom($proposal->secretEncrypted, 'admin')->provisioningUri);
        self::assertStringContainsString('issuer=Bowlingliga', $this->management->setUpTotp($this->management->createAccount('zweiter', 'passwort'), 'zweiter')->provisioningUri);
    }

    /**
     * R23.6, R23.9: the TOTP setup returns a plaintext secret once and
     * stores only the encrypted form.
     */
    public function testTotpSetupReturnsPlaintextOnceAndStoresEncrypted(): void
    {
        $id = $this->management->createAccount('admin', 'passwort');

        $setup = $this->management->setUpTotp($id, 'admin');

        self::assertNotSame('', $setup->plaintextSecret);
        self::assertStringContainsString('otpauth://', $setup->provisioningUri);

        // R23.9: only the ciphertext is stored (not the plaintext).
        $account = $this->accounts->find($id);
        self::assertNotNull($account);
        self::assertNotNull($account['totp_secret_encrypted']);
        self::assertNotSame($setup->plaintextSecret, $account['totp_secret_encrypted']);
        self::assertStringNotContainsString(
            $setup->plaintextSecret,
            (string) $account['totp_secret_encrypted']
        );
    }

    /**
     * R23.9: the stored ciphertext can be decrypted again with the
     * same key to the plaintext secret shown.
     */
    public function testStoredSecretDecryptsToPlaintext(): void
    {
        $encryption = new TotpSecretEncryption(TotpSecretEncryption::generateKey());
        $management       = new AccountManagement($this->accounts, new PasswordHasher(), $encryption);

        $id          = $management->createAccount('admin', 'passwort');
        $setup = $management->setUpTotp($id, 'admin');

        $account = $this->accounts->find($id);
        self::assertNotNull($account);
        self::assertSame(
            $setup->plaintextSecret,
            $encryption->decrypt((string) $account['totp_secret_encrypted'])
        );
    }
}

/**
 * In-memory fake of the {@see AdminAccountRepository} for unit tests.
 *
 * Overrides only the methods used by the account management and keeps the
 * accounts in an array, so that no database is needed. The database
 * constructor of the base class is deliberately not called.
 */
final class InMemoryAdminAccountRepository extends AdminAccountRepository
{
    /** @var array<int, array<string, mixed>> */
    private array $accounts = [];

    private int $nextId = 1;

    /** @phpstan-ignore-next-line: base constructor deliberately not called (no DB access in the test). */
    public function __construct()
    {
    }

    public function create(
        string $username,
        string $passwordHash,
        ?string $totpSecretEncrypted = null
    ): int {
        $id = $this->nextId++;
        $this->accounts[$id] = [
            'id'                          => $id,
            'username'                => $username,
            'password_hash'               => $passwordHash,
            'totp_secret_encrypted'  => $totpSecretEncrypted,
            'locked_until'                => null,
            'failed_attempts'                => 0,
            'session_version' => 1,
        ];

        return $id;
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        if (isset($this->accounts[$id])) {
            $this->accounts[$id]['password_hash'] = $passwordHash;
            $this->accounts[$id]['session_version']++;
        }
    }

    public function saveTotpSecret(int $id, ?string $totpSecretEncrypted): void
    {
        if (isset($this->accounts[$id])) {
            $this->accounts[$id]['totp_secret_encrypted'] = $totpSecretEncrypted;
            $this->accounts[$id]['session_version']++;
        }
    }

    public function raiseSessionVersion(int $id): void
    {
        if (isset($this->accounts[$id])) {
            $this->accounts[$id]['session_version']++;
        }
    }

    public function delete(int $id): void
    {
        unset($this->accounts[$id]);
    }

    public function find(int $id): ?array
    {
        return $this->accounts[$id] ?? null;
    }

    public function findForUpdate(int $id): ?array
    {
        return $this->find($id);
    }

    public function findByUsernameForUpdate(string $username): ?array
    {
        return $this->findByUsername($username);
    }

    public function findByUsername(string $username): ?array
    {
        foreach ($this->accounts as $account) {
            if ($account['username'] === $username) {
                return $account;
            }
        }

        return null;
    }

    public function usernameExists(string $username): bool
    {
        return $this->findByUsername($username) !== null;
    }

    public function countItems(): int
    {
        return count($this->accounts);
    }

    public function findAllAccounts(): array
    {
        return array_values($this->accounts);
    }
}
