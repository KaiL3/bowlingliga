<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\GhostTeam;

/**
 * Immutable value object for the fixed results of a single ghost team lineup
 * position over the 3 games of an evening (R20.1, R20.2).
 *
 * Every ghost team player scores a fixed net result equal to the target score
 * in every game (R20.1). The ghost team gets no handicap; the gross result of
 * every game therefore equals the net result (R20.2).
 *
 * This is a pure, side-effect-free result holder without any database or
 * persistence dependency.
 */
final class GhostTeamResult
{
    private const GAME_COUNT = 3;

    /**
     * @param int $targetScore The globally defined target score, which is also
     *                         the fixed net and gross result of every game of
     *                         this position (R20.1, R20.2). Allowed range 0 to
     *                         300 (like a net result).
     *
     * @throws \InvalidArgumentException If the target score is outside the range
     *                                   0 to 300.
     */
    public function __construct(public readonly int $targetScore)
    {
        if ($targetScore < 0 || $targetScore > 300) {
            throw new \InvalidArgumentException(
                'The ghost score of a ghost team result must be between 0 and 300, given: ' . $targetScore
            );
        }
    }

    /**
     * Fixed net result of a game of this position = target score (R20.1).
     *
     * @param int $game Game 1–3.
     *
     * @throws \InvalidArgumentException on an invalid game index.
     */
    public function net(int $game): int
    {
        $this->checkGame($game);

        return $this->targetScore;
    }

    /**
     * Fixed gross result of a game of this position = net, since the ghost team
     * gets no handicap (R20.2).
     *
     * @param int $game Game 1–3.
     *
     * @throws \InvalidArgumentException on an invalid game index.
     */
    public function gross(int $game): int
    {
        // R20.2: gross = net (no handicap).
        return $this->net($game);
    }

    private function checkGame(int $game): void
    {
        if ($game < 1 || $game > self::GAME_COUNT) {
            throw new \InvalidArgumentException(
                'The single game must be between 1 and ' . self::GAME_COUNT . ', given: ' . $game
            );
        }
    }
}
