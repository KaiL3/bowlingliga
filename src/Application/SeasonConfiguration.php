<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Domain\Points\BasePointsConfiguration;
use Bowlingliga\Domain\Points\BonusPointsScale;
use Bowlingliga\Domain\Points\PointsRules;
use Bowlingliga\Security\Validator;

/**
 * Validated value object of the season configuration (R8.11–8.14,
 * R12.1–12.3).
 *
 * Bundles the configurable raw data of a season and checks it server-side on
 * creation:
 * - Lane count as an even integer ≥ 2 (R8.11/R8.12).
 * - Entry fee and blind fee as decimal values ≥ 0.00 (R8.13/R8.14),
 *   normalised to two decimals (`numeric-string` for DECIMAL(8,2)).
 * - Maximum handicap as an integer 0–300 (R12.3), default 64.
 * - Target score as an integer 0–300 (R12.2), default 200.
 * - Handicap formula as an optional identifier (R12.1).
 * - Pins per game of the ghost team as an integer 0–300, default 190.
 * - Matchday up to which (inclusive) the previous season counts towards the
 *   average, 0–99 (0 = never), default 5.
 * - Interval of the positiondays in matchdays, 0–99 (0 = none), default 10.
 * - Points rules: game points for win/draw/loss 0–10 with win ≥ draw ≥ loss,
 *   default 2/1/0, and the bonus points scale, default 2100/100/6 (R17.1,
 *   R17.11).
 *
 * The object is immutable; invalid values already lead to a
 * {@see \Bowlingliga\Security\ValidationException} on creation.
 */
final class SeasonConfiguration
{
    /** Default target score according to R12.2. */
    public const DEFAULT_TARGET_SCORE = 200;

    /** Default maximum handicap according to R12.3/R12.5. */
    public const DEFAULT_MAX_HANDICAP = 64;

    /** Default pins per game of the ghost team. */
    public const DEFAULT_GHOST_SCORE = 190;

    /** Default last matchday on which the previous season counts towards the average. */
    public const DEFAULT_PREVIOUS_SEASON_MATCHDAYS = 5;

    /** Default interval of the positiondays: every tenth matchday (R9.13). */
    public const DEFAULT_POSITIONDAY_INTERVAL = 10;

    /** Upper bound for the matchday settings (previous season, positionday interval). */
    public const PREVIOUS_SEASON_MATCHDAYS_MAX = 99;

    /** Upper bound for the game points of a single game. */
    public const GAME_POINTS_MAX = 10;

    /**
     * @param int            $laneCount               Even integer ≥ 2 (R8.11).
     * @param numeric-string $entryFee                Amount ≥ 0.00, two decimals (R8.13).
     * @param numeric-string $blindFee                Amount ≥ 0.00, two decimals (R8.13).
     * @param int            $maxHandicapValue        Integer 0–300 (R12.3).
     * @param string|null    $handicapFormula         Formula identifier or null (R12.1).
     * @param int            $targetScore             Integer 0–300 (R12.2).
     * @param int            $ghostScore              Pins per game of the ghost team, 0–300.
     * @param int            $previousSeasonMatchdays Last matchday with previous season, 0–99.
     * @param int            $positiondayInterval     Every n-th matchday is a positionday, 0 = none.
     * @param PointsRules    $points                  Game points and bonus scale (R17.11).
     */
    private function __construct(
        public readonly int $laneCount,
        public readonly string $entryFee,
        public readonly string $blindFee,
        public readonly int $maxHandicapValue,
        public readonly ?string $handicapFormula,
        public readonly int $targetScore,
        public readonly int $ghostScore,
        public readonly int $previousSeasonMatchdays,
        public readonly int $positiondayInterval,
        public readonly PointsRules $points,
    ) {
    }

