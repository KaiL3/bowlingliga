<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use Bowlingliga\Domain\Message;

/**
 * Result of a login attempt at the auth module (R5.2, R5.3, R5.6, R23.8).
 *
 * Carries the {@see LoginStatus} and an error message suitable for display.
 * The error messages are deliberately chosen so that in the error case they
 * allow no inferences about the cause (generic message, R5.3) – except for an
 * invalid second factor, which is pointed out specifically (R23.8), and for
 * the note about the temporary account lock (R5.6).
 *
 * Pure, immutable value object without side effects.
 */
final class LoginResult
{
    private function __construct(
        public readonly LoginStatus $status,
        public readonly ?Message $message,
    ) {
    }

    /**
     * Successful login; an authenticated session has been set up (R5.2).
     */
    public static function successful(): self
    {
        return new self(LoginStatus::SUCCESSFUL, null);
    }

    /**
     * Generic rejection for invalid credentials, without revealing whether the
     * username or the password is wrong (R5.3).
     */
    public static function invalidCredentials(): self
    {
        return new self(
            LoginStatus::INVALID_CREDENTIALS,
            new Message('login.invalid_credentials'),
        );
    }

    /**
     * Password correct, but invalid/expired TOTP code with active 2FA (R23.8).
     */
    public static function invalidSecondFactor(): self
    {
        return new self(
            LoginStatus::INVALID_SECOND_FACTOR,
            new Message('login.invalid_second_factor'),
        );
    }

    /**
     * Account temporarily locked because of too many failed attempts (R5.6).
     */
    public static function accountLocked(): self
    {
        return new self(
            LoginStatus::ACCOUNT_LOCKED,
            new Message('login.account_locked'),
        );
    }

    /** Tells whether the login was successful. */
    public static function secondFactorRequired(): self
    {
        return new self(LoginStatus::SECOND_FACTOR_REQUIRED, null);
    }

    public static function loginExpired(): self
    {
        return new self(
            LoginStatus::LOGIN_EXPIRED,
            new Message('login.expired'),
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status === LoginStatus::SUCCESSFUL;
    }
}
