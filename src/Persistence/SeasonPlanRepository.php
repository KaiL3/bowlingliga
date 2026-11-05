<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\SeasonPlanning\PlannedMatchday;
use Bowlingliga\Application\SeasonPlanning\SeasonPlanningException;
use Bowlingliga\Application\SeasonPlanning\SeasonPlanStore;
use Bowlingliga\Domain\Message;

/**
 * Persistence adapter for transactionally saving the schedule including the
 * lane assignment (R9.3, R9.4, R18.4).
 *
 * Implements the {@see SeasonPlanStore} port. The whole operation runs in a
 * single transaction: first – idempotently – the season's lane pairs needed
 * are ensured, then per matchday the matchday row and its fixtures with lane
 * pair assignment are created. If a step fails, everything is rolled back and
 * the data stays unchanged (R9.4, R18.4). Prepared statements (R4.1).
 *
 * Positiondays carry no fixed fixtures and no lane assignment (R9.9, R18.7);
 * only the matchday row is created for them.
 *
 * Ghost team (R9.6, R20): the use case keeps the ghost team without a team
 * season id (null). So that tables, average lists and result entry recognize
 * it as a team of its own, it is ensured here as a team season row with
 * `is_ghost_team = 1` (an existing one is reused) and entered in the
 * fixtures.
 *
 * Replanning (R9.14): if the season already has matchdays, the existing plan
 * is replaced – but only as long as the season has not started and neither
 * played matchdays nor started result entries exist (see
 * {@see reasonAgainstReplanning()}).
 */
final class SeasonPlanRepository extends AbstractRepository implements SeasonPlanStore
{
    public const GHOST_TEAM_NAME = 'Geisterteam';

    /**
     * @param list<PlannedMatchday> $matchdays
     *
     * @throws SeasonPlanningException if an existing plan must not be replaced.
     */
    public function saveSchedule(int $seasonId, array $matchdays): void
    {
        $reason = $this->reasonAgainstReplanning($seasonId);
        if ($reason !== null) {
            throw SeasonPlanningException::planCannotBeGenerated($reason);
        }

        $this->transactional('save_schedule', function () use ($seasonId, $matchdays): void {
            // Replace the existing (not yet started) plan; fixtures and
            // dependent rows are dropped via ON DELETE CASCADE.
            $this->perform('DELETE FROM matchday WHERE season_id = :season_id', ['season_id' => $seasonId]);

            // Determine the lane pair indices needed for the lane assignment and
            // ensure the season's corresponding lane pair rows (idempotent).
            $lanePairIdByIndex = $this->ensureLanePairs($seasonId, $matchdays);
            $ghostTeamId       = $this->needsGhostTeam($matchdays)
                ? $this->ensureGhostTeam($seasonId)
                : null;

            $season = $this->findOne(
                'SELECT start_date, end_date FROM season WHERE id = :id',
                ['id' => $seasonId]
            );

            foreach ($matchdays as $matchday) {
                $matchdayId = $this->createMatchday($seasonId, $matchday, $season);

                foreach ($matchday->fixtures as $fixture) {
                    $lanePairId = $fixture->lanePairIndex !== null
                        ? ($lanePairIdByIndex[$fixture->lanePairIndex] ?? null)
                        : null;

                    $this->perform(
                        'INSERT INTO fixture
                            (matchday_id, home_team_season_id, away_team_season_id, lane_pair_id, completion_status)
                         VALUES (:matchday_id, :home_id, :away_id, :lane_pair_id, :status)',
                        [
                            'matchday_id' => $matchdayId,
                            'home_id'     => $fixture->homeTeamSeasonId ?? $ghostTeamId,
                            'away_id'     => $fixture->awayTeamSeasonId ?? $ghostTeamId,
                            'lane_pair_id' => $lanePairId,
                            'status'      => 'open',
                        ]
                    );
                }
            }
        });
    }

    /**
     * Returns the reason why a season's existing schedule may no longer be
     * replaced, or null if (re)planning is allowed.
     *
     * Without existing matchdays planning is always allowed. Otherwise the
     * season must not have started yet (R9.14) and there must be neither
     * played matchdays nor fixtures with started result entry – this raw data
     * would otherwise be lost (R35).
     */
    public function reasonAgainstReplanning(int $seasonId): ?Message
    {
        $standing = $this->findOne(
            "SELECT
                (SELECT COUNT(*) FROM matchday WHERE season_id = :s1) AS matchdays,
                (SELECT COUNT(*) FROM matchday WHERE season_id = :s2 AND is_played = 1) AS played,
                (SELECT COUNT(*) FROM fixture fx JOIN matchday md ON md.id = fx.matchday_id
                  WHERE md.season_id = :s3 AND fx.completion_status <> 'open') AS begun,
                (SELECT COUNT(*) FROM original_score_sheet os
                  JOIN fixture fx ON fx.id = os.fixture_id
                  JOIN matchday md ON md.id = fx.matchday_id
                  WHERE md.season_id = :s4) AS score_sheet,
                (SELECT start_date <= CURDATE() FROM season WHERE id = :s5) AS started",
            ['s1' => $seasonId, 's2' => $seasonId, 's3' => $seasonId, 's4' => $seasonId, 's5' => $seasonId]
        );

        if ($standing === null || (int) $standing['matchdays'] === 0) {
            return null;
        }

        if ((int) $standing['played'] > 0 || (int) $standing['begun'] > 0 || (int) $standing['score_sheet'] > 0) {
            return new Message('planning.results_entered');
        }

        if ((int) $standing['started'] === 1) {
            return new Message('planning.season_started');
        }

        return null;
    }

