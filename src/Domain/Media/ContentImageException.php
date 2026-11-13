<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Media;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Translatable;

/**
 * An image for the content cannot be stored or removed (R42).
 */
final class ContentImageException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    public static function notAnImage(): self
    {
        return new self('error.content_image.not_an_image');
    }

    public static function tooLarge(int $megabytes): self
    {
        return new self('error.content_image.too_large', ['max' => $megabytes]);
    }

    public static function tooManyPixels(int $megapixels, int $maxMegapixels): self
    {
        return new self('error.content_image.too_many_pixels', ['megapixels' => $megapixels, 'max' => $maxMegapixels]);
    }

    public static function noFile(): self
    {
        return new self('error.content_image.no_file');
    }

    public static function inUse(int $places): self
    {
        return new self('error.content_image.in_use', ['count' => $places]);
    }

    public static function notFound(): self
    {
        return new self('error.content_image.not_found');
    }
}
