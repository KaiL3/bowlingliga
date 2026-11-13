<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Media;

use Bowlingliga\Application\BlobStore;
use Bowlingliga\Domain\Media\ContentImage;
use Bowlingliga\Domain\Media\ContentImageException;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\WriteAccess;

/**
 * Use case: the images of the content (R42).
 *
 * - Uploading prepares the image ({@see ContentImage}), stores the file under
 *   the hash of its content and records it; the same image twice stays one
 *   image (R42.1, R42.2).
 * - The overview lists every image with the texts that use it; where an
 *   image is used is read from the texts each time, nothing is stored about
 *   it (R42.3, R35).
 * - An image is removed only while no text uses it (R42.3).
 *
 * Every admin may do this, writing needs a login (R5.1).
 */
final class ContentImages
{
    /** Address prefix of the images; the route delivers them from the store. */
    public const URL_PREFIX = '/media/';

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /**
     * @param callable(): \DateTimeImmutable|null $clock
     */
    public function __construct(
        private readonly ContentImageStore $store,
        private readonly BlobStore $files,
        private readonly ContentTextReader $texts,
        private readonly WriteAccess $writeAccess,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Prepares and stores an uploaded image and returns its address.
     *
     * @throws AccessDeniedException without write access (R5.1).
     * @throws ContentImageException if it is no supported image or too large.
     */
    public function upload(string $content, string $originalName): string
    {
        $this->ensureWriteAccess();

        $image = ContentImage::prepare($content);
        $hash  = $this->files->put($image->content);
        $name  = mb_substr(trim(basename(str_replace('\\', '/', $originalName))), 0, 255);
        $this->store->add($hash, $image->mimeType, $image->width, $image->height, strlen($image->content), $name !== '' ? $name : 'bild', ($this->clock)());

        return self::url($hash, $image->mimeType);
    }

    /**
     * Every image with its address and the texts that use it, newest first.
     *
     * @return list<array{hash: string, url: string, mime_type: string, width: int, height: int, size_bytes: int, original_name: string, uploaded_at: string, used_in: list<array{area: string, id: int|null, title: string|null}>}>
     *
     * @throws AccessDeniedException without write access (R5.1).
     */
    public function overview(): array
    {
        $this->ensureWriteAccess();

        $texts  = $this->texts->texts();
        $images = [];
        foreach ($this->store->all() as $record) {
            $hash     = $record['content_hash'];
            $images[] = [
                'hash'          => $hash,
                'url'           => self::url($hash, $record['mime_type']),
                'mime_type'     => $record['mime_type'],
                'width'         => $record['width'],
                'height'        => $record['height'],
                'size_bytes'    => $record['size_bytes'],
                'original_name' => $record['original_name'],
                'uploaded_at'   => $record['uploaded_at'],
                'used_in'       => self::usages($hash, $texts),
            ];
        }

        return $images;
    }

    /**
     * Removes an image no text uses any more.
     *
     * @throws AccessDeniedException without write access (R5.1).
     * @throws ContentImageException if it does not exist or is still used.
     */
    public function remove(string $hash): void
    {
        $this->ensureWriteAccess();

        if ($this->store->find($hash) === null) {
            throw ContentImageException::notFound();
        }
        $used = count(self::usages($hash, $this->texts->texts()));
        if ($used > 0) {
            throw ContentImageException::inUse($used);
        }

        $this->store->delete($hash);
        $this->files->delete($hash);
    }

    /**
     * Content and type of an image for its address `<hash>.<extension>`, or
     * null if there is none (also for a wrong extension). Reading is public.
     *
     * @return array{content: string, mime_type: string, hash: string}|null
     */
    public function file(string $name): ?array
    {
        if (preg_match('/^([0-9a-f]{64})\.(jpg|png)$/', $name, $match) !== 1) {
            return null;
        }
        $record = $this->store->find($match[1]);
        if ($record === null || self::extension($record['mime_type']) !== $match[2]) {
            return null;
        }
        $content = $this->files->read($match[1]);

        return $content !== null ? ['content' => $content, 'mime_type' => $record['mime_type'], 'hash' => $match[1]] : null;
    }

    public static function url(string $hash, string $mimeType): string
    {
        return self::URL_PREFIX . $hash . '.' . self::extension($mimeType);
    }

    private static function extension(string $mimeType): string
    {
        return $mimeType === 'image/png' ? 'png' : 'jpg';
    }

    /**
     * @param list<array{area: string, id: int|null, title: string|null, text: string}> $texts
     * @return list<array{area: string, id: int|null, title: string|null}>
     */
    private static function usages(string $hash, array $texts): array
    {
        $used = [];
        foreach ($texts as $text) {
            if (str_contains($text['text'], self::URL_PREFIX . $hash . '.')) {
                $used[] = ['area' => $text['area'], 'id' => $text['id'], 'title' => $text['title']];
            }
        }

        return $used;
    }

    private function ensureWriteAccess(): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
    }
}
