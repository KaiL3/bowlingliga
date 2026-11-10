<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Security;

use Bowlingliga\Security\AccountLogStore;

/** In-memory fake of the {@see AccountLogStore} port. */
final class InMemoryAccountLogStore implements AccountLogStore
{
    /** @var list<array{created_at: string, actor_id: int|null, actor_name: string|null, target_name: string, action: string, permission: string|null}> */
    public array $entries = [];

    public function record(\DateTimeImmutable $at, ?int $actorId, ?string $actorName, string $targetName, string $action, ?string $permission = null): void
    {
        $this->entries[] = [
            'created_at'  => $at->format('Y-m-d H:i:s'),
            'actor_id'    => $actorId,
            'actor_name'  => $actorName,
            'target_name' => $targetName,
            'action'      => $action,
            'permission'  => $permission,
        ];
    }

    public function recent(int $limit): array
    {
        $entries = array_reverse($this->entries);

        return array_map(static fn (array $e): array => [
            'created_at'  => $e['created_at'],
            'actor_name'  => $e['actor_name'],
            'target_name' => $e['target_name'],
            'action'      => $e['action'],
            'permission'  => $e['permission'],
        ], array_slice($entries, 0, $limit));
    }

    /**
     * Compact form for assertions: "actor action target [permission]".
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(
            static fn (array $e): string => trim(($e['actor_name'] ?? '-') . ' ' . $e['action'] . ' ' . $e['target_name'] . ' ' . ($e['permission'] ?? '')),
            $this->entries,
        );
    }
}
