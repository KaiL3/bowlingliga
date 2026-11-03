<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\MatchdayContext;

/**
 * In-memory fake of the {@see MatchdayContext} port.
 */
final class FixedMatchdayContext implements MatchdayContext
{
    /** @var array<int, array{season_id: int, matchday_number: int}> */
    public array $contexts = [];

    public function findSeasonAndCounter(int $matchdayId): ?array
    {
        return $this->contexts[$matchdayId] ?? null;
    }
}
