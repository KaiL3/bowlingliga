<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Printing;

/**
 * Header data of a printed score sheet (R19.1, R19.7, R32.3).
 *
 * Bundles date, matchday type, optional lane numbers as well as entry fee and
 * blind fee of the season. Whether lane numbers are printed depends on the
 * matchday type: for regular matchdays and positiondays the lane numbers of
 * the assigned lane pair are printed (R19.1); for a lot draw they are omitted,
 * since the lane is drawn on site (R32.3).
 *
 * Pure, immutable value object.
 */
final class PrintScoreSheetHeader
{
    public const TYPE_REGULAR = 'regular';
    public const TYPE_POSITIONDAY = 'positionday';
    public const TYPE_LOT_DRAW = 'lot_draw';

    /**
     * @param string   $date           Date of the matchday (Y-m-d, R19.1)
     * @param string   $matchdayType   Matchday type (regular/positionday/lot draw)
     * @param int|null $laneLeft       Lane number of the home team or null (lot draw, R32.3)
     * @param int|null $laneRight      Lane number of the away team or null (lot draw, R32.3)
     * @param float    $entryFee       Entry fee of the season (R19.7)
     * @param float    $blindFee       Blind fee of the season (R19.7)
     * @param int|null $matchdayNumber Number of the matchday (matchday counter) or null
     */
    public function __construct(
        public readonly string $date,
        public readonly string $matchdayType,
        public readonly ?int $laneLeft,
        public readonly ?int $laneRight,
        public readonly float $entryFee,
        public readonly float $blindFee,
        public readonly ?int $matchdayNumber = null,
    ) {
    }

    /**
     * Tells whether this is a lot draw; then no lane numbers are printed
     * (R32.3).
     */
    public function isLotDraw(): bool
    {
        return $this->matchdayType === self::TYPE_LOT_DRAW;
    }

    /**
     * Tells whether lane numbers exist and are printed (R19.1).
     */
    public function hasLaneNumbers(): bool
    {
        return $this->laneLeft !== null && $this->laneRight !== null;
    }
}
