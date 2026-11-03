<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\ScoreSheetReader;
use Bowlingliga\Application\ResultEntry\ScoreSheetTeamRawData;

/**
 * Fake of the {@see ScoreSheetReader} port for the unit tests of the virtual
 * score sheet, without a database.
 */
final class FixedScoreSheetReader implements ScoreSheetReader
{
    /**
     * @param array{0: ScoreSheetTeamRawData, 1: ScoreSheetTeamRawData}|null $rawData
     */
    public function __construct(
        private readonly ?array $rawData,
        private readonly ?int $matchdayId = 5,
    ) {
    }

    public function findMatchdayId(int $fixtureId): ?int
    {
        return $this->matchdayId;
    }

    public function findTeamRawData(int $fixtureId): ?array
    {
        return $this->rawData;
    }
}
