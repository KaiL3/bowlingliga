<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\OverviewEntry;
use Bowlingliga\Application\ResultEntry\OverviewReader;

/**
 * Fake of the {@see OverviewReader} port for the unit tests of the result
 * entry overview, without a database.
 */
final class FixedOverviewReader implements OverviewReader
{
    /**
     * @param list<OverviewEntry> $entries
     */
    public function __construct(private readonly array $entries)
    {
    }

    public function findEntriesForSeason(int $seasonId): array
    {
        return $this->entries;
    }
}
