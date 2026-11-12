<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\AccountKind;
use Bowlingliga\Security\AccountPermissions;
use Bowlingliga\Security\PasswordPolicy;
use Bowlingliga\Security\PasswordPolicyStore;
use Bowlingliga\Security\Permission;
use Bowlingliga\Security\PermissionDeniedException;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\WriteAccess;

/**
 * Use case: the password policies of admins and players (R23.15). Only a
 * system admin reads and changes them (R23.10).
 */
final class PasswordPolicySettings
{
    public function __construct(
        private readonly PasswordPolicyStore $store,
        private readonly WriteAccess $writeAccess,
        private readonly AccountPermissions $permissions,
    ) {
    }

    /**
     * The policy of every kind of account.
     *
     * @return array<string, PasswordPolicy> keyed by {@see AccountKind} value
     *
     * @throws AccessDeniedException without write access (R5.1).
     * @throws PermissionDeniedException without system admin (R23.10).
     */
    public function policies(): array
    {
        $this->ensureSystemAdmin();

        $policies = [];
        foreach (AccountKind::cases() as $kind) {
            $policies[$kind->value] = $this->store->policyFor($kind);
        }

        return $policies;
    }

    /**
     * Saves the policy of one kind of account.
     *
     * @throws AccessDeniedException without write access (R5.1).
     * @throws PermissionDeniedException without system admin (R23.10).
     * @throws ValidationException for an unknown kind or a value outside its
     *         range; nothing is saved then.
     */
    public function save(string $kind, mixed $minBits, mixed $maxAgeDays, mixed $historySize): AccountKind
    {
        $this->ensureSystemAdmin();

        $accountKind = AccountKind::tryFrom($kind)
            ?? throw ValidationException::forField('field.account_kind', 'validation.invalid_value');
        $policy = new PasswordPolicy(
            self::integer($minBits, 'field.min_bits'),
            self::integer($maxAgeDays, 'field.max_age_days'),
            self::integer($historySize, 'field.history_size'),
        );
        $this->store->save($accountKind, $policy);

        return $accountKind;
    }

    private function ensureSystemAdmin(): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
        $this->permissions->require(Permission::SystemAdmin);
    }

    /**
     * @throws ValidationException if the value is not a whole number.
     */
    private static function integer(mixed $value, string $field): int
    {
        $number = filter_var(is_string($value) ? trim($value) : $value, FILTER_VALIDATE_INT);
        if ($number === false) {
            throw ValidationException::forField($field, 'validation.integer');
        }

        return $number;
    }
}
