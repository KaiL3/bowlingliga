<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Ocr;

/**
 * Immutable value object of an uploaded score sheet file (R3.1, R3.2, R3.15).
 *
 * Bundles binary content, original file name and the MIME type reported by
 * the client. The format and size check is done by the {@see OcrModule}; this
 * object is a pure value holder.
 */
final class UploadedFile
{
    /**
     * @param string $content  Binary content of the file
     * @param string $fileName original file name (for the extension)
     * @param string $mimeType MIME type reported by the client
     */
    public function __construct(
        public readonly string $content,
        public readonly string $fileName,
        public readonly string $mimeType,
    ) {
    }

    /**
     * Size of the file in bytes.
     */
    public function sizeInBytes(): int
    {
        return strlen($this->content);
    }

    /**
     * Lower-case file extension without dot (e.g. "jpg", "png", "pdf").
     */
    public function extension(): string
    {
        return strtolower(pathinfo($this->fileName, PATHINFO_EXTENSION));
    }
}
