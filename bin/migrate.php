<?php

declare(strict_types=1);

/**
 * Applies new database migrations (installation and update).
 *
 * Usage: php bin/migrate.php
 * Configuration like the website: config/database.php or the file from the
 * environment variable BOWLINGLIGA_CONFIG.
 */

use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\MigrationRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$configurationFile = getenv('BOWLINGLIGA_CONFIG') ?: dirname(__DIR__) . '/config/database.php';
if (! is_file($configurationFile)) {
    fwrite(STDERR, "Konfiguration fehlt: {$configurationFile}\n");
    exit(1);
}

$pdo = (new Database(require $configurationFile))->pdo();
$fresh = (new MigrationRunner($pdo, dirname(__DIR__) . '/db/migrations'))->run();

echo $fresh === []
    ? "Datenbank ist aktuell, keine neuen Migrationen.\n"
    : 'Eingespielt: ' . implode(', ', $fresh) . "\n";
