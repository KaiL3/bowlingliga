<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use OTPHP\TOTP;

/**
 * Checking a TOTP code against a plaintext secret (R23.7, R23.8).
 *
 * Encapsulates the time-based one-time password verification (RFC 6238) via
 * the established otphp library. The secret needed for the check is stored
 * encrypted in the database ({@see TotpSecretEncryption}); the
 * {@see AuthModule} flow decrypts it only for the duration of the check and
 * passes the plaintext here.
 *
 * A small tolerance window (`leeway`) is allowed for verification, to
 * compensate small clock differences between server and authenticator app.
 * The point in time of the check can be injected, so that the flow is
 * deterministically testable with a fixed time.
 */
final class TotpVerifier
{
    /**
     * Tolerance in seconds accepted before/after the current 30 second window,
     * to compensate small clock differences between server and authenticator
     * app. otphp requires a value strictly smaller than the TOTP period (30 s);
     * 29 s thus covers the directly adjacent time window.
     */
    private const TOLERANCE_SECONDS = 29;

    /**
     * Checks whether the otphp library needed for TOTP verification is
     * available at runtime.
     */
    public static function isAvailable(): bool
    {
        return class_exists(TOTP::class);
    }

    /**
     * Checks a TOTP code against the given plaintext secret (R23.7, R23.8).
     *
     * @param string   $plaintextSecret The decrypted TOTP secret of the account.
     * @param string   $code            The code submitted by the administrator.
     * @param int|null $timestamp       Optional Unix timestamp for the check
     *                                  (for deterministic tests); null = now.
     *
     * @return bool true if the code is valid within the tolerance window.
     */
    public function check(string $plaintextSecret, string $code, ?int $timestamp = null): bool
    {
        $code = trim($code);

        if ($code === '' || !self::isAvailable()) {
            return false;
        }

        $totp = TOTP::createFromSecret($plaintextSecret);

        // The second parameter (point in time of the check) makes the check
        // deterministically testable with a fixed time; the leeway (< period)
        // compensates small clock differences (R23.7/R23.8).
        return $totp->verify($code, $timestamp, self::TOLERANCE_SECONDS);
    }

    /**
     * Like {@see check()}, but returns the time step the code matches (or
     * null). This allows detecting and rejecting a code already used (R23.8).
     * The same tolerance window as for {@see check()} is accepted: the current
     * and – within the tolerance – the directly adjacent time window.
     */
    public function timeStep(string $plaintextSecret, string $code, ?int $timestamp = null): ?int
    {
        $code = trim($code);
        if ($code === '' || ! self::isAvailable()) {
            return null;
        }

        $totp    = TOTP::createFromSecret($plaintextSecret);
        $currentTime   = $timestamp ?? time();
        $period = $totp->getPeriod();
        $epoch  = $totp->getEpoch();

        $candidates = array_unique([
            intdiv($currentTime - $epoch, $period),
            intdiv($currentTime - self::TOLERANCE_SECONDS - $epoch, $period),
            intdiv($currentTime + self::TOLERANCE_SECONDS - $epoch, $period),
        ]);

        foreach ($candidates as $step) {
            if (hash_equals($totp->at($epoch + $step * $period), $code)) {
                return $step;
            }
        }

        return null;
    }
}
