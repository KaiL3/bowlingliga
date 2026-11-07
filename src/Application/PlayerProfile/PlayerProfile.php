<?php

declare(strict_types=1);

namespace Bowlingliga\Application\PlayerProfile;

use Bowlingliga\Domain\PlayerProfile\PlayerHistory;

/**
 * Content of the player page (R39): the player, their seasons with team and
 * the history of the selected season.
 */
final class PlayerProfile
{
    /**
     * @param list<PlayerSeasonSummary> $seasons          newest first
     * @param int|null                  $selectedSeasonId null if the player has no season yet
     * @param int|null                  $previousSeasonUntil Last matchday counter whose average includes the previous season
     *                                                    in the selected season (R11.3), null if none counts
     */
    public function __construct(
        public readonly int $playerId,
        public readonly string $name,
        public readonly string $gender,
        public readonly array $seasons,
        public readonly ?int $selectedSeasonId,
        public readonly PlayerHistory $history,
        public readonly ?int $previousSeasonUntil = null,
    ) {
    }

    /** The row of the selected season, null without one. */
    public function selectedSeason(): ?PlayerSeasonSummary
    {
        foreach ($this->seasons as $season) {
            if ($season->seasonId === $this->selectedSeasonId) {
                return $season;
            }
        }

        return null;
    }
}
