<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\AuthModule;
use Bowlingliga\Security\LoginStatus;
use Bowlingliga\Security\PasswordHasher;
use Bowlingliga\Security\TotpSecretEncryption;
use Bowlingliga\Security\TotpVerifier;
use Bowlingliga\Tests\GermanText;
use Bowlingliga\Tests\Unit\Application\ImmediateTransaction;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the login, 2FA and session flow of the auth module (task
 * 20.2).
 *
 * Validates: R5.1, R5.2, R5.3, R5.4, R5.5, R5.6, R5.7, R23.7, R23.8
 *
 * R5.2, R5.7: authenticated session with a 30 minute inactivity
 * timeout; after it expires write access is no longer possible.
 * R5.3: generic error message for invalid credentials.
 * R5.5: logging out ends the session.
 * R5.6: account lock after 5 consecutive failed attempts.
 * R23.7, R23.8: with active 2FA only with a valid TOTP code; specific
 * note about an invalid second factor.
 *
 * The flow is checked without a real database and without a real PHP
 * session: an in-memory repository reproduces the account raw data (including
 * failed attempts and lock status), an in-memory session store the session.
 * The time is fixed via an injected, changeable clock, so that timeout and
 * lock window can be checked deterministically. For the 2FA check the real
 * {@see TotpVerifier} is used with a real secret generated via otphp.
 */
final class AuthModuleTest extends TestCase
{
    private const PASSWORD = 'super-geheimes-passwort';

    private InMemoryAuthAccountRepository $accounts;
    private InMemorySessionStore $session;
    private PasswordHasher $hasher;
    private TotpSecretEncryption $encryption;
    private \DateTimeImmutable $currentTime;

    protected function setUp(): void
    {
        if (! TotpSecretEncryption::isAvailable()) {
            self::markTestSkipped('libsodium is not available.');
        }

        if (! TotpVerifier::isAvailable()) {
            self::markTestSkipped('otphp (TOTP) is not available.');
        }

        $this->accounts           = new InMemoryAuthAccountRepository();
        $this->session          = new InMemorySessionStore();
        $this->hasher           = new PasswordHasher();
        $this->encryption = new TotpSecretEncryption(TotpSecretEncryption::generateKey());
        $this->currentTime            = new \DateTimeImmutable('2026-01-01 12:00:00');
    }

    /**
     * Creates the auth module with the current (fixed) test time.
     */
    private function auth(): AuthModule
    {
        return new AuthModule(
            $this->accounts,
            $this->hasher,
            $this->encryption,
            new TotpVerifier(),
            $this->session,
            new ImmediateTransaction(),
            fn (): \DateTimeImmutable => $this->currentTime,
        );
    }

    /**
     * Creates an account without 2FA and returns its id.
     */
    private function createAccountWithout2fa(string $username = 'admin'): int
    {
        return $this->accounts->create($username, $this->hasher->hash(self::PASSWORD), null);
    }

    /**
     * Creates an account with active 2FA and returns [id, plaintext secret].
     *
     * @return array{0: int, 1: string}
     */
    private function createAccountWith2fa(string $username = 'admin'): array
    {
        $secret       = TOTP::generate()->getSecret();
        $encrypter = $this->encryption->encrypt($secret);
        $id           = $this->accounts->create($username, $this->hasher->hash(self::PASSWORD), $encrypter);

        return [$id, $secret];
    }

    /**
     * Calculates a valid TOTP code for a secret at the fixed test time.
     */
    private function validCode(string $secret): string
    {
        return TOTP::createFromSecret($secret)->at($this->currentTime->getTimestamp());
    }

    // --- R5.2: successful login without 2FA sets up the session ---

