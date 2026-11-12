<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\Security;

use Bowlingliga\Persistence\AccountLogRepository;
use Bowlingliga\Persistence\AccountPermissionRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\DatabaseConnectionException;
use Bowlingliga\Persistence\MigrationRunner;
use Bowlingliga\Security\AccountLogStore;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration test of the account permissions and their log against MariaDB:
 * the migration hands the existing accounts what they could do so far and
 * never undoes a revocation; permissions go with their account, log entries
 * stay.
 *
 * Validates: R23.10, R23.13, R23.14
 */
final class AccountPermissionIntegrationTest extends TestCase
{
    private PDO $pdo;
    private Database $database;
    private string $migrations;

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
            $this->database = new Database($config);
            $this->pdo      = $this->database->pdo();
        } catch (DatabaseConnectionException $e) {
            self::markTestSkipped('No reachable MySQL test database: ' . $e->getMessage());
        }

        $this->migrations = dirname(__DIR__, 3) . '/db/migrations';
        $this->dropSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
    }

    public function testExistingAccountsKeepWhatTheyCouldDoAndARevocationStays(): void
    {
        // State before the permissions: two accounts.
        foreach (['001_schema.sql', '002_points_rules.sql', '003_players_public.sql', '004_matchday_cancelled.sql', '005_trusted_device.sql'] as $file) {
            $this->pdo->exec((string) file_get_contents($this->migrations . '/' . $file));
        }
        $anna = $this->account('anna');
        $ben  = $this->account('ben');

        $migration = (string) file_get_contents($this->migrations . '/006_account_permission.sql');
        $this->pdo->exec($migration);
        $permissions = new AccountPermissionRepository($this->database);
        self::assertSame(['accounts.manage'], $permissions->permissionsOf($anna), 'Introduced as "manage admin accounts".');

        // Migration 007 renames it to system admin; whoever held it keeps it.
        $renaming = (string) file_get_contents($this->migrations . '/007_password_policy.sql');
        $this->pdo->exec($renaming);
        self::assertSame(['system.admin'], $permissions->permissionsOf($anna));
        self::assertSame([$anna, $ben], $permissions->holders('system.admin'));

        $permissions->replace($ben, []);
        $this->pdo->exec($migration);
        $this->pdo->exec($renaming);
        self::assertSame([], $permissions->permissionsOf($ben), 'Running the migrations again does not undo a revocation.');

        $this->pdo->exec("DELETE FROM admin_account WHERE id = $anna");
        self::assertSame([], $permissions->holders('system.admin'), 'The permissions go with their account.');
    }

    public function testTheLogKeepsNamesAndShowsTheNewestFirst(): void
    {
        (new MigrationRunner($this->pdo, $this->migrations))->run();
        $anna = $this->account('anna');
        $log  = new AccountLogRepository($this->database);

        $log->record(new \DateTimeImmutable('2026-11-10 19:00:00'), null, null, 'anna', AccountLogStore::CREATED);
        $log->record(new \DateTimeImmutable('2026-11-10 19:05:00'), $anna, 'anna', 'ben', AccountLogStore::GRANTED, 'system.admin');
        $this->pdo->exec("DELETE FROM admin_account WHERE id = $anna");

        self::assertSame([
            ['created_at' => '2026-11-10 19:05:00', 'actor_name' => 'anna', 'target_name' => 'ben', 'action' => 'granted', 'permission' => 'system.admin'],
            ['created_at' => '2026-11-10 19:00:00', 'actor_name' => null, 'target_name' => 'anna', 'action' => 'created', 'permission' => null],
        ], $log->recent(10));
        self::assertCount(1, $log->recent(1));
    }

    private function account(string $username): int
    {
        $this->pdo->exec("INSERT INTO admin_account (username, password_hash) VALUES ('$username', 'x')");

        return (int) $this->pdo->lastInsertId();
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
