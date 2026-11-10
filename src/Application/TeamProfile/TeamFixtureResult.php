<?php

declare(strict_types=1);

namespace Bowlingliga\Application\TeamProfile;

/**
 * The result of one team in one fixture for the team page (R41.6): opponent,
 * outcome of the three games, points and pins.
 *
 * Pins and games include blind games like the table (R27.12); the "played"
 * values leave them out (R41.5).
 *
 * Pure, immutable read model.
 */
final class TeamFixtureResult
{
    public const WIN  = 'win';
    public const DRAW = 'draw';
    public const LOSS = 'loss';

    /**
     * @param int|null     $opponentTeamSeasonId null for the ghost team, which has no page
     * @param list<string> $outcomes             per game {@see WIN}, {@see DRAW} or {@see LOSS}
     * @param list<int>    $teamGross            team gross per game (R17.2)
     */
    public function __construct(
        public readonly ?int $opponentTeamSeasonId,
        public readonly string $opponentName,
        public readonly array $outcomes,
        public readonly array $teamGross,
        public readonly int $basePoints,
        public readonly int $bonusPoints,
        public readonly int $opponentPoints,
        public readonly int $netPins,
        public readonly int $grossPins,
        public readonly int $games,
        public readonly int $playedNetPins,
        public readonly int $playedGames,
    ) {
    }

    public function totalPoints(): int
    {
        return $this->basePoints + $this->bonusPoints;
    }

    public function count(string $outcome): int
    {
        return count(array_filter($this->outcomes, static fn (string $o): bool => $o === $outcome));
    }

    /** Best team game of the evening (gross). */
    public function bestGame(): int
    {
        return $this->teamGross === [] ? 0 : max($this->teamGross);
    }

    /** Net average per game of the evening, blind games included. */
    public function eveningAverage(): ?float
    {
        return $this->games > 0 ? $this->netPins / $this->games : null;
    }
}
