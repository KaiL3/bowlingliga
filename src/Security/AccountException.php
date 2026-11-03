<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Translatable;
use RuntimeException;

/**
 * Signals a violation of a domain rule of the account management (R23).
 *
 * Typical triggers:
 * - The username is already taken (R23.2).
 * - The last remaining admin account is to be removed (R23.5).
 * - The username is empty or does not meet the length rules (R23.1–2).
 */
final class AccountException extends RuntimeException implements Translatable
{
    use CarriesMessage;

    /**
     * The given username is already taken (R23.2).
     */
    public static function usernameTaken(string $username): self
    {
        return new self('error.account.username_taken', ['username' => $username]);
    }

    /**
     * The username is invalid (empty or too long, R23.1–2).
     */
    public static function usernameInvalid(): self
    {
        return new self('error.account.username_invalid');
    }

    /**
     * The last remaining admin account must not be removed (R23.5).
     */
    public static function lastAccount(): self
    {
        return new self('error.account.last_account');
    }

    /**
     * The admin account to edit does not exist.
     */
    public static function accountNotFound(int $id): self
    {
        return new self('error.account.not_found', ['id' => $id]);
    }

    /**
     * The code entered from the authenticator app does not match (R23.8).
     */
    public static function totpCodeInvalid(): self
    {
        return new self('error.account.totp_code_invalid');
    }

    /**
     * The current password for confirmation is wrong.
     */
    public static function passwordWrong(): self
    {
        return new self('error.account.password_wrong');
    }

    /**
     * The new password is too short.
     */
    public static function passwordTooShort(int $minimumLength): self
    {
        return new self('error.account.password_too_short', ['min' => $minimumLength]);
    }

    /**
     * New password and repetition do not match.
     */
    public static function passwordsMismatch(): self
    {
        return new self('error.account.passwords_mismatch');
    }

    /**
     * No two-factor authentication is active for the account.
     */
    public static function totpNotActive(): self
    {
        return new self('error.account.totp_not_active');
    }
}
