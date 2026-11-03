<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

/**
 * Port for a content-addressed store of immutable binary objects (R3.15).
 *
 * Every object is identified by the SHA-256 hash of its content: storing the
 * same content twice keeps it only once, and the hash serves as checksum. The
 * store knows nothing about who references an object; deleting unreferenced
 * objects is the job of the caller. The port is implemented by
 * {@see \Bowlingliga\Persistence\FileSystemBlobStore}.
 */
interface BlobStore
{
    /**
     * Stores the content unless it is already present and returns its hash
     * (64 lower-case hex digits). An already present object counts as freshly
     * stored for {@see hashesOlderThan()}.
     */
    public function put(string $content): string;

    /** Returns the content of an object, or null if it does not exist. */
    public function read(string $hash): ?string;

    public function exists(string $hash): bool;

    /** Deletes an object; a missing object is not an error. */
    public function delete(string $hash): void;

    /**
     * Returns the hashes of all objects that were not stored within the last
     * `$minAgeSeconds` seconds.
     *
     * @return list<string>
     */
    public function hashesOlderThan(int $minAgeSeconds): array;
}
