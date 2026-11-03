<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Aggregation;

/**
 * A single entered net game as raw data for the average list aggregation
 * (R2.1, R27.13, R20.4).
 *
 * Besides the net value it carries the flags that decide whether it is
 * included in the average list: whether the game is marked blind (R27.13) and
 * whether it belongs to a ghost team (R20.4). Both are excluded by the
 * {@see AverageListAggregator}; the order of the games is kept so that the
 * best series can be formed correctly (R2.12).
 *
 * Pure, immutable value object.
 */
final class GameRawData
{
    /**
     * @param int  $net         Net result 0–300 (R2.10)
     * @param bool $isBlind     true if the game is marked blind (R27.13)
     * @param bool $isGhostTeam true if the result belongs to a ghost team (R20.4)
     */
    public function __construct(
        public readonly int $net,
        public readonly bool $isBlind,
        public readonly bool $isGhostTeam,
    ) {
    }

    /**
     * Tells whether the game is included in the average list: only games
     * actually played by the player, i.e. neither blind nor ghost team (R2.1,
     * R27.13, R20.4).
     */
    public function isIncludable(): bool
    {
        return ! $this->isBlind && ! $this->isGhostTeam;
    }
}
