<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * Port for setting the played matchday flag (R24.1, R24.4, R30.18).
 *
 * Decouples {@see ResultRecording} from the matchday persistence. The port is
 * implemented by the {@see \Bowlingliga\Persistence\MatchdayRepository}; in
 * tests a slim in-memory fake is enough.
 */
interface MatchdayFlagStore
{
    /**
     * Marks a matchday as played or not played (R24.1, R24.4).
     */
    public function setIsPlayed(int $matchdayId, bool $isPlayed): void;
}
