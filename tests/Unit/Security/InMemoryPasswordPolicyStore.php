<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\AccountKind;
use Bowlingliga\Security\PasswordPolicy;
use Bowlingliga\Security\PasswordPolicyStore;

/** In-memory fake of the {@see PasswordPolicyStore} port. */
final class InMemoryPasswordPolicyStore implements PasswordPolicyStore
{
    /** @var array<string, PasswordPolicy> */
    public array $policies = [];

    public function policyFor(AccountKind $kind): PasswordPolicy
    {
        return $this->policies[$kind->value] ?? PasswordPolicy::defaultFor($kind);
    }

    public function save(AccountKind $kind, PasswordPolicy $policy): void
    {
        $this->policies[$kind->value] = $policy;
    }
}
