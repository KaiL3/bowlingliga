<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Security\AccountKind;
use Bowlingliga\Security\PasswordPolicy;
use Bowlingliga\Security\PasswordPolicyStore;

/**
 * Raw data repository for the password policies (table `password_policy`,
 * R23.15).
 */
final class PasswordPolicyRepository extends AbstractRepository implements PasswordPolicyStore
{
    public function policyFor(AccountKind $kind): PasswordPolicy
    {
        $row = $this->findOne(
            'SELECT min_bits, max_age_days, history_size FROM password_policy WHERE account_kind = :kind',
            ['kind' => $kind->value]
        );

        return $row === null
            ? PasswordPolicy::defaultFor($kind)
            : new PasswordPolicy((int) $row['min_bits'], (int) $row['max_age_days'], (int) $row['history_size']);
    }

    public function save(AccountKind $kind, PasswordPolicy $policy): void
    {
        $this->transactional('save_password_policy', function () use ($kind, $policy): void {
            $this->perform(
                'INSERT INTO password_policy (account_kind, min_bits, max_age_days, history_size)
                 VALUES (:kind, :min_bits, :max_age_days, :history_size)
                 ON DUPLICATE KEY UPDATE min_bits = VALUES(min_bits), max_age_days = VALUES(max_age_days),
                    history_size = VALUES(history_size)',
                [
                    'kind'         => $kind->value,
                    'min_bits'     => $policy->minBits,
                    'max_age_days' => $policy->maxAgeDays,
                    'history_size' => $policy->historySize,
                ]
            );
        });
    }
}
