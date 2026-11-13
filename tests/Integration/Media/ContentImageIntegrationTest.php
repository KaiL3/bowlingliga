<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\Media;

use Bowlingliga\Persistence\ContentImageRepository;
use Bowlingliga\Persistence\ContentTextRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\DatabaseConnectionException;
use Bowlingliga\Persistence\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration test of the records of the content images and of reading the
 * texts that can use them, against MariaDB.
 *
 * Validates: R42.1, R42.3
 */
final class ContentImageIntegrationTest extends TestCase
{
    private PDO $pdo;
    private Database $database;

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

        $this->dropSchema();
        (new MigrationRunner($this->pdo, dirname(__DIR__, 3) . '/db/migrations'))->run();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
    }

    public function testImagesAreRecordedOnceAndListedNewestFirst(): void
    {
        $images = new ContentImageRepository($this->database);
        $a      = str_repeat('a', 64);
        $b      = str_repeat('b', 64);

        $images->add($a, 'image/jpeg', 2000, 1500, 345678, 'team.jpg', new \DateTimeImmutable('2026-11-13 10:00:00'));
        $images->add($b, 'image/png', 400, 400, 12345, 'logo.png', new \DateTimeImmutable('2026-11-13 11:00:00'));
        $images->add($a, 'image/jpeg', 1, 1, 1, 'nochmal.jpg', new \DateTimeImmutable('2026-11-13 12:00:00'));

        self::assertSame(['logo.png', 'team.jpg'], array_column($images->all(), 'original_name'));
        self::assertSame(
            ['content_hash' => $a, 'mime_type' => 'image/jpeg', 'width' => 2000, 'height' => 1500, 'size_bytes' => 345678, 'original_name' => 'team.jpg', 'uploaded_at' => '2026-11-13 10:00:00'],
            $images->find($a),
            'The same image twice keeps its first record.',
        );

        $images->delete($a);
        self::assertNull($images->find($a));
        self::assertCount(1, $images->all());
    }

    public function testEveryTextThatCanHoldImagesIsRead(): void
    {
        $this->pdo->exec("INSERT INTO home_page_post (id, title, text) VALUES (3, 'Saison &amp; Start', '<p>A</p>'), (5, 'Neu', '<p>B</p>')");
        $this->pdo->exec("INSERT INTO rules (text) VALUES ('<p>Regeln</p>')");
        $this->pdo->exec("INSERT INTO privacy_policy (text) VALUES ('<p>Datenschutz</p>')");

        self::assertSame([
            ['area' => 'home_page', 'id' => 5, 'title' => 'Neu', 'text' => '<p>B</p>'],
            ['area' => 'home_page', 'id' => 3, 'title' => 'Saison &amp; Start', 'text' => '<p>A</p>'],
            ['area' => 'rules', 'id' => null, 'title' => null, 'text' => '<p>Regeln</p>'],
            ['area' => 'privacy_policy', 'id' => null, 'title' => null, 'text' => '<p>Datenschutz</p>'],
        ], (new ContentTextRepository($this->database))->texts());
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
