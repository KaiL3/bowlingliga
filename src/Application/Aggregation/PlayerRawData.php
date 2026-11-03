<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Aggregation;

use Bowlingliga\Domain\Honours\Gender;

/**
 * The raw data collected per player for the average list aggregation (R2.1,
 * R2.7, R2.8).
 *
 * Bundles a player's master data (id, name, gender), the games entered up to
 * and including the matchday in question in game order, and the frozen
 * handicap assigned to the matchday (or null for "without handicap", R2.15).
 * Filtering the includable games is done by the {@see AverageListAggregator}.
 *
 * Pure, immutable value object.
 */
final class PlayerRawData
{
    /**
     * @param string            $playerId       Player id
     * @param string            $playerName     Display name
     * @param Gender            $gender         Gender (R2.7)
     * @param list<GameRawData> $games          entered games in game order
     * @param int|null          $frozenHandicap frozen HDC of the matchday (R2.8) or null (R2.15)
     */
    public function __construct(
        public readonly string $playerId,
        public readonly string $playerName,
        public readonly Gender $gender,
        public readonly array $games,
        public readonly ?int $frozenHandicap,
    ) {
    }
}
