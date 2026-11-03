<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\Ocr;

use Bowlingliga\Domain\Ocr\UploadedFile;
use Bowlingliga\Domain\Ocr\OcrException;
use Bowlingliga\Domain\Ocr\TextRecognizer;

/**
 * Fake of the {@see TextRecognizer} port with a fixed text output for the
 * OcrModule unit tests, without a real OCR engine.
 */
final class FixedTextRecognizer implements TextRecognizer
{
    public function __construct(
        private readonly string $text,
        private readonly bool $throwsError = false,
    ) {
    }

    public function recognizeText(UploadedFile $file): string
    {
        if ($this->throwsError) {
            throw OcrException::recognitionFailed('Simulierter Fehlschlag.');
        }

        return $this->text;
    }
}
