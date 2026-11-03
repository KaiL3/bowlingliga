<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Ocr;

/**
 * Domain errors of the OCR module (R3.2, R3.14).
 *
 * Covers rejecting unsupported formats or too large files (R3.2) and failure
 * or timeout of the recognition (R3.14). The message names the reason; the
 * uploaded original score sheet is not affected (R3.17) and is stored
 * separately.
 */
final class OcrException extends \RuntimeException
{
    /**
     * Unsupported file format (R3.2).
     */
    public static function unsupportedFormat(string $given): self
    {
        return new self(sprintf(
            'Das Dateiformat "%s" wird nicht unterstützt. Erlaubt sind JPEG, PNG und PDF.',
            $given
        ));
    }

    /**
     * Maximum file size exceeded (R3.2).
     */
    public static function fileTooLarge(int $sizeInBytes, int $maxInBytes): self
    {
        return new self(sprintf(
            'Die Datei ist mit %d Byte zu groß; erlaubt sind höchstens %d Byte (10 MB).',
            $sizeInBytes,
            $maxInBytes
        ));
    }

    /**
     * The recognition could not be completed or exceeded the time limit
     * (R3.14).
     */
    public static function recognitionFailed(string $reason): self
    {
        return new self(sprintf(
            'Die OCR-Erkennung ist fehlgeschlagen: %s. Die hochgeladene Datei bleibt zur erneuten Verarbeitung verfügbar.',
            $reason
        ));
    }
}
