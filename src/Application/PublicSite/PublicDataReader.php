<?php

declare(strict_types=1);

namespace Bowlingliga\Application\PublicSite;

use Bowlingliga\Domain\Honours\Honours;
use Bowlingliga\Domain\Table\Table;

/**
 * Read port for the public (read-only) views (R1, R2, R13, R14, R15, R16).
 *
 * Bundles the data access needed by the
 * {@see \Bowlingliga\Presentation\PublicController}: a season's
 * chronological matchday list (R14), the prepared detail view of a played
 * matchday (R15/R2), the league table (R16) and a season's honours (R13).
 * The actual aggregation from the raw data (average lists per matchday,
 * team statistics, countable series) lives behind this port, so that the
 * presentation and visibility logic stays testable independently.
 *
 * The port is implemented by an adapter based on the raw data and cache
 * layer; in tests a fake is enough. If a requested resource cannot be
 * determined, the port returns null; the controller then shows the intended
 * message (R1.5, R15.5).
 */
interface PublicDataReader
{
    /**
     * Returns a season's chronologically sorted matchday list (R14.1, R14.2);
     * empty if the season has no matchdays (R14.7).
     *
     * @return list<MatchdayListEntry>
     */
    public function matchdayList(int $seasonId): array;

    /**
     * Tells whether a matchday exists and has been played (R15.5). Only then
     * may the detail view be shown.
     */
    public function isMatchdayPlayed(int $matchdayId): bool;

    /**
     * Returns the prepared detail view of a played matchday, or null if the
     * matchday does not exist or the data cannot be retrieved (R15.1, R1.5).
     */
    public function matchdayDetail(int $matchdayId): ?MatchdayDetail;

    /**
     * Returns a season's league table, or null if the season does not exist
     * (R16.1).
     */
    public function leagueTable(int $seasonId): ?Table;

    /**
     * Returns a season's honours, or null if the season does not exist; the
     * visibility (after the end date) is represented via
     * {@see Honours::isPubliclyVisible()} (R13.8, R13.10).
     */
    public function honours(int $seasonId): ?Honours;

    /**
     * Returns a team's current average list (as of the last played matchday
     * of its season, R2.1), or null if the team season does not exist or is
     * the ghost team (R20.4).
     */
    public function teamAverageList(int $teamSeasonId): ?TeamAverageList;

    /**
     * Returns the next matchday of a season that is not played yet and takes
     * place today or later, with fixtures and lanes (R14.8), or null if there
     * is none.
     *
     * @param string $today date Y-m-d
     */
    public function nextMatchday(int $seasonId, string $today): ?UpcomingMatchday;
}
