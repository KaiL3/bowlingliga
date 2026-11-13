<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Media;

/**
 * Port: the records of the images of the content (R42); the files lie in a
 * {@see \Bowlingliga\Application\BlobStore}.
 *
 * @phpstan-type ImageRecord array{content_hash: string, mime_type: string, width: int, height: int, size_bytes: int, original_name: string, uploaded_at: string}
 */
interface ContentImageStore
{
    /**
     * Records an image unless one with the same hash exists already.
     */
    public function add(string $hash, string $mimeType, int $width, int $height, int $sizeBytes, string $originalName, \DateTimeImmutable $uploadedAt): void;

    /**
     * @return array{content_hash: string, mime_type: string, width: int, height: int, size_bytes: int, original_name: string, uploaded_at: string}|null
     */
    public function find(string $hash): ?array;

    /**
     * All images, newest first.
     *
     * @return list<array{content_hash: string, mime_type: string, width: int, height: int, size_bytes: int, original_name: string, uploaded_at: string}>
     */
    public function all(): array;

    public function delete(string $hash): void;
}