    public function testSuccessfulLoginWithout2faSetsUpSession(): void
    {
        $id   = $this->createAccountWithout2fa();
        $auth = $this->auth();

        $result = $auth->login('admin', self::PASSWORD);

        self::assertSame(LoginStatus::SUCCESSFUL, $result->status);
        self::assertTrue($result->isSuccessful());
        self::assertTrue($auth->checkWriteAccess(), 'After a successful login there must be write access (R5.2).');
        self::assertSame($id, $auth->loggedInAccount());
        self::assertTrue($this->session->idRegenerated, 'The session id must be regenerated (R5.2).');
    }

    public function testDeletedAccountLosesOpenSession(): void
    {
        $id   = $this->createAccountWithout2fa();
        $auth = $this->auth();
        $auth->login('admin', self::PASSWORD);
        self::assertTrue($auth->checkWriteAccess());

        $this->accounts->delete($id);

        self::assertFalse($auth->checkWriteAccess(), 'A deleted account must no longer have write access.');
        self::assertNull($this->session->retrieve(AuthModule::SESSION_ACCOUNT_ID), 'The session is ended.');
    }

    /**
     * R5.3: even for an unknown username a valid hash of the same method is
     * checked, so that the response time does not reveal the username.
     */
    public function testUnknownUserVerifiesValidDummyHash(): void
    {
        $dummy = (new \ReflectionMethod(AuthModule::class, 'dummyHash'))->invoke($this->auth());

        self::assertSame(
            password_get_info($this->hasher->hash('x'))['algo'],
            password_get_info($dummy)['algo'],
            'The dummy hash uses the method of real passwords.'
        );
        self::assertSame(LoginStatus::INVALID_CREDENTIALS, $this->auth()->login('gibtsnicht', self::PASSWORD)->status);
    }

    // --- R5.3: generic error message without disclosure ---

    public function testWrongPasswordReturnsGenericMessage(): void
    {
        $this->createAccountWithout2fa();
        $auth = $this->auth();

        $result = $auth->login('admin', 'falsch');

        self::assertSame(LoginStatus::INVALID_CREDENTIALS, $result->status);
        self::assertFalse($auth->checkWriteAccess());
        // The message must not reveal whether the username or the password is wrong.
        self::assertStringNotContainsStringIgnoringCase('passwort', GermanText::of($result->message));
        self::assertStringNotContainsStringIgnoringCase('benutzer', GermanText::of($result->message));
    }

    public function testUnknownUserReturnsGenericMessage(): void
    {
        $auth = $this->auth();

        $result = $auth->login('gibtsnicht', self::PASSWORD);

        self::assertSame(LoginStatus::INVALID_CREDENTIALS, $result->status);
        self::assertFalse($auth->checkWriteAccess());
    }

    // --- R23.7/R23.8: 2FA ---

    public function testLoginWith2faAndValidCodeIsSuccessful(): void
    {
        [$id, $secret] = $this->createAccountWith2fa();
        $auth          = $this->auth();

        $result = $auth->login('admin', self::PASSWORD, $this->validCode($secret), $this->currentTime->getTimestamp());

        self::assertSame(LoginStatus::SUCCESSFUL, $result->status);
        self::assertSame($id, $auth->loggedInAccount());
    }

    // --- Two-step login with active 2FA (R23.7, R23.8) ---

    public function testLoginWith2faWithoutCodeRequiresSecondStepWithoutFailedAttempt(): void
    {
        [$id, $secret] = $this->createAccountWith2fa();
        $auth = $this->auth();

        $result = $auth->login('admin', self::PASSWORD, null, $this->currentTime->getTimestamp());

        self::assertSame(LoginStatus::SECOND_FACTOR_REQUIRED, $result->status);
        self::assertFalse($auth->checkWriteAccess(), 'There is no access before the code.');
        self::assertTrue($auth->awaitsSecondFactor());
        self::assertSame(0, $this->accounts->find($id)['failed_attempts'], 'The missing code is no failed attempt.');

        $result = $auth->confirmSecondFactor($this->validCode($secret), $this->currentTime->getTimestamp());

        self::assertSame(LoginStatus::SUCCESSFUL, $result->status);
        self::assertSame($id, $auth->loggedInAccount());
        self::assertFalse($auth->awaitsSecondFactor());
    }

