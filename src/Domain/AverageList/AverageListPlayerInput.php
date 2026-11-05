<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\AverageList;

use Bowlingliga\Domain\Honours\Gender;

/**
 * Immutable value object with the raw data already collected per player as
 * input to the {@see AverageListService} (R2).
 *
 * The average list service is a pure calculation unit without side effects:
 * it does not access the database but receives per player the net results of
 * the games played personally and regularly up to and including the matchday
 * in question (R2.1), the gender (R2.7) and the handicap frozen for that
 * matchday according to R12.12 (R2.8). Which games are
 * included is decided by the aggregating layer; games marked blind and ghost
 * team results are already excluded (R2.1, R20.4, R27.13).
 *
 * The net results are stored per game so that the service can derive the key
 * figures from them: number of games (R2.9), net pins (R2.10), net average
 * (R2.2), best net game (R2.13) and the best net series of exactly three
 * consecutive games (R2.12). The gross values arise exclusively from the
 * handicap frozen for this matchday (gross = net + HDC, R12.11, R13.3); the
 * order of the net results determines the windows of three considered for
 * the best series.
 *
 * The frozen handicap is deliberately nullable: if the player has no handicap
 * as of the matchday in question (status "without handicap" according to
 * R12), {@see $frozenHandicap} is null. In that case the HDC field
 * of the average list stays empty and none of the player's gross values are
 * calculated (R2.15).
 */
final class AverageListPlayerInput
{
    /**
     * @param string    $playerId       Stable technical player id; lets the
     *                                  caller attribute the row.
     * @param string    $playerName     Display name of the player.
     * @param Gender    $gender         Gender of the player shown in the
     *                                  average list (R2.7).
     * @param list<int> $netResults     Net results of the games played
     *                                  personally and regularly up to and
     *                                  including the matchday in question, in
     *                                  game order; each value in the range
     *                                  0 to 300 (R2.1, R2.10).
     * @param int|null  $frozenHandicap The handicap frozen for the matchday in
     *                                  question in the range 0 to 300 (R2.8),
     *                                  or null if the player is "without
     *                                  handicap" at that point (R2.15).
     *
     * @throws \InvalidArgumentException If id or name is empty, a net result is
     *                                   outside 0 to 300 or the frozen handicap
     *                                   is outside 0 to 300.
     */
    public function __construct(
        public readonly string $playerId,
        public readonly string $playerName,
        public readonly Gender $gender,
        public readonly array $netResults,
        public readonly ?int $frozenHandicap,
    ) {
        if ($playerId === '') {
            throw new \InvalidArgumentException('The player id of an average list input must not be empty.');
        }

        if ($playerName === '') {
            throw new \InvalidArgumentException('The player name of an average list input must not be empty.');
        }

        foreach ($netResults as $net) {
            if ($net < 0 || $net > 300) {
                throw new \InvalidArgumentException('A net result of an average list input must be between 0 and 300, given: ' . $net);
            }
        }

        if ($frozenHandicap !== null && ($frozenHandicap < 0 || $frozenHandicap > 300)) {
            throw new \InvalidArgumentException('The frozen handicap of an average list input must be between 0 and 300, given: ' . $frozenHandicap);
        }
    }
}
