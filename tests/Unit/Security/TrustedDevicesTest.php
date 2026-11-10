<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\AuthModule;
use Bowlingliga\Security\LoginStatus;
use Bowlingliga\Security\PasswordHasher;
use Bowlingliga\Security\PhpDeviceCookie;
use Bowlingliga\Security\TotpSecretEncryption;
use Bowlingliga\Security\TotpVerifier;
use Bowlingliga\Security\TrustedDevices;
use Bowlingliga\Tests\Unit\Application\ImmediateTransaction;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;

/**
 * Unit test of the trusted devices in the login flow: somebody who knows a
 * username can lock the account for unknown devices, but not keep its owner
 * out of a browser in which the owner logged in before.
 *
 * Each "browser" has its own session and device cookie; accounts and devices
 * are shared like in the database.
 *
 * Validates: R5.6, R5.9, R5.10
 */
final class TrustedDevicesTest extends TestCase
{
    private const PASSWORD = 'super-geheimes-passwort';

    private InMemoryAuthAccountRepository $accounts;
    private InMemoryTrustedDeviceStore $devices;
    private PasswordHasher $hasher;
    private TotpSecretEncryption $encryption;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        if (! TotpSecretEncryption::isAvailable() || ! TotpVerifier::isAvailable()) {
            self::markTestSkipped('libsodium or otphp is not available.');
        }

