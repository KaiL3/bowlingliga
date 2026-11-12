<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\AccountException;
use Bowlingliga\Security\AccountKind;
use Bowlingliga\Security\PasswordChangeReason;
use Bowlingliga\Security\PasswordHasher;
use Bowlingliga\Security\PasswordPolicy;
use Bowlingliga\Security\PasswordRules;
use Bowlingliga\Tests\GermanText;
use PHPUnit\Framework\TestCase;

/**
 * The password policies applied (R23.15–R23.18): strength, history, record
 * of the change and the reasons for a forced change at the login.
 */
final class PasswordRulesTest extends TestCase
{
    /** Random, about 78 bits each. */
    private const STRONG = ['kX9#mP2$vL7q', 'Rq4!tZ8%hW2^', 'Lm6&yC1*pD5@', 'Vb3?nJ7=sF0+'];

    private InMemoryAuthAccountRepository $accounts;
    private InMemoryPasswordPolicyStore $policies;
    private InMemoryPasswordHistoryStore $history;
    private PasswordHasher $hasher;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAuthAccountRepository();
        $this->policies = new InMemoryPasswordPolicyStore();
        $this->history  = new InMemoryPasswordHistoryStore();
        $this->hasher   = new PasswordHasher();
        $this->now      = new \DateTimeImmutable('2026-11-12 10:00:00');
    }

    private function rules(): PasswordRules
    {
        return new PasswordRules(
            $this->policies,
            $this->history,
            $this->accounts,
            $this->hasher,
            commonWords: ['Mittwochsliga'],
            clock: fn (): \DateTimeImmutable => $this->now,
        );
    }

    private function account(string $password = self::STRONG[0]): int
    {
        $id = $this->accounts->create('kai', $this->hasher->hash($password));
        $this->rules()->remember($id, (string) $this->accounts->find($id)['password_hash'], 78);

        return $id;
    }

    public function testAPasswordBelowTheMinimumIsRejectedWithItsBits(): void
    {
        try {
            $this->rules()->check(AccountKind::Admin, 'Sehr-Geheim-123', 'kai');
            self::fail('A password of 58 bits must not pass 70.');
        } catch (AccountException $e) {
            self::assertSame('Das Passwort ist zu schwach: 58 Bit, verlangt sind mindestens 70 Bit. Längere Passwörter aus mehreren Wörtern oder zufälligen Zeichen sind stärker.', GermanText::of($e));
        }

        self::assertSame(58, $this->rules()->check(AccountKind::User, 'Sehr-Geheim-123', 'kai'), 'Players need only 50 bits.');
    }

    public function testTheMinimumLengthAppliesEvenWithoutAMinimumStrength(): void
    {
        $this->policies->save(AccountKind::Admin, new PasswordPolicy(0, -1, 0));

        $this->expectException(AccountException::class);
        $this->rules()->check(AccountKind::Admin, 'x7#Q', 'kai');
    }

    public function testUsernameAndLeagueNameCountAsKnown(): void
    {
        $rules    = $this->rules();
        $unknown  = new \Bowlingliga\Security\PasswordStrength();

        self::assertLessThan($unknown->bits('Mittwochsliga!2026') - 20, $rules->bits('Mittwochsliga!2026', 'kai'), 'league name');
        self::assertLessThan($rules->bits('Kegelbahn-77', 'kai') - 20, $rules->bits('Kegelbahn-77', 'Kegelbahn'), 'username');
    }

    public function testTheCurrentPasswordIsAlwaysBlocked(): void
    {
        $this->policies->save(AccountKind::Admin, new PasswordPolicy(70, -1, 0));
        $id = $this->account();

        try {
            $this->rules()->change(AccountKind::Admin, $id, 'kai', self::STRONG[0]);
            self::fail('The current password may not be set again.');
        } catch (AccountException $e) {
            self::assertSame('Das neue Passwort muss sich vom aktuellen unterscheiden.', GermanText::of($e));
        }
    }

    public function testTheHistoryBlocksTheLastPasswordsOfThePolicy(): void
    {
        $this->policies->save(AccountKind::Admin, new PasswordPolicy(70, -1, 2));
        $id    = $this->account(self::STRONG[0]);
        $rules = $this->rules();
        $rules->change(AccountKind::Admin, $id, 'kai', self::STRONG[1]);
        $rules->change(AccountKind::Admin, $id, 'kai', self::STRONG[2]);

        try {
            $rules->change(AccountKind::Admin, $id, 'kai', self::STRONG[1]);
            self::fail('One of the last two passwords may not come back.');
        } catch (AccountException $e) {
            self::assertSame('Das Passwort darf weder das aktuelle noch eines der letzten 2 sein.', GermanText::of($e));
        }

        // The first one is three changes back: allowed again.
        $rules->change(AccountKind::Admin, $id, 'kai', self::STRONG[0]);
        self::assertTrue($this->hasher->check(self::STRONG[0], (string) $this->accounts->find($id)['password_hash']));
    }

    public function testAChangeRecordsStrengthTimeAndHistoryAndEndsTheSessions(): void
    {
        $id      = $this->account();
        $version = (int) $this->accounts->find($id)['session_version'];

        $this->rules()->change(AccountKind::Admin, $id, 'kai', self::STRONG[1], mustChange: true);

        $account = $this->accounts->find($id);
        self::assertSame('2026-11-12 10:00:00', $account['password_changed_at']);
        self::assertSame($this->rules()->bits(self::STRONG[1], 'kai'), $account['password_bits']);
        self::assertSame(1, $account['must_change_password']);
        self::assertSame($version + 1, $account['session_version'], 'R5.9');
        self::assertCount(2, $this->history->hashes[$id]);
    }

    public function testNoReasonForAGoodPassword(): void
    {
        $id = $this->account();

        self::assertNull($this->rules()->changeReason(AccountKind::Admin, $this->accounts->find($id), self::STRONG[0]));
    }

    public function testAPasswordSetBySomebodyElseHasToBeReplaced(): void
    {
        $id = $this->account();
        $this->accounts->setColumn($id, 'must_change_password', 1);

        self::assertSame(PasswordChangeReason::Required, $this->rules()->changeReason(AccountKind::Admin, $this->accounts->find($id), self::STRONG[0]));
    }

    public function testAnExpiredPasswordHasToBeReplaced(): void
    {
        $this->policies->save(AccountKind::Admin, new PasswordPolicy(70, 30, 5));
        $id = $this->account();

        $this->now = $this->now->modify('+29 days');
        self::assertNull($this->rules()->changeReason(AccountKind::Admin, $this->accounts->find($id), self::STRONG[0]));

        $this->now = $this->now->modify('+1 day');
        self::assertSame(PasswordChangeReason::Expired, $this->rules()->changeReason(AccountKind::Admin, $this->accounts->find($id), self::STRONG[0]));
    }

    public function testARaisedMinimumCatchesTheOldPasswordAtTheLogin(): void
    {
        $id = $this->account();
        $this->policies->save(AccountKind::Admin, new PasswordPolicy(100, -1, 5));

        self::assertSame(PasswordChangeReason::TooWeak, $this->rules()->changeReason(AccountKind::Admin, $this->accounts->find($id), self::STRONG[0]));
    }

    public function testAnAccountWithoutRecordedChangeDoesNotExpire(): void
    {
        $this->policies->save(AccountKind::Admin, new PasswordPolicy(70, 1, 5));
        $id = $this->accounts->create('alt', $this->hasher->hash(self::STRONG[0]));

        self::assertNull($this->rules()->changeReason(AccountKind::Admin, $this->accounts->find($id), self::STRONG[0]));
    }
}
