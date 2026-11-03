<?php

declare(strict_types=1);

namespace Bowlingliga\Application\LotDraw;

use Bowlingliga\Domain\CarriesMessage;
use Bowlingliga\Domain\Translatable;

/**
 * Rejected fixtures of a lot draw, with the reason for the admin.
 */
final class LotDrawException extends \RuntimeException implements Translatable
{
    use CarriesMessage;

    public static function notALotDraw(): self
    {
        return new self('error.lot_draw.not_a_lot_draw');
    }

    public static function resultsEntered(): self
    {
        return new self('error.lot_draw.results_entered');
    }

    public static function oddTeamCount(): self
    {
        return new self('error.lot_draw.odd_team_count');
    }

    public static function incomplete(int $expected): self
    {
        return new self('error.lot_draw.incomplete', ['count' => $expected]);
    }

    public static function sameTeam(string $team): self
    {
        return new self('error.lot_draw.same_team', ['team' => $team]);
    }

    public static function teamTwice(string $team): self
    {
        return new self('error.lot_draw.team_twice', ['team' => $team]);
    }

    public static function unknownTeam(): self
    {
        return new self('error.lot_draw.unknown_team');
    }
}
