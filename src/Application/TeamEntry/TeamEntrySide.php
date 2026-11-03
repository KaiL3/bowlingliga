<?php

declare(strict_types=1);

namespace Bowlingliga\Application\TeamEntry;

/**
 * State of one side (home or away) in the result entry by the teams (R37).
 *
 * `submitted` = this side has sent its own lineup and results, `confirmed` =
 * this side has confirmed the opponent's sent entry, `rejectionNote` = this
 * side has sent the opponent's entry back with this reason.
 */
final class TeamEntrySide
{
    public function __construct(
        public readonly string $side,
        public readonly int $codeVersion = 1,
        public readonly bool $submitted = false,
        public readonly bool $confirmed = false,
        public readonly ?string $rejectionNote = null,
        public readonly bool $closed = false,
    ) {
    }

    public static function opposite(string $side): string
    {
        return $side === 'home' ? 'away' : 'home';
    }
}
