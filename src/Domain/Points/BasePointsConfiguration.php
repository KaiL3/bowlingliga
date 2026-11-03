<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Points;

/**
 * Configurable base point values for win, draw and loss.
 *
 * R17.1: the system SHALL store configurable base points for a
 * win, a draw and a loss, with the defaults 2 (win), 1 (draw) and 0 (loss).
 *
 * Value object: immutable, without side effects.
 */
final class BasePointsConfiguration
{
    /**
     * @param int $win  Base points for a won game comparison (default 2).
     * @param int $draw Base points for a drawn game (default 1).
     * @param int $loss Base points for a lost game (default 0).
     */
    public function __construct(
        public readonly int $win = 2,
        public readonly int $draw = 1,
        public readonly int $loss = 0,
    ) {
    }

    /**
     * Returns the default configuration 2/1/0 according to R17.1.
     */
    public static function standard(): self
    {
        return new self();
    }
}
