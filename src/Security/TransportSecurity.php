<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * HTTPS detection and the HSTS header (Strict-Transport-Security).
 *
 * Without HSTS the first request after typing the address without
 * "https://" (or following an old http link) goes out unencrypted; someone in
 * the same network – e.g. an open WLAN at the bowling alley – can intercept it
 * and keep serving the site over plain HTTP ("SSL stripping"), reading the
 * password and the current two-factor code. With HSTS the browser remembers
 * to use only HTTPS for this domain and never sends that request again.
 *
 * The header is only sent over HTTPS (browsers ignore it over HTTP anyway)
 * and without `includeSubDomains`, so other sites under the same domain are
 * not affected.
 */
final class TransportSecurity
{
    /**
     * Lifetime of the HSTS entry in the browser: 1 day to start with. Once
     * HTTPS has proven stable on the server, raise it to one year
     * (31536000). While the entry is valid, a broken certificate makes the
     * site unreachable for these browsers – there is no "continue anyway".
     */
    public const HSTS_MAX_AGE = 86400;

    /**
     * Whether the request runs over HTTPS (`HTTPS` set by the web server, or
     * port 443).
     *
     * @param array<string, mixed> $server usually `$_SERVER`
     */
    public static function isHttps(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return isset($server['SERVER_PORT']) && (int) $server['SERVER_PORT'] === 443;
    }

    /**
     * The HSTS header line for this request, or null over plain HTTP.
     *
     * @param array<string, mixed> $server usually `$_SERVER`
     */
    public static function strictTransportSecurityHeader(array $server): ?string
    {
        return self::isHttps($server) ? 'Strict-Transport-Security: max-age=' . self::HSTS_MAX_AGE : null;
    }
}
