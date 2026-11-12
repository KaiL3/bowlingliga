<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Status values of a login attempt at the auth module (R5.2, R5.3, R5.6,
 * R23.7, R23.8).
 *
 * Distinguishes success from the intended error cases. The status values are
 * deliberately coarse, so that no information pointing to the cause is
 * revealed to the outside (R5.3) – except for the specific note about an
 * invalid second factor (R23.8), once the password is correct and 2FA is
 * active.
 */
enum LoginStatus: string
{
    /** Login successful; an authenticated session has been set up (R5.2). */
    case SUCCESSFUL = 'successful';

    /**
     * Invalid credentials (unknown username or wrong password). It is not
     * revealed which of the two applies (R5.3).
     */
    case INVALID_CREDENTIALS = 'invalid_credentials';

    /**
     * Password correct, but the submitted TOTP code is invalid or expired with
     * active 2FA (R23.8).
     */
    case INVALID_SECOND_FACTOR = 'invalid_second_factor';

    /**
     * The account is temporarily locked because of too many consecutive failed
     * attempts (R5.6).
     */
    case ACCOUNT_LOCKED = 'account_locked';

    /**
     * Password correct, 2FA is active: the code from the app is asked for as
     * second step; no session yet (R23.7).
     */
    case SECOND_FACTOR_REQUIRED = 'second_factor_required';

    /**
     * The second step has expired or was called without a first step; the
     * login starts over.
     */
    case LOGIN_EXPIRED = 'login_expired';

    /**
     * Password (and code) correct, but the password has to be replaced
     * before the session starts (R23.17).
     */
    case PASSWORD_CHANGE_REQUIRED = 'password_change_required';
}
