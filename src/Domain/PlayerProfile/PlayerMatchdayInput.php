<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\PlayerProfile;

use Bowlingliga\Domain\Average\Game;

/**
 * Raw data of one played matchday of a season for the history of a player
 * (R39.3): the matchday, the player's games of this matchday (empty if they
 * did not play) and the handicap frozen for them on this matchday.
 */
final class PlayerMatchdayInput
{
    /** @var list<Game> */
    public readonly array $games;

    /**
     * @param int        $matchdayNumber Matchday counter within the season (R11.3).
     * @param string     $date           Date (Y-m-d).
     * @param list<Game> $games          The player's games of this matchday.
     * @param int|null   $handicap       Frozen handicap of this matchday (R12.12), null without one.
     */
    public function __construct(
        public readonly int $matchdayId,
        public readonly int $matchdayNumber,
        public readonly string $date,
        array $games = [],
        public readonly ?int $handicap = null,
    ) {
        $this->games = array_values($games);
    }
}
