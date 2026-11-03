<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\Ocr;

use Bowlingliga\Domain\Ocr\MatchdayAssignmentReader;

/**
 * Fake of the {@see MatchdayAssignmentReader} port for the OcrModule unit
 * tests, without a database.
 */
final class FixedAssignmentReader implements MatchdayAssignmentReader
{
    /**
     * @param array<string, list<array{matchday_id: int, fixture_id: int, team_names: list<string>}>> $byDate
     */
    public function __construct(private readonly array $byDate)
    {
    }

    public function findFixturesByDate(string $date): array
    {
        return $this->byDate[$date] ?? [];
    }
}
