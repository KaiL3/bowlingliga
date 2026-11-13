<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Media;

use Bowlingliga\Application\Media\ContentImages;
use Bowlingliga\Application\Media\ContentImageStore;
use Bowlingliga\Application\Media\ContentTextReader;
use Bowlingliga\Domain\Media\ContentImageException;
use Bowlingliga\Presentation\MediaController;
use Bowlingliga\Presentation\MediaView;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Tests\Unit\Application\InMemoryBlobStore;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Validates: R42.1, R42.2, R42.3, R42.4
 *
 * Uploading stores an image once under its hash, the overview reads where
 * it is used from the texts, an image in use is not removed.
 */
final class ContentImagesTest extends TestCase
{
    private InMemoryContentImageStore $store;
    private InMemoryBlobStore $files;
    private InMemoryContentTexts $texts;
    private SwitchableWriteAccess $access;
    private ContentImages $images;

    protected function setUp(): void
    {
        $this->store  = new InMemoryContentImageStore();
        $this->files  = new InMemoryBlobStore();
        $this->texts  = new InMemoryContentTexts();
        $this->access = new SwitchableWriteAccess(true);
        $this->images = new ContentImages($this->store, $this->files, $this->texts, $this->access, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-13 15:00:00'));
    }

    public function testAnUploadIsStoredOnceUnderItsHash(): void
    {
        $url = $this->images->upload($this->jpeg(), 'C:\\Fotos\\Mannschaft.JPG');

        self::assertMatchesRegularExpression('#^/media/[0-9a-f]{64}\.jpg$#', $url);
        $hash = substr($url, 7, 64);
        self::assertArrayHasKey($hash, $this->files->objects);
        self::assertSame('Mannschaft.JPG', $this->store->records[$hash]['original_name']);
        self::assertSame('2026-11-13 15:00:00', $this->store->records[$hash]['uploaded_at']);

        self::assertSame($url, $this->images->upload($this->jpeg(), 'nochmal.jpg'), 'The same image twice stays one image.');
        self::assertCount(1, $this->store->records);
    }

    public function testTheOverviewNamesTheTextsThatUseAnImage(): void
    {
        $used   = $this->images->upload($this->jpeg(), 'team.jpg');
        $unused = $this->images->upload($this->jpeg(20), 'logo.jpg');
        $this->texts->texts = [
            ['area' => 'home_page', 'id' => 4, 'title' => 'Saisonstart', 'text' => '<p><img src="' . $used . '" alt="Team"></p>'],
            ['area' => 'rules', 'id' => null, 'title' => null, 'text' => '<figure class="image"><img src="' . $used . '" alt="Team"></figure>'],
        ];

        $overview = [];
        foreach ($this->images->overview() as $image) {
            $overview[$image['original_name']] = $image['used_in'];
        }

        self::assertSame([
            ['area' => 'home_page', 'id' => 4, 'title' => 'Saisonstart'],
            ['area' => 'rules', 'id' => null, 'title' => null],
        ], $overview['team.jpg']);
        self::assertSame([], $overview['logo.jpg']);

        try {
            $this->images->remove(substr($used, 7, 64));
            self::fail('An image in use stays.');
        } catch (ContentImageException $e) {
            self::assertSame('error.content_image.in_use', $e->message()->key);
            self::assertSame(2, $e->message()->params['count']);
        }

        $this->images->remove(substr($unused, 7, 64));
        self::assertCount(1, $this->store->records);
        self::assertArrayNotHasKey(substr($unused, 7, 64), $this->files->objects, 'The file goes with its record.');
    }

    public function testAFileIsFoundOnlyUnderItsOwnAddress(): void
    {
        $url  = $this->images->upload($this->jpeg(), 'team.jpg');
        $name = substr($url, 7);

        $file = $this->images->file($name);
        self::assertNotNull($file);
        self::assertSame('image/jpeg', $file['mime_type']);
        self::assertNull($this->images->file(substr($name, 0, 64) . '.png'), 'Wrong extension.');
        self::assertNull($this->images->file(str_repeat('0', 64) . '.jpg'), 'Unknown hash.');
        self::assertNull($this->images->file('../config.jpg'));
    }

    public function testWithoutLoginNothingIsStored(): void
    {
        $this->access->allowed = false;

        $this->expectException(AccessDeniedException::class);
        $this->images->upload($this->jpeg(), 'team.jpg');
    }

    public function testTheAreaShowsImagesAndOffersRemovalOnlyForUnusedOnes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($file, $this->jpeg());
        $controller = new MediaController($this->images, new MediaView(), isUploadedFile: is_file(...));

        $result = $controller->upload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $file, 'name' => 'team.jpg']);
        unlink($file);
        self::assertTrue($result['ok']);
        self::assertSame('Das Bild ist gespeichert.', $result['message']);

        $other = $controller->upload(['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'name' => 'riesig.jpg']);
        self::assertSame(['ok' => false, 'location' => null, 'message' => 'Das Bild ist größer als 10 MB.'], $other);

        $this->texts->texts = [['area' => 'home_page', 'id' => 4, 'title' => 'Saison &amp; Start', 'text' => '<img src="' . $result['location'] . '" alt="Team">']];
        $html = $controller->page('<input name="csrf_token">');
        self::assertStringContainsString('Alle Bilder (1)', $html);
        self::assertStringContainsString('<a href="/admin/home-page?edit=4">Meldung „Saison &amp; Start“</a>', $html, 'The stored title is escaped already.');
        self::assertStringNotContainsString('action="/admin/media/remove"', $html);

        $this->texts->texts = [];
        self::assertStringContainsString('action="/admin/media/remove"', $controller->page('<input name="csrf_token">'));
        self::assertSame([['title' => 'team.jpg', 'value' => $result['location']]], $controller->editorList());
    }

    private function jpeg(int $size = 10): string
    {
        $image = imagecreatetruecolor($size, $size);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}

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

/** In-memory fake of the {@see ContentTextReader} port. */
final class InMemoryContentTexts implements ContentTextReader
{
    /** @var list<array{area: string, id: int|null, title: string|null, text: string}> */
    public array $texts = [];

    public function texts(): array
    {
        return $this->texts;
    }
}
