<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\BlobStore;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Transfers the data of one league database into another, e.g. the local demo
 * database to the server as an online demo (bin/transfer-data.php).
 *
 * All raw data is transferred with its ids. Never transferred are admin
 * accounts including login attempts (the target keeps its own; their TOTP
 * secrets depend on the key of the respective installation), the migration
 * log and the caches – they are derived and are emptied in the target
 * (R35.3). Legal notice and privacy policy belong to the operator of the
 * target and are only replaced on explicit request.
 *
 * The import replaces the target's data completely in one transaction.
 *
 * The files of the original score sheets (R3.15) travel in the export under
 * `files` (hash => base64). The import writes them into the target's store
 * before the records; files of the target that are no longer referenced
 * afterwards are removed by the caller
 * ({@see OriginalScoreSheetRepository::removeOrphanedFiles()}).
 */
final class LeagueDataTransfer
{
    public const FORMAT = 1;

    /** Neither exported nor touched in the target. */
    private const NEVER_TRANSFER = ['admin_account', 'login_attempt', 'schema_migration'];

    private const CACHE_PREFIX = 'cache_';

    /** Only replaced in the target on explicit request. */
    public const LEGAL_TEXTS = ['legal_notice', 'privacy_policy'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?BlobStore $files = null,
    ) {
    }

    /**
     * Reads all transferable tables as one consistent state (the source may be
     * in use at the same time).
     *
     * @return array{format: int, created_at: string, tables: array<string, array{columns: list<string>, rows: list<list<mixed>>}>, files: array<string, string>}
     */
    public function export(): array
    {
        $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

        try {
            $tables = [];
            foreach ($this->dataTables() as $table) {
                $columns = $this->columns($table);
                $rows  = [];
                $query = $this->pdo->query('SELECT * FROM `' . $table . '` ORDER BY 1');
                while (($row = $query->fetch(PDO::FETCH_NUM)) !== false) {
                    $rows[] = array_map(self::encode(...), $row);
                }
                $tables[$table] = ['columns' => $columns, 'rows' => $rows];
            }
        } finally {
            $this->pdo->exec('COMMIT');
        }

        return ['format' => self::FORMAT, 'created_at' => date('Y-m-d H:i:s'), 'tables' => $tables, 'files' => $this->exportFiles($tables)];
    }