        $this->accounts   = new InMemoryAuthAccountRepository();
        $this->devices    = new InMemoryTrustedDeviceStore();
        $this->hasher     = new PasswordHasher();
        $this->encryption = new TotpSecretEncryption(TotpSecretEncryption::generateKey());
        $this->now        = new \DateTimeImmutable('2026-11-10 19:00:00');
    }

    public function testACompleteLoginTrustsTheBrowserFor90Days(): void
    {
        $id     = $this->account();
        $cookie = new InMemoryDeviceCookie();

        self::assertSame(LoginStatus::SUCCESSFUL, $this->browser($cookie)->login('admin', self::PASSWORD)->status);

        self::assertNotNull($cookie->value);
        self::assertTrue(TrustedDevices::isWellFormedToken($cookie->value));
        self::assertSame(90 * 24 * 60 * 60, $cookie->lifetime);
        $device = $this->devices->of($id)[0];
        self::assertSame(hash('sha256', $cookie->value), $device['token_hash'], 'Only the hash of the token is stored.');
        self::assertSame('2027-02-08 19:00:00', $device['expires_at']);
    }

    public function testALockByAStrangerDoesNotKeepTheOwnerOut(): void
    {
        $id    = $this->account();
        $owner = new InMemoryDeviceCookie();
        $this->browser($owner)->login('admin', self::PASSWORD);

        // A stranger locks the account with five wrong passwords.
        $stranger = $this->browser(new InMemoryDeviceCookie());
        for ($i = 1; $i <= 5; $i++) {
            $stranger->login('admin', 'falsch');
        }
        self::assertSame(LoginStatus::ACCOUNT_LOCKED, $stranger->login('admin', self::PASSWORD)->status, 'Unknown devices stay locked out (R5.6).');
        self::assertNotNull($this->accounts->find($id)['locked_until']);

        $session = $this->browser($owner);
        self::assertSame(LoginStatus::SUCCESSFUL, $session->login('admin', self::PASSWORD)->status, 'The trusted browser of the owner gets in (R5.10).');
        self::assertTrue($session->checkWriteAccess());
    }

    public function testATrustedDeviceCountsItsOwnFailedAttemptsAndIsForgottenAfterFive(): void
    {
        $id     = $this->account();
        $cookie = new InMemoryDeviceCookie();
        $this->browser($cookie)->login('admin', self::PASSWORD);

        $auth = $this->browser($cookie);
        for ($i = 1; $i <= 4; $i++) {
            self::assertSame(LoginStatus::INVALID_CREDENTIALS, $auth->login('admin', 'falsch')->status);
        }
        self::assertSame(0, $this->accounts->find($id)['failed_attempts'], 'Typing errors on a trusted device do not lock the account.');
        self::assertSame(4, $this->devices->of($id)[0]['failed_attempts']);

        self::assertSame(LoginStatus::INVALID_CREDENTIALS, $auth->login('admin', 'falsch')->status);
        self::assertSame([], $this->devices->of($id), 'After five failed attempts the device is forgotten.');

        // From now on the browser is an unknown device: its attempts count for the account.
        $auth->login('admin', 'falsch');
        self::assertSame(1, $this->accounts->find($id)['failed_attempts']);
    }

    public function testATrustedDeviceExpiresAfter90Days(): void
    {
        $this->account();
        $cookie = new InMemoryDeviceCookie();
        $this->browser($cookie)->login('admin', self::PASSWORD);

        $this->now = $this->now->add(new \DateInterval('P91D'));
        $this->lockByStranger();

        self::assertSame(LoginStatus::ACCOUNT_LOCKED, $this->browser($cookie)->login('admin', self::PASSWORD)->status);
    }

    public function testEveryLoginRenewsTheDevice(): void
    {
        $id     = $this->account();
        $cookie = new InMemoryDeviceCookie();
        $this->browser($cookie)->login('admin', self::PASSWORD);
        $token = $cookie->value;

        $this->now = $this->now->add(new \DateInterval('P60D'));
        $this->browser($cookie)->login('admin', self::PASSWORD);

        self::assertSame($token, $cookie->value, 'The browser keeps its token.');
        self::assertCount(1, $this->devices->of($id));
        self::assertSame('2027-04-09 19:00:00', $this->devices->of($id)[0]['expires_at']);
    }

    public function testANewSessionVersionForgetsTheOtherDevicesButKeepsThisOne(): void
    {
        $id    = $this->account();
        $mine = new InMemoryDeviceCookie();
        $other = new InMemoryDeviceCookie();
        $auth  = $this->browser($mine);
        $auth->login('admin', self::PASSWORD);
        $this->browser($other)->login('admin', self::PASSWORD);
        self::assertCount(2, $this->devices->of($id));

        // E.g. "Alle anderen Sitzungen abmelden" or a new password (R5.9).
        $this->accounts->raiseSessionVersion($id);
        $auth->renewSession($id);

        self::assertCount(1, $this->devices->of($id));
        $this->lockByStranger();
        self::assertSame(LoginStatus::ACCOUNT_LOCKED, $this->browser($other)->login('admin', self::PASSWORD)->status);
        self::assertSame(LoginStatus::SUCCESSFUL, $this->browser($mine)->login('admin', self::PASSWORD)->status);
    }

    public function testOneBrowserCanBeTrustedForSeveralAccounts(): void
    {
        $anna   = $this->account('anna');
        $ben    = $this->account('ben');
        $shared = new InMemoryDeviceCookie();

        $this->browser($shared)->login('anna', self::PASSWORD);
        $token = $shared->value;
        $this->browser($shared)->login('ben', self::PASSWORD);

        self::assertSame($token, $shared->value, 'One token for the browser.');
        self::assertCount(1, $this->devices->of($anna));
        self::assertCount(1, $this->devices->of($ben));
    }

    public function testAtMostTenDevicesPerAccount(): void
    {
        $id = $this->account();
        for ($i = 1; $i <= 12; $i++) {
            $this->now = $this->now->add(new \DateInterval('PT1M'));
            $this->browser(new InMemoryDeviceCookie())->login('admin', self::PASSWORD);
        }

        self::assertCount(10, $this->devices->of($id));
    }

    public function testWithTwoFactorLoginTheDeviceIsTrustedOnlyAfterTheCode(): void
    {
        $secret = TOTP::generate()->getSecret();
        $id     = $this->accounts->create('admin', $this->hasher->hash(self::PASSWORD), $this->encryption->encrypt($secret));
        $cookie = new InMemoryDeviceCookie();
        $auth   = $this->browser($cookie);

        self::assertSame(LoginStatus::SECOND_FACTOR_REQUIRED, $auth->login('admin', self::PASSWORD)->status);
        self::assertNull($cookie->value, 'The password alone does not trust the browser.');

        $code = TOTP::createFromSecret($secret)->at($this->now->getTimestamp());
        self::assertSame(LoginStatus::SUCCESSFUL, $auth->confirmSecondFactor($code, $this->now->getTimestamp())->status);
        self::assertNotNull($cookie->value);
        self::assertCount(1, $this->devices->of($id));

        // During a lock the trusted browser still reaches and passes the second step.
        $this->lockByStranger();
        $this->now = $this->now->add(new \DateInterval('PT1M'));
        $again     = $this->browser($cookie);
        self::assertSame(LoginStatus::SECOND_FACTOR_REQUIRED, $again->login('admin', self::PASSWORD)->status);
        $code = TOTP::createFromSecret($secret)->at($this->now->getTimestamp());
        self::assertSame(LoginStatus::SUCCESSFUL, $again->confirmSecondFactor($code, $this->now->getTimestamp())->status);
    }

    public function testOnlyWellFormedTokensAreReadFromTheCookie(): void
    {
        $token = str_repeat('aB3_-', 8) . 'xyz';
        self::assertSame($token, (new PhpDeviceCookie([PhpDeviceCookie::NAME => $token], []))->token());

        foreach (['', 'kurz', $token . 'x', str_repeat('ä', 43), "' OR 1=1 --"] as $bad) {
            self::assertNull((new PhpDeviceCookie([PhpDeviceCookie::NAME => $bad], []))->token(), $bad);
        }
        self::assertNull((new PhpDeviceCookie([PhpDeviceCookie::NAME => ['array']], []))->token());
        self::assertNull((new PhpDeviceCookie([], []))->token());
    }

    private function account(string $username = 'admin'): int
    {
        return $this->accounts->create($username, $this->hasher->hash(self::PASSWORD), null);
    }

    /** A browser with its own session and device cookie. */
    private function browser(InMemoryDeviceCookie $cookie): AuthModule
    {
        return new AuthModule(
            $this->accounts,
            $this->hasher,
            $this->encryption,
            new TotpVerifier(),
            new InMemorySessionStore(),
            new ImmediateTransaction(),
            fn (): \DateTimeImmutable => $this->now,
            new TrustedDevices($this->devices, $cookie),
        );
    }

    private function lockByStranger(): void
    {
        $stranger = $this->browser(new InMemoryDeviceCookie());
        for ($i = 1; $i <= 5; $i++) {
            $stranger->login('admin', 'falsch');
        }
    }
}
