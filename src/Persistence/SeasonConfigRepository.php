<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Domain\Points\PointsRules;

/**
 * Raw data repository for the season configuration (table `season_config`,
 * R8, R12.1–5).
 *
 * Keeps the configurable raw data of a season: lane count, entry fee, blind
 * fee, maximum handicap, handicap formula, the global target score and the
 * points rules (game points, bonus scale, R17.11). There
 * is a 1:1 relationship with the season (primary key `season_id`). Entry fee
 * and blind fee are stored as DECIMAL(8,2) and passed as string here, to keep
 * the exact decimal representation. Write operations transactional (R4.3),
 * prepared statements (R4.1), reads unchanged (R4.5).
 */
final class SeasonConfigRepository extends AbstractRepository implements \Bowlingliga\Application\SeasonConfigStore
{
    /**
     * Creates or updates the configuration of a season (upsert via the primary
     * key `season_id`).
     *
     * @param numeric-string $entryFee Amount of money with two decimals (>= 0.00).
     * @param numeric-string $blindFee Amount of money with two decimals (>= 0.00).
     */
    public function save(
        int $seasonId,
        int $laneCount,
        string $entryFee,
        string $blindFee,
        int $maxHandicapValue,
        ?string $handicapFormula,
        int $targetScore,
        int $ghostScore = \Bowlingliga\Application\SeasonConfiguration::DEFAULT_GHOST_SCORE,
        int $previousSeasonMatchdays = \Bowlingliga\Application\SeasonConfiguration::DEFAULT_PREVIOUS_SEASON_MATCHDAYS,
        int $positiondayInterval = \Bowlingliga\Application\SeasonConfiguration::DEFAULT_POSITIONDAY_INTERVAL,
        ?PointsRules $points = null,
    ): void {
        $points ??= PointsRules::standard();
        $this->transactional('save_season_config', function () use (
            $seasonId,
            $laneCount,
            $entryFee,
            $blindFee,
            $maxHandicapValue,
            $handicapFormula,
            $targetScore,
            $ghostScore,
            $previousSeasonMatchdays,
            $positiondayInterval,
            $points
        ): void {
            $this->perform(
                'INSERT INTO season_config
                    (season_id, lane_count, entry_fee, blind_fee, max_handicap, handicap_formula, target_score,
                     ghost_score, previous_season_matchdays, positionday_interval,
                     win_points, draw_points, loss_points, bonus_threshold, bonus_step, bonus_maximum)
                 VALUES
                    (:season_id, :lane_count, :entry_fee, :blind_fee, :max_handicap, :handicap_formula, :target_score,
                     :ghost_score, :previous_season_matchdays, :positionday_interval,
                     :win_points, :draw_points, :loss_points, :bonus_threshold, :bonus_step, :bonus_maximum)
                 ON DUPLICATE KEY UPDATE
                    lane_count = VALUES(lane_count),
                    entry_fee = VALUES(entry_fee),
                    blind_fee = VALUES(blind_fee),
                    max_handicap = VALUES(max_handicap),
                    handicap_formula = VALUES(handicap_formula),
                    target_score = VALUES(target_score),
                    ghost_score = VALUES(ghost_score),
                    previous_season_matchdays = VALUES(previous_season_matchdays),
                    positionday_interval = VALUES(positionday_interval),
                    win_points = VALUES(win_points),
                    draw_points = VALUES(draw_points),
                    loss_points = VALUES(loss_points),
                    bonus_threshold = VALUES(bonus_threshold),
                    bonus_step = VALUES(bonus_step),
                    bonus_maximum = VALUES(bonus_maximum)',
                [
                    'season_id'            => $seasonId,
                    'lane_count'           => $laneCount,
                    'entry_fee'            => $entryFee,
                    'blind_fee' => $blindFee,
                    'max_handicap'     => $maxHandicapValue,
                    'handicap_formula'      => $handicapFormula,
                    'target_score'             => $targetScore,
                    'ghost_score'              => $ghostScore,
                    'previous_season_matchdays' => $previousSeasonMatchdays,
                    'positionday_interval'     => $positiondayInterval,
                    'win_points'               => $points->base->win,
                    'draw_points'              => $points->base->draw,
                    'loss_points'              => $points->base->loss,
                    'bonus_threshold'          => $points->bonus->threshold,
                    'bonus_step'               => $points->bonus->step,
                    'bonus_maximum'            => $points->bonus->maximum,
                ]
            );
        });
    }

    /**
     * Removes the configuration of a season (R4).
     */
    public function delete(int $seasonId): void
    {
        $this->transactional('delete_season_config', function () use ($seasonId): void {
            $this->perform('DELETE FROM season_config WHERE season_id = :season_id', ['season_id' => $seasonId]);
        });
    }

    /**
     * Returns the configuration of a season, or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $seasonId): ?array
    {
        return $this->findOne(
            'SELECT season_id, lane_count, entry_fee, blind_fee, max_handicap, handicap_formula, target_score,
                    ghost_score, previous_season_matchdays, positionday_interval,
                    win_points, draw_points, loss_points, bonus_threshold, bonus_step, bonus_maximum
             FROM season_config WHERE season_id = :season_id',
            ['season_id' => $seasonId]
        );
    }
}
