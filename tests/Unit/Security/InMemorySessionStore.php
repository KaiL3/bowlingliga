<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\SessionStore;

/**
 * In-memory session store for the auth test.
 *
 * Keeps the session values in an array and records whether the session id was
 * regenerated (check of the session fixation protection).
 */
final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, int|string> */
    private array $values = [];

    public bool $idRegenerated = false;

    public function set(string $key, int|string $value): void
    {
        $this->values[$key] = $value;
    }

    public function retrieve(string $key): int|string|null
    {
        return $this->values[$key] ?? null;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    public function regenerateId(): void
    {
        $this->idRegenerated = true;
    }

    public function end(): void
    {
        $this->values = [];
    }
}
