<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\MatchdayFlagStore;

/**
 * In-memory fake of the {@see MatchdayFlagStore} port.
 */
final class RecordingFlagStore implements MatchdayFlagStore
{
    /** @var array<int, bool> per matchday the played status set last */
    public array $isPlayed = [];

    public function setIsPlayed(int $matchdayId, bool $isPlayed): void
    {
        $this->isPlayed[$matchdayId] = $isPlayed;
    }
}
