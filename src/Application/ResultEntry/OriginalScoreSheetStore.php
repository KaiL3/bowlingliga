<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * Port for the immutable persistence of the uploaded original score sheet
 * (R3.15–R3.17).
 *
 * Decouples {@see OriginalScoreSheetStorage} from the concrete persistence
 * technology: the original score sheet is stored as binary content including
 * MIME type, assigned to the fixture, and is kept permanently regardless of
 * the OCR result. The port is implemented by the
 * {@see \Bowlingliga\Persistence\OriginalScoreSheetRepository}; in tests an
 * in-memory fake is enough.
 */
interface OriginalScoreSheetStore
{
    /**
     * Stores an original score sheet transactionally and returns its id
     * (R3.15, R3.16).
     */
    public function save(int $fixtureId, string $file, string $mimeType): int;
}
