<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use PDO;

/**
 * Applies the numbered SQL migrations from `db/migrations/` and records in the
 * table `schema_migration` which ones have already run.
 *
 * For installation and updates on the server: running it again only applies
 * new migrations. `001_schema.sql` holds the complete schema of a new
 * installation; later changes follow as `002_…`. All migrations are
 * idempotent (`IF NOT EXISTS`), so that even a database from before the log
 * table is brought up to date safely.
 *
 * DDL statements end every transaction in MariaDB; each migration is
 * therefore run on its own and only logged afterwards.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /**
     * Applies all migrations not yet logged, in name order.
     *
     * @return list<string> File names of the newly applied migrations
     */
    public function run(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migration (
                name VARCHAR(190) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (name)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
        );

        $known = array_flip($this->pdo->query('SELECT name FROM schema_migration')->fetchAll(PDO::FETCH_COLUMN));

        $fresh = [];
        foreach ($this->files() as $pathName) {
            $name = basename($pathName);
            if (isset($known[$name])) {
                continue;
            }

            $this->pdo->exec((string) file_get_contents($pathName));
            $entry = $this->pdo->prepare('INSERT INTO schema_migration (name, applied_at) VALUES (:name, NOW())');
            $entry->execute(['name' => $name]);
            $fresh[] = $name;
        }

        return $fresh;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob(rtrim($this->directory, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }
}
