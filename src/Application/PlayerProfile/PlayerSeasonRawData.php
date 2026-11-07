<?php

declare(strict_types=1);

namespace Bowlingliga\Application\PlayerProfile;

use Bowlingliga\Domain\Average\AverageModule;
use Bowlingliga\Domain\Average\Game;
use Bowlingliga\Domain\Average\PreviousSeasonRecord;
use Bowlingliga\Domain\PlayerProfile\PlayerMatchdayInput;

/**
 * Raw data of a player in one season for the {@see \Bowlingliga\Domain\PlayerProfile\PlayerHistoryService}.
 */
final class PlayerSeasonRawData
{
    /**
     * @param list<PlayerMatchdayInput> $matchdays               Played matchdays of the season (with a complete fixture).
     * @param list<Game>                $previousSeasonGames     Games of the preceding season, only without a record (R29.2).
     * @param PreviousSeasonRecord|null $record                  The season's previous season record of the player (R29).
     * @param int                       $previousSeasonMatchdays Season setting "previous season in the average up to matchday" (R11.3).
     */
    public function __construct(
        public readonly array $matchdays = [],
        public readonly array $previousSeasonGames = [],
        public readonly ?PreviousSeasonRecord $record = null,
        public readonly int $previousSeasonMatchdays = AverageModule::PREVIOUS_SEASON_THRESHOLD,
    ) {
    }
}
