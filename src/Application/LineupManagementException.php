<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Translatable;

/**
 * Domain errors of the lineup management that go beyond pure field
 * validation (R21.4, R21.5).
 *
 * Covers the cases of more than 4 real players (R21.4) and the lineup of a
 * player not belonging to the roster (R21.5). The message names the reason
 * for the rejection.
 */
final class LineupManagementException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    public static function tooManyRealPlayers(int $quantity): self
    {
        return new self('error.lineup.too_many_players', ['count' => $quantity]);
    }

    public static function playerNotInRoster(int $playerId): self
    {
        return new self('error.lineup.not_in_roster', ['id' => $playerId]);
    }

    public static function playerDuplicate(int $playerId): self
    {
        return new self('error.lineup.duplicate', ['id' => $playerId]);
    }
}
