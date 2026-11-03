<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Keeps the own session logged in after the session version of its account
 * was raised, while all other sessions of that account end (implemented by
 * {@see AuthModule}).
 */
interface SessionRenewal
{
    /**
     * Takes over the account's current session version into the running
     * session if the session belongs to this account; otherwise nothing
     * happens.
     */
    public function renewSession(int $accountId): void;
}
