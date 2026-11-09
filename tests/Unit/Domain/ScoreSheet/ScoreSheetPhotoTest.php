<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\ScoreSheet;

use Bowlingliga\Domain\Ocr\UploadedFile;
use Bowlingliga\Domain\ScoreSheet\ScoreSheetPhoto;
use Bowlingliga\Domain\ScoreSheet\ScoreSheetPhotoException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validates: R3.20
 *
 * Score sheet photos are stored upright, scaled down and as JPEG without
 * metadata; PDFs stay unchanged.
 */
final class ScoreSheetPhotoTest extends TestCase
{
    private const SECRET = 'GPS 52.5200N 13.4050E Pixel-9';

    public function testALargePhotoIsScaledDownAndLosesItsMetadata(): void
    {
        $photo = $this->withExif($this->jpeg(3000, 2250), 1);

        $stored = ScoreSheetPhoto::prepare(new UploadedFile($photo, 'IMG_0815.jpeg', 'image/jpeg'));

        self::assertSame('image/jpeg', $stored->mimeType);
        self::assertSame('IMG_0815.jpg', $stored->fileName);
        self::assertSame([2000, 1500], $this->dimensions($stored->content), 'Longer edge 2000 px, aspect ratio kept.');
        self::assertStringNotContainsString(self::SECRET, $stored->content);
        self::assertStringNotContainsString('Exif', $stored->content);
        self::assertLessThan(strlen($photo), strlen($stored->content));
    }

    public function testASmallPhotoIsNotEnlarged(): void
    {
        $stored = ScoreSheetPhoto::prepare(new UploadedFile($this->jpeg(400, 300), 'a.jpg', 'image/jpeg'));

        self::assertSame([400, 300], $this->dimensions($stored->content));
    }

    /** @return array<string, array{int, string}> orientation and where the red left half ends up */
    public static function orientations(): array
    {
        return [
            'normal'           => [1, 'left'],
            'mirrored'         => [2, 'right'],
            'upside down'      => [3, 'right'],
            'flipped'          => [4, 'left'],
            'transposed'       => [5, 'top'],
            'turned clockwise' => [6, 'top'],
            'transversed'      => [7, 'bottom'],
            'turned counter'   => [8, 'bottom'],
        ];
    }

    #[DataProvider('orientations')]
    public function testThePhotoIsTurnedUpright(int $orientation, string $redSide): void
    {
        $stored = ScoreSheetPhoto::prepare(new UploadedFile($this->withExif($this->jpeg(80, 40), $orientation), 'a.jpg', 'image/jpeg'));

        $image = imagecreatefromstring($stored->content);
        self::assertNotFalse($image);
        $sideways = $orientation >= 5;
        self::assertSame($sideways ? [40, 80] : [80, 40], [imagesx($image), imagesy($image)]);

        [$x, $y] = match ($redSide) {
            'left'   => [10, 20],
            'right'  => [70, 20],
            'top'    => [20, 10],
            'bottom' => [20, 70],
        };
        self::assertGreaterThan(150, (imagecolorat($image, $x, $y) >> 16) & 0xFF, 'Red ends up ' . $redSide . '.');
    }

    public function testAPngBecomesAJpegOnWhite(): void
    {
        $image = imagecreatetruecolor(20, 10);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $stored = ScoreSheetPhoto::prepare(new UploadedFile($png, 'scan.png', 'image/png'));

        self::assertSame('image/jpeg', $stored->mimeType);
        $decoded = imagecreatefromstring($stored->content);
        self::assertNotFalse($decoded);
        self::assertGreaterThan(240, imagecolorat($decoded, 5, 5) & 0xFF, 'Transparency becomes white.');
    }

    public function testAPdfStaysUnchanged(): void
    {
        $pdf = new UploadedFile('%PDF-1.7 ' . self::SECRET, 'zettel.pdf', 'application/pdf');

        self::assertSame($pdf, ScoreSheetPhoto::prepare($pdf));
    }

    public function testAnUnreadablePhotoIsRejected(): void
    {
        $this->expectException(ScoreSheetPhotoException::class);
        ScoreSheetPhoto::prepare(new UploadedFile("\xFF\xD8\xFF\xE0broken", 'a.jpg', 'image/jpeg'));
    }

    public function testTooManyPixelsAreRejectedBeforeDecoding(): void
    {
        $header = pack('NNCCCCC', 9000, 6000, 8, 2, 0, 0, 0);
        $png    = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $header . pack('N', crc32('IHDR' . $header));

        try {
            ScoreSheetPhoto::prepare(new UploadedFile($png, 'a.png', 'image/png'));
            self::fail('A 54 MP photo must be rejected.');
        } catch (ScoreSheetPhotoException $e) {
            self::assertSame('error.score_sheet_photo.too_many_pixels', $e->message()->key);
        }
    }

    // --- helpers ---------------------------------------------------------------

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
