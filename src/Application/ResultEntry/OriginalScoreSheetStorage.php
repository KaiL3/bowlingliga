<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

use Bowlingliga\Domain\Ocr\UploadedFile;
use Bowlingliga\Domain\Ocr\OcrException;
use Bowlingliga\Domain\Ocr\OcrModule;
use Bowlingliga\Security\WriteAccess;
use Bowlingliga\Security\AccessDeniedException;

/**
 * Writing use case: immutable storage of the uploaded original score sheet and
 * assignment to the fixture (R3.15–R3.17).
 *
 * The original score sheet is stored permanently and unchanged as
 * documentary evidence (R3.15) and assigned to the fixture so that it can be
 * shown in the public detail view (R3.16). Storing is deliberately decoupled
 * from the OCR result: it happens regardless of whether the OCR recognition
 * succeeded, failed or was corrected manually (R3.17). This use case
 * therefore only validates format and size of the upload (consistent with
 * R3.1/R3.2) and then saves transactionally.
 *
 * Every call is protected by {@see WriteAccess} (R5.1).
 */
final class OriginalScoreSheetStorage
{
    /** Supported file extensions (R3.1). */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf'];

    /** Supported MIME types (R3.1). */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'application/pdf'];

    public function __construct(
        private readonly OriginalScoreSheetStore $store,
        private readonly WriteAccess $writeAccess,
    ) {
    }

    /**
     * Stores the uploaded original score sheet unchanged and assigns it to the
     * fixture; returns the id of the stored record (R3.15, R3.16, R3.17).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws OcrException for an unsupported format or too large a file
     *         (R3.2).
     */
    public function save(int $fixtureId, UploadedFile $file): int
    {
        $this->ensureWriteAccess();
        $this->ensureFormatAndSize($file);

        return $this->store->save($fixtureId, $file->content, $file->mimeType);
    }

    /**
     * Validates file format and size (R3.2), consistent with the
     * {@see OcrModule}.
     *
     * @throws OcrException
     */
    private function ensureFormatAndSize(UploadedFile $file): void
    {
        if ($file->sizeInBytes() > OcrModule::MAX_SIZE_BYTES) {
            throw OcrException::fileTooLarge($file->sizeInBytes(), OcrModule::MAX_SIZE_BYTES);
        }

        $extensionOk = in_array($file->extension(), self::ALLOWED_EXTENSIONS, true);
        $mimeOk   = in_array(strtolower($file->mimeType), self::ALLOWED_MIME, true);

        if (! $extensionOk || ! $mimeOk) {
            throw OcrException::unsupportedFormat($file->mimeType !== '' ? $file->mimeType : $file->extension());
        }
    }

    private function ensureWriteAccess(): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
    }
}
