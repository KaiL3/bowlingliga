<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\ScoreSheet;

use Bowlingliga\Domain\Image\PhotoTools;
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

        PhotoTools::ensureMemoryFor($size[0] * $size[1]);
        $image = @imagecreatefromstring($file->content);
        if ($image === false) {
            throw ScoreSheetPhotoException::unreadable();
        }

        $image = self::scaleDown($image);
        if ($mimeType === 'image/jpeg') {
            $image = PhotoTools::orient($image, PhotoTools::orientation($file->content));
        }

        ob_start();
        imagejpeg($image, null, self::JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();

        return new UploadedFile($jpeg, pathinfo($file->fileName, PATHINFO_FILENAME) . '.jpg', 'image/jpeg');
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
}
