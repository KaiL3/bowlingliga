<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Why a login has to choose a new password before it gets any further
 * (R23.17).
 */
enum PasswordChangeReason: string
{
    /** The password was set by somebody else (new account). */
    case Required = 'required';

    /** Older than the maximum age of the policy. */
    case Expired = 'expired';

    /** Weaker than the minimum strength of the policy. */
    case TooWeak = 'too_weak';

    /** Catalog key of the explanation on the page. */
    public function message(): string
    {
        return 'password_change.reason.' . $this->value;
    }
}
