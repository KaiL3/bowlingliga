<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\ScoreSheet;

use Bowlingliga\Domain\Ocr\UploadedFile;

/**
 * Prepares an uploaded score sheet photo for storage (R3.20).
 *
 * JPEG and PNG photos are turned upright by their EXIF orientation, scaled
 * down to at most {@see MAX_EDGE} pixels on the longer edge and stored as
 * JPEG. Re-encoding drops all metadata (location, device, time of capture),
 * which would otherwise become public with the score sheet (R3.16), and
 * keeps the files small. PDFs are returned unchanged.
 */
final class ScoreSheetPhoto
{
    /** Longer edge of a stored photo; handwriting stays legible when zooming in. */
    public const MAX_EDGE = 2000;

    public const JPEG_QUALITY = 85;

    /** Decoding needs about four bytes per pixel; larger photos are rejected. */
    public const MAX_MEGAPIXELS = 50;

    private const ORIENTATION_TAG = 0x0112;

    /**
     * @throws ScoreSheetPhotoException if the photo cannot be decoded or is too large in pixels.
     */
    public static function prepare(UploadedFile $file): UploadedFile
    {
        $mimeType = strtolower($file->mimeType);
        if ($mimeType !== 'image/jpeg' && $mimeType !== 'image/png') {
            return $file;
        }

        $size = @getimagesizefromstring($file->content);
        if ($size === false) {
            throw ScoreSheetPhotoException::unreadable();
        }
        $megapixels = (int) ceil($size[0] * $size[1] / 1_000_000);
        if ($megapixels > self::MAX_MEGAPIXELS) {
            throw ScoreSheetPhotoException::tooManyPixels($megapixels, self::MAX_MEGAPIXELS);
        }

        self::ensureMemoryFor($size[0] * $size[1]);
        $image = @imagecreatefromstring($file->content);
        if ($image === false) {
            throw ScoreSheetPhotoException::unreadable();
        }

        $image = self::scaleDown($image);
        if ($mimeType === 'image/jpeg') {
            $image = self::orient($image, self::orientation($file->content));
        }

        ob_start();
        imagejpeg($image, null, self::JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();

        return new UploadedFile($jpeg, pathinfo($file->fileName, PATHINFO_FILENAME) . '.jpg', 'image/jpeg');
    }

    /** GD keeps the decoded image uncompressed; a 48 MP photo needs ~200 MB. */
    private static function ensureMemoryFor(int $pixels): void
    {
        $needed  = memory_get_usage() + $pixels * 5 + 64 * 1024 * 1024;
        $current = self::bytes((string) ini_get('memory_limit'));
        if ($current !== -1 && $current < $needed) {
            ini_set('memory_limit', (string) $needed);
        }
    }

    private static function bytes(string $limit): int
    {
        $value = (int) $limit;

        return match (strtolower(substr(trim($limit), -1))) {
            'g'     => $value * 1024 ** 3,
            'm'     => $value * 1024 ** 2,
            'k'     => $value * 1024,
            default => $value,
        };
    }

    /** Scales to {@see MAX_EDGE} and flattens transparency onto white. */
    private static function scaleDown(\GdImage $image): \GdImage
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $factor = min(1.0, self::MAX_EDGE / max($width, $height));
        $newWidth  = max(1, (int) round($width * $factor));
        $newHeight = max(1, (int) round($height * $factor));

        $scaled = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($scaled, 0, 0, (int) imagecolorallocate($scaled, 255, 255, 255));
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $scaled;
    }

    /** Applies an EXIF orientation (1–8); imagerotate turns counter-clockwise. */
    private static function orient(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [5, 6, 7, 8], true)) {
            $image = imagerotate($image, $orientation <= 6 ? 270 : 90, 0) ?: $image;
        } elseif ($orientation === 3 || $orientation === 4) {
            $image = imagerotate($image, 180, 0) ?: $image;
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    /** Reads the orientation from the EXIF segment of a JPEG; 1 if there is none. */
    private static function orientation(string $jpeg): int
    {
        $length   = strlen($jpeg);
        $position = 2;
        while ($position + 4 <= $length && $jpeg[$position] === "\xFF") {
            $marker = ord($jpeg[$position + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $segmentLength = unpack('n', substr($jpeg, $position + 2, 2))[1];
            $payload       = substr($jpeg, $position + 4, max(0, $segmentLength - 2));
            if ($marker === 0xE1 && str_starts_with($payload, "Exif\x00\x00")) {
                return self::orientationFromTiff(substr($payload, 6)) ?? 1;
            }
            $position += 2 + $segmentLength;
        }

        return 1;
    }

    private static function orientationFromTiff(string $tiff): ?int
    {
        [$short, $long] = match (substr($tiff, 0, 2)) {
            'II'    => ['v', 'V'],
            'MM'    => ['n', 'N'],
            default => [null, null],
        };
        if ($short === null || strlen($tiff) < 8) {
            return null;
        }

        $ifd = unpack($long, substr($tiff, 4, 4))[1];
        if ($ifd + 2 > strlen($tiff)) {
            return null;
        }
        $entries = unpack($short, substr($tiff, $ifd, 2))[1];
        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + 12 * $i;
            if ($entry + 12 > strlen($tiff)) {
                return null;
            }
            if (unpack($short, substr($tiff, $entry, 2))[1] === self::ORIENTATION_TAG) {
                $value = unpack($short, substr($tiff, $entry + 8, 2))[1];

                return $value >= 1 && $value <= 8 ? $value : null;
            }
        }

        return null;
    }
}
