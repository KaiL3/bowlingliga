<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\Media;

use Bowlingliga\Domain\Media\ContentImage;
use Bowlingliga\Domain\Media\ContentImageException;
use PHPUnit\Framework\TestCase;

/**
 * Validates: R42.2
 *
 * Images of the content: type from the content, upright, at most 2000 px,
 * without metadata; PNG keeps its transparency, JPEG and WebP become JPEG.
 */
final class ContentImageTest extends TestCase
{
    private const SECRET = 'GPS 52.5200N 13.4050E Pixel-9';

    public function testALargeJpegIsScaledDownTurnedUprightAndLosesItsMetadata(): void
    {
        $image = ContentImage::prepare($this->withExif($this->jpeg(3000, 1500), 6));

        self::assertSame('image/jpeg', $image->mimeType);
        self::assertSame('jpg', $image->extension());
        self::assertSame([1000, 2000], [$image->width, $image->height], 'Turned upright, longer edge 2000 px.');
        self::assertSame([1000, 2000], $this->dimensions($image->content));
        self::assertStringNotContainsString(self::SECRET, $image->content);
        self::assertStringNotContainsString('Exif', $image->content);
    }

    public function testAPngKeepsItsTransparency(): void
    {
        $png = imagecreatetruecolor(40, 20);
        imagealphablending($png, false);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, (int) imagecolorallocatealpha($png, 0, 0, 0, 127));
        imagefilledrectangle($png, 0, 0, 9, 9, (int) imagecolorallocatealpha($png, 200, 0, 0, 0));
        ob_start();
        imagepng($png);

        $image = ContentImage::prepare((string) ob_get_clean());

        self::assertSame('image/png', $image->mimeType);
        self::assertSame('png', $image->extension());
        $decoded = imagecreatefromstring($image->content);
        self::assertNotFalse($decoded);
        self::assertSame(127, (imagecolorat($decoded, 30, 15) >> 24) & 0x7F, 'Transparent stays transparent.');
        self::assertSame(0, (imagecolorat($decoded, 5, 5) >> 24) & 0x7F, 'Opaque stays opaque.');
    }

    public function testAWebpBecomesAJpeg(): void
    {
        if (! function_exists('imagewebp')) {
            self::markTestSkipped('GD without WebP.');
        }
        $webp = imagecreatetruecolor(30, 20);
        ob_start();
        imagewebp($webp);

        $image = ContentImage::prepare((string) ob_get_clean());

        self::assertSame('image/jpeg', $image->mimeType);
        self::assertSame([30, 20], [$image->width, $image->height]);
    }

    public function testASmallImageIsNotEnlarged(): void
    {
        self::assertSame([400, 300], $this->dimensions(ContentImage::prepare($this->jpeg(400, 300))->content));
    }

    /**
     * SVG can carry scripts; GIF, PDF and text are no images of the content.
     */
    public function testOtherTypesAreRefused(): void
    {
        $gif = imagecreatetruecolor(4, 4);
        ob_start();
        imagegif($gif);
        $files = [
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'gif'   => (string) ob_get_clean(),
            'pdf'   => '%PDF-1.7',
            'empty' => '',
        ];

        foreach ($files as $type => $content) {
            try {
                ContentImage::prepare($content);
                self::fail('Refused: ' . $type);
            } catch (ContentImageException $e) {
                self::assertSame('error.content_image.not_an_image', $e->message()->key, $type);
            }
        }
    }

    public function testMoreThanTenMegabytesAreRefused(): void
    {
        try {
            ContentImage::prepare(str_repeat('x', ContentImage::MAX_BYTES + 1));
            self::fail('Too large.');
        } catch (ContentImageException $e) {
            self::assertSame('error.content_image.too_large', $e->message()->key);
        }
    }

    public function testTooManyPixelsAreRefusedBeforeDecoding(): void
    {
        $header = pack('NNCCCCC', 9000, 6000, 8, 2, 0, 0, 0);
        $png    = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $header . pack('N', crc32('IHDR' . $header));

        $this->expectException(ContentImageException::class);
        ContentImage::prepare($png);
    }

    /** A JPEG whose left half is red. */
    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 240, 240, 240));
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, (int) imagecolorallocate($image, 220, 20, 20));
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /** Inserts an EXIF segment with orientation and a secret behind the start of the image. */
    private function withExif(string $jpeg, int $orientation): string
    {
        $tiff = 'II' . pack('v', 42) . pack('V', 8)
            . pack('v', 2)
            . pack('vvV', 0x010F, 2, 4) . 'Make'
            . pack('vvVvv', 0x0112, 3, 1, $orientation, 0)
            . pack('V', 0)
            . self::SECRET;
        $payload = "Exif\x00\x00" . $tiff;

        return "\xFF\xD8\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    /** @return array{int, int} */
    private function dimensions(string $content): array
    {
        $size = getimagesizefromstring($content);
        self::assertNotFalse($size);

        return [$size[0], $size[1]];
    }
}
