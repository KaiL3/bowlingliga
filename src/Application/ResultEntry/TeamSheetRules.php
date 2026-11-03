<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * Rules for the score sheet of one real team on an evening (R21.2, R21.4,
 * R27.1–R27.5, R27.17, R30.4).
 *
 * Players can be substituted between two games, never during a game. A team
 * can therefore field more than 4 players on an evening; per game the rules
 * are:
 * - at most 4 results per game, real and blind ones together – at most 4
 *   players play at the same time ({@see checkEntered()}, also for drafts),
 * - on confirmation exactly 4 results per game, at least 1 of them real
 *   (R27.2), and the games a player played themselves without a gap – a
 *   substituted player does not come back ({@see checkComplete()}).
 *
 * A row with only blind games is a roster player serving as blind player
 * (R27.4); which players may serve is checked by {@see BlindPlayerOrder}.
 *
 * Pure checks without side effects; violations are reported as
 * {@see ResultEntryException} with the names of team and player.
 */
final class TeamSheetRules
{
    /** Players on the lanes per team and game (R21.2, R27.1). */
    public const PLAYERS_PER_GAME = 4;

    /** Games per evening (R22.1). */
    private const GAMES = [1, 2, 3];

    /**
     * Rules that already apply while entering (draft): at most 4 results per
     * game.
     *
     * @param list<EnteredGame> $games games of one team
     *
     * @throws ResultEntryException
     */
    public function checkEntered(array $games, string $team): void
    {
        foreach ($this->countPerGame($games) as $gameNumber => $count) {
            if ($count > self::PLAYERS_PER_GAME) {
                throw ResultEntryException::tooManyInGame($team, $gameNumber, $count);
            }
        }
    }

    /**
     * Rules for confirming a complete score sheet.
     *
     * @param list<EnteredGame>  $games       games of one team
     * @param array<int, string> $playerNames player id → name for messages
     *
     * @throws ResultEntryException
     */
    public function checkComplete(array $games, string $team, array $playerNames = []): void
    {
        $this->checkEntered($games, $team);

        $counts = $this->countPerGame($games);
        $real   = $this->countPerGame(array_filter($games, static fn (EnteredGame $g): bool => ! $g->isBlind));
        foreach (self::GAMES as $gameNumber) {
            if (($counts[$gameNumber] ?? 0) < self::PLAYERS_PER_GAME) {
                throw ResultEntryException::gameIncomplete($team, $gameNumber, $counts[$gameNumber] ?? 0);
            }
            if (($real[$gameNumber] ?? 0) === 0) {
                throw ResultEntryException::noRealPlayerInGame($team, $gameNumber);
            }
        }

        foreach ($this->realGamesPerPlayer($games) as $playerId => $numbers) {
            if ($numbers !== [] && max($numbers) - min($numbers) + 1 !== count($numbers)) {
                throw ResultEntryException::reentry($playerNames[$playerId] ?? 'Spieler ' . $playerId, $team);
            }
        }
    }

    /**
     * Number of results per game.
     *
     * @param array<EnteredGame> $games
     *
     * @return array<int, int> game number → count
     */
    private function countPerGame(array $games): array
    {
        $counts = [];
        foreach ($games as $game) {
            $counts[$game->gameNumber] = ($counts[$game->gameNumber] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Game numbers each player played themselves (without blind games); a
     * blind player gets an empty list.
     *
     * @param list<EnteredGame> $games
     *
     * @return array<int, list<int>> player id → game numbers
     */
    public function realGamesPerPlayer(array $games): array
    {
        $players = [];
        foreach ($games as $game) {
            if (! $game->isPlayerRow() || $game->playerId === null) {
                continue;
            }
            $players[$game->playerId] ??= [];
            if (! $game->isBlind) {
                $players[$game->playerId][] = $game->gameNumber;
            }
        }

        return $players;
    }
}
