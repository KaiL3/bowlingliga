<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\BlobStore;
use RuntimeException;

/**
 * Content-addressed object store in a directory (R3.15).
 *
 * Layout like git: `<directory>/objects/3f/a9c1…` – the first two hex digits
 * of the SHA-256 hash form the subdirectory, the rest the file name. Objects
 * are written once and never changed: the content goes into a temporary file
 * in the same directory, is flushed to disk and then renamed, so a reader
 * never sees a partial object. File names come only from hashes, never from
 * user input.
 *
 * The directory must lie outside `public/`; the web server needs write
 * access to it.
 */
final class FileSystemBlobStore implements BlobStore
{
    /** Default directory below the project root if the configuration names none. */
    public const DEFAULT_DIRECTORY = 'storage/score-sheets';

    private const HASH_PATTERN = '/^[0-9a-f]{64}$/';

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Store for the original score sheets: `score_sheet_dir` from the
     * configuration, otherwise {@see DEFAULT_DIRECTORY} below the project root.
     *
     * @param array<string, mixed> $config
     */
    public static function forScoreSheets(array $config, string $projectRoot): self
    {
        $directory = trim((string) ($config['score_sheet_dir'] ?? ''));

        return new self($directory !== '' ? $directory : rtrim($projectRoot, '/\\') . '/' . self::DEFAULT_DIRECTORY);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function put(string $content): string
    {
        $hash = hash('sha256', $content);
        $path = $this->path($hash);

        if (is_file($path)) {
            // Refresh the time stamp so that a concurrent cleanup does not
            // remove the object before the caller has referenced it. PHP keeps
            // the stat result of is_file() above; drop it so that the new time
            // stamp is seen in this request too (PHP 8.1 does not do it on touch).
            touch($path);
            clearstatcache(true, $path);

            return $hash;
        }

        $folder = dirname($path);
        if (! is_dir($folder) && ! @mkdir($folder, 0750, true) && ! is_dir($folder)) {
            throw new RuntimeException("The directory {$folder} for the score sheets could not be created.");
        }

        $temporary = $folder . '/.tmp-' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException("No file can be created in the directory {$folder}.");
        }

        $written = fwrite($handle, $content);
        $flushed = fflush($handle) && fsync($handle);
        fclose($handle);

        if ($written !== strlen($content) || ! $flushed || ! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("The file of the score sheet could not be stored completely ({$path}).");
        }
        @chmod($path, 0640);

        return $hash;
    }

    public function read(string $hash): ?string
    {
        $path = $this->path($hash);
        if (! is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }

    public function exists(string $hash): bool
    {
        return is_file($this->path($hash));
    }

    public function delete(string $hash): void
    {
        $path = $this->path($hash);
        if (is_file($path) && ! @unlink($path) && is_file($path)) {
            throw new RuntimeException("The file {$path} could not be deleted.");
        }
    }

    public function hashesOlderThan(int $minAgeSeconds): array
    {
        $limit  = time() - $minAgeSeconds;
        $hashes = [];
        foreach (glob($this->objectsDirectory() . '/[0-9a-f][0-9a-f]/*') ?: [] as $path) {
            $hash = basename(dirname($path)) . basename($path);
            // The age decides about deleting: never use a cached stat result.
            clearstatcache(true, $path);
            if (preg_match(self::HASH_PATTERN, $hash) === 1 && is_file($path) && (int) filemtime($path) <= $limit) {
                $hashes[] = $hash;
            }
        }
        sort($hashes, SORT_STRING);

        return $hashes;
    }

    private function path(string $hash): string
    {
        if (preg_match(self::HASH_PATTERN, $hash) !== 1) {
            throw new RuntimeException('Invalid hash of a score sheet.');
        }

        return $this->objectsDirectory() . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2);
    }

    private function objectsDirectory(): string
    {
        return rtrim($this->directory, '/\\') . '/objects';
    }
}
