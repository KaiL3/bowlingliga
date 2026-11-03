<?php

declare(strict_types=1);

/**
 * Empties the database for a fresh start but keeps the admin accounts.
 *
 * Usage:
 *   php bin/reset-database.php          shows what would be deleted (no change)
 *   php bin/reset-database.php --yes    deletes all data except admin accounts
 *
 * Kept: `admin_account` (including password and 2FA) and `schema_migration`
 * (the schema stays as it is). Everything else is emptied – seasons, teams,
 * players, results, frozen handicaps, original score sheets, CMS texts,
 * caches and login attempts – and the id counters start again at 1. The
 * files of the original score sheets are removed from the score sheet
 * directory as well.
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

const KEPT_TABLES = ['admin_account', 'schema_migration'];

$configurationFile = getenv('BOWLINGLIGA_CONFIG') ?: dirname(__DIR__) . '/config/database.php';
if (! is_file($configurationFile)) {
    fwrite(STDERR, "Konfiguration fehlt: {$configurationFile}\n");
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

echo "Datenbank: {$configuration['dbname']}\n\n";
echo "Wird geleert:\n";
foreach ($emptied as $table) {
    printf("  %-28s %6d Zeilen\n", $table, $count($table));
}
echo "\nBleibt erhalten:\n";
foreach (array_intersect(KEPT_TABLES, $tables) as $table) {
    printf("  %-28s %6d Zeilen\n", $table, $count($table));
}

if (! $confirmed) {
    echo "\nNichts geändert. Zum Leeren erneut mit --yes aufrufen.\n";
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
    fwrite(STDERR, "Fehler, nichts geändert: {$e->getMessage()}\n");
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

echo "\nGeleert, {$removed} Spielzettel-Dateien gelöscht. Die Adminkonten sind erhalten.\n";
