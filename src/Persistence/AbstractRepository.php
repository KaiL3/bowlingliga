<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Base repository of the persistence layer (R4).
 *
 * Responsibilities:
 * - R4.1: persist records in the MySQL database; all data access
 *   happens exclusively via PDO prepared statements, to ensure correct type
 *   binding as well as protection against SQL injection.
 * - R4.3: write operations run transactionally via
 *   {@see AbstractRepository::transactional()}. If an operation fails, it is
 *   rolled back completely, so that no partially written data remains; the
 *   {@see RepositoryException} thrown names the operation concerned.
 * - R4.5: read operations return the previously persisted records
 *   unchanged in content; the stored values are not transformed.
 *
 * Concrete aggregate repositories extend this class and use the protected
 * helper methods for their CRUD operations.
 */
abstract class AbstractRepository
{
    public function __construct(protected readonly Database $database)
    {
    }

    /**
     * Runs the given operation within a database transaction (requirement
     * 4.3).
     *
     * On success the transaction is committed and the operation's return value
     * is passed through. If an error occurs within the operation, the
     * transaction is rolled back completely, so that no partially written data
     * remains, and a {@see RepositoryException} is thrown whose message names
     * the failed operation.
     *
     * Nested calls (transaction already running) are detected: in that case
     * the operation runs within the outer transaction without beginning or
     * ending a transaction of its own.
     *
     * @template T
     * @param string          $operation Key of the operation name for the error message
     *                                   (catalog key `operation.<key>`).
     * @param callable(PDO):T $execution The operation to run in the transaction context.
     * @return T Return value of the operation.
     *
     * @throws RepositoryException if the operation fails (after rollback).
     */
    protected function transactional(string $operation, callable $execution): mixed
    {
        $pdo = $this->database->pdo();

        // An (outer) transaction is already running: start no transaction of our own,
        // so that the rollback stays reserved for the outer transaction.
        if ($pdo->inTransaction()) {
            try {
                return $execution($pdo);
            } catch (Throwable $throwable) {
                throw RepositoryException::forOperation($operation, $throwable);
            }
        }

        $pdo->beginTransaction();

        try {
            $result = $execution($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $throwable) {
            // R4.3: complete rollback, no partial writes.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw RepositoryException::forOperation($operation, $throwable);
        }
    }

    /**
     * Prepares an SQL statement as prepared statement and executes it with the
     * given parameters (R4.1).
     *
     * The parameters are only bound (not interpolated into the SQL), so that
     * prepared statements are used throughout.
     *
     * @param string                           $sql       The SQL statement with named or positional placeholders.
     * @param array<string, mixed>|list<mixed> $parameter The parameters to bind.
     */
    protected function perform(string $sql, array $parameter = []): PDOStatement
    {
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($parameter);

        return $statement;
    }

    /**
     * Returns exactly one row as associative array, or null if no row was found
     * (R4.5: unchanged return).
     *
     * @param string                           $sql
     * @param array<string, mixed>|list<mixed> $parameter
     * @return array<string, mixed>|null
     */
    protected function findOne(string $sql, array $parameter = []): ?array
    {
        $row = $this->perform($sql, $parameter)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Returns all rows as a list of associative arrays (R4.5:
     * unchanged return of the persisted records).
     *
     * @param string                           $sql
     * @param array<string, mixed>|list<mixed> $parameter
     * @return list<array<string, mixed>>
     */
    protected function findAll(string $sql, array $parameter = []): array
    {
        return $this->perform($sql, $parameter)->fetchAll(PDO::FETCH_ASSOC);
    }
}
