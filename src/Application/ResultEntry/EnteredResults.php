<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * The net results entered for a fixture (R22.1, R30.5).
 *
 * Bundles the game results of both teams of a fixture entered in the virtual
 * score sheet as input for the use case {@see ResultRecording}. The structure
 * is a pure value holder; completeness, range and lineup checks are done by
 * the use case.
 *
 * Complete means: in each of the 3 games exactly 4 results per team – real
 * games of the players on the lanes plus blind games (R22.1, R30.4).
 */
final class EnteredResults
{
    /**
     * @param int               $fixtureId    Fixture id
     * @param int               $matchdayId   Matchday id
     * @param list<EnteredGame> $games        entered games of both teams
     * @param list<int>         $ghostTeamIds team season ids of the ghost team
     *        (fixed lineup, not subject to the lineup rules, R20.6)
     * @param array<int, string> $teamNames   team season id → name, for messages
     * @param array<int, string> $playerNames player id → name, for messages
     */
    public function __construct(
        public readonly int $fixtureId,
        public readonly int $matchdayId,
        public readonly array $games,
        public readonly array $ghostTeamIds = [],
        public readonly array $teamNames = [],
        public readonly array $playerNames = [],
    ) {
    }

    /**
     * Number of entered games.
     */
    public function quantity(): int
    {
        return count($this->games);
    }

    /**
     * Tells whether any games were entered at all.
     */
    public function isEmpty(): bool
    {
        return $this->games === [];
    }

    /**
     * The entered games grouped by team season id, in input order.
     *
     * @return array<int, list<EnteredGame>>
     */
    public function gamesByTeam(): array
    {
        $teams = [];
        foreach ($this->games as $game) {
            $teams[$game->teamSeasonId][] = $game;
        }

        return $teams;
    }
}
