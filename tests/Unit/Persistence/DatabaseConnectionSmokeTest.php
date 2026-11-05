<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Persistence;

use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\DatabaseConnectionException;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test for the connection behaviour of the database.
 *
 * Validates: R4.4
 *
 * R4.4: if the connection to the MySQL database cannot be
 * established within 10 seconds, the system denies read and write access and
 * returns an error message that points to the missing database connection.
 *
 * The test uses an unreachable address, so that establishing the connection
 * fails deterministically without needing a real database.
 */
final class DatabaseConnectionSmokeTest extends TestCase
{
    /**
     * R4.4: if the connection cannot be established, access is
     * denied – signalled by a DatabaseConnectionException.
     */
    public function testAccessIsDeniedWhenConnectionCannotBeEstablished(): void
    {
        $database = new Database($this->unreachableConfiguration());

        $this->expectException(DatabaseConnectionException::class);

        $database->pdo();
    }

    /**
     * R4.4: an error message is returned that points to the missing
     * database connection.
     */
    public function testErrorMessagePointsToMissingDatabaseConnection(): void
    {
        $database = new Database($this->unreachableConfiguration());

        try {
            $database->pdo();
            self::fail('No DatabaseConnectionException was thrown.');
        } catch (DatabaseConnectionException $exception) {
            self::assertStringContainsStringIgnoringCase(
                'database connection',
                $exception->getMessage()
            );
        }
    }

    /**
     * R4.4: the connection attempt aborts within 10 seconds and
     * denies access instead of blocking indefinitely.
     */
    public function testConnectionAttemptAbortsWithinTenSeconds(): void
    {
        $database = new Database($this->unreachableConfiguration());

        $start = microtime(true);

        try {
            $database->pdo();
            self::fail('No DatabaseConnectionException was thrown.');
        } catch (DatabaseConnectionException) {
            $duration = microtime(true) - $start;

            // Tolerance above the 10 second limit to absorb fluctuations of the
            // environment; access must not block indefinitely.
            self::assertLessThan(
                12.0,
                $duration,
                'The connection attempt should refuse access quickly (R4.4).'
            );
        }
    }

    /**
     * R4.4: the default connection timeout is 10 seconds.
     */
    public function testDefaultConnectionTimeoutIsTenSeconds(): void
    {
        self::assertSame(10, Database::DEFAULT_CONNECT_TIMEOUT);
    }

    /**
     * Configuration with a non-routable address (TEST-NET-1, RFC 5737) and a
     * short timeout, so that establishing the connection fails reliably and
     * without a real database.
     *
     * @return array<string, mixed>
     */
    private function unreachableConfiguration(): array
    {
        return [
            'host'            => '192.0.2.1',
            'port'            => 3306,
            'dbname'          => 'bowlingliga_test',
            'user'            => 'bowlingliga_test',
            'password'        => 'irrelevant',
            'charset'         => 'utf8mb4',
            'connect_timeout' => 2,
        ];
    }
}