    /**
     * Creates a validated season configuration.
     *
     * @throws \Bowlingliga\Security\ValidationException for an invalid lane
     *         count (R8.12), an invalid amount of money (R8.14), a maximum
     *         handicap/target score/ghost score outside 0–300 (R12.2/R12.3) or
     *         a previous-season matchday or positionday interval outside 0–99.
     */
    public static function generate(
        Validator $validator,
        int $laneCount,
        float $entryFee,
        float $blindFee,
        int $maxHandicapValue = self::DEFAULT_MAX_HANDICAP,
        ?string $handicapFormula = null,
        int $targetScore = self::DEFAULT_TARGET_SCORE,
        int $ghostScore = self::DEFAULT_GHOST_SCORE,
        int $previousSeasonMatchdays = self::DEFAULT_PREVIOUS_SEASON_MATCHDAYS,
        int $positiondayInterval = self::DEFAULT_POSITIONDAY_INTERVAL,
        ?PointsRules $points = null,
    ): self {
        $validator->checkLaneCount($laneCount);
        $entryFeeChecked = $validator->checkMoneyAmount($entryFee, 'field.entry_fee');
        $feeChecked   = $validator->checkMoneyAmount($blindFee, 'field.blind_fee');
        $validator->checkRange0To300($maxHandicapValue, 'field.max_handicap');
        $validator->checkRange0To300($targetScore, 'season.target_score');
        $validator->checkRange0To300($ghostScore, 'field.ghost_pins');
        $validator->checkRange($previousSeasonMatchdays, 0, self::PREVIOUS_SEASON_MATCHDAYS_MAX, 'field.previous_matchdays');
        $validator->checkRange($positiondayInterval, 0, self::PREVIOUS_SEASON_MATCHDAYS_MAX, 'field.positionday_interval');

        return new self(
            $laneCount,
            self::asMoneyString($entryFeeChecked),
            self::asMoneyString($feeChecked),
            $maxHandicapValue,
            $handicapFormula,
            $targetScore,
            $ghostScore,
            $previousSeasonMatchdays,
            $positiondayInterval,
            $points ?? PointsRules::standard(),
        );
    }

    /**
     * Points rules from a stored configuration row (`season_config`); missing
     * columns fall back to the defaults (R17.1, R17.11).
     *
     * @param array<string, mixed>|null $row
     */
    public static function pointsRulesFromRow(?array $row): PointsRules
    {
        $value = static fn (string $column, int $default): int => isset($row[$column]) ? (int) $row[$column] : $default;

        return new PointsRules(
            new BasePointsConfiguration($value('win_points', 2), $value('draw_points', 1), $value('loss_points', 0)),
            new BonusPointsScale(
                $value('bonus_threshold', BonusPointsScale::DEFAULT_THRESHOLD),
                $value('bonus_step', BonusPointsScale::DEFAULT_STEP),
                $value('bonus_maximum', BonusPointsScale::DEFAULT_MAXIMUM),
            ),
        );
    }

    /**
     * Validated points rules from form values (R17.1, R17.11): game points
     * 0–10 with win ≥ draw ≥ loss, bonus threshold 0–9999, pins per further
     * bonus point 1–1000, bonus maximum 0–20.
     *
     * @throws \Bowlingliga\Security\ValidationException for a value outside its range
     *         or game points in the wrong order.
     */
    public static function pointsRules(
        Validator $validator,
        int $win,
        int $draw,
        int $loss,
        int $bonusThreshold,
        int $bonusStep,
        int $bonusMaximum,
    ): PointsRules {
        $validator->checkRange($win, 0, self::GAME_POINTS_MAX, 'field.win_points');
        $validator->checkRange($draw, 0, $win, 'field.draw_points');
        $validator->checkRange($loss, 0, $draw, 'field.loss_points');
        $validator->checkRange($bonusThreshold, 0, BonusPointsScale::THRESHOLD_MAX, 'field.bonus_threshold');
        $validator->checkRange($bonusStep, 1, BonusPointsScale::STEP_MAX, 'field.bonus_step');
        $validator->checkRange($bonusMaximum, 0, BonusPointsScale::MAXIMUM_MAX, 'field.bonus_maximum');

        return new PointsRules(
            new BasePointsConfiguration($win, $draw, $loss),
            new BonusPointsScale($bonusThreshold, $bonusStep, $bonusMaximum),
        );
    }

    /**
     * Formats an amount of money as `numeric-string` with exactly two decimals
     * (suitable for DECIMAL(8,2)).
     */
    private static function asMoneyString(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
