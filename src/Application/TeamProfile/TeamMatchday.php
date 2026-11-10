<?php

declare(strict_types=1);

namespace Bowlingliga\Application\TeamProfile;

/**
 * One matchday on the team page (R41.6): the team's result and the standing
 * after it, or a cancelled matchday, or a played one without a fixture of
 * the team.
 *
 * Pure, immutable read model.
 */
final class TeamMatchday
{
    /**
     * @param TeamFixtureResult|null $result           null if cancelled or the team had no fixture
     * @param int|null               $rank             rank in the league table after this matchday (R16.5)
     * @param int|null               $points           the team's points after this matchday
     * @param int|null               $leaderPoints     points of the leader after this matchday
     * @param float|null             $averageNet       team net average after this matchday, blind games included (R16.1)
     * @param float|null             $playedAverageNet the same without blind games (R41.5)
     */
    public function __construct(
        public readonly int $matchdayId,
        public readonly int $matchdayNumber,
        public readonly string $date,
        public readonly bool $cancelled,
        public readonly ?TeamFixtureResult $result = null,
        public readonly ?int $rank = null,
        public readonly ?int $points = null,
        public readonly ?int $leaderPoints = null,
        public readonly ?float $averageNet = null,
        public readonly ?float $playedAverageNet = null,
    ) {
    }

    /** Whether the league played this matchday (cancelled ones are not played). */
    public function isPlayed(): bool
    {
        return ! $this->cancelled;
    }
}
