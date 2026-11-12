<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Kind of an account; each kind has its own password policy (R23.15).
 *
 * Only admin accounts exist so far; user accounts (players registered for a
 * team) follow, their policy can already be set.
 */
enum AccountKind: string
{
    case Admin = 'admin';
    case User = 'user';

    /** Catalog key of the name shown in the admin area. */
    public function label(): string
    {
        return 'account_kind.' . $this->value;
    }
}
