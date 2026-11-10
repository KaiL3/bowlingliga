<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Device cookie of the browser (implementation of the {@see DeviceCookie}
 * port, R5.10).
 *
 * The cookie holds only a random token. It is valid for the whole site, so
 * that other kinds of accounts can use it later, and like the session cookie
 * it is `HttpOnly`, `SameSite=Strict` and over HTTPS `Secure`.
 */
final class PhpDeviceCookie implements DeviceCookie
{
    public const NAME = 'bl_device';

    /**
     * @param array<string, mixed> $cookies Usually $_COOKIE.
     * @param array<string, mixed> $server  Usually $_SERVER.
     */
    public function __construct(
        private readonly array $cookies,
        private readonly array $server,
    ) {
    }

    public function token(): ?string
    {
        $token = $this->cookies[self::NAME] ?? null;

        return is_string($token) && TrustedDevices::isWellFormedToken($token) ? $token : null;
    }

    public function issue(string $token, int $lifetimeSeconds): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::NAME, $token, [
            'expires'  => time() + $lifetimeSeconds,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => TransportSecurity::isHttps($this->server),
        ]);
    }
}