    public function testWrongCodeInSecondStepCountsAsFailedAttemptUntilLock(): void
    {
        [$id] = $this->createAccountWith2fa();
        $auth = $this->auth();
        $auth->login('admin', self::PASSWORD, null, $this->currentTime->getTimestamp());

        $result = $auth->confirmSecondFactor('000000', $this->currentTime->getTimestamp());
        self::assertSame(LoginStatus::INVALID_SECOND_FACTOR, $result->status);
        self::assertSame(1, $this->accounts->find($id)['failed_attempts']);
        self::assertTrue($auth->awaitsSecondFactor(), 'After a wrong code another attempt is allowed.');

        for ($i = 2; $i <= AuthModule::MAX_FAILED_ATTEMPTS; $i++) {
            $result = $auth->confirmSecondFactor('000000', $this->currentTime->getTimestamp());
        }
        self::assertSame(LoginStatus::ACCOUNT_LOCKED, $result->status, 'Codes cannot be tried one after another (R5.6).');
        self::assertFalse($auth->awaitsSecondFactor());
        self::assertFalse($auth->checkWriteAccess());
    }

    public function testSecondStepExpiresAfterFiveMinutes(): void
    {
        [, $secret] = $this->createAccountWith2fa();
        $auth = $this->auth();
        $auth->login('admin', self::PASSWORD, null, $this->currentTime->getTimestamp());

        $this->currentTime = $this->currentTime->modify('+' . (AuthModule::SECOND_FACTOR_TIMEOUT_SECONDS + 1) . ' seconds');

        self::assertFalse($auth->awaitsSecondFactor());
        $result = $auth->confirmSecondFactor($this->validCode($secret), $this->currentTime->getTimestamp());
        self::assertSame(LoginStatus::LOGIN_EXPIRED, $result->status);
        self::assertFalse($auth->checkWriteAccess());
    }

    public function testSecondStepWithoutFirstStepIsNotPossible(): void
    {
        [, $secret] = $this->createAccountWith2fa();
        $auth = $this->auth();

        $result = $auth->confirmSecondFactor($this->validCode($secret), $this->currentTime->getTimestamp());

        self::assertSame(LoginStatus::LOGIN_EXPIRED, $result->status);
        self::assertFalse($auth->checkWriteAccess());
    }

    public function testCancelDiscardsTheSecondStep(): void
    {
        [, $secret] = $this->createAccountWith2fa();
        $auth = $this->auth();
        $auth->login('admin', self::PASSWORD, null, $this->currentTime->getTimestamp());

        $auth->cancelSecondFactor();

        self::assertSame(LoginStatus::LOGIN_EXPIRED, $auth->confirmSecondFactor($this->validCode($secret), $this->currentTime->getTimestamp())->status);
    }

    public function testTheSameCodeIsValidOnlyOnce(): void
    {
        [$id, $secret] = $this->createAccountWith2fa();
        $code = $this->validCode($secret);

        self::assertSame(LoginStatus::SUCCESSFUL, $this->auth()->login('admin', self::PASSWORD, $code, $this->currentTime->getTimestamp())->status);
        $this->auth()->logout();

        $repeated = $this->auth()->login('admin', self::PASSWORD, $code, $this->currentTime->getTimestamp());
        self::assertSame(LoginStatus::INVALID_SECOND_FACTOR, $repeated->status, 'A code already used is rejected (R23.8).');

        // The code of the next time window is valid again.
        $this->currentTime = $this->currentTime->modify('+30 seconds');
        self::assertSame(LoginStatus::SUCCESSFUL, $this->auth()->login('admin', self::PASSWORD, $this->validCode($secret), $this->currentTime->getTimestamp())->status);
        self::assertSame($id, $this->auth()->loggedInAccount());
    }

