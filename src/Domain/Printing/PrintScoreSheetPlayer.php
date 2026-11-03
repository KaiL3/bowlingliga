<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Printing;

/**
 * A roster player on the printed score sheet (R19.2, R19.3, R19.6).
 *
 * Carries the name and – as of the time of generation – the net average and
 * the handicap. Both are nullable: if the player has no average or no
 * handicap, the field concerned is printed empty on the score sheet (R19.6).
 *
 * Pure, immutable value object.
 */
final class PrintScoreSheetPlayer
{
    /**
     * @param string     $name          Name of the player (R19.2)
     * @param float|null $averageNet    Net average (R19.3) or null (R19.6)
     * @param int|null   $handicap      Handicap/HDC (R19.3) or null (R19.6)
     * @param int        $numberOfGames Number of games played before the start
     *                                  of this matchday (column "G"); 0 if the
     *                                  player has no games before.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?float $averageNet,
        public readonly ?int $handicap,
        public readonly int $numberOfGames = 0,
    ) {
    }
}
