<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Media;

use Bowlingliga\Application\Media\ContentImageStore;

/** In-memory fake of the {@see ContentImageStore} port. */
final class InMemoryContentImageStore implements ContentImageStore
{
    /** @var array<string, array{content_hash: string, mime_type: string, width: int, height: int, size_bytes: int, original_name: string, uploaded_at: string}> */
    public array $records = [];

    public function add(string $hash, string $mimeType, int $width, int $height, int $sizeBytes, string $originalName, \DateTimeImmutable $uploadedAt): void
    {
        $this->records[$hash] ??= [
            'content_hash'  => $hash,
            'mime_type'     => $mimeType,
            'width'         => $width,
            'height'        => $height,
            'size_bytes'    => $sizeBytes,
            'original_name' => $originalName,
            'uploaded_at'   => $uploadedAt->format('Y-m-d H:i:s'),
        ];
    }

    public function find(string $hash): ?array
    {
        return $this->records[$hash] ?? null;
    }

    public function all(): array
    {
        return array_values(array_reverse($this->records));
    }

    public function delete(string $hash): void
    {
        unset($this->records[$hash]);
    }
}
