<?php

declare(strict_types=1);

/**
 * Empties the database for a fresh start but keeps the admin accounts.
 *
 * Usage:
 *   php bin/reset-database.php          shows what would be deleted (no change)
 *   php bin/reset-database.php --yes    deletes all data except admin accounts
 *
 * Kept: `admin_account` (including password and 2FA) with its permissions,
 * log, trusted devices and password history, the password policies and
 * `schema_migration` (the schema stays as it is). Everything else is emptied – seasons, teams,
 * players, results, frozen handicaps, original score sheets, CMS texts,
 * caches, login attempts and images – and the id counters start again at
 * 1. The files of the original score sheets and of the images are removed
 * from their directories as well.
 *
 * Configuration like the website: config/database.php or the file from the
 * environment variable BOWLINGLIGA_CONFIG.
 */

use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\FileSystemBlobStore;
use Bowlingliga\Persistence\OriginalScoreSheetRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const KEPT_TABLES = [
    'admin_account', 'account_permission', 'account_log', 'trusted_device',
    'password_policy', 'password_history', 'schema_migration',
];

$configurationFile = getenv('BOWLINGLIGA_CONFIG') ?: dirname(__DIR__) . '/config/database.php';
if (! is_file($configurationFile)) {
    fwrite(STDERR, "Configuration missing: {$configurationFile}\n");
    exit(1);
}

$configuration = require $configurationFile;
$database = new Database($configuration);
$pdo = $database->pdo();
$confirmed = in_array('--yes', array_slice($argv, 1), true);

$tables = $pdo->query(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
     ORDER BY TABLE_NAME"
)->fetchAll(PDO::FETCH_COLUMN);
$emptied = array_values(array_diff($tables, KEPT_TABLES));

$count = static fn (string $table): int => (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();

echo "Database: {$configuration['dbname']}\n\n";
echo "Will be emptied:\n";
foreach ($emptied as $table) {
    printf("  %-28s %6d rows\n", $table, $count($table));
}
echo "\nKept:\n";
foreach (array_intersect(KEPT_TABLES, $tables) as $table) {
    printf("  %-28s %6d rows\n", $table, $count($table));
}

if (! $confirmed) {
    echo "\nNothing changed. Run again with --yes to empty the database.\n";
    exit(0);
}

// DELETE instead of TRUNCATE: it runs inside one transaction, so a failure
// leaves the database untouched. Foreign keys are switched off for the order.
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->beginTransaction();
try {
    foreach ($emptied as $table) {
        $pdo->exec("DELETE FROM `{$table}`");
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    fwrite(STDERR, "Error, nothing changed: {$e->getMessage()}\n");
    exit(1);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// Let the ids start again at 1 (ALTER TABLE commits implicitly, so only after
// the data is gone).
foreach ($emptied as $table) {
    $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
}

// The records are gone, so every score sheet file is unreferenced now.
$removed = (new OriginalScoreSheetRepository($database, FileSystemBlobStore::forScoreSheets($configuration, dirname(__DIR__))))
    ->removeOrphanedFiles(0);

// The same for the images of the content (R42): their records are gone too.
$media         = FileSystemBlobStore::forMedia($configuration, dirname(__DIR__));
$removedImages = 0;
foreach ($media->hashesOlderThan(0) as $hash) {
    $media->delete($hash);
    $removedImages++;
}

echo "\nEmptied, {$removed} score sheet files and {$removedImages} images deleted. The admin accounts are kept.\n";
