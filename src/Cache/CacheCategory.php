<?php

declare(strict_types=1);

namespace Bowlingliga\Cache;

/**
 * The four domain cache categories of derived values (R35.2, R35.3).
 *
 * Each category corresponds to a cache table (`cache_*`) and to a
 * derived value that can be calculated reproducibly from the raw data:
 * - {@see self::AVERAGE}: player average per state (R11).
 * - {@see self::LEAGUE_TABLE}: league table per season/state (R16).
 * - {@see self::MATCHDAY_TABLE}: matchday table per matchday (R15.3).
 * - {@see self::HONOURS}: honours per season (R13).
 *
 * The frozen handicap is deliberately NOT a cache category, since it is raw
 * data and is never invalidated (R35.5).
 */
enum CacheCategory: string
{
    case AVERAGE = 'average';
    case LEAGUE_TABLE  = 'league_table';
    case MATCHDAY_TABLE = 'matchday_table';
    case HONOURS     = 'honours';

    /**
     * Name of the corresponding cache table.
     */
    public function table(): string
    {
        return match ($this) {
            self::AVERAGE => 'cache_average',
            self::LEAGUE_TABLE  => 'cache_league_table',
            self::MATCHDAY_TABLE => 'cache_matchday_table',
            self::HONOURS     => 'cache_honours',
        };
    }
}
