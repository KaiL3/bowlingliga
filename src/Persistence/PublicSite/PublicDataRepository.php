<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\PublicSite;

use Bowlingliga\Application\Aggregation\HonoursAggregator;
use Bowlingliga\Application\Aggregation\AverageListAggregator;
use Bowlingliga\Application\Aggregation\TableAggregator;
use Bowlingliga\Application\PublicSite\PublicDataReader;
use Bowlingliga\Application\PublicSite\MatchdayDetail;
use Bowlingliga\Application\PublicSite\MatchdayFixture;
use Bowlingliga\Application\PublicSite\MatchdayListEntry;
use Bowlingliga\Application\PublicSite\ScoreSheetTeamView;
use Bowlingliga\Application\PublicSite\TeamAverageList;
use Bowlingliga\Application\PublicSite\UpcomingMatchday;
use Bowlingliga\Domain\Honours\Honours;
use Bowlingliga\Domain\Table\Table;
use Bowlingliga\Persistence\LanePairRepository;
use Bowlingliga\Persistence\OriginalScoreSheetRepository;
use Bowlingliga\Persistence\SeasonRepository;
use Bowlingliga\Persistence\MatchdayRepository;
use Bowlingliga\Persistence\FixtureRepository;
use Bowlingliga\Persistence\TeamSeasonRepository;

/**
 * Production adapter of the {@see PublicDataReader} port (R1, R2, R13, R14,
 * R15, R16).
 *
 * Orchestrates the reads of the public views: it connects the pure
 * aggregators (tables, average lists, honours) with the raw data repositories
 * (season, matchday, fixture, team season, original score sheet) to form the
 * prepared display data structures. No data is changed; if a requested
 * resource cannot be determined, the adapter returns null or an empty list,
 * so that the controller shows the intended message (R1.5, R15.5).
 *
 * The team result of the detail view (pins per team) is taken from the
 * matchday table (gross pins, R15.3); the mapping happens via the team season
 * id.
 */
final class PublicDataRepository implements PublicDataReader
{
    public function __construct(
        private readonly SeasonRepository $seasons,
        private readonly MatchdayRepository $matchdays,
        private readonly FixtureRepository $teamFixtures,
        private readonly TeamSeasonRepository $teamSeasons,
        private readonly OriginalScoreSheetRepository $scoreSheet,
        private readonly LanePairRepository $lanePairs,
        private readonly ScoreSheetDisplayRepository $scoreSheetDisplay,
        private readonly TableAggregator $tables,
        private readonly AverageListAggregator $averageLists,
        private readonly HonoursAggregator $honoursAggregator,
    ) {
    }

    public function matchdayList(int $seasonId): array
    {
        $entries = [];
        foreach ($this->matchdays->findAllForSeason($seasonId) as $matchday) {
            $matchdayId = (int) $matchday['id'];
            [$homeName, $awayName] = $this->teamNamesOfFirstFixture($matchdayId);

            $entries[] = new MatchdayListEntry(
                $matchdayId,
                (int) $matchday['matchday_number'],
                (string) $matchday['date'],
                $homeName,
                $awayName,
                (int) $matchday['is_played'] === 1,
            );
        }

        return $entries;
    }

