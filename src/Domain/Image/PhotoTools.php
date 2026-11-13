<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Image;

/**
 * Shared steps of preparing uploaded photos with GD: enough memory for the
 * decoded image, and turning a JPEG upright by its EXIF orientation. Used by
 * the score sheet photos (R3.20) and the images of the content (R42.2).
 */
final class PhotoTools
{
    private const ORIENTATION_TAG = 0x0112;

    /** GD keeps the decoded image uncompressed; a 48 MP photo needs ~200 MB. */
    public static function ensureMemoryFor(int $pixels): void
    {
        $needed  = memory_get_usage() + $pixels * 5 + 64 * 1024 * 1024;
        $current = self::bytes((string) ini_get('memory_limit'));
        if ($current !== -1 && $current < $needed) {
            ini_set('memory_limit', (string) $needed);
        }
    }

    /** Applies an EXIF orientation (1–8); imagerotate turns counter-clockwise. */
    public static function orient(\GdImage $image, int $orientation): \GdImage
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
    public static function orientation(string $jpeg): int
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
}