    public function testLoginWith2faAndWrongCodePointsToSecondFactor(): void
    {
        $this->createAccountWith2fa();
        $auth = $this->auth();

        $result = $auth->login('admin', self::PASSWORD, '000000', $this->currentTime->getTimestamp());

        self::assertSame(LoginStatus::INVALID_SECOND_FACTOR, $result->status);
    }

    public function testWrongPasswordWithActive2faReturnsGenericMessage(): void
    {
        [, $secret] = $this->createAccountWith2fa();
        $auth       = $this->auth();

        // Wrong password: the second factor must NOT be pointed out (R5.3).
        $result = $auth->login('admin', 'falsch', $this->validCode($secret), $this->currentTime->getTimestamp());

        self::assertSame(LoginStatus::INVALID_CREDENTIALS, $result->status);
    }

    // --- R5.6: account lock after 5 failed attempts ---

    public function testAccountIsLockedAfterFiveFailedAttempts(): void
    {
        $id   = $this->createAccountWithout2fa();
        $auth = $this->auth();

        // Four failed attempts: no lock yet.
        for ($i = 1; $i <= 4; $i++) {
            $result = $auth->login('admin', 'falsch');
            self::assertSame(LoginStatus::INVALID_CREDENTIALS, $result->status, "Attempt$i");
        }

        // Fifth failed attempt: the account is locked.
        $fifth = $auth->login('admin', 'falsch');
        self::assertSame(LoginStatus::ACCOUNT_LOCKED, $fifth->status);

        // Even with the correct password access stays denied during the lock.
        $despiteCorrect = $auth->login('admin', self::PASSWORD);
        self::assertSame(LoginStatus::ACCOUNT_LOCKED, $despiteCorrect->status);
        self::assertFalse($auth->checkWriteAccess());

        // Lock stored in the DB.
        $account = $this->accounts->find($id);
        self::assertNotNull($account);
        self::assertNotNull($account['locked_until']);
    }

    public function testAfterLockExpiresLoginIsPossibleAgain(): void
    {
        $this->createAccountWithout2fa();
        $auth = $this->auth();

        for ($i = 1; $i <= 5; $i++) {
            $auth->login('admin', 'falsch');
        }
        self::assertSame(LoginStatus::ACCOUNT_LOCKED, $auth->login('admin', self::PASSWORD)->status);

        // Move the time forward by more than 15 minutes: lock expired.
        $this->currentTime = $this->currentTime->add(new \DateInterval('PT16M'));

        $result = $this->auth()->login('admin', self::PASSWORD);
        self::assertSame(LoginStatus::SUCCESSFUL, $result->status);
    }

    public function testSuccessfulLoginResetsFailedAttempts(): void
    {
        $id   = $this->createAccountWithout2fa();
        $auth = $this->auth();

        $auth->login('admin', 'falsch');
        $auth->login('admin', 'falsch');
        self::assertSame(2, $this->accounts->find($id)['failed_attempts']);

        $auth->login('admin', self::PASSWORD);
        self::assertSame(0, $this->accounts->find($id)['failed_attempts'], 'A successful login resets the counter (R5.6).');
    }

    // --- R5.5: logout ---

    public function testLogoutEndsTheSession(): void
    {
        $this->createAccountWithout2fa();
        $auth = $this->auth();

        $auth->login('admin', self::PASSWORD);
        self::assertTrue($auth->checkWriteAccess());

        $auth->logout();

        self::assertFalse($auth->checkWriteAccess(), 'After logging out there must be no write access (R5.5).');
        self::assertNull($auth->loggedInAccount());
    }

    // --- R5.1/R5.4: no write access without login ---

    public function testWithoutLoginNoWriteAccess(): void
    {
        $this->createAccountWithout2fa();

        self::assertFalse($this->auth()->checkWriteAccess(), 'Without a login there must be no write access (R5.1).');
    }

    // --- R5.7: inactivity timeout ---

