<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * CSRF protection following the synchronizer token pattern for writing forms
 * (R22.5, cross-cutting – task 19).
 *
 * Every writing form in the admin area carries a hidden field with a random,
 * session-bound token. On submit the token passed is checked against the
 * value stored in the session. This way a foreign origin (cross-site request
 * forgery) cannot trigger a valid writing request on behalf of a logged-in
 * admin session, since it does not know the current token.
 *
 * The helper only encapsulates the token logic and accesses the session state
 * via the {@see SessionStore} port; this keeps it free of PHP session details
 * and testable without a real session.
 *
 * Usage:
 * - {@see self::fetchToken()} when rendering a writing form, to fill the
 *   hidden field (generates a new token if needed and stores it in the
 *   session).
 * - {@see self::fieldHtml()} as a convenience to output the complete hidden
 *   input field directly.
 * - {@see self::check()} when processing the submitted form; throws a
 *   {@see CsrfTokenException} for a missing or wrong token.
 */
final class CsrfTokenHelper
{
    /** Key under which the token is stored in the session. */
    public const SESSION_KEY = 'csrf_token';

    /** Name of the hidden form field that carries the token. */
    public const FIELD_NAME = 'csrf_token';

    /** Length of the random part in bytes (32 bytes → 64 hex characters). */
    private const TOKEN_BYTES = 32;

    public function __construct(
        private readonly SessionStore $session,
    ) {
    }

    /**
     * Returns the current, session-bound CSRF token (R22.5).
     *
     * If no token is stored in the session yet, a new cryptographically secure
     * token is generated and stored. A token once generated stays stable for
     * the duration of the session, so that several forms open at the same time
     * use the same valid token.
     */
    public function fetchToken(): string
    {
        $present = $this->session->retrieve(self::SESSION_KEY);

        if (is_string($present) && $present !== '') {
            return $present;
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    /**
     * Returns the complete hidden input field (HTML) for a writing form. The
     * token value is escaped context-aware as attribute value.
     */
    public function fieldHtml(): string
    {
        $token = $this->fetchToken();

        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD_NAME,
            htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')
        );
    }

    /**
     * Checks the token passed by the form against the session-bound value
     * (R22.5).
     *
     * The comparison runs in constant time ({@see hash_equals()}), to prevent
     * inferences from the response time. If there is no token in the session or
     * the value passed does not match, an exception is thrown and the writing
     * operation is thus not executed.
     *
     * @param mixed $submittedToken Usually `$_POST['csrf_token']`.
     *
     * @throws CsrfTokenException if there is no token or a wrong one.
     */
    public function check(mixed $submittedToken): void
    {
        if (! $this->isValid($submittedToken)) {
            throw CsrfTokenException::invalid();
        }
    }

    /**
     * Checks whether the token passed matches the session-bound token, without
     * throwing an exception.
     *
     * Useful if the caller wants to decide on the reaction itself.
     */
    public function isValid(mixed $submittedToken): bool
    {
        // Only a non-empty string qualifies as token; manipulated
        // form data (e.g. csrf_token[]=…) is rejected instead of crashing.
        if (! is_string($submittedToken) || $submittedToken === '') {
            return false;
        }

        $expected = $this->session->retrieve(self::SESSION_KEY);

        if (! is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $submittedToken);
    }
}
