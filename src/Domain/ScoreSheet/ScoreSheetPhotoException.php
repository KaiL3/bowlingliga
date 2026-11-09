<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\ScoreSheet;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Translatable;

/**
 * An uploaded score sheet photo cannot be prepared for storage (R3.20).
 */
final class ScoreSheetPhotoException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    public static function unreadable(): self
    {
        return new self('error.score_sheet_photo.unreadable');
    }

    public static function tooManyPixels(int $megapixels, int $maxMegapixels): self
    {
        return new self('error.score_sheet_photo.too_many_pixels', ['megapixels' => $megapixels, 'max' => $maxMegapixels]);
    }
}
