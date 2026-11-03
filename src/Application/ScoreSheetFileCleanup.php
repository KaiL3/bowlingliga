<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

/**
 * Port for removing original score sheet files that no record references any
 * more (R3.15, R3.19).
 *
 * Deleting a fixture removes its score sheet records via `ON DELETE CASCADE`;
 * the files in the {@see BlobStore} remain. Use cases that can delete
 * fixtures (deleting a season, re-forming positionday fixtures) call this port
 * afterwards. Implemented by
 * {@see \Bowlingliga\Persistence\OriginalScoreSheetRepository}.
 */
interface ScoreSheetFileCleanup
{
    /**
     * Deletes unreferenced files that are older than a short grace period (so
     * that an upload in progress is not affected) and returns their number.
     */
    public function removeOrphanedFiles(): int;
}
