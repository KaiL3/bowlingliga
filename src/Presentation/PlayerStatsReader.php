<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Domain\Points\PointsRules;

/**
 * Read port for a player's key figures in the virtual score sheet (task
 * 35.3): average (AVG), number of games (G) and handicap (HDC) as of the state
 * before the matchday – as on the printed score sheet (R19.3).
 *
 * In production the
 * {@see \Bowlingliga\Persistence\ResultEntry\HandicapFreezingRepository}
 * implements this port with the same rules by which handicaps are frozen on
 * confirmation.
 */
interface PlayerStatsReader
{
    /**
     * Key figures of a player for a matchday.
     *
     * `hdc` is the frozen handicap of the matchday or – as long as none is
     * frozen yet – the one that would be frozen on confirmation; null if none
     * can be calculated (without handicap, R12.14). If the player has no
     * average before the matchday, `erstspieltag` is true: their handicap then
     * follows from the games of this matchday (R12.9).
     *
     * @return array{average: float|null, games: int, hdc: int|null, frozen: bool, first_matchday: bool}
     */
    public function stats(int $matchdayId, int $playerId): array;

    /**
     * Parameters of the season's handicap formula, or null if no formula is
     * configured (R12.2–R12.4, R12.13).
     *
     * @return array{factor: float, target_score: int, max: int}|null
     */
    public function formula(int $seasonId): ?array;

    /**
     * Points rules of the season: game points and bonus scale (R17.11).
     */
    public function pointsRules(int $seasonId): PointsRules;
}
