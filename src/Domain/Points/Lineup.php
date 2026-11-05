<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Points;

use InvalidArgumentException;

/**
 * A team's lineup for an evening, reduced to the gross results relevant for
 * the points calculation.
 *
 * R17.2: the team gross of game k is the sum of the gross results
 * of all 4 positions of the lineup, including any blind player positions
 * (R27) or ghost team positions (R20).
 * R20.7, R21.7: the ghost team also has exactly 4 positions.
 * R21.6, R27.3: if a real team fields no real player at all, the
 * evening counts as lost and the team gets 0 points; this special case is
 * represented via {@see hasRealPlayer()}.
 *
 * Value object: immutable, without side effects.
 */
final class Lineup
{
    private const POSITION_COUNT = 4;
    private const GAME_COUNT = 3;

    /**
     * @var array<int, array<int, int>> $positions Gross results per position
     *      (4 positions) and game (3 games, index 0..2).
     */
    private readonly array $positions;

    /**
     * @param array<int, array<int, int>> $positions Exactly 4 positions with
     *        exactly 3 gross values each for games 1–3 (index 0..2).
     * @param bool $hasRealPlayer Whether the lineup contains at least one real
     *        player (R21.6, R27.3). Always true for the ghost team
     *        and for regularly lined-up real teams.
     *
     * @throws InvalidArgumentException if not exactly 4 positions with exactly
     *         3 gross values each are passed.
     */
    public function __construct(array $positions, private readonly bool $hasRealPlayer = true)
    {
        if (count($positions) !== self::POSITION_COUNT) {
            throw new InvalidArgumentException(
                'A lineup must have exactly ' . self::POSITION_COUNT . ' Positionen umfassen.'
            );
        }

        $normalised = [];
        $positionIndex = 0;
        foreach ($positions as $games) {
            if (!is_array($games) || count($games) !== self::GAME_COUNT) {
                throw new InvalidArgumentException(
                    'Every position must have exactly ' . self::GAME_COUNT . ' gross values (single games 1–3).'
                );
            }

            $gameIndex = 0;
            foreach ($games as $gross) {
                if (!is_int($gross)) {
                    throw new InvalidArgumentException('Gross values must be whole numbers.');
                }
                $normalised[$positionIndex][$gameIndex] = $gross;
                $gameIndex++;
            }
            $positionIndex++;
        }

        $this->positions = $normalised;
    }

    /**
     * R21.6, R27.3: tells whether at least one real player takes part.
     */
    public function hasRealPlayer(): bool
    {
        return $this->hasRealPlayer;
    }

    /**
     * Gross value of a position in a game.
     *
     * @param int $position Position 1–4.
     * @param int $game     Game 1–3.
     *
     * @throws InvalidArgumentException for invalid indices.
     */
    public function grossOfPosition(int $position, int $game): int
    {
        if ($position < 1 || $position > self::POSITION_COUNT) {
            throw new InvalidArgumentException('The position must be between 1 and ' . self::POSITION_COUNT . '.');
        }
        if ($game < 1 || $game > self::GAME_COUNT) {
            throw new InvalidArgumentException('The single game must be between 1 and ' . self::GAME_COUNT . '.');
        }

        return $this->positions[$position - 1][$game - 1];
    }

    public function positionCount(): int
    {
        return self::POSITION_COUNT;
    }

    public function gameCount(): int
    {
        return self::GAME_COUNT;
    }
}
