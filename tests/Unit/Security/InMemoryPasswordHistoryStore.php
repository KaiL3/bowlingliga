<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\PasswordHistoryStore;

/** In-memory fake of the {@see PasswordHistoryStore} port. */
final class InMemoryPasswordHistoryStore implements PasswordHistoryStore
{
    /** @var array<int, list<string>> hashes per account, oldest first */
    public array $hashes = [];

    public function recent(int $accountId, int $limit): array
    {
        return $limit > 0 ? array_slice(array_reverse($this->hashes[$accountId] ?? []), 0, $limit) : [];
    }

    public function add(int $accountId, string $passwordHash, \DateTimeImmutable $at, int $keep): void
    {
        $this->hashes[$accountId][] = $passwordHash;
        $this->hashes[$accountId]   = array_slice($this->hashes[$accountId], -max(1, $keep));
    }
}
