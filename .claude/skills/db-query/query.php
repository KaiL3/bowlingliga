<?php

declare(strict_types=1);

/**
 * Runs SQL against the database from config/database.php (or the one
 * given with --db) and prints the result as a table.
 *
 * Usage:
 *   php query.php "SELECT * FROM season"
 *   php query.php --file=query.sql
 *   php query.php --db=bowlingliga_test "SHOW TABLES"
 *   php query.php --write "UPDATE …"   (only on explicit request by the user)
 *
 * Without --write the session runs READ ONLY; writing statements then
 * fail. With --write everything runs in one transaction that is only
 * committed if all statements succeed.
 */

$project = dirname(__DIR__, 3);
$options = ['db' => null, 'file' => null, 'write' => false];
$sql = '';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--db=')) {
        $options['db'] = substr($arg, 5);
    } elseif (str_starts_with($arg, '--file=')) {
        $options['file'] = substr($arg, 7);
    } elseif ($arg === '--write') {
        $options['write'] = true;
    } else {
        $sql .= ($sql === '' ? '' : ' ') . $arg;
    }
}

if ($options['file'] !== null) {
    $sql = (string) file_get_contents($options['file']);
}
if (trim($sql) === '') {
    fwrite(STDERR, "No SQL given.\n");
    exit(2);
}

$config = require getenv('BOWLINGLIGA_CONFIG') ?: $project . '/config/database.php';
$db = $options['db'] ?? $config['dbname'];

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $config['port'], $db, $config['charset'] ?? 'utf8mb4'),
    $config['user'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);

echo "-- Database: {$db}" . ($options['write'] ? ' (WRITING)' : ' (read only)') . "\n";

if (! $options['write']) {
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
}

// Split statements at a semicolon at the end of a line (enough for simple queries).
$statements = array_filter(array_map('trim', preg_split('/;\s*(\R|$)/', $sql)), static fn ($s) => $s !== '');

$pdo->beginTransaction();
try {
    foreach ($statements as $statement) {
        echo "\n> " . preg_replace('/\s+/', ' ', $statement) . "\n";
        $stmt = $pdo->query($statement);
        if ($stmt->columnCount() === 0) {
            echo "  {$stmt->rowCount()} row(s) affected\n";
            continue;
        }
        printTable($stmt->fetchAll());
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Error, nothing committed: {$e->getMessage()}\n");
    exit(1);
}

/** @param list<array<string, mixed>> $rows */
function printTable(array $rows): void
{
    if ($rows === []) {
        echo "  (no rows)\n";
        return;
    }
    $columns = array_keys($rows[0]);
    $widths = [];
    foreach ($columns as $column) {
        $widths[$column] = mb_strlen($column);
        foreach ($rows as $r) {
            $widths[$column] = min(60, max($widths[$column], mb_strlen(value($r[$column]))));
        }
    }
    $row = static fn (array $values) => '| ' . implode(' | ', array_map(
        static function ($column) use ($values, $widths): string {
            $text = mb_substr($values[$column], 0, 60);
            return str_pad($text, $widths[$column] + strlen($text) - mb_strlen($text));
        },
        $columns,
    )) . ' |';
    echo $row(array_combine($columns, $columns)) . "\n";
    echo '|' . implode('|', array_map(static fn ($column) => str_repeat('-', $widths[$column] + 2), $columns)) . "|\n";
    foreach ($rows as $r) {
        echo $row(array_map('value', $r)) . "\n";
    }
    echo '  ' . count($rows) . " row(s)\n";
}

function value(mixed $v): string
{
    return match (true) {
        $v === null => 'NULL',
        is_string($v) && ! mb_check_encoding($v, 'UTF-8') => '<binary ' . strlen($v) . ' B>',
        default => (string) $v,
    };
}
