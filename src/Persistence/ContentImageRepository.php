<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\Media\ContentImageStore;

/**
 * Raw data repository for the images of the content (table `content_image`,
 * R42).
 */
final class ContentImageRepository extends AbstractRepository implements ContentImageStore
{
    private const COLUMNS = 'content_hash, mime_type, width, height, size_bytes, original_name, uploaded_at';

    public function add(string $hash, string $mimeType, int $width, int $height, int $sizeBytes, string $originalName, \DateTimeImmutable $uploadedAt): void
    {
        $this->transactional('save_content_image', function () use ($hash, $mimeType, $width, $height, $sizeBytes, $originalName, $uploadedAt): void {
            $this->perform(
                'INSERT IGNORE INTO content_image (' . self::COLUMNS . ')
                 VALUES (:hash, :mime, :width, :height, :size, :name, :uploaded)',
                [
                    'hash'     => $hash,
                    'mime'     => $mimeType,
                    'width'    => $width,
                    'height'   => $height,
                    'size'     => $sizeBytes,
                    'name'     => $originalName,
                    'uploaded' => $uploadedAt->format('Y-m-d H:i:s'),
                ]
            );
        });
    }

    public function find(string $hash): ?array
    {
        $row = $this->findOne('SELECT ' . self::COLUMNS . ' FROM content_image WHERE content_hash = :hash', ['hash' => $hash]);

        return $row !== null ? self::record($row) : null;
    }

    public function all(): array
    {
        return array_map(self::record(...), $this->findAll('SELECT ' . self::COLUMNS . ' FROM content_image ORDER BY uploaded_at DESC, id DESC'));
    }

    public function delete(string $hash): void
    {
        $this->transactional('delete_content_image', function () use ($hash): void {
            $this->perform('DELETE FROM content_image WHERE content_hash = :hash', ['hash' => $hash]);
        });
    }

    /**
     * @param array<string, mixed> $row
     * @return array{content_hash: string, mime_type: string, width: int, height: int, size_bytes: int, original_name: string, uploaded_at: string}
     */
    private static function record(array $row): array
    {
        return [
            'content_hash'  => (string) $row['content_hash'],
            'mime_type'     => (string) $row['mime_type'],
            'width'         => (int) $row['width'],
            'height'        => (int) $row['height'],
            'size_bytes'    => (int) $row['size_bytes'],
            'original_name' => (string) $row['original_name'],
            'uploaded_at'   => (string) $row['uploaded_at'],
        ];
    }
}
