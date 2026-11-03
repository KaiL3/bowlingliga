<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Import;

/**
 * One player line of an import file: team, name, gender and optionally the
 * previous season (average and number of games).
 *
 * The previous season is stored like a manual previous season record: number
 * of games and net pins total, the total derived as average × games.
 */
final class PlayerImportRow
{
    /**
     * @param int        $line            Line number in the file (1 = header).
     * @param string     $team            Team name as written in the file.
     * @param string     $name            Player name as written in the file.
     * @param string     $gender          `male` or `female`.
     * @param float|null $previousAverage Previous season average or null.
     * @param int|null   $previousGames   Previous season games or null.
     */
    public function __construct(
        public readonly int $line,
        public readonly string $team,
        public readonly string $name,
        public readonly string $gender,
        public readonly ?float $previousAverage = null,
        public readonly ?int $previousGames = null,
    ) {
    }

    public function hasPreviousSeason(): bool
    {
        return $this->previousAverage !== null && $this->previousGames !== null;
    }

    /** Net pins total of the previous season: average × games, rounded. */
    public function previousPins(): int
    {
        return (int) round((float) $this->previousAverage * (int) $this->previousGames);
    }
}
