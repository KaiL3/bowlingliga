<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\AccountException;
use Bowlingliga\Security\AccountKind;
use Bowlingliga\Security\AccountManagement;
use Bowlingliga\Security\AuthModule;
use Bowlingliga\Security\LoginStatus;
use Bowlingliga\Security\PasswordChangeReason;
use Bowlingliga\Security\PasswordHasher;
use Bowlingliga\Security\PasswordPolicy;
use Bowlingliga\Security\PasswordRules;
use Bowlingliga\Security\TotpSecretEncryption;
use Bowlingliga\Security\TotpVerifier;
use Bowlingliga\Tests\Unit\Application\ImmediateTransaction;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;

/**
 * A login whose password has to be replaced first (R23.17): no session
 * until a new password meeting the policy is saved, also behind the second
 * factor, limited in time.
 */
final class ForcedPasswordChangeTest extends TestCase
{
    private const WEAK   = 'Sehr-Geheim-123';
    private const STRONG = 'kX9#mP2$vL7q';
    private const OTHER  = 'Rq4!tZ8%hW2^';

    private InMemoryAuthAccountRepository $accounts;
    private InMemorySessionStore $session;
    private InMemoryPasswordPolicyStore $policies;
    private PasswordHasher $hasher;
    private TotpSecretEncryption $encryption;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->accounts   = new InMemoryAuthAccountRepository();
        $this->session    = new InMemorySessionStore();
        $this->policies   = new InMemoryPasswordPolicyStore();
        $this->hasher     = new PasswordHasher();
        $this->encryption = new TotpSecretEncryption(TotpSecretEncryption::generateKey());
        $this->now        = new \DateTimeImmutable('2026-11-12 10:00:00');
    }

    private function rules(): PasswordRules
    {
        return new PasswordRules(
            $this->policies,
            new InMemoryPasswordHistoryStore(),
            $this->accounts,
            $this->hasher,
            clock: fn (): \DateTimeImmutable => $this->now,
        );
    }

    private function auth(): AuthModule
    {
        return new AuthModule(
            $this->accounts,
            $this->hasher,
            $this->encryption,
            new TotpVerifier(),
            $this->session,
            new ImmediateTransaction(),
            fn (): \DateTimeImmutable => $this->now,
            passwordRules: $this->rules(),
        );
    }

    private function management(): AccountManagement
    {
        return new AccountManagement($this->accounts, $this->hasher, $this->encryption, passwordRules: $this->rules());
    }

    private function account(string $password, ?string $secret = null): int
    {
        return $this->accounts->create('admin', $this->hasher->hash($password), $secret !== null ? $this->encryption->encrypt($secret) : null);
    }

    private function changeTo(AuthModule $auth, string $fresh, ?string $repetition = null): \Bowlingliga\Security\LoginResult
    {
        return $auth->completePasswordChange(fn (int $id) => $this->management()->replaceOwnPassword($id, $fresh, $repetition ?? $fresh));
    }

    public function testAWeakPasswordLogsInOnlyAfterTheChange(): void
    {
        $id   = $this->account(self::WEAK);
        $auth = $this->auth();

        $result = $auth->login('admin', self::WEAK);

        self::assertSame(LoginStatus::PASSWORD_CHANGE_REQUIRED, $result->status);
        self::assertFalse($auth->checkWriteAccess(), 'No session before the new password.');
        self::assertSame(PasswordChangeReason::TooWeak, $auth->awaitsPasswordChange());
        self::assertSame('admin', $auth->passwordChangeUsername());

        $result = $this->changeTo($auth, self::STRONG);

        self::assertTrue($result->isSuccessful());
        self::assertTrue($auth->checkWriteAccess());
        self::assertNull($auth->awaitsPasswordChange());
        self::assertTrue($this->hasher->check(self::STRONG, (string) $this->accounts->find($id)['password_hash']));
    }

    public function testAStrongPasswordLogsInDirectly(): void
    {
        $this->account(self::STRONG);
        $auth = $this->auth();

        self::assertTrue($auth->login('admin', self::STRONG)->isSuccessful());
        self::assertNull($auth->awaitsPasswordChange());
    }

    public function testARejectedNewPasswordKeepsTheLoginWaiting(): void
    {
        $this->account(self::WEAK);
        $auth = $this->auth();
        $auth->login('admin', self::WEAK);

        foreach ([[self::WEAK, null], ['geheim123', null], [self::STRONG, self::OTHER]] as [$fresh, $repetition]) {
            try {
                $this->changeTo($auth, $fresh, $repetition);
                self::fail('Rejected: ' . $fresh);
            } catch (AccountException) {
                self::assertFalse($auth->checkWriteAccess());
                self::assertSame(PasswordChangeReason::TooWeak, $auth->awaitsPasswordChange());
            }
        }
    }

    public function testTheReasonSurvivesTheSecondFactor(): void
    {
        $secret = TOTP::generate()->getSecret();
        $this->account(self::WEAK, $secret);
        $auth = $this->auth();

        self::assertSame(LoginStatus::SECOND_FACTOR_REQUIRED, $auth->login('admin', self::WEAK)->status);
        $result = $auth->confirmSecondFactor(TOTP::createFromSecret($secret)->at($this->now->getTimestamp()), $this->now->getTimestamp());

        self::assertSame(LoginStatus::PASSWORD_CHANGE_REQUIRED, $result->status);
        self::assertFalse($auth->checkWriteAccess());
        self::assertTrue($this->changeTo($auth, self::STRONG)->isSuccessful());
        self::assertTrue($auth->checkWriteAccess());
    }

    public function testAnAccountCreatedBySomebodyElseChangesAtTheFirstLogin(): void
    {
        $this->management()->createAccount('neu', self::STRONG, mustChange: true);
        $auth = $this->auth();

        self::assertSame(LoginStatus::PASSWORD_CHANGE_REQUIRED, $auth->login('neu', self::STRONG)->status);
        self::assertSame(PasswordChangeReason::Required, $auth->awaitsPasswordChange());
        self::assertTrue($this->changeTo($auth, self::OTHER)->isSuccessful());

        $auth->logout();
        self::assertTrue($auth->login('neu', self::OTHER)->isSuccessful(), 'Afterwards the login is normal again.');
    }

    public function testAnExpiredPasswordHasToBeReplaced(): void
    {
        $this->policies->save(AccountKind::Admin, new PasswordPolicy(70, 90, 5));
        $this->management()->createAccount('admin', self::STRONG);
        $this->now = $this->now->modify('+90 days');

        $auth = $this->auth();
        self::assertSame(LoginStatus::PASSWORD_CHANGE_REQUIRED, $auth->login('admin', self::STRONG)->status);
        self::assertSame(PasswordChangeReason::Expired, $auth->awaitsPasswordChange());
    }

    public function testTheWaitingLoginExpires(): void
    {
        $this->account(self::WEAK);
        $auth = $this->auth();
        $auth->login('admin', self::WEAK);

        $this->now = $this->now->modify('+' . (AuthModule::PASSWORD_CHANGE_TIMEOUT_SECONDS + 1) . ' seconds');

        self::assertNull($auth->awaitsPasswordChange());
        self::assertSame(LoginStatus::LOGIN_EXPIRED, $this->changeTo($auth, self::STRONG)->status);
        self::assertFalse($auth->checkWriteAccess());
    }

    public function testCancellingStartsTheLoginOver(): void
    {
        $this->account(self::WEAK);
        $auth = $this->auth();
        $auth->login('admin', self::WEAK);

        $auth->cancelPasswordChange();

        self::assertNull($auth->awaitsPasswordChange());
        self::assertSame(LoginStatus::LOGIN_EXPIRED, $this->changeTo($auth, self::STRONG)->status);
    }

    public function testAWrongPasswordNeverReachesTheChange(): void
    {
        $this->account(self::WEAK);
        $auth = $this->auth();

        self::assertSame(LoginStatus::INVALID_CREDENTIALS, $auth->login('admin', 'falsch-falsch')->status);
        self::assertNull($auth->awaitsPasswordChange());
    }
}