    /**
     * Checks whether the export matches the target's schema and returns per
     * table the row count before (target) and after (export). Changes nothing.
     *
     * @param array<string, mixed> $export
     *
     * @return array<string, array{before: int, after: int}>
     */
    public function preview(array $export, bool $withLegalTexts): array
    {
        $this->checkSchema($export);

        $preview = [];
        foreach ($this->tablesToReplace($withLegalTexts) as $table) {
            $preview[$table] = [
                'before'  => (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn(),
                'after' => count($export['tables'][$table]['rows']),
            ];
        }

        return $preview;
    }

    /**
     * Replaces the target's data with the export and empties the caches.
     *
     * @param array<string, mixed> $export
     *
     * @return array<string, int> inserted rows per table
     */
    public function import(array $export, bool $withLegalTexts): array
    {
        $this->checkSchema($export);
        $tables = $this->tablesToReplace($withLegalTexts);
        $caches   = array_values(array_filter($this->allTables(), self::isCache(...)));

        // Files first: a failure afterwards leaves at most unreferenced files,
        // never a record without its file.
        $this->importFiles($export);

        // This way the order of the foreign keys does not matter; the ids come
        // complete and consistent from the source.
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->beginTransaction();

        try {
            foreach (array_merge($tables, $caches) as $table) {
                $this->pdo->exec('DELETE FROM `' . $table . '`');
            }

            $inserted = [];
            foreach ($tables as $table) {
                $columns = $export['tables'][$table]['columns'];
                $insert = $this->pdo->prepare(sprintf(
                    'INSERT INTO `%s` (%s) VALUES (%s)',
                    $table,
                    implode(', ', array_map(static fn (string $s): string => '`' . $s . '`', $columns)),
                    implode(', ', array_fill(0, count($columns), '?'))
                ));
                foreach ($export['tables'][$table]['rows'] as $row) {
                    $insert->execute(array_map(self::decode(...), $row));
                }
                $inserted[$table] = count($export['tables'][$table]['rows']);
            }

            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $inserted;
    }

    /**
     * @param array<string, mixed> $export
     */
    private function checkSchema(array $export): void
    {
        if (($export['format'] ?? null) !== self::FORMAT || ! is_array($export['tables'] ?? null)) {
            throw new RuntimeException('Unbekanntes Exportformat.');
        }

        $target   = $this->dataTables();
        $sourceText = array_keys($export['tables']);
        $missingItems = array_diff($target, $sourceText);
        $foreign  = array_diff($sourceText, $target);
        if ($missingItems !== [] || $foreign !== []) {
            throw new RuntimeException(
                'Die Tabellen von Quelle und Ziel unterscheiden sich ('
                . implode(', ', array_merge($missingItems, $foreign))
                . '). Beide Datenbanken erst auf denselben Migrationsstand bringen (bin/migrate.php).'
            );
        }

        foreach ($target as $table) {
            $targetColumns   = $this->columns($table);
            $sourceColumns = $export['tables'][$table]['columns'];
            sort($targetColumns);
            sort($sourceColumns);
            if ($targetColumns !== $sourceColumns) {
                throw new RuntimeException(
                    "Die Spalten der Tabelle {$table} unterscheiden sich zwischen Quelle und Ziel. "
                    . 'Beide Datenbanken erst auf denselben Migrationsstand bringen (bin/migrate.php).'
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function tablesToReplace(bool $withLegalTexts): array
    {
        return array_values(array_filter(
            $this->dataTables(),
            static fn (string $t): bool => $withLegalTexts || ! in_array($t, self::LEGAL_TEXTS, true)
        ));
    }

    /**
     * All tables with transferable data (including legal texts).
     *
     * @return list<string>
     */
    private function dataTables(): array
    {
        return array_values(array_filter(
            $this->allTables(),
            static fn (string $t): bool => ! in_array($t, self::NEVER_TRANSFER, true) && ! self::isCache($t)
        ));
    }

    /**
     * @return list<string>
     */
    private function allTables(): array
    {
        $tables = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function columns(string $table): array
    {
        $query = $this->pdo->prepare(
            'SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position'
        );
        $query->execute([$table]);

        return $query->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Reads the files of all score sheets referenced in the exported rows.
     *
     * @param array<string, array{columns: list<string>, rows: list<list<mixed>>}> $tables
     *
     * @return array<string, string> hash => base64 content
     */
    private function exportFiles(array $tables): array
    {
        $files = [];
        foreach (self::referencedHashes($tables) as $hash) {
            $content = $this->files?->read($hash);
            if ($content === null) {
                throw new RuntimeException("Die Datei {$hash} eines Original-Spielzettels fehlt im Spielzettel-Verzeichnis.");
            }
            $files[$hash] = base64_encode($content);
        }

        return $files;
    }

    /**
     * Writes the exported files into the target's store and checks that every
     * referenced score sheet is then present there.
     *
     * @param array<string, mixed> $export
     */
    private function importFiles(array $export): void
    {
        $hashes = self::referencedHashes($export['tables']);
        if ($hashes === []) {
            return;
        }
        if ($this->files === null) {
            throw new RuntimeException('Für die Spielzettel-Dateien fehlt das Zielverzeichnis.');
        }

        foreach ((array) ($export['files'] ?? []) as $hash => $encoded) {
            $content = base64_decode((string) $encoded, true);
            if ($content === false || hash('sha256', $content) !== $hash) {
                throw new RuntimeException("Beschädigte Spielzettel-Datei {$hash} im Export.");
            }
            $this->files->put($content);
        }

        foreach ($hashes as $hash) {
            if (! $this->files->exists($hash)) {
                throw new RuntimeException("Die Datei {$hash} eines Original-Spielzettels fehlt im Export.");
            }
        }
    }

    /**
     * @param array<string, mixed> $tables
     *
     * @return list<string>
     */
    private static function referencedHashes(array $tables): array
    {
        $table  = $tables['original_score_sheet'] ?? null;
        $column = is_array($table) ? array_search('content_hash', $table['columns'], true) : false;
        if ($column === false) {
            return [];
        }

        $hashes = [];
        foreach ($table['rows'] as $row) {
            if (is_string($row[$column] ?? null)) {
                $hashes[$row[$column]] = true;
            }
        }

        return array_keys($hashes);
    }

    private static function isCache(string $table): bool
    {
        return str_starts_with($table, self::CACHE_PREFIX);
    }

    /**
     * JSON only supports valid UTF-8; binary data (score sheet photos) is
     * therefore stored as ['b64' => …].
     */
    private static function encode(mixed $value): mixed
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            return ['b64' => base64_encode($value)];
        }

        return $value;
    }

    private static function decode(mixed $value): mixed
    {
        if (is_array($value)) {
            $raw = base64_decode((string) ($value['b64'] ?? ''), true);
            if ($raw === false) {
                throw new RuntimeException('Beschädigte Binärdaten im Export.');
            }

            return $raw;
        }

        return $value;
    }
}
