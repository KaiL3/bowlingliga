<?php

declare(strict_types=1);

namespace Bowlingliga\Application\TeamEntry;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Translatable;
use RuntimeException;

/**
 * Domain error of the result entry by the teams (R37), meant for the teams;
 * its text is a catalog key (`team_entry.error.*`) with parameters.
 */
final class TeamEntryException extends RuntimeException implements Translatable
{
    use CarriesMessage;

    public static function invalidCode(): self
    {
        return new self('team_entry.error.invalid_code');
    }

    public static function notAvailable(): self
    {
        return new self('team_entry.error.not_available');
    }

    /** @param string $date matchday as `YYYY-MM-DD` */
    public static function notToday(string $date): self
    {
        return new self('team_entry.error.not_today', ['date' => $date]);
    }

    public static function finished(): self
    {
        return new self('team_entry.error.finished');
    }

    public static function alreadySubmitted(): self
    {
        return new self('team_entry.error.already_submitted');
    }

    public static function notSent(): self
    {
        return new self('team_entry.error.not_sent');
    }

    public static function unknownPlayer(): self
    {
        return new self('team_entry.error.unknown_player');
    }

    public static function unknownGame(): self
    {
        return new self('team_entry.error.unknown_game');
    }

    public static function tooManyPlayers(int $game): self
    {
        return new self('team_entry.error.too_many_players', ['game' => $game]);
    }

    public static function invalidNet(string $player, int $game): self
    {
        return new self('team_entry.error.invalid_net', ['player' => $player, 'game' => $game]);
    }

    public static function missingNet(string $player, int $game): self
    {
        return new self('team_entry.error.missing_net', ['player' => $player, 'game' => $game]);
    }

    public static function gameEmpty(int $game): self
    {
        return new self('team_entry.error.game_empty', ['game' => $game]);
    }

    public static function blindWithoutGame(string $player, int $game): self
    {
        return new self('team_entry.error.blind_without_game', ['player' => $player, 'game' => $game]);
    }

    public static function reentry(string $player, int $game): self
    {
        return new self('team_entry.error.reentry', ['player' => $player, 'game' => $game]);
    }

    public static function blindValueMissing(int $game): self
    {
        return new self('team_entry.error.blind_value_missing', ['game' => $game]);
    }

    public static function notSubmitted(string $team): self
    {
        return new self('team_entry.error.not_submitted', ['team' => $team]);
    }

    public static function alreadyConfirmed(string $team): self
    {
        return new self('team_entry.error.already_confirmed', ['team' => $team]);
    }

    public static function noteRequired(): self
    {
        return new self('team_entry.error.note_required');
    }
}
