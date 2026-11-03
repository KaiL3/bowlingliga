<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\Transaction;
use Throwable;

/**
 * PDO implementation of the {@see Transaction} port (R4.3).
 *
 * Uses the same connection as the repositories; their
 * {@see AbstractRepository::transactional()} detects the running transaction
 * and joins it instead of committing on its own. If a transaction is already
 * running, it is joined as well.
 */
final class PdoTransaction implements Transaction
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function perform(callable $steps): mixed
    {
        $pdo = $this->database->pdo();

        if ($pdo->inTransaction()) {
            return $steps();
        }

        $pdo->beginTransaction();

        try {
            $result = $steps();
            $pdo->commit();

            return $result;
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }
    }
}
