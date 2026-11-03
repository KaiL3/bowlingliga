<?php

declare(strict_types=1);

namespace Bowlingliga\Application\PublicSite;

/**
 * The next matchday not played yet, with its fixtures and lanes, for the
 * public matchday page (R14.8): players see where their team plays.
 *
 * On a positionday the fixtures only exist once they have been formed from
 * the standings; on a lot draw fixtures and lanes are drawn on site.
 */
final class UpcomingMatchday
{
    public const TYPE_REGULAR = 'regular';
    public const TYPE_POSITIONDAY = 'positionday';
    public const TYPE_LOT_DRAW = 'lot_draw';

    /**
     * @param list<array{home: string, away: string, home_lane: int|null, away_lane: int|null}> $fixtures
     *        sorted by lane; the first team starts on the first lane of the pair
     */
    public function __construct(
        public readonly int $matchdayId,
        public readonly int $number,
        public readonly string $date,
        public readonly string $type,
        public readonly array $fixtures,
    ) {
    }
}