    public function nextMatchday(int $seasonId, string $today): ?UpcomingMatchday
    {
        foreach ($this->matchdays->findAllForSeason($seasonId) as $matchday) {
            if ((int) $matchday['is_played'] === 1 || (string) $matchday['date'] < $today) {
                continue;
            }

            $matchdayId = (int) $matchday['id'];
            $fixtures = [];
            foreach ($this->teamFixtures->findAllForMatchday($matchdayId) as $raw) {
                $lanePair = $raw['lane_pair_id'] !== null ? $this->lanePairs->find((int) $raw['lane_pair_id']) : null;
                $fixtures[] = [
                    'home'  => $this->teamLabel($raw['home_team_season_id'] === null ? null : (int) $raw['home_team_season_id']) ?? 'offen',
                    'away'  => $this->teamLabel($raw['away_team_season_id'] === null ? null : (int) $raw['away_team_season_id']) ?? 'offen',
                    'home_lane' => $lanePair !== null ? (int) $lanePair['lane_a'] : null,
                    'away_lane' => $lanePair !== null ? (int) $lanePair['lane_b'] : null,
                    'sort'  => $lanePair !== null ? (int) $lanePair['lane_a'] : PHP_INT_MAX,
                ];
            }
            usort($fixtures, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

            return new UpcomingMatchday(
                $matchdayId,
                (int) $matchday['matchday_number'],
                (string) $matchday['date'],
                (string) $matchday['matchday_type'],
                array_map(static fn (array $f): array => ['home' => $f['home'], 'away' => $f['away'], 'home_lane' => $f['home_lane'], 'away_lane' => $f['away_lane']], $fixtures),
            );
        }

        return null;
    }

    public function isMatchdayPlayed(int $matchdayId): bool
    {
        $matchday = $this->matchdays->find($matchdayId);

        return $matchday !== null && (int) $matchday['is_played'] === 1;
    }

    public function matchdayDetail(int $matchdayId): ?MatchdayDetail
    {
        $matchday = $this->matchdays->find($matchdayId);
        if ($matchday === null) {
            return null;
        }

        $fixturesRaw = $this->teamFixtures->findAllForMatchday($matchdayId);
        if ($fixturesRaw === []) {
            return null;
        }

        $counter = (int) $matchday['matchday_number'];

        // Each fixture of the matchday is prepared as tab content
        // (R15.1): lane pair label, score sheets of both teams, both average lists.
        $fixtures = [];
        foreach ($fixturesRaw as $raw) {
            $fixtures[] = $this->buildMatchdayFixture($raw, $matchdayId, $counter);
        }

        return new MatchdayDetail(
            $matchdayId,
            $counter,
            (string) $matchday['date'],
            $fixtures,
            $this->tables->matchdayTable($matchdayId),
        );
    }

    /**
     * Builds a {@see MatchdayFixture} (tab content) from the raw data of a
     * fixture.
     *
     * @param array<string, mixed> $raw
     */
    private function buildMatchdayFixture(array $raw, int $matchdayId, int $counter): MatchdayFixture
    {
        $fixtureId = (int) $raw['id'];
        $homeId        = $raw['home_team_season_id'] === null ? null : (int) $raw['home_team_season_id'];
        $awayId        = $raw['away_team_season_id'] === null ? null : (int) $raw['away_team_season_id'];
        $lanePairId    = $raw['lane_pair_id'] === null ? null : (int) $raw['lane_pair_id'];

        $homeName = $this->teamLabel($homeId);
        $awayName = $this->teamLabel($awayId);

        $homeSheet = $this->scoreSheetDisplay->forTeam($fixtureId, $homeId ?? 0, $matchdayId, $homeName);
        $awaySheet = $this->scoreSheetDisplay->forTeam($fixtureId, $awayId ?? 0, $matchdayId, $awayName);

        return new MatchdayFixture(
            $fixtureId,
            $this->lanes($lanePairId),
            $homeSheet,
            $awaySheet,
            $this->averageLists->averageList($homeId ?? 0, $counter),
            $this->averageLists->averageList($awayId ?? 0, $counter),
            $this->originalScoreSheetId($fixtureId),
        );
    }

    /**
     * The lanes of the fixture's lane pair ("1/2"), null without lane
     * assignment; the view names the tab after them.
     */
    private function lanes(?int $lanePairId): ?string
    {
        $lanePair = $lanePairId !== null ? $this->lanePairs->find($lanePairId) : null;

        return $lanePair !== null ? sprintf('%d/%d', (int) $lanePair['lane_a'], (int) $lanePair['lane_b']) : null;
    }

    public function leagueTable(int $seasonId): ?Table
    {
        if ($this->seasons->find($seasonId) === null) {
            return null;
        }

        return $this->tables->leagueTable($seasonId);
    }

    public function honours(int $seasonId): ?Honours
    {
        if ($this->seasons->find($seasonId) === null) {
            return null;
        }

        return $this->honoursAggregator->honours($seasonId);
    }

    public function teamAverageList(int $teamSeasonId): ?TeamAverageList
    {
        $team = $this->teamSeasons->find($teamSeasonId);
        if ($team === null || (int) $team['is_ghost_team'] === 1) {
            return null;
        }

        $seasonId = (int) $team['season_id'];

        // State: last played matchday of the season (like the league table).
        $lastOne = null;
        foreach ($this->matchdays->findAllForSeason($seasonId) as $matchday) {
            if ((int) $matchday['is_played'] === 1
                && ($lastOne === null || (int) $matchday['matchday_number'] > (int) $lastOne['matchday_number'])) {
                $lastOne = $matchday;
            }
        }

        return new TeamAverageList(
            $teamSeasonId,
            $seasonId,
            (string) $team['team_name'],
            $lastOne !== null ? (int) $lastOne['matchday_number'] : null,
            $lastOne !== null ? (string) $lastOne['date'] : null,
            $this->averageLists->averageList($teamSeasonId, $lastOne !== null ? (int) $lastOne['matchday_number'] : 0),
        );
    }

    /**
     * Returns the team names of the first fixture of a matchday (home, away)
     * for the matchday list; missing teams as null.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function teamNamesOfFirstFixture(int $matchdayId): array
    {
        $fixtures = $this->teamFixtures->findAllForMatchday($matchdayId);
        if ($fixtures === []) {
            return [null, null];
        }

        $fixture = $fixtures[0];

        return [
            $this->teamLabel($fixture['home_team_season_id'] === null ? null : (int) $fixture['home_team_season_id']),
            $this->teamLabel($fixture['away_team_season_id'] === null ? null : (int) $fixture['away_team_season_id']),
        ];
    }

    private function teamLabel(?int $teamSeasonId): ?string
    {
        if ($teamSeasonId === null) {
            return null;
        }

        $teamSeason = $this->teamSeasons->find($teamSeasonId);

        return $teamSeason === null ? null : (string) $teamSeason['team_name'];
    }

    private function originalScoreSheetId(int $fixtureId): ?int
    {
        $sheet = $this->scoreSheet->findAllForFixture($fixtureId);
        if ($sheet === []) {
            return null;
        }

        return (int) $sheet[0]['id'];
    }
}
