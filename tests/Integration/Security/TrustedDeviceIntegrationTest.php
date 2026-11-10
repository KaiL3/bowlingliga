<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\Security;

use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\DatabaseConnectionException;
use Bowlingliga\Persistence\MigrationRunner;
use Bowlingliga\Persistence\TrustedDeviceRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration test of the trusted devices against MariaDB: one token for
 * several accounts, renewing, failed attempts, cleaning up and deleting
 * together with the account.
 *
 * Validates: R5.10
 */
final class TrustedDeviceIntegrationTest extends TestCase
{
    private PDO $pdo;
    private TrustedDeviceRepository $devices;
    private int $anna;
    private int $ben;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $config = [
            'host'            => self::env('BOWLINGLIGA_TEST_DB_HOST', '127.0.0.1'),
            'port'            => (int) self::env('BOWLINGLIGA_TEST_DB_PORT', '3306'),
            'dbname'          => self::env('BOWLINGLIGA_TEST_DB_NAME', 'bowlingliga_test'),
            'user'            => self::env('BOWLINGLIGA_TEST_DB_USER', 'root'),
            'password'        => self::env('BOWLINGLIGA_TEST_DB_PASSWORD', ''),
            'charset'         => 'utf8mb4',
            'connect_timeout' => 5,
        ];

        try {
            $database  = new Database($config);
            $this->pdo = $database->pdo();
        } catch (DatabaseConnectionException $e) {
            self::markTestSkipped('No reachable MySQL test database: ' . $e->getMessage());
        }

        $this->dropSchema();
        (new MigrationRunner($this->pdo, dirname(__DIR__, 3) . '/db/migrations'))->run();
        $this->devices = new TrustedDeviceRepository($database);
        $this->anna    = $this->account('anna');
        $this->ben     = $this->account('ben');
        $this->now     = new \DateTimeImmutable('2026-11-10 19:00:00');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
    }

    public function testOneTokenStandsForSeveralAccountsAndIsRenewed(): void
    {
        $hash = hash('sha256', 'browser');
        $this->devices->save($hash, $this->anna, 1, $this->now, $this->in(90));
        $this->devices->save($hash, $this->ben, 3, $this->now, $this->in(90));

        $anna = $this->devices->find($hash, $this->anna);
        self::assertSame(1, $anna['session_version'] ?? null);
        self::assertSame('2027-02-08 19:00:00', $anna['expires_at'] ?? null);
        self::assertSame(3, $this->devices->find($hash, $this->ben)['session_version'] ?? null);
        self::assertNull($this->devices->find(hash('sha256', 'other'), $this->anna));

        $this->devices->updateFailedAttempts((int) $anna['id'], 3);
        self::assertSame(3, $this->devices->find($hash, $this->anna)['failed_attempts'] ?? null);

        // A new login renews the same record and resets the failed attempts.
        $later = $this->now->add(new \DateInterval('P30D'));
        $this->devices->save($hash, $this->anna, 2, $later, $later->add(new \DateInterval('P90D')));
        $renewed = $this->devices->find($hash, $this->anna);
        self::assertSame($anna['id'] ?? null, $renewed['id'] ?? null);
        self::assertSame(2, $renewed['session_version'] ?? null);
        self::assertSame(0, $renewed['failed_attempts'] ?? null);
        self::assertSame('2027-03-10 19:00:00', $renewed['expires_at'] ?? null);
        self::assertSame(2, $this->deviceCount());

        $this->devices->delete((int) $renewed['id']);
        self::assertNull($this->devices->find($hash, $this->anna));
    }

    public function testPruneKeepsTheRecentDevicesOfTheCurrentSessionVersion(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->devices->save(hash('sha256', "current $i"), $this->anna, 2, $this->now->add(new \DateInterval("PT{$i}M")), $this->in(90));
        }
        $this->devices->save(hash('sha256', 'expired'), $this->anna, 2, $this->now->sub(new \DateInterval('P100D')), $this->now->sub(new \DateInterval('P10D')));
        $this->devices->save(hash('sha256', 'old version'), $this->anna, 1, $this->now, $this->in(90));
        $this->devices->save(hash('sha256', 'ben'), $this->ben, 1, $this->now, $this->in(90));

        $this->devices->prune($this->anna, 2, $this->now, 3);

        self::assertNull($this->devices->find(hash('sha256', 'expired'), $this->anna));
        self::assertNull($this->devices->find(hash('sha256', 'old version'), $this->anna));
        self::assertNull($this->devices->find(hash('sha256', 'current 1'), $this->anna), 'The least recently used one goes.');
        self::assertNotNull($this->devices->find(hash('sha256', 'current 4'), $this->anna));
        self::assertNotNull($this->devices->find(hash('sha256', 'ben'), $this->ben), 'Other accounts are not touched.');
        self::assertSame(4, $this->deviceCount());
    }

    public function testTheDevicesGoWithTheirAccount(): void
    {
        $this->devices->save(hash('sha256', 'browser'), $this->anna, 1, $this->now, $this->in(90));
        $this->pdo->exec('DELETE FROM admin_account WHERE id = ' . $this->anna);

        self::assertSame(0, $this->deviceCount());
    }

    private function in(int $days): \DateTimeImmutable
    {
        return $this->now->add(new \DateInterval("P{$days}D"));
    }

    private function account(string $username): int
    {
        $this->pdo->exec("INSERT INTO admin_account (username, password_hash) VALUES ('$username', 'x')");

        return (int) $this->pdo->lastInsertId();
    }

    private function deviceCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM trusted_device')->fetchColumn();
    }

    private function dropSchema(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }
}
