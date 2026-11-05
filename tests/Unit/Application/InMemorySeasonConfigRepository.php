<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\SeasonConfigStore;
use Bowlingliga\Domain\Points\PointsRules;

/**
 * In-memory fake of the season config persistence port for the use case
 * tests, without a database. Reproduces the upsert per `season_id`.
 */
final class InMemorySeasonConfigRepository implements SeasonConfigStore
{
    /** @var array<int, array<string, mixed>> */
    private array $config = [];

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
        $this->config[$seasonId] = [
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
        ];
    }

    public function delete(int $seasonId): void
    {
        unset($this->config[$seasonId]);
    }

    public function find(int $seasonId): ?array
    {
        return $this->config[$seasonId] ?? null;
    }
}
