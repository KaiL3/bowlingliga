<?php

declare(strict_types=1);

namespace Bowlingliga\Application\PublicSite;

use Bowlingliga\Domain\AverageList\AverageList;

/**
 * A team's current average list for the public team page (R2): as of the last
 * played matchday of the season. If no matchday has been played yet, the
 * as-of values are null and the list is empty.
 *
 * Pure, immutable read model.
 */
final class TeamAverageList
{
    public function __construct(
        public readonly int $teamSeasonId,
        public readonly int $seasonId,
        public readonly string $teamLabel,
        public readonly ?int $standingMatchdayCounter,
        public readonly ?string $standingDate,
        public readonly AverageList $averageList,
    ) {
    }
}
