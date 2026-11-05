<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Points;

use InvalidArgumentException;

/**
 * Pure calculation service for team gross, base, bonus and total points of an
 * evening (R17, R20.3, R20.7, R21.6,
 * R27.3, R27.12).
 *
 * All methods are side-effect-free and deterministic: they calculate solely
 * from the inputs passed and change no state. The logic applies uniformly to
 * real teams, lineups filled with blind players (R27) and the ghost
 * team (R20), since all of them are represented by a
 * {@see Lineup} with exactly 4 positions.
 */
final class PointsService
{
    private const GAME_COUNT = 3;

    /** Gross threshold up to which exactly 1 bonus point is awarded (R17.6). */
    private const BONUS_POINTS_LOWER_THRESHOLD = 2100;

    /** Gross threshold from which 2 bonus points plus the scale apply (R17.7). */
    private const BONUS_POINTS_SCALE_START = 2101;

    /** Gross threshold from which the cap of 6 bonus points applies (R17.8). */
    private const BONUS_POINTS_CAP_THRESHOLD = 2501;

    /** Maximum number of bonus points (R17.8). */
    private const BONUS_POINTS_MAXIMUM = 6;

    /**
     * Team gross of a game: sum of the gross results of all 4 positions
     * (including blind player or ghost team positions).
     *
     * R17.2: team gross of game k = sum of the gross results of all
     * 4 positions of the lineup in this game.
     * R20.7, R27.12: ghost team and blind player positions count
     * equally towards this sum.
     *
     * @param int $game Game 1–3.
     *
     * @throws InvalidArgumentException for an invalid game index.
     */
    public function teamGrossGame(Lineup $lineup, int $game): int
    {
        if ($game < 1 || $game > self::GAME_COUNT) {
            throw new InvalidArgumentException('The single game must be between 1 and ' . self::GAME_COUNT . '.');
        }

        $sum = 0;
        for ($position = 1; $position <= $lineup->positionCount(); $position++) {
            $sum += $lineup->grossOfPosition($position, $game);
        }

        return $sum;
    }

    /**
     * Gross total of the evening: sum of the team gross values of the 3 games.
     *
     * R17.5: gross total = sum of the team gross values of the 3
     * games formed according to R17.2.
     */
    public function grossTotal(Lineup $lineup): int
    {
        $sum = 0;
        for ($game = 1; $game <= self::GAME_COUNT; $game++) {
            $sum += $this->teamGrossGame($lineup, $game);
        }

        return $sum;
    }

    /**
     * A team's base points for a matchday from comparing the team gross values
     * per game against the opposing team.
     *
     * R17.3: for each of the 3 games the own team gross is compared
     * with the opponent's team gross and win, draw or loss points are awarded.
     * R17.4: winning all 3 games yields the maximum of
     * win value × 3.
     *
     * @param Lineup                       $own      Lineup of the team to score.
     * @param Lineup                       $opponent Lineup of the opposing team.
     * @param BasePointsConfiguration|null $config   Point values; default 2/1/0 (R17.1).
     */
    public function basePoints(
        Lineup $own,
        Lineup $opponent,
        ?BasePointsConfiguration $config = null
    ): int {
        $config ??= BasePointsConfiguration::standard();

        $sum = 0;
        for ($game = 1; $game <= self::GAME_COUNT; $game++) {
            $ownGross = $this->teamGrossGame($own, $game);
            $opponentGross = $this->teamGrossGame($opponent, $game);

            if ($ownGross > $opponentGross) {
                $sum += $config->win;
            } elseif ($ownGross === $opponentGross) {
                $sum += $config->draw;
            } else {
                $sum += $config->loss;
            }
        }

        return $sum;
    }

    /**
     * Highest possible base points of a matchday = win value × 3 (R17.4).
     */
    public function maximumBasePoints(?BasePointsConfiguration $config = null): int
    {
        $config ??= BasePointsConfiguration::standard();

        return $config->win * self::GAME_COUNT;
    }

    /**
     * Bonus points according to the scale rule of the gross total.
     *
     * R17.6: gross total ≤ 2100 → exactly 1 bonus point.
     * R17.7: from 2101 → 2 bonus points, then one more bonus point
     * for every further full 100 pins.
     * R17.8: from 2501 → at most 6 bonus points (cap).
     *
     * @param int $grossTotal Gross total of the evening (R17.5).
     *
     * @throws InvalidArgumentException for a negative gross total.
     */
    public function bonusPoints(int $grossTotal): int
    {
        if ($grossTotal < 0) {
            throw new InvalidArgumentException('The gross total must not be negative.');
        }

        if ($grossTotal <= self::BONUS_POINTS_LOWER_THRESHOLD) {
            return 1;
        }

        if ($grossTotal >= self::BONUS_POINTS_CAP_THRESHOLD) {
            return self::BONUS_POINTS_MAXIMUM;
        }

        // From 2101 pins: 2 bonus points plus one more for every full 100 pins
        // above the scale start threshold (R17.7).
        $further = intdiv($grossTotal - self::BONUS_POINTS_SCALE_START, 100);
        $points = 2 + $further;

        return min($points, self::BONUS_POINTS_MAXIMUM);
    }

    /**
     * A team's total points for a matchday = base points + bonus points.
     *
     * R17.9: points = sum of the base points of all 3 games and the
     * bonus points; maximum = (win value × 3) + 6.
     * R21.6, R27.3: if a real team fields no real player at all, the
     * evening counts as lost and 0 points are awarded.
     * R20.3, R20.7: the same rules apply to the real team against
     * the ghost team and to the ghost team itself.
     *
     * @param Lineup                       $own      Lineup of the team to score.
     * @param Lineup                       $opponent Lineup of the opposing team.
     * @param BasePointsConfiguration|null $config   Point values; default 2/1/0 (R17.1).
     */
    public function totalPoints(
        Lineup $own,
        Lineup $opponent,
        ?BasePointsConfiguration $config = null
    ): int {
        // R21.6, R27.3: without a real player 0 points (evening lost).
        if (!$own->hasRealPlayer()) {
            return 0;
        }

        $config ??= BasePointsConfiguration::standard();

        return $this->basePoints($own, $opponent, $config)
            + $this->bonusPoints($this->grossTotal($own));
    }

    /**
     * Highest possible total points of a matchday = (win value × 3) + 6 (R17.9).
     */
    public function maximumTotalPoints(?BasePointsConfiguration $config = null): int
    {
        return $this->maximumBasePoints($config) + self::BONUS_POINTS_MAXIMUM;
    }
}