    public function testSessionExpiresAfter30MinutesInactivity(): void
    {
        $this->createAccountWithout2fa();
        $auth = $this->auth();

        $auth->login('admin', self::PASSWORD);
        self::assertTrue($auth->checkWriteAccess());

        // 31 minutes without activity: the session must have expired (R5.7).
        $this->currentTime = $this->currentTime->add(new \DateInterval('PT31M'));

        self::assertFalse($this->auth()->checkWriteAccess(), 'After 30 minutes of inactivity the session ends (R5.7).');
    }

    public function testActivityExtendsTheSession(): void
    {
        $this->createAccountWithout2fa();

        $this->auth()->login('admin', self::PASSWORD);

        // Access after 20 minutes (extends the sliding timeout).
        $this->currentTime = $this->currentTime->add(new \DateInterval('PT20M'));
        self::assertTrue($this->auth()->checkWriteAccess());

        // Another 20 minutes (40 in total, but only 20 since the last activity).
        $this->currentTime = $this->currentTime->add(new \DateInterval('PT20M'));
        self::assertTrue($this->auth()->checkWriteAccess(), 'Activity within 30 minutes keeps the session alive (R5.2).');
    }

    // --- Maximum duration and session version ---

    public function testSessionEndsAfterMaximumDurationDespiteActivity(): void
    {
        $this->createAccountWithout2fa();
        $this->auth()->login('admin', self::PASSWORD);

        // Activity every 20 minutes keeps the sliding timeout alive up to 8 hours ...
        for ($step = 1; $step <= 24; $step++) {
            $this->currentTime = $this->currentTime->add(new \DateInterval('PT20M'));
            self::assertTrue($this->auth()->checkWriteAccess(), "Nach {$step} × 20 Minuten mit Aktivität besteht die Sitzung noch.");
        }

        // ... but not beyond.
        $this->currentTime = $this->currentTime->add(new \DateInterval('PT1M'));
        self::assertFalse($this->auth()->checkWriteAccess(), 'After 8 hours the session ends despite activity.');
    }

    public function testSessionWithoutLoginTimeEnds(): void
    {
        $id = $this->createAccountWithout2fa();
        // Session from before the maximum duration existed: no login time, no version.
        $this->session->set(AuthModule::SESSION_ACCOUNT_ID, $id);
        $this->session->set(AuthModule::SESSION_LAST_ACTIVITY, $this->currentTime->getTimestamp());

        self::assertFalse($this->auth()->checkWriteAccess(), 'A session without a login time ends.');
    }

    public function testRaisedSessionVersionEndsOpenSession(): void
    {
        $id   = $this->createAccountWithout2fa();
        $auth = $this->auth();
        $auth->login('admin', self::PASSWORD);
        self::assertTrue($auth->checkWriteAccess());

        // E.g. the password was changed from another session.
        $this->accounts->raiseSessionVersion($id);

        self::assertFalse($auth->checkWriteAccess(), 'After a new session version the open session ends.');
        self::assertNull($this->session->retrieve(AuthModule::SESSION_ACCOUNT_ID));
    }

    public function testRenewSessionKeepsOwnSessionAfterRaise(): void
    {
        $id   = $this->createAccountWithout2fa();
        $auth = $this->auth();
        $auth->login('admin', self::PASSWORD);
        $this->session->idRegenerated = false;

        $this->accounts->raiseSessionVersion($id);
        $auth->renewSession($id);

        self::assertTrue($auth->checkWriteAccess(), 'The own session stays logged in after renewSession.');
        self::assertTrue($this->session->idRegenerated, 'The session id is regenerated in the process.');
    }

    public function testRenewSessionIgnoresOtherAccount(): void
    {
        $this->createAccountWithout2fa();
        $other = $this->createAccountWithout2fa('zweiter');
        $auth  = $this->auth();
        $auth->login('admin', self::PASSWORD);

        // The other account's version is raised; renewing for it must not touch this session.
        $this->accounts->raiseSessionVersion($other);
        $auth->renewSession($other);

        self::assertSame(1, $this->session->retrieve(AuthModule::SESSION_VERSION));
        self::assertTrue($auth->checkWriteAccess());
    }
}
