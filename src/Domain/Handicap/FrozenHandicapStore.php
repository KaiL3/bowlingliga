<?php

declare(strict_types=1);

namespace Bowlingliga\Domain\Handicap;

/**
 * Port for persisting a frozen handicap as raw data (R12.12, R35.5).
 *
 * This narrow outlet decouples the {@see HandicapFreezeService} from the
 * concrete persistence technology: the domain only knows the capability of
 * permanently storing a handicap valid per matchday/player as raw data. The
 * port is implemented by
 * {@see \Bowlingliga\Persistence\FrozenHandicapRepository}.
 *
 * @see R12.12, R35.5
 */
interface FrozenHandicapStore
{
    /**
     * Freezes a player's handicap for a matchday as raw data or updates it
     * (upsert per matchday/player, R12.12).
     *
     * @param int $matchdayId Matchday id.
     * @param int $playerId   Player id.
     * @param int $hdcValue   Integer handicap in the range 0–300.
     */
    public function save(int $matchdayId, int $playerId, int $hdcValue): void;
}
