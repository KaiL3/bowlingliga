<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

/**
 * Port for the log of changes to accounts and permissions (R23.14).
 *
 * The names are stored as they were at the time, so an entry stays readable
 * after an account was removed. A missing actor means the command line.
 * Implemented by {@see \Bowlingliga\Persistence\AccountLogRepository}.
 */
interface AccountLogStore
{
    /** Actions of the log. */
    public const CREATED = 'created';
    public const REMOVED = 'removed';
    public const GRANTED = 'granted';
    public const REVOKED = 'revoked';

    public function record(
        \DateTimeImmutable $at,
        ?int $actorId,
        ?string $actorName,
        string $targetName,
        string $action,
        ?string $permission = null,
    ): void;

    /**
     * The most recent entries, newest first.
     *
     * @return list<array{created_at: string, actor_name: string|null, target_name: string, action: string, permission: string|null}>
     */
    public function recent(int $limit): array;
}
