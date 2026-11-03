<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Aggregation;

use Bowlingliga\Application\Aggregation\HonoursAggregator;
use Bowlingliga\Domain\Honours\HonourCategory;
use Bowlingliga\Domain\Honours\Gender;
use Bowlingliga\Domain\Honours\GameResultInput;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see HonoursAggregator} (option A, step 2).
 *
 * Validates: R13.1, R13.2, R13.8, R13.10, R20.5, R27.13
 *
 * Checks passing the raw games on to the HonoursModule including visibility
 * after the end date and – via the real module – the exclusion of blind/ghost
 * team results and the forming of series. The raw data access is an
 * in-memory fake.
 */
final class HonoursAggregatorTest extends TestCase
{
    private function series(int $playerId, int $evening, int $net, bool $blind = false, bool $ghosts = false): array
    {
        // Three regular games of the same evening form a countable series.
        return [
            new GameResultInput($playerId, Gender::MALE, $evening, $net, 0, $blind, $ghosts, 'Spieler ' . $playerId),
            new GameResultInput($playerId, Gender::MALE, $evening, $net, 0, $blind, $ghosts, 'Spieler ' . $playerId),
            new GameResultInput($playerId, Gender::MALE, $evening, $net, 0, $blind, $ghosts, 'Spieler ' . $playerId),
        ];
    }

    public function testHonoursAreCalculatedWithVisibility(): void
    {
        $results = $this->series(1, 10, 200);
        $reader = new FixedHonoursRawDataReader($results, true);

        $honours = (new HonoursAggregator($reader))->honours(1);

        self::assertTrue($honours->isPubliclyVisible(), 'The end date flag is passed on (R13.10).');

        $result = $honours->result(HonourCategory::HIGHEST_SERIES_NET, Gender::MALE);
        self::assertNotNull($result);
        self::assertSame(600, $result->places[0]->placements[0]->value, '3x200 = Serie 600 (R13.2).');
    }

    public function testNotPublicBeforeEndDate(): void
    {
        $reader = new FixedHonoursRawDataReader($this->series(1, 10, 200), false);

        $honours = (new HonoursAggregator($reader))->honours(1);

        self::assertFalse($honours->isPubliclyVisible(), 'Not public before the end date (R13.8).');
    }

    public function testBlindAndGhostTeamAreExcluded(): void
    {
        // Only blind and ghost team series -> no countable placement.
        $results = [
            ...$this->series(1, 10, 250, blind: true),
            ...$this->series(2, 11, 250, ghosts: true),
        ];
        $reader = new FixedHonoursRawDataReader($results, true);

        $honours = (new HonoursAggregator($reader))->honours(1);

        $result = $honours->result(HonourCategory::HIGHEST_SERIES_NET, Gender::MALE);
        self::assertNotNull($result);
        // Place 1 is vacant, since there is no regular series (R13.4/R13.6).
        self::assertTrue($result->places[0]->isVacant(), 'Blind/ghost team results do not count (R20.5, R27.13).');
    }
}
