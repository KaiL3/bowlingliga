<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Port: the password policy of each kind of account (R23.15).
 */
interface PasswordPolicyStore
{
    /** The policy of the kind; the default of a new installation if none is stored. */
    public function policyFor(AccountKind $kind): PasswordPolicy;

    public function save(AccountKind $kind, PasswordPolicy $policy): void;
}
