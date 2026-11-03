<?php

declare(strict_types=1);

namespace Bowlingliga\Ocr;

use Bowlingliga\Domain\Ocr\UploadedFile;
use Bowlingliga\Domain\Ocr\OcrException;
use Bowlingliga\Domain\Ocr\TextRecognizer;
use thiagoalessio\TesseractOCR\TesseractOCR;

/**
 * Production adapter of the {@see TextRecognizer} port based on Tesseract OCR
 * (`thiagoalessio/tesseract_ocr`, installed locally, R4.2).
 *
 * The adapter writes the uploaded binary content to a temporary file, lets
 * Tesseract read it with a time limit of 30 seconds (R3.1, R3.14) and returns
 * the recognized raw text. Interpreting the raw text is done by the pure
 * {@see \Bowlingliga\Domain\Ocr\OcrModule}.
 *
 * If the recognition fails or exceeds the time limit, an {@see OcrException}
 * is thrown (R3.14); the uploaded original score sheet is not affected and is
 * stored separately (R3.17).
 */
final class TesseractTextRecognizer implements TextRecognizer
{
    /** Time limit of the recognition in seconds (R3.1, R3.14). */
    public const TIMEOUT_SECONDS = 30;

    /**
     * @param string      $language Tesseract language pack (default German).
     * @param string|null $tempDir  optional directory for the temporary file;
     *        without it the system temp directory.
     * @param string|null $program  optional path to the Tesseract binary;
     *        without it `tesseract` is looked up via the PATH.
     */
    public function __construct(
        private readonly string $language = 'deu',
        private readonly ?string $tempDir = null,
        private readonly ?string $program = null,
    ) {
    }

    public function recognizeText(UploadedFile $file): string
    {
        $tempFile = $this->writeTempFile($file);

        try {
            $ocr = new TesseractOCR($tempFile);
            if ($this->program !== null && $this->program !== '') {
                // Fixed path instead of a PATH lookup; the web server process does not
                // necessarily know the PATH of the login session.
                $ocr->executable($this->program);
            }

            $text = $ocr
                ->long($this->language)
                ->run(self::TIMEOUT_SECONDS);

            return $text;
        } catch (\Throwable $throwable) {
            // Failure or timeout: the file is kept (R3.17); the
            // caller keeps the upload available for another attempt (R3.14).
            throw OcrException::recognitionFailed($throwable->getMessage());
        } finally {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Writes the binary content to a temporary file with a matching extension.
     *
     * @throws OcrException if no temporary file can be created.
     */
    private function writeTempFile(UploadedFile $file): string
    {
        $directory = $this->tempDir ?? sys_get_temp_dir();
        $extension      = $file->extension() !== '' ? $file->extension() : 'tmp';
        $pathName        = $directory . DIRECTORY_SEPARATOR . uniqid('spielzettel_', true) . '.' . $extension;

        if (file_put_contents($pathName, $file->content) === false) {
            throw OcrException::recognitionFailed('Die hochgeladene Datei konnte nicht zwischengespeichert werden.');
        }

        return $pathName;
    }
}
