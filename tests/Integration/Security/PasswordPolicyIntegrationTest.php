<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\Security;

use Bowlingliga\Persistence\AdminAccountRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\DatabaseConnectionException;
use Bowlingliga\Persistence\MigrationRunner;
use Bowlingliga\Persistence\PasswordHistoryRepository;
use Bowlingliga\Persistence\PasswordPolicyRepository;
use Bowlingliga\Security\AccountKind;
use Bowlingliga\Security\PasswordPolicy;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration test of the password policies, the history and the record of a
 * change against MariaDB: the migration brings the defaults and dates the
 * existing passwords, saving overwrites per kind, the history keeps only
 * the newest hashes of each account and goes with it.
 *
 * Validates: R23.15, R23.17, R23.18
 */
final class PasswordPolicyIntegrationTest extends TestCase
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

    public function testTheMigrationBringsTheDefaultsAndDatesExistingPasswords(): void
    {
        foreach (['001_schema.sql', '002_points_rules.sql', '003_players_public.sql', '004_matchday_cancelled.sql', '005_trusted_device.sql', '006_account_permission.sql'] as $file) {
            $this->pdo->exec((string) file_get_contents($this->migrations . '/' . $file));
        }
        $this->pdo->exec("INSERT INTO admin_account (username, password_hash) VALUES ('anna', 'x')");

        $migration = (string) file_get_contents($this->migrations . '/007_password_policy.sql');
        $this->pdo->exec($migration);

        $account = (new AdminAccountRepository($this->database))->findByUsername('anna');
        self::assertNotNull($account['password_changed_at'], 'The age of an existing password starts with the update.');
        self::assertNull($account['password_bits']);
        self::assertSame(0, (int) $account['must_change_password']);

        $policies = new PasswordPolicyRepository($this->database);
        $policies->save(AccountKind::Admin, new PasswordPolicy(90, 365, 10));
        $this->pdo->exec($migration);
        self::assertSame(90, $policies->policyFor(AccountKind::Admin)->minBits, 'Running the migration again keeps a changed policy.');
        self::assertSame(50, $policies->policyFor(AccountKind::User)->minBits);
    }

    public function testPoliciesAreSavedPerKind(): void
    {
        (new MigrationRunner($this->pdo, $this->migrations))->run();
        $policies = new PasswordPolicyRepository($this->database);

        $policies->save(AccountKind::User, new PasswordPolicy(55, -1, 0));

        $user  = $policies->policyFor(AccountKind::User);
        $admin = $policies->policyFor(AccountKind::Admin);
        self::assertSame([55, -1, 0], [$user->minBits, $user->maxAgeDays, $user->historySize]);
        self::assertSame([70, -1, 5], [$admin->minBits, $admin->maxAgeDays, $admin->historySize]);

        $this->pdo->exec('DELETE FROM password_policy');
        self::assertSame(70, $policies->policyFor(AccountKind::Admin)->minBits, 'Without a row the default applies.');
    }

    public function testTheHistoryKeepsTheNewestHashesOfEachAccount(): void
    {
        (new MigrationRunner($this->pdo, $this->migrations))->run();
        $accounts = new AdminAccountRepository($this->database);
        $anna     = $accounts->create('anna', 'h0');
        $ben      = $accounts->create('ben', 'b0');
        $history  = new PasswordHistoryRepository($this->database);
        $at       = new \DateTimeImmutable('2026-11-12 10:00:00');

        foreach (['h1', 'h2', 'h3', 'h4'] as $hash) {
            $history->add($anna, $hash, $at, 3);
        }
        $history->add($ben, 'b1', $at, 3);

        self::assertSame(['h4', 'h3', 'h2'], $history->recent($anna, 10));
        self::assertSame(['h4', 'h3'], $history->recent($anna, 2));
        self::assertSame([], $history->recent($anna, 0));
        self::assertSame(['b1'], $history->recent($ben, 5));

        $accounts->delete($anna);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM password_history WHERE account_id = ' . $anna)->fetchColumn(), 'The history goes with its account.');
    }

    public function testAChangeIsRecordedWithStrengthAndTime(): void
    {
        (new MigrationRunner($this->pdo, $this->migrations))->run();
        $accounts = new AdminAccountRepository($this->database);
        $anna     = $accounts->create('anna', 'h0');

        $accounts->recordPasswordChange($anna, 78, new \DateTimeImmutable('2026-11-12 10:30:00'), true);

        $account = $accounts->find($anna);
        self::assertSame('2026-11-12 10:30:00', $account['password_changed_at']);
        self::assertSame(78, (int) $account['password_bits']);
        self::assertSame(1, (int) $account['must_change_password']);
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
