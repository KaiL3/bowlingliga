<?php

declare(strict_types=1);

/**
 * Checks the original score sheets against their files (R3.15, R35).
 *
 * Usage:
 *   php bin/check-score-sheets.php            checks every record: file present,
 *                                             content matches the hash; changes nothing
 *   php bin/check-score-sheets.php --clean    additionally deletes files that no
 *                                             record references any more (older than
 *                                             15 minutes, so uploads in progress stay)
 *
 * Exit code 0 if everything is in order, 1 if files are missing or damaged
 * (suitable for a cron job that reports problems).
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

$configurationFile = getenv('BOWLINGLIGA_CONFIG') ?: dirname(__DIR__) . '/config/database.php';
if (! is_file($configurationFile)) {
    fwrite(STDERR, "Konfiguration fehlt: {$configurationFile}\n");
    exit(1);
}

$config = require $configurationFile;
$files = FileSystemBlobStore::forScoreSheets($config, dirname(__DIR__));
$repository = new OriginalScoreSheetRepository(new Database($config), $files);

$result = $repository->verifyFiles();
echo "Verzeichnis: {$files->directory()}\n";
echo "Geprüft: {$result['checked']} Spielzettel\n";

$problem = false;
foreach (['missing' => 'Datei fehlt', 'damaged' => 'Inhalt passt nicht zum Hash'] as $key => $text) {
    if ($result[$key] !== []) {
        echo "  {$text}: Spielzettel " . implode(', ', $result[$key]) . "\n";
        $problem = true;
    }
}

if (in_array('--clean', array_slice($argv, 1), true)) {
    $removed = $repository->removeOrphanedFiles();
    echo "Nicht mehr benötigte Dateien gelöscht: {$removed}\n";
}

echo $problem ? "Es gibt Probleme, siehe oben.\n" : "Alles in Ordnung.\n";
exit($problem ? 1 : 0);
