<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Persistence;

use Bowlingliga\Persistence\FileSystemBlobStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Content-addressed file store of the original score sheets (R3.15), on a
 * temporary directory.
 */
final class FileSystemBlobStoreTest extends TestCase
{
    private string $directory;

    private FileSystemBlobStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ml-blobs-' . bin2hex(random_bytes(4));
        $this->store = new FileSystemBlobStore($this->directory);
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->directory)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testContentIsStoredUnderItsHashAndReadUnchanged(): void
    {
        $content = "\xFF\xD8\xFF\xE0 kein UTF-8 \x80\x81";

        $hash = $this->store->put($content);

        self::assertSame(hash('sha256', $content), $hash);
        self::assertFileExists($this->directory . '/objects/' . substr($hash, 0, 2) . '/' . substr($hash, 2));
        self::assertSame($content, $this->store->read($hash));
        self::assertTrue($this->store->exists($hash));
    }

    public function testSameContentIsStoredOnlyOnce(): void
    {
        $first  = $this->store->put('Spielzettel');
        $second = $this->store->put('Spielzettel');

        self::assertSame($first, $second);
        self::assertCount(1, glob($this->directory . '/objects/*/*') ?: []);
        self::assertSame([], glob($this->directory . '/objects/*/.tmp-*') ?: [], 'No temporary file remains.');
    }

    public function testDeleteRemovesTheObjectAndToleratesMissingOnes(): void
    {
        $hash = $this->store->put('weg damit');

        $this->store->delete($hash);
        $this->store->delete($hash);

        self::assertFalse($this->store->exists($hash));
        self::assertNull($this->store->read($hash));
    }

    public function testOnlyObjectsOlderThanTheLimitAreListed(): void
    {
        $old   = $this->store->put('alt');
        $fresh = $this->store->put('neu');
        touch($this->directory . '/objects/' . substr($old, 0, 2) . '/' . substr($old, 2), time() - 3600);

        self::assertSame([$old], $this->store->hashesOlderThan(900));
        self::assertEqualsCanonicalizing([$old, $fresh], $this->store->hashesOlderThan(0));
    }

    public function testStoringAgainRefreshesTheAge(): void
    {
        $hash = $this->store->put('wieder hochgeladen');
        touch($this->directory . '/objects/' . substr($hash, 0, 2) . '/' . substr($hash, 2), time() - 3600);

        $this->store->put('wieder hochgeladen');

        self::assertSame([], $this->store->hashesOlderThan(900), 'A cleanup must not remove an object that is just being referenced again.');
    }

    public function testHashesThatAreNoPathAreRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->store->read('../../config/database');
    }

    public function testScoreSheetDirectoryComesFromTheConfiguration(): void
    {
        self::assertSame('/srv/zettel', FileSystemBlobStore::forScoreSheets(['score_sheet_dir' => '/srv/zettel'], '/projekt')->directory());
        self::assertSame('/projekt/storage/score-sheets', FileSystemBlobStore::forScoreSheets([], '/projekt')->directory());
        self::assertSame('/projekt/storage/score-sheets', FileSystemBlobStore::forScoreSheets(['score_sheet_dir' => ' '], '/projekt/')->directory());
    }
}
