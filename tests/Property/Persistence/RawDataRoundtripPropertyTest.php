<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Persistence;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Persistence\AbstractRepository;
use Bowlingliga\Persistence\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Property test for the raw data roundtrip part of the reproducibility of
 * derived values and cache consistency.
 *
 * Feature: bowlingliga-website, Property 23: Reproducibility of derived values
 * and cache consistency (raw data roundtrip part: raw data read is identical
 * to raw data written).
 *
 * Validates: R35.2
 *
 * Domain background: the raw data is the single source of truth (R35.1). So
 * that a recalculation of a derived value (R35.2) gives the same result as a
 * previously cached value, the underlying precondition must hold: raw data
 * that was written is returned unchanged in content when read (R4.5). This
 * test only checks this roundtrip part (write -> read == identical). The
 * complete cache consistency part (recalculation == valid cache, behaviour
 * after cache invalidation) is the subject of task 24.3.
 *
 * The test needs a reachable local MySQL test database and the PDO MySQL
 * driver. If either is missing (e.g. in a pure build environment), the test
 * is skipped in a controlled way (markTestSkipped) instead of failing –
 * consistent with the pattern of
 * {@see \Bowlingliga\Tests\Integration\Persistence\TransactionRollbackIntegrationTest}.
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
 * A separate test table with the same structure as the raw data repository
 * `player` (db/migrations/001_schema.sql: name VARCHAR(100),
 * gender ENUM('male','female')) is used. The table is created per
 * test run and removed again, so that the test runs repeatably without
 * migration prerequisites.
 */
final class RawDataRoundtripPropertyTest extends TestCase
{
    use TestTrait;

    private const TABLE = 'property_rohdaten_roundtrip_spieler';

    /**
     * @var 'male'|'female'[]
     */
    private const GENDERS = ['male', 'female'];

    private ?Database $database = null;

    private ?PDO $pdo = null;

    /**
     * Property 23 (raw data roundtrip): for every generated raw record (player
     * name, gender) the record read from the database is identical in content
     * to the one written.
     *
     * Validates: R35.2 (presupposes R4.5 as roundtrip invariant).
     *
     * At least 100 inputs are generated (limitTo(100)); the precondition check
     * (available test DB) happens lazily, so that the test is skipped in a
     * controlled way without a DB.
     */
    public function testWrittenRawDataIsReadIdentically(): void
    {
        $repository = $this->repositoryOrSkip();

        // Names from Eris' built-in, mixed name data set.
        // This gives realistic, non-empty names
        // (including multibyte characters) without error-prone byte-wise splitting
        // of UTF-8 strings. A prepended character and the
        // limit to 100 characters respect the schema condition
        // chk_spieler_name_nicht_leer (length 1–100).
        $nameGenerator = Generators::map(
            static function (string $name): string {
                $sanitized = trim($name);
                if ($sanitized === '') {
                    $sanitized = 'Spieler';
                }

                return mb_substr($sanitized, 0, 100);
            },
            Generators::names()
        );

        $genderGenerator = Generators::elements(...self::GENDERS);

        $this
            ->limitTo(100)
            ->forAll($nameGenerator, $genderGenerator)
            ->then(function (string $name, string $gender) use ($repository): void {
                $id = $repository->write($name, $gender);

                $read = $repository->find($id);

                self::assertNotNull(
                    $read,
                    'The written raw data record must be retrievable (R35.1/R4.1).'
                );
                // Raw data roundtrip: raw data read == raw data written
                // (unchanged in content, R4.5 as precondition for R35.2).
                self::assertSame($name, $read['name']);
                self::assertSame($gender, $read['gender']);
            });
    }

    protected function tearDown(): void
    {
        if ($this->pdo instanceof PDO) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        }
    }

    /**
     * Returns a ready-to-use test repository or skips the test in a controlled
     * way if no MySQL test database is reachable.
     */
    private function repositoryOrSkip(): TestRawDataRoundtripRepository
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped(
                'The PDO MySQL driver is not available; property test skipped.'
            );
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

        return new TestRawDataRoundtripRepository($this->database, self::TABLE);
    }

    private function createTestTable(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        // Structure like the raw data table `player`
        // (db/migrations/001_schema.sql).
        $this->pdo->exec(
            'CREATE TABLE ' . self::TABLE . ' (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                gender ENUM(\'male\', \'female\') NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
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
 * Test-specific repository subclass that makes the protected helper methods
 * of {@see AbstractRepository} available via slim, testable methods (like the
 * pattern of the transaction integration test). It encapsulates exactly the
 * raw data operations of the player repository (writing/reading name +
 * gender) that this roundtrip test depends on.
 */
final class TestRawDataRoundtripRepository extends AbstractRepository
{
    public function __construct(Database $database, private readonly string $table)
    {
        parent::__construct($database);
    }

    /**
     * Writes a raw record transactionally and returns its id (R4.1).
     *
     * @param 'male'|'female' $gender
     */
    public function write(string $name, string $gender): int
    {
        return $this->transactional('Rohdaten schreiben', function (PDO $pdo) use ($name, $gender): int {
            $this->perform(
                'INSERT INTO ' . $this->table . ' (name, gender) VALUES (:name, :gender)',
                ['name' => $name, 'gender' => $gender]
            );

            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Returns the raw record for the id unchanged in content (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, name, gender FROM ' . $this->table . ' WHERE id = :id',
            ['id' => $id]
        );
    }
}
