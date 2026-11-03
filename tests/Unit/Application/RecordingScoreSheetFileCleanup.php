<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\ScoreSheetFileCleanup;

/** Counts the calls of the {@see ScoreSheetFileCleanup} port. */
final class RecordingScoreSheetFileCleanup implements ScoreSheetFileCleanup
{
    public int $calls = 0;

    public function removeOrphanedFiles(): int
    {
        $this->calls++;

        return 0;
    }
}
