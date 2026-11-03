<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Domain\Printing\PrintScoreSheetHeader;
use Bowlingliga\Domain\Printing\PrintScoreSheetTeam;

/**
 * Data source for generating printed score sheets in the admin area (task
 * 35.4).
 *
 * Decouples the {@see PrintController} from the concrete persistence: for a
 * fixture the controller only needs the header data and the home and away
 * team. In production the
 * {@see \Bowlingliga\Persistence\Printing\PrintScoreSheetData} adapter
 * implements this port via the raw data repositories; in tests a fake is
 * enough.
 *
 * All methods return null if the fixture or the respective team assignment
 * cannot be determined.
 */
interface PrintScoreSheetDataSource
{
    /**
     * Returns the header data of a fixture's printed score sheet, or null.
     */
    public function header(int $fixtureId): ?PrintScoreSheetHeader;

    /**
     * Returns the home team of a fixture's printed score sheet, or null.
     */
    public function homeTeam(int $fixtureId): ?PrintScoreSheetTeam;

    /**
     * Returns the away team of a fixture's printed score sheet, or null.
     */
    public function awayTeam(int $fixtureId): ?PrintScoreSheetTeam;

    /**
     * Matchdays of a season for the selection when printing, chronologically.
     *
     * @return list<array{id: int, number: int, date: string, type: string, played: bool, pairings: int}>
     */
    public function matchdays(int $seasonId): array;

    /**
     * Fixtures of a matchday in print order (by lane).
     *
     * @return list<int>
     */
    public function fixtureIds(int $matchdayId): array;

    /**
     * Half pre-filled score sheets of a lot draw whose fixtures are not drawn
     * yet: the header (matchday, date, fees, no lanes) and every team of the
     * season with its roster as of the matchday, regular teams by name, the
     * ghost team last. Null for any other matchday.
     *
     * @return array{header: PrintScoreSheetHeader, teams: list<PrintScoreSheetTeam>}|null
     */
    public function lotDrawSheets(int $matchdayId): ?array;

    /**
     * Language of the printed score sheets of the matchday's season; null =
     * the installation's default.
     */
    public function scoreSheetLanguage(int $matchdayId): ?string;

    /** Matchday of a fixture, or null. */
    public function matchdayOfFixture(int $fixtureId): ?int;
}
