<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Media;

use Bowlingliga\Domain\Image\PhotoTools;

/**
 * Prepares an uploaded image for the content of the website (R42.2).
 *
 * The type is taken from the content, not from the name or what the browser
 * claims: JPEG, PNG and WebP are accepted, anything else (SVG, which can
 * carry scripts, GIF, PDF …) is refused. The image is turned upright by its
 * EXIF orientation, scaled down to at most {@see MAX_EDGE} pixels on the
 * longer edge and encoded anew, which drops all metadata (location, device,
 * time of capture). PNG stays PNG with its transparency (logos, graphics);
 * JPEG and WebP become JPEG. The original is not kept.
 */
final class ContentImage
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_EDGE = 2000;
    public const MAX_MEGAPIXELS = 50;
    public const JPEG_QUALITY = 85;

    private function __construct(
        public readonly string $content,
        public readonly string $mimeType,
        public readonly int $width,
        public readonly int $height,
    ) {
    }

    /** File extension of the stored image, also part of its address. */
    public function extension(): string
    {
        return $this->mimeType === 'image/png' ? 'png' : 'jpg';
    }

    /**
     * @throws ContentImageException if the file is too large, no supported
     *         image or has too many pixels.
     */
    public static function prepare(string $upload): self
    {
        if (strlen($upload) > self::MAX_BYTES) {
            throw ContentImageException::tooLarge(intdiv(self::MAX_BYTES, 1024 * 1024));
        }

        $size = $upload !== '' ? @getimagesizefromstring($upload) : false;
        if ($size === false || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ContentImageException::notAnImage();
        }
        $megapixels = (int) ceil($size[0] * $size[1] / 1_000_000);
        if ($megapixels > self::MAX_MEGAPIXELS) {
            throw ContentImageException::tooManyPixels($megapixels, self::MAX_MEGAPIXELS);
        }

        PhotoTools::ensureMemoryFor($size[0] * $size[1]);
        $image = @imagecreatefromstring($upload);
        if ($image === false) {
            throw ContentImageException::notAnImage();
        }

        $png = $size[2] === IMAGETYPE_PNG;
        if ($size[2] === IMAGETYPE_JPEG) {
            $image = PhotoTools::orient($image, PhotoTools::orientation($upload));
        }
        $image = self::scaleDown($image, $png);

        ob_start();
        if ($png) {
            imagepng($image, null, 9);
        } else {
            imagejpeg($image, null, self::JPEG_QUALITY);
        }
        $content = (string) ob_get_clean();

        return new self($content, $png ? 'image/png' : 'image/jpeg', imagesx($image), imagesy($image));
    }

    /**
     * Scales to {@see MAX_EDGE}. A PNG keeps its transparency; for a JPEG it
     * is flattened onto white.
     */
    private static function scaleDown(\GdImage $image, bool $keepAlpha): \GdImage
    {
        $width     = imagesx($image);
        $height    = imagesy($image);
        $factor    = min(1.0, self::MAX_EDGE / max($width, $height));
        $newWidth  = max(1, (int) round($width * $factor));
        $newHeight = max(1, (int) round($height * $factor));

        $scaled = imagecreatetruecolor($newWidth, $newHeight);
        if ($keepAlpha) {
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            imagefill($scaled, 0, 0, (int) imagecolorallocatealpha($scaled, 0, 0, 0, 127));
        } else {
            imagefill($scaled, 0, 0, (int) imagecolorallocate($scaled, 255, 255, 255));
        }
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $scaled;
    }
}
