<?php

declare(strict_types=1);

/**
 * Transfers the data of one league database into another, e.g. the local demo
 * database to the server as an online demo.
 *
 * Usage:
 *   php bin/transfer-data.php export <file.json.gz>
 *        reads the database from config/database.php (locally: the demo DB), changes nothing
 *   php bin/transfer-data.php import <file.json.gz> [--with-legal-texts] [--yes]
 *        without --yes only shows what would be replaced; with --yes it replaces
 *        the target's complete data (seasons, teams, players, results, texts,
 *        score sheet photos). Admin accounts stay, caches are emptied, legal
 *        notice and privacy policy are kept without --with-legal-texts.
 *        The score sheet files travel in the export and are written into the
 *        target's score_sheet_dir.
 *
 * Configuration like the website: config/database.php or the file from the
 * environment variable BOWLINGLIGA_CONFIG. Both databases must be on the
 * same migration level (bin/migrate.php).
 */

use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\FileSystemBlobStore;
use Bowlingliga\Persistence\OriginalScoreSheetRepository;
use Bowlingliga\Persistence\LeagueDataTransfer;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$action          = $argv[1] ?? '';
$file           = $argv[2] ?? '';
$withLegalTexts = in_array('--with-legal-texts', $argv, true);
$confirmed      = in_array('--yes', $argv, true);
if (! in_array($action, ['export', 'import'], true) || $file === '' || str_starts_with($file, '--')) {
    fwrite(STDERR, "Aufruf: php bin/transfer-data.php export <datei.json.gz>\n"
        . "       php bin/transfer-data.php import <datei.json.gz> [--with-legal-texts] [--yes]\n");
    exit(2);
}

$configurationFile = getenv('BOWLINGLIGA_CONFIG') ?: dirname(__DIR__) . '/config/database.php';
if (! is_file($configurationFile)) {
    fwrite(STDERR, "Konfiguration fehlt: {$configurationFile}\n");
    exit(1);
}
$config = require $configurationFile;

try {
    $database = new Database($config);
    $files    = FileSystemBlobStore::forScoreSheets($config, dirname(__DIR__));
    $transfer = new LeagueDataTransfer($database->pdo(), $files);

    if ($action === 'export') {
        $export = $transfer->export();
        $content = gzencode(json_encode($export, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 9);
        if ($content === false || file_put_contents($file, $content) === false) {
            throw new RuntimeException("Datei {$file} konnte nicht geschrieben werden.");
        }
        $rows = array_sum(array_map(static fn (array $t): int => count($t['rows']), $export['tables']));
        printf("Exportiert aus %s: %d Tabellen, %d Zeilen → %s (%.1f MB)\n",
            $config['dbname'] ?? '?', count($export['tables']), $rows, $file, strlen($content) / 1048576);
        exit(0);
    }

    $raw = is_file($file) ? file_get_contents($file) : false;
    $json = $raw === false ? false : gzdecode($raw);
    if ($json === false) {
        throw new RuntimeException("Datei {$file} fehlt oder ist kein gültiger Export.");
    }
    $export = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    echo "Ziel: Datenbank {$config['dbname']}, Export vom {$export['created_at']}\n\n";
    printf("  %-24s %8s %8s\n", 'Tabelle', 'vorher', 'nachher');
    foreach ($transfer->preview($export, $withLegalTexts) as $table => $quantity) {
        printf("  %-24s %8d %8d\n", $table, $quantity['before'], $quantity['after']);
    }
    echo "\nAdminkonten bleiben erhalten, die Caches werden geleert.\n";
    if (! $withLegalTexts) {
        echo 'Unverändert bleiben: ' . implode(', ', LeagueDataTransfer::LEGAL_TEXTS) . " (sonst --with-legal-texts).\n";
    }

    if (! $confirmed) {
        echo "\nNichts geändert. Zum Ersetzen erneut mit --yes aufrufen.\n";
        exit(0);
    }

    $transfer->import($export, $withLegalTexts);
    // Score sheet files of the replaced data are no longer referenced.
    $removed = (new OriginalScoreSheetRepository($database, $files))->removeOrphanedFiles(0);
    echo "\nDatenbestand ersetzt, " . count($export['files'] ?? []) . " Spielzettel-Dateien übernommen, {$removed} nicht mehr benötigte gelöscht.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Fehler: ' . $error->getMessage() . "\n");
    exit(1);
}
