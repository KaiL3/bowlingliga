<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * Use case: preparing a season's result entry overview (R30.1–R30.4, R30.19).
 *
 * Reads the season's fixtures via the {@see OverviewReader} and returns them
 * as overview entries. The overview is a pure read view of the admin area;
 * access protection happens at controller level. The pure calculation part
 * (which entries still have to be filled in, R30.19) lives in
 * {@see OverviewEntry}.
 */
final class ScoreSheetOverview
{
    public function __construct(
        private readonly OverviewReader $reader,
    ) {
    }

    /**
     * Returns the overview of all fixtures of a season with their completion
     * status (R30.1).
     *
     * @return list<OverviewEntry>
     */
    public function showOverview(int $seasonId): array
    {
        return $this->reader->findEntriesForSeason($seasonId);
    }

    /**
     * Returns only the fixtures still to be filled in (status "offen" or
     * "in Bearbeitung", R30.19).
     *
     * @return list<OverviewEntry>
     */
    public function showToBeFilled(int $seasonId): array
    {
        return array_values(array_filter(
            $this->showOverview($seasonId),
            static fn (OverviewEntry $e): bool => $e->isToBeFilled()
        ));
    }
}
