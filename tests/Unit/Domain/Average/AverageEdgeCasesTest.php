<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\Average;

use Bowlingliga\Domain\Average\AverageInput;
use Bowlingliga\Domain\Average\AverageModule;
use Bowlingliga\Domain\Average\Game;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the status value "without average" and the edge cases of the
 * average calculation (task 7.4).
 *
 * Validates: R11.7
 *
 * R11.7: if a player has no includable played game, the
 * {@see AverageModule} determines no average and marks the player as "without
 * average". This status is represented by the return value
 * {@see AverageModule::WITHOUT_AVERAGE} (null).
 *
 * The edge cases checked are: empty history, only games marked blind, only
 * ghost team games, the boundary values 0 and 300 of the net result and a mix
 * of includable and non-includable games.
 */
final class AverageEdgeCasesTest extends TestCase
{
    private const PLAYER = 1;
    private const SEASON  = 1;

    /**
     * A matchday counter from 6, so that the previous season is not included
     * and the edge cases are assessed solely on the current season (R11.5).
     */
    private const MATCHDAY_WITHOUT_PREVIOUS_SEASON = 6;

    private AverageModule $module;

    protected function setUp(): void
    {
        $this->module = new AverageModule();
    }

    public function testEmptyHistoryReturnsWithoutAverage(): void
    {
        $input = new AverageInput([]);

        $result = $this->module->calculateAverage(
            self::PLAYER,
            self::SEASON,
            self::MATCHDAY_WITHOUT_PREVIOUS_SEASON,
            $input,
        );

        self::assertSame(AverageModule::WITHOUT_AVERAGE, $result);
        self::assertNull($result);
    }

    public function testOnlyBlindGamesReturnWithoutAverage(): void
    {
        $input = new AverageInput([
            new Game(netResult: 180, blind: true),
            new Game(netResult: 210, blind: true),
        ]);

        $result = $this->module->calculateAverage(
            self::PLAYER,
            self::SEASON,
            self::MATCHDAY_WITHOUT_PREVIOUS_SEASON,
            $input,
        );

        self::assertSame(AverageModule::WITHOUT_AVERAGE, $result);
        self::assertNull($result);
    }

    public function testOnlyGhostTeamGamesReturnWithoutAverage(): void
    {
        $input = new AverageInput([
            new Game(netResult: 200, ghostTeam: true),
            new Game(netResult: 200, ghostTeam: true),
            new Game(netResult: 200, ghostTeam: true),
        ]);

        $result = $this->module->calculateAverage(
            self::PLAYER,
            self::SEASON,
            self::MATCHDAY_WITHOUT_PREVIOUS_SEASON,
            $input,
        );

        self::assertSame(AverageModule::WITHOUT_AVERAGE, $result);
        self::assertNull($result);
    }

    public function testBoundaryNetZeroIsCalculatedCorrectly(): void
    {
        $input = new AverageInput([
            new Game(netResult: 0),
            new Game(netResult: 0),
        ]);

        $result = $this->module->calculateAverage(
            self::PLAYER,
            self::SEASON,
            self::MATCHDAY_WITHOUT_PREVIOUS_SEASON,
            $input,
        );

        self::assertSame(0.0, $result);
    }

    public function testBoundaryNetThreeHundredIsCalculatedCorrectly(): void
    {
        $input = new AverageInput([
            new Game(netResult: 300),
            new Game(netResult: 300),
        ]);

        $result = $this->module->calculateAverage(
            self::PLAYER,
            self::SEASON,
            self::MATCHDAY_WITHOUT_PREVIOUS_SEASON,
            $input,
        );

        self::assertSame(300.0, $result);
    }

    public function testMixedBoundaryValuesAverageBetweenZeroAndThreeHundred(): void
    {
        $input = new AverageInput([
            new Game(netResult: 0),
            new Game(netResult: 300),
        ]);

        $result = $this->module->calculateAverage(
            self::PLAYER,
            self::SEASON,
            self::MATCHDAY_WITHOUT_PREVIOUS_SEASON,
            $input,
        );

        self::assertSame(150.0, $result);
    }

    public function testMixIncludesOnlyRealGames(): void
    {
        // Only the two games actually played (150 and 210) count; the
        // blind and the ghost team game are ignored (R11.1).
        $input = new AverageInput([
            new Game(netResult: 150),
            new Game(netResult: 300, blind: true),
            new Game(netResult: 200, ghostTeam: true),
            new Game(netResult: 210),
        ]);

        $result = $this->module->calculateAverage(
            self::PLAYER,
            self::SEASON,
            self::MATCHDAY_WITHOUT_PREVIOUS_SEASON,
            $input,
        );

        self::assertSame(180.0, $result);
    }
}
