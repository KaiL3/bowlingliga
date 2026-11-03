<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Application\PublicSite\PublicDataReader;
use Bowlingliga\Application\PublicSite\MatchdayDetail;
use Bowlingliga\Application\PublicSite\TeamAverageList;
use Bowlingliga\Domain\Honours\Honours;
use Bowlingliga\Domain\Table\Table;

/**
 * Fake of the {@see PublicDataReader} port for the controller tests, without
 * a database.
 */
final class FixedPublicDataReader implements PublicDataReader
{
    /**
     * @param list<\Bowlingliga\Application\PublicSite\MatchdayListEntry> $matchdayList
     * @param array<int, bool>                                                    $played
     * @param array<int, MatchdayDetail>                                          $details
     * @param array<int, TeamAverageList>                                        $teamAverageLists
     */
    public function __construct(
        public array $matchdayList = [],
        public array $played = [],
        public array $details = [],
        public ?Table $leagueTable = null,
        public ?Honours $honours = null,
        public array $teamAverageLists = [],
        public ?\Bowlingliga\Application\PublicSite\UpcomingMatchday $nextMatchday = null,
    ) {
    }

    public function nextMatchday(int $seasonId, string $today): ?\Bowlingliga\Application\PublicSite\UpcomingMatchday
    {
        return $this->nextMatchday;
    }

    public function matchdayList(int $seasonId): array
    {
        return $this->matchdayList;
    }

    public function isMatchdayPlayed(int $matchdayId): bool
    {
        return $this->played[$matchdayId] ?? false;
    }

    public function matchdayDetail(int $matchdayId): ?MatchdayDetail
    {
        return $this->details[$matchdayId] ?? null;
    }

    public function leagueTable(int $seasonId): ?Table
    {
        return $this->leagueTable;
    }

    public function honours(int $seasonId): ?Honours
    {
        return $this->honours;
    }

    public function teamAverageList(int $teamSeasonId): ?TeamAverageList
    {
        return $this->teamAverageLists[$teamSeasonId] ?? null;
    }
}
