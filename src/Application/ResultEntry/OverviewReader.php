<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * Read port for a season's result entry overview (R30.1).
 *
 * Returns all fixtures of a season's matchdays with completion status and
 * the team names involved, so that the {@see ScoreSheetOverview} can prepare
 * the overview. The port is implemented by an adapter based on the matchday,
 * fixture and team season repositories; in tests a fake is enough.
 */
interface OverviewReader
{
    /**
     * Returns all fixtures of the season as overview entries, chronologically
     * by matchday (R30.1).
     *
     * @return list<OverviewEntry>
     */
    public function findEntriesForSeason(int $seasonId): array;
}
