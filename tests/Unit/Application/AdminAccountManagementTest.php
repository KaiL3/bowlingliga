<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\AdminAccountManagement;
use Bowlingliga\Persistence\AdminAccountRepository;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\AccountException;
use Bowlingliga\Security\AccountManagement;
use Bowlingliga\Security\PasswordHasher;
use Bowlingliga\Security\SessionRenewal;
use Bowlingliga\Security\TotpSecretEncryption;
use Bowlingliga\Tests\GermanText;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the wired admin account management of the admin area (task
 * 21.4).
 *
 * Validates: R23.1, R23.2, R23.4, R23.5, R23.6, R5.1
 *
 * Checks that the existing {@see AccountManagement} is only executed via the
 * {@see AdminAccountManagement} use case with existing write access (R5.1) and
 * that the domain rules (unique username R23.2, keeping at least one account
 * R23.5) are passed on unchanged. Without a database via an in-memory fake of
 * the {@see AdminAccountRepository}; the TOTP setup is only checked if
 * libsodium is available.
 */
final class AdminAccountManagementTest extends TestCase
{
    private InMemoryAdminAccountRepositoryForApp $accounts;
    private SwitchableWriteAccess $access;
    private AdminAccountManagement $management;
    private RecordingSessionRenewal $sessions;

    protected function setUp(): void
    {
        if (! TotpSecretEncryption::isAvailable()) {
            self::markTestSkipped('libsodium is not available.');
        }

        $this->accounts  = new InMemoryAdminAccountRepositoryForApp();
        $this->access = new SwitchableWriteAccess(true);

        $accountManagement = new AccountManagement(
            $this->accounts,
            new PasswordHasher(),
            new TotpSecretEncryption(TotpSecretEncryption::generateKey()),
        );

        $this->sessions   = new RecordingSessionRenewal();
        $this->management = new AdminAccountManagement($accountManagement, $this->access, $this->sessions);
    }

    // --- R23.1 / R23.2: Anlegen ---

    public function testAccountIsCreatedWithWriteAccess(): void
    {
        $id = $this->management->createAccount('admin', 'passwort-123');

        self::assertNotNull($this->accounts->find($id));
        self::assertSame(1, $this->accounts->countItems());
    }

    public function testDuplicateUsernameIsPassedOn(): void
    {
        $this->management->createAccount('admin', 'passwort-1');

        $this->expectException(AccountException::class);
        $this->management->createAccount('admin', 'passwort-2');
    }

    // --- R23.4 / R23.5: removing, the last account stays ---

    public function testAccountCanBeRemovedWhenSeveralExist(): void
    {
        $firstId = $this->management->createAccount('admin1', 'passwort-1');
        $this->management->createAccount('admin2', 'passwort-2');

        $this->management->removeAccount($firstId);

        self::assertNull($this->accounts->find($firstId));
        self::assertSame(1, $this->accounts->countItems());
    }

    public function testLastAccountCannotBeRemoved(): void
    {
        $id = $this->management->createAccount('admin', 'passwort');

        $this->expectException(AccountException::class);
        try {
            $this->management->removeAccount($id);
        } finally {
            self::assertNotNull($this->accounts->find($id), 'The last account must remain (R23.5).');
        }
    }

    // --- R23.6: 2FA-Einrichtung ---

    public function testTotpCanBeSetUp(): void
    {
        $id = $this->management->createAccount('admin', 'passwort');

        $setup = $this->management->setUpTotp($id, 'admin');

        self::assertNotSame('', $setup->plaintextSecret);
        // R23.9: only the ciphertext is stored.
        $account = $this->accounts->find($id);
        self::assertNotNull($account['totp_secret_encrypted']);
        self::assertNotSame($setup->plaintextSecret, $account['totp_secret_encrypted']);
    }

    // --- 2FA in two steps: only active after a valid code ---

    public function testTotpProposalBecomesActiveOnlyWithValidCode(): void
    {
        $id = $this->management->createAccount('admin', 'passwort-123');

        $proposal = $this->management->generateTotpProposal($id, 'admin');
        self::assertFalse($this->management->isTotpActive($id), 'The proposal alone activates nothing.');
        self::assertStringStartsWith('otpauth://totp/', $proposal->provisioningUri);

        try {
            $this->management->activateTotp($id, $proposal->secretEncrypted, '000000' === $this->code($proposal->plaintextSecret) ? '111111' : '000000');
            self::fail('A wrong code must not activate (R23.8).');
        } catch (AccountException $e) {
            self::assertStringContainsString('ungültig', GermanText::of($e));
        }
        self::assertFalse($this->management->isTotpActive($id));

        $this->management->activateTotp($id, $proposal->secretEncrypted, $this->code($proposal->plaintextSecret));
        self::assertTrue($this->management->isTotpActive($id));
        self::assertSame($proposal->secretEncrypted, $this->accounts->find($id)['totp_secret_encrypted'], 'Only the ciphertext is stored (R23.9).');

        // Can be shown again (e.g. after a wrong code) with the same secret.
        self::assertSame($proposal->plaintextSecret, $this->management->totpProposalFrom($proposal->secretEncrypted, 'admin')->plaintextSecret);
    }

    public function testDisablingTotpRequiresPasswordAndCode(): void
    {
        $id = $this->management->createAccount('admin', 'passwort-123');
        $proposal = $this->management->generateTotpProposal($id, 'admin');
        $this->management->activateTotp($id, $proposal->secretEncrypted, $this->code($proposal->plaintextSecret));

        foreach ([['falsch', $this->code($proposal->plaintextSecret)], ['passwort-123', '']] as [$password, $code]) {
            try {
                $this->management->deactivateTotp($id, $password, $code);
                self::fail('It must not be switched off without password and code.');
            } catch (AccountException) {
            }
        }
        self::assertTrue($this->management->isTotpActive($id));

        $this->management->deactivateTotp($id, 'passwort-123', $this->code($proposal->plaintextSecret));
        self::assertFalse($this->management->isTotpActive($id));
    }

