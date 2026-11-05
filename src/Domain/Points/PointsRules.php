<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Points;

/**
 * The points rules of a season: game points per single game and the bonus
 * points scale (R17.1, R17.11). Defaults 2/1/0 and 2100/100/6.
 *
 * Value object: immutable, without side effects.
 */
final class PointsRules
{
    public function __construct(
        public readonly BasePointsConfiguration $base = new BasePointsConfiguration(),
        public readonly BonusPointsScale $bonus = new BonusPointsScale(),
    ) {
    }

    public static function standard(): self
    {
        return new self();
    }
}