    /**
     * Creates a matchday row and returns its id.
     *
     * @param array<string, mixed>|null $season Start/end date of the season
     */
    private function createMatchday(int $seasonId, PlannedMatchday $matchday, ?array $season): int
    {
        $this->perform(
            'INSERT INTO matchday (season_id, matchday_number, date, matchday_type, is_played)
             VALUES (:season_id, :number, :date, :type, 0)',
            [
                'season_id' => $seasonId,
                'number'   => $matchday->number,
                'date'     => $this->determineMatchdayDate($season, $matchday->number),
                'type'       => $matchday->type,
            ]
        );

        return (int) $this->database->pdo()->lastInsertId();
    }

    /**
     * @param list<PlannedMatchday> $matchdays
     */
    private function needsGhostTeam(array $matchdays): bool
    {
        foreach ($matchdays as $matchday) {
            foreach ($matchday->fixtures as $fixture) {
                if ($fixture->homeTeamSeasonId === null || $fixture->awayTeamSeasonId === null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns the team season id of the season's ghost team and creates it
     * (including the team row) if needed (R9.6, R20).
     */
    private function ensureGhostTeam(int $seasonId): int
    {
        $present = $this->findOne(
            'SELECT id FROM team_season WHERE season_id = :season_id AND is_ghost_team = 1 ORDER BY id LIMIT 1',
            ['season_id' => $seasonId]
        );
        if ($present !== null) {
            return (int) $present['id'];
        }

        $this->perform('INSERT INTO team () VALUES ()');
        $teamId = (int) $this->database->pdo()->lastInsertId();

        $this->perform(
            'INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team)
             VALUES (:team_id, :season_id, :team_name, 1)',
            ['team_id' => $teamId, 'season_id' => $seasonId, 'team_name' => self::GHOST_TEAM_NAME]
        );

        return (int) $this->database->pdo()->lastInsertId();
    }

    /**
     * Ensures the season's lane pairs needed for the lane assignment and
     * returns the mapping lane pair index → lane pair id.
     *
     * Lane pair index n corresponds to lanes (n−1)·2+1 and +2 (lane pair 1 →
     * lanes 1/2, …). Existing lane pairs are reused, missing ones are created.
     *
     * @param list<PlannedMatchday> $matchdays
     *
     * @return array<int, int> [lanePairIndex] => lane_pair_id
     */
    private function ensureLanePairs(int $seasonId, array $matchdays): array
    {
        // Collect the lane pair indices needed.
        $indices = [];
        foreach ($matchdays as $matchday) {
            foreach ($matchday->fixtures as $fixture) {
                if ($fixture->lanePairIndex !== null) {
                    $indices[$fixture->lanePairIndex] = true;
                }
            }
        }

        // Index the season's existing lane pairs by (lane_a).
        $existingItems = $this->findAll(
            'SELECT id, lane_a FROM lane_pair WHERE season_id = :season_id',
            ['season_id' => $seasonId]
        );
        $idByLaneA = [];
        foreach ($existingItems as $row) {
            $idByLaneA[(int) $row['lane_a']] = (int) $row['id'];
        }

        $idByIndex = [];
        foreach (array_keys($indices) as $index) {
            $laneA = ($index - 1) * 2 + 1;
            $laneB = $laneA + 1;

            if (isset($idByLaneA[$laneA])) {
                $idByIndex[$index] = $idByLaneA[$laneA];
                continue;
            }

            $this->perform(
                'INSERT INTO lane_pair (season_id, lane_a, lane_b) VALUES (:season_id, :lane_a, :lane_b)',
                ['season_id' => $seasonId, 'lane_a' => $laneA, 'lane_b' => $laneB]
            );
            $idByIndex[$index] = (int) $this->database->pdo()->lastInsertId();
        }

        return $idByIndex;
    }

    /**
     * Determines a provisional date for the matchday: weekly rhythm from the
     * season's start date, at most until the end date. The season planner
     * works independently of the calendar; the concrete scheduling can be
     * adjusted via the matchday correction (R8.5, R9.14).
     *
     * @param array<string, mixed>|null $season
     */
    private function determineMatchdayDate(?array $season, int $number): string
    {
        $start = $season !== null ? \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $season['start_date']) : false;
        if ($start === false) {
            return date('Y-m-d');
        }

        $date = $start->modify('+' . (7 * ($number - 1)) . ' days');

        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $season['end_date']);
        if ($end !== false && $date > $end) {
            $date = $end;
        }

        return $date->format('Y-m-d');
    }
}
