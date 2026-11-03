<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

use Bowlingliga\Domain\BlindPlayerService;

/**
 * Fills the free positions of a team's games with blind values (R21.3,
 * R27.4–R27.7, R27.18; decisions of 2026-10-25).
 *
 * Input is what the team itself entered: per player and game "plays" with
 * the net or "blind" for a player who drops out after having played. Per game
 * 4 positions are needed; the free ones are filled in this order:
 *
 * 1. a player marked blind keeps his position with his own average − 10,
 * 2. every roster player who does not play that evening serves as blind
 *    player, the one with the most games played first ({@see BlindPlayerOrder});
 *    the first one serves in every game with a free position, the second one
 *    in every game with two, and so on – each in his own row. A new player
 *    without an average plays with the season's ghost score (and has no
 *    handicap),
 * 3. only if the roster is used up – fewer than 4 players – the position
 *    becomes a blind slot without a player of its own that plays like a ghost
 *    player: the season's ghost score, HDC 0 (R20.1, R27.18). It depends on
 *    no player, so a strong player cannot lift it.
 *
 * The blind value of a player is always derived, never entered: net average
 * before the matchday − 10, clamped to 0–300. A new player without an average
 * gets the average of the games he played himself that evening; such a value
 * follows the entered results until the end (R12.9 applied to the blind
 * value).
 *
 * A game without any real player is not filled (it cannot be completed,
 * R27.2). Reading service without side effects.
 */
final class BlindFilling
{
    private const GAMES = [1, 2, 3];
    private const PLAYERS_PER_GAME = 4;

    public function __construct(
        private readonly BlindPlayerOrder $order,
        private readonly PlayerAverageReader $averages,
        private readonly BlindPlayerService $blindPlayer = new BlindPlayerService(),
    ) {
    }

    /**
     * @param array<int, array<int, array{is_blind: bool, net: int|null}>> $entries    own entries [player id][game]
     * @param int                                                          $ghostScore the season's ghost score for blind slots
     *
     * @return list<BlindPosition> by kind (own, substitute, slot), then game
     */
    public function fill(int $teamSeasonId, int $matchdayId, array $entries, int $ghostScore): array
    {
        [$real, $ownBlind] = $this->split($entries);

        $need = [];
        foreach (self::GAMES as $game) {
            $players = count(array_filter($real, static fn (array $games): bool => array_key_exists($game, $games)));
            $blind   = count(array_filter($ownBlind, static fn (array $games): bool => in_array($game, $games, true)));
            $need[$game] = $players === 0 ? 0 : max(0, self::PLAYERS_PER_GAME - $players - $blind);
        }

        $positions = [];
        foreach ($ownBlind as $playerId => $games) {
            [$net, $fromEvening] = $this->value($matchdayId, $playerId, $real[$playerId] ?? []);
            foreach ($games as $game) {
                $positions[] = new BlindPosition(BlindPosition::OWN, $playerId, 0, $game, $net, $fromEvening);
            }
        }

        $maxNeed = max($need);
        if ($maxNeed === 0) {
            return $positions;
        }

        // Substitutes: every roster player who does not play that evening; a new one with the ghost score.
        $participants = array_keys($real + $ownBlind);
        $substitutes  = [];
        foreach ($this->order->candidates($teamSeasonId, $matchdayId, $participants) as $candidate) {
            if (count($substitutes) === $maxNeed) {
                break;
            }
            $average = $this->averages->averageBefore($matchdayId, $candidate['player_id']);
            $substitutes[] = $average !== null
                ? [$candidate['player_id'], $this->blindPlayer->derivedBlindNet($average), false]
                : [$candidate['player_id'], $ghostScore, true];
        }
        foreach ($substitutes as $index => [$playerId, $net, $ghostValue]) {
            foreach (self::GAMES as $game) {
                if ($need[$game] > $index) {
                    $positions[] = new BlindPosition(BlindPosition::SUBSTITUTE, $playerId, 0, $game, $net, false, $ghostValue);
                }
            }
        }

        // Blind slots for what is still free (roster used up): like a ghost player.
        $slots = [];
        foreach (self::GAMES as $game) {
            $slots[$game] = max(0, $need[$game] - count($substitutes));
        }
        for ($slot = 1; $slot <= max($slots); $slot++) {
            foreach (self::GAMES as $game) {
                if ($slots[$game] >= $slot) {
                    $positions[] = new BlindPosition(BlindPosition::SLOT, null, $slot, $game, $ghostScore, false, true);
                }
            }
        }

        return $positions;
    }

    /**
     * Derived blind net of a player: average before the matchday − 10, for a
     * new player the average of his real games of this evening − 10.
     *
     * @param array<int, int|null> $realGames
     *
     * @return array{0: int|null, 1: bool} value, from this evening
     */
    private function value(int $matchdayId, int $playerId, array $realGames): array
    {
        $average = $this->averages->averageBefore($matchdayId, $playerId);
        if ($average !== null) {
            return [$this->blindPlayer->derivedBlindNet($average), false];
        }

        $played = array_values(array_filter($realGames, static fn (?int $net): bool => $net !== null));
        if ($played === []) {
            return [null, true];
        }

        return [$this->blindPlayer->derivedBlindNet(array_sum($played) / count($played)), true];
    }

    /**
     * @param array<int, array<int, array{is_blind: bool, net: int|null}>> $entries
     *
     * @return array{0: array<int, array<int, int|null>>, 1: array<int, list<int>>} real games, own blind games
     */
    private function split(array $entries): array
    {
        $real = [];
        $ownBlind = [];
        foreach ($entries as $playerId => $games) {
            foreach ($games as $game => $entry) {
                if ($entry['is_blind']) {
                    $ownBlind[$playerId][] = $game;
                } else {
                    $real[$playerId][$game] = $entry['net'];
                }
            }
        }
        foreach ($ownBlind as &$games) {
            sort($games);
        }
        unset($games);

        return [$real, $ownBlind];
    }
}
