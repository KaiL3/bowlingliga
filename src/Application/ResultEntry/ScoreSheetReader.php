<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * Read port for the raw data of a fixture to build the virtual score sheet
 * (R30.5, R30.17).
 *
 * Returns the matchday id and both teams with their positions and already
 * stored game results. The port is implemented by an adapter based on the
 * fixture, lineup, game and team season repositories; in tests a fake is
 * enough.
 */
interface ScoreSheetReader
{
    /**
     * Returns the matchday id of a fixture, or null if the fixture does not
     * exist.
     */
    public function findMatchdayId(int $fixtureId): ?int;

    /**
     * Returns the raw data of both teams of a fixture (home, away) for building
     * the score sheet.
     *
     * @return array{0: ScoreSheetTeamRawData, 1: ScoreSheetTeamRawData}|null
     *         null if the fixture does not exist.
     */
    public function findTeamRawData(int $fixtureId): ?array;
}
