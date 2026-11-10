<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\DeviceCookie;

/** In-memory fake of the {@see DeviceCookie} port: one browser. */
final class InMemoryDeviceCookie implements DeviceCookie
{
    public ?string $value = null;

    public ?int $lifetime = null;

    public function token(): ?string
    {
        return $this->value;
    }

    public function issue(string $token, int $lifetimeSeconds): void
    {
        $this->value    = $token;
        $this->lifetime = $lifetimeSeconds;
    }
}
