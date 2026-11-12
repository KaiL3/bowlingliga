<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\AccountKind;
use Bowlingliga\Security\PasswordPolicy;
use Bowlingliga\Security\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Password policy (R23.15): ranges, defaults and the maximum age.
 */
final class PasswordPolicyTest extends TestCase
{
    public function testDefaultsDemandMoreFromAdmins(): void
    {
        $admin = PasswordPolicy::defaultFor(AccountKind::Admin);
        $user  = PasswordPolicy::defaultFor(AccountKind::User);

        self::assertSame([70, -1, 5], [$admin->minBits, $admin->maxAgeDays, $admin->historySize]);
        self::assertSame([50, -1, 5], [$user->minBits, $user->maxAgeDays, $user->historySize]);
    }

    /**
     * @return iterable<string, array{0: int, 1: int, 2: int}>
     */
    public static function invalidValues(): iterable
    {
        yield 'negative bits'     => [-1, -1, 0];
        yield 'too many bits'     => [201, -1, 0];
        yield 'age zero'          => [50, 0, 0];
        yield 'age below -1'      => [50, -2, 0];
        yield 'age too long'      => [50, 3651, 0];
        yield 'negative history'  => [50, -1, -1];
        yield 'history too long'  => [50, -1, 25];
    }

    #[DataProvider('invalidValues')]
    public function testValuesOutsideTheirRangeAreRejected(int $bits, int $age, int $history): void
    {
        $this->expectException(ValidationException::class);

        new PasswordPolicy($bits, $age, $history);
    }

    public function testTheLimitsThemselvesAreAllowed(): void
    {
        $low  = new PasswordPolicy(0, 1, 0);
        $high = new PasswordPolicy(200, 3650, 24);

        self::assertSame(0, $low->minBits);
        self::assertSame(24, $high->historySize);
    }

    public function testUnlimitedAgeNeverExpires(): void
    {
        $policy = new PasswordPolicy(50, PasswordPolicy::UNLIMITED, 0);

        self::assertFalse($policy->isExpired(new \DateTimeImmutable('2000-01-01'), new \DateTimeImmutable('2026-11-12')));
    }

    public function testAPasswordExpiresAfterTheMaximumAge(): void
    {
        $policy  = new PasswordPolicy(50, 90, 0);
        $changed = new \DateTimeImmutable('2026-08-01 10:00:00');

        self::assertFalse($policy->isExpired($changed, new \DateTimeImmutable('2026-10-30 09:59:59')));
        self::assertTrue($policy->isExpired($changed, new \DateTimeImmutable('2026-10-30 10:00:00')));
    }
}
