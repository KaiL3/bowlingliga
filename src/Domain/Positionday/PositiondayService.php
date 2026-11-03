<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Positionday;

use Bowlingliga\Domain\Message;

use Bowlingliga\Domain\Table\TableService;
use Bowlingliga\Domain\Table\TeamStatistics;

/**
 * Pure calculation service for forming the fixtures of a positionday (R31).
 *
 * A positionday is automatically every tenth matchday of a season (R31.1);
 * its fixtures are formed from the current state of the league table by
 * putting the first placed team against the second, the third against the
 * fourth and so on (R31.2). The placement follows solely the sort order of the
 * league table according to R16 – points, then gross of the games
 * played, then net pins, then shared rank (R31.3). This order is not
 * re-implemented but obtained from the {@see TableService}; this keeps
 * positionday and league table sorting guaranteed consistent.
 *
 * The service is free of side effects and purely reproducible (R35.2): it
 * neither accesses the database nor persists anything, but receives as input
 *   - whether all matchdays before the positionday have the completion status
 *     "complete" (R31.2, R31.4), and
 *   - the key figures of the current league table already aggregated per team
 *     as {@see TeamStatistics} (R31.3).
 * From the same input it always returns the same {@see PositiondayResult}.
 *
 * Outcomes (R31.2, R31.4):
 *   - If all previous matchdays are "complete", the ordered fixtures 1v2,
 *     3v4, ... are formed and returned as a ready result.
 *   - Otherwise no fixtures are fixed; the result carries a note that all
 *     previous matchdays must be entered completely first.
 *
 * Odd number of teams: if a season contains a ghost team according to
 * R20, it is passed and sorted in regularly as its own
 * {@see TeamStatistics}, so that the number of teams is even. If the service
 * is nevertheless passed an odd number, it pairs the last placed real team
 * with an added ghost team placeholder ({@see self::GHOST_TEAM_ID}); this is
 * consistent with R20, according to which the ghost team steps in
 * as opponent with an odd number of teams, so that all real teams have a
 * fixture on every matchday.
 *
 * Otherwise a positionday is treated like a regular matchday with regard to
 * result entry, points and tables (R31.6); the fixtures being correctable
 * until the completion status "complete" (R31.7) is a concern of the
 * application layer and needs no special handling here, since the service is
 * a function that can be recalculated purely from its inputs.
 */
final class PositiondayService
{
    /**
     * Id of the ghost team placeholder added for an odd number of teams
     * according to R20.
     */
    public const GHOST_TEAM_ID = 'geisterteam';

    public function __construct(
        private readonly TableService $tableService = new TableService(),
    ) {
    }

    /**
     * Forms the fixtures of a positionday from the current state of the league
     * table (R31.2, R31.3, R31.4).
     *
     * If not every matchday before the positionday is "complete", the service
     * fixes no fixtures and returns a result with a note that all previous
     * matchdays must be entered completely first (R31.4). Otherwise the league
     * table is sorted via the {@see TableService} and the teams placed next to
     * each other are paired against each other (R31.2, R31.3).
     *
     * @param bool                 $allPreviousMatchdaysComplete true if all
     *                             matchdays before the positionday have the
     *                             completion status "complete" (R31.2, R31.4).
     * @param list<TeamStatistics> $standings                    Key figures of
     *                             the league table aggregated per team over all
     *                             matchdays played up to the positionday
     *                             (R31.3); with an odd number of real teams it
     *                             contains the ghost team according to
     *                             R20 as its own statistic.
     */
    public function formPositiondayFixtures(
        bool $allPreviousMatchdaysComplete,
        array $standings,
    ): PositiondayResult {
        if (!$allPreviousMatchdaysComplete) {
            return PositiondayResult::notReady(new Message('positionday.not_ready'));
        }

        $table = $this->tableService->leagueTable($standings);
        $rows = $table->rows;

        // With an odd number of teams the ghost team steps in according to R20
        // as opponent of the last placed real team, so that every real
        // team has a fixture.
        $addedGhostTeam = null;
        if (count($rows) % 2 === 1) {
            $addedGhostTeam = self::GHOST_TEAM_ID;
        }

        $fixtures = [];
        $quantity = count($rows);

        for ($i = 0; $i + 1 < $quantity; $i += 2) {
            $home = $rows[$i];
            $away = $rows[$i + 1];

            $fixtures[] = new PositiondayFixture(
                $home->teamId,
                $away->teamId,
                $home->rank,
                $away->rank,
                $home->isGhostTeam,
                $away->isGhostTeam,
            );
        }

        if ($addedGhostTeam !== null) {
            $last = $rows[$quantity - 1];

            $fixtures[] = new PositiondayFixture(
                $last->teamId,
                $addedGhostTeam,
                $last->rank,
                $last->rank + 1,
                $last->isGhostTeam,
                true,
            );
        }

        return PositiondayResult::ready($fixtures);
    }
}
