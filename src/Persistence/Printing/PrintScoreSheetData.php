<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\Printing;

use Bowlingliga\Application\Aggregation\AverageListAggregator;
use Bowlingliga\Domain\AverageList\AverageList;
use Bowlingliga\Domain\Printing\PrintScoreSheetHeader;
use Bowlingliga\Domain\Printing\PrintScoreSheetPlayer;
use Bowlingliga\Domain\Printing\PrintScoreSheetTeam;
use Bowlingliga\Persistence\FixtureRepository;
use Bowlingliga\Persistence\FrozenHandicapRepository;
use Bowlingliga\Persistence\LanePairRepository;
use Bowlingliga\Persistence\MatchdayRepository;
use Bowlingliga\Persistence\PlayerRepository;
use Bowlingliga\Persistence\RosterRepository;
use Bowlingliga\Persistence\SeasonConfigRepository;
use Bowlingliga\Persistence\SeasonRepository;
use Bowlingliga\Persistence\TeamSeasonRepository;
use Bowlingliga\Presentation\PlayerStatsReader;
use Bowlingliga\Presentation\PrintScoreSheetDataSource;

/**
 * Read adapter that gathers the data of a printed score sheet from the raw
 * data repositories (task 35.4, R19).
 *
 * Builds the header and team data for the pure
 * {@see \Bowlingliga\Domain\Printing\PrintScoreSheetService} from a fixture:
 * - Header: date and type of the matchday, lane numbers of the assigned lane
 *   pair (for regular/positionday) and entry fee and blind fee of the season
 *   (R19.1, R19.7, R32.3). For a lot draw or a missing assignment the lane
 *   numbers stay null; the error rule for a missing assignment (R19.5) is
 *   enforced by the domain service.
 * - Per team: team name and the complete roster with net average and frozen
 *   handicap as of the matchday (R19.2, R19.3). Roster players without an
 *   includable game get empty values (R19.6).
 *
 * Average and handicap are determined via the {@see AverageListAggregator}
 * as of the matchday, inclusive; the roster forms the base set, so that
 * players without averages (yet) also appear on the score sheet. Read-only,
 * raw data unchanged (R4.5).
 */
final class PrintScoreSheetData implements PrintScoreSheetDataSource
{
    public function __construct(
        private readonly FixtureRepository $teamFixtures,
        private readonly MatchdayRepository $matchdays,
        private readonly TeamSeasonRepository $teamSeasons,
        private readonly SeasonConfigRepository $seasonConfig,
        private readonly LanePairRepository $lanePairs,
        private readonly RosterRepository $roster,
        private readonly PlayerRepository $player,
        private readonly AverageListAggregator $averageLists,
        private readonly FrozenHandicapRepository $frozenHandicaps,
        private readonly ?PlayerStatsReader $stats = null,
        private readonly ?SeasonRepository $seasons = null,
    ) {
    }

    public function scoreSheetLanguage(int $matchdayId): ?string
    {
        $matchday = $this->matchdays->find($matchdayId);
        $season = $matchday !== null ? $this->seasons?->find((int) $matchday['season_id']) : null;

        return isset($season['score_sheet_language']) ? (string) $season['score_sheet_language'] : null;
    }

    public function matchdayOfFixture(int $fixtureId): ?int
    {
        $fixture = $this->teamFixtures->find($fixtureId);

        return $fixture !== null ? (int) $fixture['matchday_id'] : null;
    }

    /**
     * Builds the header of a fixture's printed score sheet, or returns null if
     * the fixture or its matchday does not exist.
     */
    public function header(int $fixtureId): ?PrintScoreSheetHeader
    {
        $fixture = $this->teamFixtures->find($fixtureId);
        if ($fixture === null) {
            return null;
        }

        $matchday = $this->matchdays->find((int) $fixture['matchday_id']);
        if ($matchday === null) {
            return null;
        }

        $config = $this->seasonConfig->find((int) $matchday['season_id']);

        [$laneLeft, $laneRight] = $this->laneNumbers($fixture['lane_pair_id'] ?? null);

        return new PrintScoreSheetHeader(
            (string) $matchday['date'],
            (string) $matchday['matchday_type'],
            $laneLeft,
            $laneRight,
            $config !== null ? (float) $config['entry_fee'] : 0.0,
            $config !== null ? (float) $config['blind_fee'] : 0.0,
            (int) $matchday['matchday_number'],
        );
    }

