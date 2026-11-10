<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Port for the cookie that identifies a browser as trusted device (R5.10).
 *
 * Implemented by {@see PhpDeviceCookie}; the unit tests use an in-memory
 * fake, so that the login flow can be checked without HTTP.
 */
interface DeviceCookie
{
    /** The token the browser sent, or null if there is none or it is malformed. */
    public function token(): ?string;

    /** Sends the token to the browser, valid for the given number of seconds. */
    public function issue(string $token, int $lifetimeSeconds): void;
}
