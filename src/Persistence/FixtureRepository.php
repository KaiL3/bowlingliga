<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\ResultEntry\FixtureStatusStore;
use Bowlingliga\Security\Validator;

/**
 * Raw data repository for fixtures (table `fixture`, R8.6, R18, R30).
 *
 * A fixture assigns two team season entries (home/away) and optionally a lane
 * pair to a matchday and keeps the completion status. Home/away and lane pair
 * may be NULL as long as the fixture (e.g. for the positionday) is not fixed
 * yet (R8.6, R18.7). Write operations transactional (R4.3), prepared
 * statements (R4.1), reads unchanged (R4.5).
 *
 * Also implements the {@see FixtureStatusStore} port, via which
 * {@see \Bowlingliga\Application\ResultEntry\ResultRecording} sets the
 * completion status (R30.2–4, R30.15, R30.18).
 */
final class FixtureRepository extends AbstractRepository implements FixtureStatusStore
{
    private readonly Validator $validator;

    public function __construct(Database $database, ?Validator $validator = null)
    {
        parent::__construct($database);
        $this->validator = $validator ?? new Validator();
    }

    /**
     * Creates a fixture for a matchday and returns its id (R8.6, R18.1).
     *
     * If both teams are already fixed, they must differ (R8.6); the check
     * happens via the validator, since it cannot be expressed as a CHECK
     * constraint in MariaDB.
     */
    public function create(
        int $matchdayId,
        ?int $teamSeasonHomeId = null,
        ?int $teamSeasonAwayId = null,
        ?int $lanePairId = null,
        string $completionStatus = 'open'
    ): int {
        $this->validator->checkDifferentTeams($teamSeasonHomeId, $teamSeasonAwayId);

        return $this->transactional('create_fixture', function ($pdo) use ($matchdayId, $teamSeasonHomeId, $teamSeasonAwayId, $lanePairId, $completionStatus): int {
            $this->perform(
                'INSERT INTO fixture
                    (matchday_id, home_team_season_id, away_team_season_id, lane_pair_id, completion_status)
                 VALUES
                    (:matchday_id, :home_id, :away_id, :lane_pair_id, :completion_status)',
                [
                    'matchday_id'    => $matchdayId,
                    'home_id'        => $teamSeasonHomeId,
                    'away_id'        => $teamSeasonAwayId,
                    'lane_pair_id'    => $lanePairId,
                    'completion_status' => $completionStatus,
                ]
            );

            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Sets the teams involved (e.g. positionday formation, R31.2).
     *
     * If both teams are fixed, they must differ (R8.6).
     */
    public function setTeams(int $id, ?int $teamSeasonHomeId, ?int $teamSeasonAwayId): void
    {
        $this->validator->checkDifferentTeams($teamSeasonHomeId, $teamSeasonAwayId);

        $this->transactional('set_fixture_teams', function () use ($id, $teamSeasonHomeId, $teamSeasonAwayId): void {
            $this->perform(
                'UPDATE fixture SET home_team_season_id = :home_id, away_team_season_id = :away_id WHERE id = :id',
                ['id' => $id, 'home_id' => $teamSeasonHomeId, 'away_id' => $teamSeasonAwayId]
            );
        });
    }

    /**
     * Assigns a lane pair to the fixture (R18.1, R18.7).
     */
    public function setLanePair(int $id, ?int $lanePairId): void
    {
        $this->transactional('set_fixture_lane_pair', function () use ($id, $lanePairId): void {
            $this->perform(
                'UPDATE fixture SET lane_pair_id = :lane_pair_id WHERE id = :id',
                ['id' => $id, 'lane_pair_id' => $lanePairId]
            );
        });
    }

    /**
     * Sets the completion status of a fixture (R30).
     *
     * @param 'open'|'in_progress'|'complete' $completionStatus
     */
    public function setCompletionStatus(int $id, string $completionStatus): void
    {
        $this->transactional('set_fixture_status', function () use ($id, $completionStatus): void {
            $this->perform(
                'UPDATE fixture SET completion_status = :completion_status WHERE id = :id',
                ['id' => $id, 'completion_status' => $completionStatus]
            );
        });
    }

    /**
     * Tells whether all fixtures of the matchday are entered completely
     * (R24.1); fixtures without fixed teams count as incomplete.
     */
    public function areAllComplete(int $matchdayId): bool
    {
        $row = $this->findOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(completion_status = 'complete'
                        AND home_team_season_id IS NOT NULL
                        AND away_team_season_id IS NOT NULL), 0) AS finished
             FROM fixture WHERE matchday_id = :id",
            ['id' => $matchdayId]
        );

        return $row !== null && (int) $row['total'] > 0 && (int) $row['total'] === (int) $row['finished'];
    }

    /**
     * First earlier matchday of the season that is not complete – without
     * fixtures, with a fixture not fixed yet or not entered completely – or
     * null; null as well if the fixture itself is already complete (R30.20).
     * A cancelled matchday does not count (R8.20).
     */
    public function blockingMatchdayNumber(int $fixtureId): ?int
    {
        $row = $this->findOne(
            "SELECT MIN(earlier.matchday_number) AS number
             FROM fixture fx
             JOIN matchday md ON md.id = fx.matchday_id
             JOIN matchday earlier ON earlier.season_id = md.season_id
                                  AND earlier.matchday_number < md.matchday_number
                                  AND earlier.is_cancelled = 0
             WHERE fx.id = :id AND fx.completion_status <> 'complete'
               AND (NOT EXISTS (SELECT 1 FROM fixture f WHERE f.matchday_id = earlier.id)
                    OR EXISTS (SELECT 1 FROM fixture f
                               WHERE f.matchday_id = earlier.id
                                 AND (f.completion_status <> 'complete'
                                      OR f.home_team_season_id IS NULL
                                      OR f.away_team_season_id IS NULL)))",
            ['id' => $fixtureId]
        );

        return $row === null || $row['number'] === null ? null : (int) $row['number'];
    }

    /** Tells whether the matchday is cancelled (R8.20). */
    public function isMatchdayCancelled(int $matchdayId): bool
    {
        return $this->findOne('SELECT 1 FROM matchday WHERE id = :id AND is_cancelled = 1', ['id' => $matchdayId]) !== null;
    }

    /**
     * Deletes a fixture (R4).
     */
    public function delete(int $id): void
    {
        $this->transactional('delete_fixture', function () use ($id): void {
            $this->perform('DELETE FROM fixture WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * Returns a fixture or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->findOne(
            'SELECT id, matchday_id, home_team_season_id, away_team_season_id, lane_pair_id, completion_status
             FROM fixture WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Returns all fixtures of a matchday (R4.5).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForMatchday(int $matchdayId): array
    {
        return $this->findAll(
            'SELECT id, matchday_id, home_team_season_id, away_team_season_id, lane_pair_id, completion_status
             FROM fixture WHERE matchday_id = :matchday_id ORDER BY id',
            ['matchday_id' => $matchdayId]
        );
    }
}