    /**
     * Builds the home team of the printed score sheet, or null if fixture,
     * matchday or the home team assignment are missing.
     */
    public function homeTeam(int $fixtureId): ?PrintScoreSheetTeam
    {
        return $this->team($fixtureId, 'home_team_season_id');
    }

    /**
     * Builds the away team of the printed score sheet, or null if fixture,
     * matchday or the away team assignment are missing.
     */
    public function awayTeam(int $fixtureId): ?PrintScoreSheetTeam
    {
        return $this->team($fixtureId, 'away_team_season_id');
    }

    /**
     * Builds a team (home or away) from team name and roster including net
     * average and frozen handicap as of the matchday (R19.2, R19.3, R19.6).
     */
    private function team(int $fixtureId, string $column): ?PrintScoreSheetTeam
    {
        $fixture = $this->teamFixtures->find($fixtureId);
        if ($fixture === null) {
            return null;
        }

        $teamSeasonId = $fixture[$column] ?? null;
        if ($teamSeasonId === null) {
            return null;
        }

        $teamSeason = $this->teamSeasons->find((int) $teamSeasonId);
        if ($teamSeason === null) {
            return null;
        }

        $matchday = $this->matchdays->find((int) $fixture['matchday_id']);
        if ($matchday === null) {
            return null;
        }

        return $this->teamOnMatchday($teamSeason, $matchday);
    }

    /**
     * Team name and roster of a team season with AVG, HDC and G as of the
     * matchday (R19.2, R19.3, R19.6).
     *
     * @param array<string, mixed> $teamSeason
     * @param array<string, mixed> $matchday
     */
    private function teamOnMatchday(array $teamSeason, array $matchday): PrintScoreSheetTeam
    {
        $teamSeasonId    = (int) $teamSeason['id'];
        $matchdayId      = (int) $matchday['id'];
        $matchdayCounter = (int) $matchday['matchday_number'];

        // AVG and number of games (G) refer to the state BEFORE this
        // matchday, i.e. to all games up to and including the previous
        // matchday. For the first matchday there is no previous state.
        $averageValues = $matchdayCounter > 1
            ? $this->averageValuesPerPlayer(
                $this->averageLists->averageList($teamSeasonId, $matchdayCounter - 1)
            )
            : [];

        // Ghost team: fixed ghost players with the ghost score of the season and HDC 0 (R20.1, R20.2).
        if ((int) ($teamSeason['is_ghost_team'] ?? 0) === 1) {
            return new PrintScoreSheetTeam((string) $teamSeason['team_name'], [], $this->ghostScore((int) $matchday['season_id']));
        }

        $rosterPlayers = [];
        foreach ($this->roster->findPlayerIdsForTeamSeason($teamSeasonId) as $playerId) {
            $playerData = $this->player->find($playerId);
            if ($playerData === null) {
                continue;
            }

            // AVG, G and HDC before this matchday as in the virtual score sheet:
            // frozen HDC, otherwise the one calculated from the current average
            // (R12, R19.3).
            if ($this->stats !== null) {
                $k = $this->stats->stats($matchdayId, (int) $playerId);
                $rosterPlayers[] = new PrintScoreSheetPlayer(
                    (string) $playerData['name'],
                    $k['average'],
                    $k['hdc'],
                    $k['games'],
                );
                continue;
            }

            $values = $averageValues[$playerId] ?? ['average' => null, 'anzahlSpiele' => 0];

            // HDC: the handicap frozen for THIS matchday (R19.3); if there is
            // none, the field stays empty (R19.6).
            $hdc = $this->frozenHandicaps->findForMatchdayAndPlayer($matchdayId, $playerId);

            $rosterPlayers[] = new PrintScoreSheetPlayer(
                (string) $playerData['name'],
                $values['average'],
                $hdc !== null ? (int) $hdc['handicap'] : null,
                $values['anzahlSpiele'],
            );
        }

        return new PrintScoreSheetTeam((string) $teamSeason['team_name'], $rosterPlayers);
    }

