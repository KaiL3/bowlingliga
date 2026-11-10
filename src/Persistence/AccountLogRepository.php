<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Security\AccountLogStore;

/**
 * Raw data repository for the log of changes to accounts and permissions
 * (table `account_log`, R23.14).
 */
final class AccountLogRepository extends AbstractRepository implements AccountLogStore
{
    public function record(
        \DateTimeImmutable $at,
        ?int $actorId,
        ?string $actorName,
        string $targetName,
        string $action,
        ?string $permission = null,
    ): void {
        $this->transactional('record_account_log', function () use ($at, $actorId, $actorName, $targetName, $action, $permission): void {
            $this->perform(
                'INSERT INTO account_log (created_at, actor_account_id, actor_name, target_name, action, permission)
                 VALUES (:at, :actor, :actor_name, :target, :action, :permission)',
                [
                    'at'         => $at->format('Y-m-d H:i:s'),
                    'actor'      => $actorId,
                    'actor_name' => $actorName,
                    'target'     => $targetName,
                    'action'     => $action,
                    'permission' => $permission,
                ]
            );
        });
    }

    public function recent(int $limit): array
    {
        $rows = $this->findAll(
            'SELECT created_at, actor_name, target_name, action, permission FROM account_log
             ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit)
        );

        return array_map(static fn (array $row): array => [
            'created_at'  => (string) $row['created_at'],
            'actor_name'  => $row['actor_name'] !== null ? (string) $row['actor_name'] : null,
            'target_name' => (string) $row['target_name'],
            'action'      => (string) $row['action'],
            'permission'  => $row['permission'] !== null ? (string) $row['permission'] : null,
        ], $rows);
    }
}
