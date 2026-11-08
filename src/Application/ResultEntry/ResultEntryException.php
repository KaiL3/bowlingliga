<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Translatable;

/**
 * Domain errors of the result entry that go beyond pure field validation
 * (R22, R30).
 *
 * Covers among others: incomplete results on confirmation (R30.4/R30.18) and
 * a matchday context that cannot be found. The message names the reason for
 * the rejection.
 */
final class ResultEntryException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    private const EARLIER_MATCHDAY_INCOMPLETE = 'error.result.earlier_matchday_incomplete';

    /**
     * A confirmation was attempted although not all required net results of
     * both teams are given (R30.4, R30.18).
     */
    public static function incomplete(): self
    {
        return new self('error.result.incomplete');
    }

    /**
     * An earlier matchday of the season is not complete yet, so the fixture
     * can only be saved as a draft (R30.20).
     */
    public static function earlierMatchdayIncomplete(int $number): self
    {
        return new self(self::EARLIER_MATCHDAY_INCOMPLETE, ['number' => $number]);
    }

    public function isEarlierMatchdayIncomplete(): bool
    {
        return $this->message()->key === self::EARLIER_MATCHDAY_INCOMPLETE;
    }

    /**
     * More than 4 results in one game of a team: at most 4 players play at the
     * same time, blind games included (R21.4, R27.1).
     */
    public static function tooManyInGame(string $team, int $gameNumber, int $count): self
    {
        return new self('error.result.too_many_in_game', ['team' => $team, 'game' => $gameNumber, 'count' => $count]);
    }

    /**
     * A game of a team has fewer than 4 results on confirmation (R30.4).
     */
    public static function gameIncomplete(string $team, int $gameNumber, int $count): self
    {
        return new self('error.result.game_incomplete', ['team' => $team, 'game' => $gameNumber, 'count' => $count]);
    }

    /**
     * All results of a game are blind: at most 3 blind results per game, at
     * least 1 real player (R27.2).
     */
    public static function noRealPlayerInGame(string $team, int $gameNumber): self
    {
        return new self('error.result.no_real_player', ['team' => $team, 'game' => $gameNumber]);
    }

    /**
     * A player played again after a break: players are only substituted
     * between games and a substituted player does not come back (R21.2).
     */
    public static function reentry(string $player, string $team): self
    {
        return new self('error.result.reentry', ['player' => $player, 'team' => $team]);
    }

    /**
     * A player serves as blind player although a roster player who does not
     * play either has more games (R27.4, R27.15).
     */
    public static function wrongBlindPlayer(string $team, string $expected, int $expectedGames, string $chosen, int $chosenGames): self
    {
        return new self('error.result.wrong_blind_player', ['team' => $team, 'expected' => $expected, 'expected_games' => $expectedGames, 'chosen' => $chosen, 'chosen_games' => $chosenGames]);
    }

    /**
     * A player of the score sheet does not belong to the team's roster
     * (R21.5).
     */
    public static function notInRoster(string $player, string $team): self
    {
        return new self('error.result.not_in_roster', ['player' => $player, 'team' => $team]);
    }

    /**
     * The matchday context (season, matchday counter) could not be determined.
     */
    public static function matchdayContextMissing(int $matchdayId): self
    {
        return new self('error.result.matchday_context_missing', ['id' => $matchdayId]);
    }

    /**
     * The handicap chain has changed since the preview shown; nothing is
     * applied (R12.19).
     */
    public static function chainChanged(): self
    {
        return new self('error.result.chain_changed');
    }
}