    private function ghostScore(int $seasonId): int
    {
        $config = $this->seasonConfig->find($seasonId);

        return $config !== null ? (int) $config['ghost_score'] : \Bowlingliga\Application\SeasonConfiguration::DEFAULT_GHOST_SCORE;
    }

    /**
     * Indexes net average and number of games played (before the matchday) per
     * player id from an average list.
     *
     * @return array<int, array{average: float|null, numberOfGames: int}>
     */
    private function averageValuesPerPlayer(AverageList $averageList): array
    {
        $values = [];
        foreach ($averageList->rows as $row) {
            $values[(int) $row->playerId] = [
                'average' => $row->averageNet,
                'anzahlSpiele' => $row->numberOfGames,
            ];
        }

        return $values;
    }

    /**
     * Determines the two lane numbers of the assigned lane pair, or
     * [null, null] if there is no lane assignment (R19.5, R32.3).
     *
     * @return array{0: int|null, 1: int|null}
     */
    public function matchdays(int $seasonId): array
    {
        $matchdays = [];
        foreach ($this->matchdays->findAllForSeason($seasonId) as $matchday) {
            $matchdays[] = [
                'id'        => (int) $matchday['id'],
                'number'    => (int) $matchday['matchday_number'],
                'date'     => (string) $matchday['date'],
                'type'       => (string) $matchday['matchday_type'],
                'played'  => (int) $matchday['is_played'] === 1,
                'pairings' => count($this->teamFixtures->findAllForMatchday((int) $matchday['id'])),
            ];
        }

        return $matchdays;
    }

    public function lotDrawSheets(int $matchdayId): ?array
    {
        $matchday = $this->matchdays->find($matchdayId);
        if ($matchday === null || (string) $matchday['matchday_type'] !== PrintScoreSheetHeader::TYPE_LOT_DRAW
            || $this->teamFixtures->findAllForMatchday($matchdayId) !== []) {
            return null;
        }

        $seasonId = (int) $matchday['season_id'];
        $config = $this->seasonConfig->find($seasonId);
        $teamSeasons = $this->teamSeasons->findAllForSeason($seasonId);
        // Ordered by name already; the ghost team goes last.
        usort($teamSeasons, static fn (array $a, array $b): int => (int) $a['is_ghost_team'] <=> (int) $b['is_ghost_team']);

        return [
            'header' => new PrintScoreSheetHeader(
                (string) $matchday['date'],
                PrintScoreSheetHeader::TYPE_LOT_DRAW,
                null,
                null,
                $config !== null ? (float) $config['entry_fee'] : 0.0,
                $config !== null ? (float) $config['blind_fee'] : 0.0,
                (int) $matchday['matchday_number'],
            ),
            'teams' => array_map(fn (array $teamSeason): PrintScoreSheetTeam => $this->teamOnMatchday($teamSeason, $matchday), $teamSeasons),
        ];
    }

    public function fixtureIds(int $matchdayId): array
    {
        $fixtures = [];
        foreach ($this->teamFixtures->findAllForMatchday($matchdayId) as $fixture) {
            [$lane] = $this->laneNumbers($fixture['lane_pair_id'] ?? null);
            $fixtures[] = ['id' => (int) $fixture['id'], 'lane' => $lane ?? PHP_INT_MAX];
        }

        // By first lane; for an equal or missing lane by creation order.
        usort($fixtures, static fn (array $a, array $b): int => [$a['lane'], $a['id']] <=> [$b['lane'], $b['id']]);

        return array_column($fixtures, 'id');
    }

    private function laneNumbers(mixed $lanePairId): array
    {
        if ($lanePairId === null) {
            return [null, null];
        }

        $lanePair = $this->lanePairs->find((int) $lanePairId);
        if ($lanePair === null) {
            return [null, null];
        }

        return [(int) $lanePair['lane_a'], (int) $lanePair['lane_b']];
    }
}
