<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Domain\Points\PointsRules;

/**
 * Port for persisting the season configuration (R8.11–8.17, R12.1–12.5).
 *
 * Decouples {@see SeasonAdministration} from the concrete persistence
 * technology. The port is implemented by the
 * {@see \Bowlingliga\Persistence\SeasonConfigRepository}; in tests a slim
 * in-memory fake is enough.
 */
interface SeasonConfigStore
{
    /**
     * Creates or updates the configuration of a season (upsert via
     * `season_id`).
     *
     * @param numeric-string $entryFee Amount of money with two decimals (>= 0.00).
     * @param numeric-string $blindFee Amount of money with two decimals (>= 0.00).
     * @param int            $ghostScore Pins per game of the ghost team.
     * @param int            $previousSeasonMatchdays Last matchday on which the previous season counts.
     * @param int            $positiondayInterval Every n-th matchday is a positionday, 0 = none.
     * @param PointsRules|null $points Game points and bonus scale; null = defaults (R17.11).
     */
    public function save(
        int $seasonId,
        int $laneCount,
        string $entryFee,
        string $blindFee,
        int $maxHandicapValue,
        ?string $handicapFormula,
        int $targetScore,
        int $ghostScore = SeasonConfiguration::DEFAULT_GHOST_SCORE,
        int $previousSeasonMatchdays = SeasonConfiguration::DEFAULT_PREVIOUS_SEASON_MATCHDAYS,
        int $positiondayInterval = SeasonConfiguration::DEFAULT_POSITIONDAY_INTERVAL,
        ?PointsRules $points = null,
    ): void;

    /**
     * Removes the configuration of a season (R8.9).
     */
    public function delete(int $seasonId): void;

    /**
     * Returns the configuration of a season or null (R4.5).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $seasonId): ?array;
}
