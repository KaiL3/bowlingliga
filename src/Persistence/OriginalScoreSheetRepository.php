<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\BlobStore;
use Bowlingliga\Application\ResultEntry\OriginalScoreSheetStore;
use Bowlingliga\Application\ScoreSheetFileCleanup;
use PDO;

/**
 * Raw data repository for original score sheets (table
 * `original_score_sheet`, R3.15–17).
 *
 * The unchanged upload lies as an immutable file in the content-addressed
 * {@see BlobStore}; the table holds fixture, MIME type, size, upload time and
 * the SHA-256 hash, which names the file and serves as checksum. The same file
 * uploaded twice is stored only once.
 *
 * Order without a common transaction: the file is written first, then the
 * record. A failure can therefore leave an unreferenced file, never a record
 * without a file; unreferenced files are removed by
 * {@see removeOrphanedFiles()} (R35: score sheets are raw data).
 *
 * Implements the {@see OriginalScoreSheetStore} port (R3.15, R3.16) and the
 * {@see ScoreSheetFileCleanup} port.
 */
final class OriginalScoreSheetRepository extends AbstractRepository implements OriginalScoreSheetStore, ScoreSheetFileCleanup
{
    /**
     * Unreferenced files younger than this are kept: between writing the file
     * and the record of an upload in progress the file is not referenced yet.
     */
    public const GRACE_SECONDS = 900;

    public function __construct(Database $database, private readonly BlobStore $files)
    {
        parent::__construct($database);
    }

    /**
     * Stores an original score sheet and returns its id (R3.15–16).
     *
     * @param string $file     Binary content of the uploaded file.
     * @param string $mimeType MIME type (e.g. image/jpeg, image/png, application/pdf).
     */
    public function save(int $fixtureId, string $file, string $mimeType): int
    {
        $hash = $this->files->put($file);

        return $this->transactional('save_score_sheet', function (PDO $pdo) use ($fixtureId, $hash, $file, $mimeType): int {
            $statement = $pdo->prepare(
                'INSERT INTO original_score_sheet (fixture_id, content_hash, size_bytes, mime_type, uploaded_at)
                 VALUES (:fixture_id, :content_hash, :size_bytes, :mime_type, NOW())'
            );
            $statement->execute([
                'fixture_id'   => $fixtureId,
                'content_hash' => $hash,
                'size_bytes'   => strlen($file),
                'mime_type'    => $mimeType,
            ]);

            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Deletes an original score sheet and its file, unless another record
     * references the same content (R3.19). Inside an outer transaction the file
     * stays until {@see removeOrphanedFiles()}, because a rollback would
     * restore the record.
     */
    public function delete(int $id): void
    {
        $row = $this->findOne('SELECT content_hash FROM original_score_sheet WHERE id = :id', ['id' => $id]);

        $this->transactional('delete_score_sheet', function () use ($id): void {
            $this->perform('DELETE FROM original_score_sheet WHERE id = :id', ['id' => $id]);
        });

        $hash = $row['content_hash'] ?? null;
        if (is_string($hash) && ! $this->database->pdo()->inTransaction() && ! $this->isReferenced($hash)) {
            $this->files->delete($hash);
        }
    }

    /**
     * Returns an original score sheet including binary content (key `file`),
     * or null (R15.1, R4.5). `file` is null if the file is missing from the
     * store.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->findOne(
            'SELECT id, fixture_id, content_hash, size_bytes, mime_type, uploaded_at
             FROM original_score_sheet WHERE id = :id',
            ['id' => $id]
        );

        if ($row !== null) {
            $row['file'] = $this->files->read((string) $row['content_hash']);
        }

        return $row;
    }

    /**
     * Returns the metadata of all original score sheets of a fixture, without
     * content (R3.16, R4.5).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForFixture(int $fixtureId): array
    {
        return $this->findAll(
            'SELECT id, fixture_id, content_hash, size_bytes, mime_type, uploaded_at
             FROM original_score_sheet WHERE fixture_id = :fixture_id ORDER BY id',
            ['fixture_id' => $fixtureId]
        );
    }

    public function removeOrphanedFiles(int $minAgeSeconds = self::GRACE_SECONDS): int
    {
        $referenced = array_flip($this->referencedHashes());
        $removed = 0;
        foreach ($this->files->hashesOlderThan($minAgeSeconds) as $hash) {
            if (! isset($referenced[$hash])) {
                $this->files->delete($hash);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Checks every record against its file: missing files, and files whose
     * content no longer matches the hash.
     *
     * @return array{checked: int, missing: list<int>, damaged: list<int>}
     */
    public function verifyFiles(): array
    {
        $result = ['checked' => 0, 'missing' => [], 'damaged' => []];
        $rows = $this->findAll('SELECT id, content_hash FROM original_score_sheet ORDER BY id');
        foreach ($rows as $row) {
            $result['checked']++;
            $hash = (string) $row['content_hash'];
            $content = $this->files->read($hash);
            if ($content === null) {
                $result['missing'][] = (int) $row['id'];
            } elseif (hash('sha256', $content) !== $hash) {
                $result['damaged'][] = (int) $row['id'];
            }
        }

        return $result;
    }

    /** @return list<string> */
    public function referencedHashes(): array
    {
        return $this->perform(
            'SELECT DISTINCT content_hash FROM original_score_sheet'
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    private function isReferenced(string $hash): bool
    {
        return $this->findOne(
            'SELECT 1 AS found FROM original_score_sheet WHERE content_hash = :hash LIMIT 1',
            ['hash' => $hash]
        ) !== null;
    }
}