    public function testChangeOwnPassword(): void
    {
        $id = $this->management->createAccount('admin', 'passwort-123');
        $hasher = new PasswordHasher();

        foreach ([
            ['falsch', 'neues-passwort', 'neues-passwort', 'nicht korrekt'],
            ['passwort-123', 'kurz', 'kurz', 'mindestens 8'],
            ['passwort-123', 'neues-passwort', 'anderes-passwort', 'stimmen nicht'],
        ] as [$current, $fresh, $repetition, $message]) {
            try {
                $this->management->changeOwnPassword($id, $current, $fresh, $repetition);
                self::fail($message);
            } catch (AccountException $e) {
                self::assertStringContainsString($message, GermanText::of($e));
            }
        }
        self::assertTrue($hasher->check('passwort-123', (string) $this->accounts->find($id)['password_hash']));

        $this->management->changeOwnPassword($id, 'passwort-123', 'neues-passwort', 'neues-passwort');
        self::assertTrue($hasher->check('neues-passwort', (string) $this->accounts->find($id)['password_hash']));
    }

    // --- Ending other sessions ---

    public function testPasswordChangeEndsOtherSessionsAndKeepsOwn(): void
    {
        $id = $this->management->createAccount('admin', 'passwort-123');
        $this->sessions->renewed = [];

        // Failed attempts change nothing.
        try {
            $this->management->changeOwnPassword($id, 'falsch', 'neues-passwort', 'neues-passwort');
        } catch (AccountException) {
        }
        self::assertSame(1, $this->accounts->find($id)['session_version']);
        self::assertSame([], $this->sessions->renewed);

        $this->management->changeOwnPassword($id, 'passwort-123', 'neues-passwort', 'neues-passwort');

        self::assertSame(2, $this->accounts->find($id)['session_version'], 'Changing the password ends all other sessions.');
        self::assertSame([$id], $this->sessions->renewed, 'The own session takes over the new version.');
    }

    public function testEndOtherSessionsRaisesVersionAndKeepsOwn(): void
    {
        $id = $this->management->createAccount('admin', 'passwort-123');

        $this->management->endOtherSessions($id);

        self::assertSame(2, $this->accounts->find($id)['session_version']);
        self::assertSame([$id], $this->sessions->renewed);
    }

    public function testEndOtherSessionsOfUnknownAccountIsRejected(): void
    {
        $this->expectException(AccountException::class);

        $this->management->endOtherSessions(99);
    }

    public function testWithoutWriteAccessNoSessionsAreEnded(): void
    {
        $id = $this->management->createAccount('admin', 'passwort-123');
        $this->access->allowed = false;

        try {
            $this->management->endOtherSessions($id);
            self::fail('Without write access nothing may be changed.');
        } catch (AccessDeniedException) {
        }

        self::assertSame(1, $this->accounts->find($id)['session_version']);
        self::assertSame([], $this->sessions->renewed);
    }

    private function code(string $secret): string
    {
        return TOTP::createFromSecret($secret)->now();
    }

    // --- R5.1: Zugriffsschutz ---

    public function testWithoutWriteAccessNothingIsCreated(): void
    {
        $this->access->allowed = false;

        $this->expectException(AccessDeniedException::class);
        try {
            $this->management->createAccount('admin', 'passwort');
        } finally {
            self::assertSame(0, $this->accounts->countItems(), 'Without write access no account may be created (R5.1).');
        }
    }

    public function testWithoutWriteAccessNothingIsRemoved(): void
    {
        $id1 = $this->management->createAccount('admin1', 'passwort-1');
        $this->management->createAccount('admin2', 'passwort-2');
        $this->access->allowed = false;

        $this->expectException(AccessDeniedException::class);
        try {
            $this->management->removeAccount($id1);
        } finally {
            self::assertNotNull($this->accounts->find($id1), 'Without write access nothing may be removed (R5.1).');
        }
    }

    public function testWithoutWriteAccessTotpIsNotSetUp(): void
    {
        $id = $this->management->createAccount('admin', 'passwort');
        $this->access->allowed = false;

        $this->expectException(AccessDeniedException::class);
        $this->management->setUpTotp($id, 'admin');
    }
}

/**
 * In-memory fake of the {@see AdminAccountRepository} for the admin account
 * management tests. Reproduces the methods used by AccountManagement without
 * a database; the base constructor is deliberately not called.
 */
/**
 * Records for which accounts the own session took over the new version.
 */
final class RecordingSessionRenewal implements SessionRenewal
{
    /** @var list<int> */
    public array $renewed = [];

    public function renewSession(int $accountId): void
    {
        $this->renewed[] = $accountId;
    }
}

final class InMemoryAdminAccountRepositoryForApp extends AdminAccountRepository
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
            'id'                         => $id,
            'username'               => $username,
            'password_hash'              => $passwordHash,
            'totp_secret_encrypted' => $totpSecretEncrypted,
            'locked_until'               => null,
            'failed_attempts'               => 0,
            'session_version' => 1,
        ];

        return $id;
    }

    public function saveTotpSecret(int $id, ?string $totpSecretEncrypted): void
    {
        if (isset($this->accounts[$id])) {
            $this->accounts[$id]['totp_secret_encrypted'] = $totpSecretEncrypted;
            $this->accounts[$id]['session_version']++;
        }
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        if (isset($this->accounts[$id])) {
            $this->accounts[$id]['password_hash'] = $passwordHash;
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
}
