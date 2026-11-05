<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\Persistence;

use Bowlingliga\Domain\Message;
use Bowlingliga\Persistence\AbstractRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\RepositoryException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Integration test of the transactional persistence layer against a local
 * MySQL test database.
 *
 * Validates: R4.1, R4.3, R4.5
 *
 * - R4.1: a created record is persisted in the MySQL database and
 *   stays retrievable.
 * - R4.3: if a write operation fails, the operation concerned is
 *   rolled back completely, so that no partially written data remains; the
 *   exception thrown names the operation.
 * - R4.5: a read operation returns the previously persisted record
 *   unchanged in content.
 *
 * The test needs a reachable local MySQL test database and the PDO MySQL
 * driver. If either is missing (e.g. in a pure build environment), the test
 * is skipped in a controlled way (markTestSkipped) instead of failing.
 *
 * The connection parameters are read only from environment variables, so that
 * no credentials are stored in the source code:
 *
 *   BOWLINGLIGA_TEST_DB_HOST     (default: 127.0.0.1)
 *   BOWLINGLIGA_TEST_DB_PORT     (default: 3306)
 *   BOWLINGLIGA_TEST_DB_NAME     (default: bowlingliga_test)
 *   BOWLINGLIGA_TEST_DB_USER     (default: root)
 *   BOWLINGLIGA_TEST_DB_PASSWORD (default: empty)
 *   BOWLINGLIGA_TEST_DB_CHARSET  (default: utf8mb4)
 *
 * The table is created per test and removed afterwards, so that the test is
 * repeatable and runs without migration prerequisites.
 */
final class TransactionRollbackIntegrationTest extends TestCase
{
    private const TABLE = 'integration_transaktion_test';

    private Database $database;

    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The PDO MySQL driver is not available; integration test skipped.');
        }

        $this->database = new Database($this->testConfiguration());

        try {
            $this->pdo = $this->database->pdo();
            // Actively verify the connection, so that a pure connection error
            // counts as "skipped" and not as a test failure.
            $this->pdo->query('SELECT 1');
        } catch (Throwable $throwable) {
            self::markTestSkipped(
                'No reachable MySQL test database: ' . $throwable->getMessage()
            );
        }

        $this->createTestTable();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            // Roll back a transaction left open (e.g. after an unexpected error),
            // so that the subsequent DROP does not block.
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        }
    }

    /**
     * R4.1 and R4.5: a record written transactionally is persisted
     * and returned unchanged in content by a read operation.
     */
    public function testWrittenRecordIsReadUnchanged(): void
    {
        $repository = $this->repository();

        $input = [
            'name'  => 'Käte Müller-Groß',
            'net' => 217,
        ];

        $id = $repository->writeRecord($input['name'], $input['net']);

        $read = $repository->findById($id);

        self::assertNotNull($read, 'The persisted record must be retrievable (R4.1).');
        // R4.5: return of the stored values unchanged in content.
        self::assertSame($input['name'], $read['name']);
        self::assertSame($input['net'], (int) $read['net']);
    }

    /**
     * R4.3: if a write operation fails in the middle of its
     * execution, the whole operation is rolled back; no partially written rows
     * remain.
     */
    public function testErrorInMiddleOfOperationRollsBackCompletely(): void
    {
        $repository = $this->repository();

        try {
            $repository->writeTwoAndFail('Erste Zeile', 'Zweite Zeile');
            self::fail('No RepositoryException was thrown (R4.3).');
        } catch (RepositoryException $exception) {
            // R4.3: the error message names the operation (catalog key of its name).
            $operation = $exception->message()->params['operation'];
            self::assertInstanceOf(Message::class, $operation);
            self::assertSame('operation.writeTwoAndFail', $operation->key);
        }

        // R4.3: complete rollback – neither of the two rows of the
        // failed operation may be persisted.
        self::assertSame(0, $repository->recordCount(), 'No partial writes may remain after the rollback.');
    }

    /**
     * R4.3: after a rollback the database stays in a consistent
     * state, so that a subsequent valid write operation is persisted normally
     * (no "stuck" transaction state).
     */
    public function testAfterRollbackFurtherWriteOperationSucceeds(): void
    {
        $repository = $this->repository();

        try {
            $repository->writeTwoAndFail('A', 'B');
        } catch (RepositoryException) {
            // expected
        }

        $id = $repository->writeRecord('Nach Rollback', 42);

        self::assertSame(1, $repository->recordCount());
        $read = $repository->findById($id);
        self::assertNotNull($read);
        self::assertSame('Nach Rollback', $read['name']);
    }

    private function repository(): TestTransactionRepository
    {
        return new TestTransactionRepository($this->database, self::TABLE);
    }

    private function createTestTable(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->pdo->exec(
            'CREATE TABLE ' . self::TABLE . ' (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                net SMALLINT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Connection parameters of the test database from environment variables
     * (no hard-coded credentials).
     *
     * @return array<string, mixed>
     */
    private function testConfiguration(): array
    {
        return [
            'host'            => self::env('BOWLINGLIGA_TEST_DB_HOST', '127.0.0.1'),
            'port'            => (int) self::env('BOWLINGLIGA_TEST_DB_PORT', '3306'),
            'dbname'          => self::env('BOWLINGLIGA_TEST_DB_NAME', 'bowlingliga_test'),
            'user'            => self::env('BOWLINGLIGA_TEST_DB_USER', 'root'),
            'password'        => self::env('BOWLINGLIGA_TEST_DB_PASSWORD', ''),
            'charset'         => self::env('BOWLINGLIGA_TEST_DB_CHARSET', 'utf8mb4'),
            'connect_timeout' => 5,
        ];
    }

    private static function env(string $name, string $standard): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $standard : $value;
    }
}

/**
 * Concrete repository subclass meant only for the test, which makes the
 * protected helper methods of {@see AbstractRepository} (in particular
 * {@see AbstractRepository::transactional()}) available via slim, testable
 * methods.
 */
final class TestTransactionRepository extends AbstractRepository
{
    public function __construct(Database $database, private readonly string $table)
    {
        parent::__construct($database);
    }

    /**
     * Writes a record transactionally and returns its id (R4.1,
     * 4.3).
     */
    public function writeRecord(string $name, int $net): int
    {
        return $this->transactional('writeRecord', function (PDO $pdo) use ($name, $net): int {
            $this->perform(
                'INSERT INTO ' . $this->table . ' (name, net) VALUES (:name, :net)',
                ['name' => $name, 'net' => $net]
            );

            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Writes a first row and then deliberately fails within the same
     * transaction, to provoke the complete rollback (R4.3). After the
     * rollback the first row must not be persisted either.
     */
    public function writeTwoAndFail(string $firstRow, string $secondRow): void
    {
        $this->transactional('writeTwoAndFail', function () use ($firstRow, $secondRow): void {
            $this->perform(
                'INSERT INTO ' . $this->table . ' (name, net) VALUES (:name, :net)',
                ['name' => $firstRow, 'net' => 100]
            );

            // Simulated write error in the middle of the operation.
            unset($secondRow);
            throw new RuntimeException('Simulierter Schreibfehler mitten in der Operation.');
        });
    }

    /**
     * Returns the record for the id unchanged in content (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, name, net FROM ' . $this->table . ' WHERE id = :id',
            ['id' => $id]
        );
    }

    public function recordCount(): int
    {
        $row = $this->findOne('SELECT COUNT(*) AS quantity FROM ' . $this->table);

        return (int) ($row['quantity'] ?? 0);
    }
}
