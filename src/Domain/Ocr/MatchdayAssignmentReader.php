<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Ocr;

/**
 * Read port for assigning recognized game data to matchday and fixture
 * (R3.3–R3.5).
 *
 * Returns the matchdays matching a date together with their fixtures and the
 * team names involved, so that the {@see OcrModule} can perform the assignment
 * purely and without direct persistence coupling. The port is implemented by
 * an adapter based on the matchday, fixture and team season repositories; in
 * tests a fake is enough.
 */
interface MatchdayAssignmentReader
{
    /**
     * Returns the fixtures of all matchdays with the given date (Y-m-d)
     * together with the team names involved.
     *
     * Each entry has the form:
     * `['matchday_id' => int, 'fixture_id' => int, 'teamnamen' => list<string>]`.
     * If no matchday exists for the date, the list is empty (R3.5).
     *
     * @return list<array{matchday_id: int, fixture_id: int, team_names: list<string>}>
     */
    public function findFixturesByDate(string $date): array;
}
