<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\PermissionStore;

/** In-memory fake of the {@see PermissionStore} port. */
final class InMemoryPermissionStore implements PermissionStore
{
    /** @var array<int, list<string>> */
    public array $permissions = [];

    public function permissionsOf(int $accountId): array
    {
        return $this->permissions[$accountId] ?? [];
    }

    public function replace(int $accountId, array $permissions): void
    {
        $this->permissions[$accountId] = array_values(array_unique($permissions));
    }

    public function holders(string $permission): array
    {
        $holders = [];
        foreach ($this->permissions as $accountId => $held) {
            if (in_array($permission, $held, true)) {
                $holders[] = $accountId;
            }
        }
        sort($holders);

        return $holders;
    }
}
