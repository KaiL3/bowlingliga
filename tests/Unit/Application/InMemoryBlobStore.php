<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\BlobStore;

/**
 * In-memory fake of the {@see BlobStore} port: content addressed by SHA-256
 * like the file system adapter, with a settable clock for the age of the
 * objects.
 */
final class InMemoryBlobStore implements BlobStore
{
    /** @var array<string, string> hash => content */
    public array $objects = [];

    /** @var array<string, int> hash => time of storing */
    public array $storedAt = [];

    public int $now = 1_000_000;

    public function put(string $content): string
    {
        $hash = hash('sha256', $content);
        $this->objects[$hash]  = $content;
        $this->storedAt[$hash] = $this->now;

        return $hash;
    }

    public function read(string $hash): ?string
    {
        return $this->objects[$hash] ?? null;
    }

    public function exists(string $hash): bool
    {
        return isset($this->objects[$hash]);
    }

    public function delete(string $hash): void
    {
        unset($this->objects[$hash], $this->storedAt[$hash]);
    }

    public function hashesOlderThan(int $minAgeSeconds): array
    {
        $hashes = array_keys(array_filter(
            $this->storedAt,
            fn (int $time): bool => $time <= $this->now - $minAgeSeconds
        ));
        sort($hashes, SORT_STRING);

        return $hashes;
    }
}
