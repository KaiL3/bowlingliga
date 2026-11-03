<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Ocr;

/**
 * Port for the actual text recognition of a score sheet image (R3.1).
 *
 * Decouples the {@see OcrModule} from the concrete OCR engine. In production
 * the port is implemented by an adapter based on Tesseract OCR
 * (`thiagoalessio/tesseract_ocr`, installed locally, R4.2); in tests a fake
 * returning a fixed text output is enough.
 *
 * The port only returns the recognized raw text; the interpretation (net
 * values, blind marker, date, team names) is done by the pure
 * {@see OcrModule}, so that the parsing logic stays testable without a real
 * OCR engine.
 */
interface TextRecognizer
{
    /**
     * Returns the raw text recognized from the file.
     *
     * @throws OcrException if the recognition fails or the time limit is
     *         exceeded (R3.14).
     */
    public function recognizeText(UploadedFile $file): string;
}
