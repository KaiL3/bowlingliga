<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Port: the account of the current request (implemented by
 * {@see AuthModule}).
 */
interface LoggedInAccount
{
    /** Id of the logged-in account, or null without a valid login. */
    public function loggedInAccount(): ?int;
}
