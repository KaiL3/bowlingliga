<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Aggregation;

use Bowlingliga\Domain\Points\Lineup as PointsLineup;
use Bowlingliga\Domain\Points\PointsService;
use Bowlingliga\Domain\Table\Table;
use Bowlingliga\Domain\Table\TableService;
use Bowlingliga\Domain\Table\TeamStatistics;

/**
 * Aggregates the raw data of played fixtures into a league or matchday table
 * (R16, R15.3, R17).
 *
 * The aggregator reads the played fixtures via the {@see TableRawDataReader},
 * calculates the points per team and matchday via the pure
 * {@see PointsService} and sums up points, net pins, gross pins and the number
 * of games played per team over all included matchdays. From the
 * {@see TeamStatistics} formed this way, the pure {@see TableService} produces
 * the sorted table with ranks and total row.
 *
 * For the league table all played matchdays of the season are included
 * (R16.6, R24.3), for the matchday table only the one matchday (R15.3). Teams
 * without played games appear with zero values (R16.7). The ghost team – like
 * every team – gets its own statistic (R16.10, R20.8); its net and gross pins
 * are equal since it carries no handicap (R20.2).
 *
 * The aggregation is free of side effects; its only dependency is the reading
 * raw data port (R35.2).
 */
final class TableAggregator
{
    /** Id of the ghost team in the statistics mapping (R20). */
    private const GHOST_TEAM_ID = 'geisterteam';

    public function __construct(
        private readonly TableRawDataReader $reader,
        private readonly PointsService $points = new PointsService(),
        private readonly TableService $tables = new TableService(),
    ) {
    }

    /**
     * Builds a season's league table from all played matchdays (R16).
     */
    public function leagueTable(int $seasonId): Table
    {
        $fixtures = $this->reader->findFixturesForSeason($seasonId);
        $teams = $this->reader->findTeamsForSeason($seasonId);

        return $this->tables->leagueTable($this->buildStatistics($fixtures, $teams));
    }

    /**
     * Baut die Tagestabelle eines Spieltags (R15.3).
     */
    public function matchdayTable(int $matchdayId): Table
    {
        $fixtures = $this->reader->findFixturesForMatchday($matchdayId);

        // For the matchday table only the teams involved on this matchday
        // are considered; teams without a game on this day do not appear.
        return $this->tables->leagueTable($this->buildStatistics($fixtures, []));
    }

    /**
     * Forms the {@see TeamStatistics} per team from the fixture raw data
     * (R16.1, R17). Teams from {@see $presetTeams} without played games get a
     * zero statistic (R16.7).
     *
     * @param list<FixtureRawData> $fixtures
     * @param array<int, string>   $presetTeams Team season id → team name (R16.7)
     *
     * @return list<TeamStatistics>
     */
    private function buildStatistics(array $fixtures, array $presetTeams): array
    {
        /** @var array<string, array{name: string, points: int, netPins: int, grossPins: int, games: int, ghosts: bool}> $accumulator */
        $accumulator = [];

        // Preset teams with zero values (R16.7).
        foreach ($presetTeams as $teamSeasonId => $teamName) {
            $accumulator[(string) $teamSeasonId] = [
                'name' => $teamName, 'points' => 0, 'pinsNetto' => 0, 'pinsBrutto' => 0, 'games' => 0, 'ghosts' => false,
            ];
        }

        foreach ($fixtures as $fixture) {
            $this->processTeam($accumulator, $fixture->home, $fixture->away);
            $this->processTeam($accumulator, $fixture->away, $fixture->home);
        }

        $statistics = [];
        foreach ($accumulator as $teamId => $values) {
            // PHP converts numeric array keys to int; TeamStatistics
            // expects the id as string.
            $statistics[] = new TeamStatistics(
                (string) $teamId,
                $values['name'],
                $values['points'],
                $values['pinsNetto'],
                $values['pinsBrutto'],
                $values['games'],
                $values['ghosts'],
            );
        }

        return $statistics;
    }

    /**
     * Processes one team of a fixture: calculates the points against the
     * opponent and adds points, pins and games to the accumulator (R17).
     *
     * @param array<string, array{name: string, points: int, pinsNetto: int, pinsBrutto: int, games: int, ghosts: bool}> $accumulator
     */
    private function processTeam(array &$accumulator, FixtureTeamRawData $team, FixtureTeamRawData $opponent): void
    {
        $teamId = $team->isGhostTeam ? self::GHOST_TEAM_ID : (string) $team->teamSeasonId;

        if (! isset($accumulator[$teamId])) {
            $accumulator[$teamId] = [
                'name' => $team->teamName, 'points' => 0, 'pinsNetto' => 0, 'pinsBrutto' => 0, 'games' => 0, 'ghosts' => $team->isGhostTeam,
            ];
        }

        $points = $this->points->totalPoints(
            $this->buildLineup($team),
            $this->buildLineup($opponent),
        );

        $netPins = 0;
        $grossPins = 0;
        $games = 0;
        foreach ($team->rows as $row) {
            $netPins += $row->sumNet();
            $grossPins += $row->sumGross();
            $games += $row->gameCount();
        }

        $accumulator[$teamId]['points'] += $points;
        $accumulator[$teamId]['pinsNetto'] += $netPins;
        $accumulator[$teamId]['pinsBrutto'] += $grossPins;
        $accumulator[$teamId]['games'] += $games;
    }

    /**
     * Builds the {@see PointsLineup} (gross matrix) of a team for the points
     * service (R17.2): per game the gross values of the 4 players on the
     * lanes, whichever rows they come from. Missing values (incomplete legacy
     * data) count as 0.
     */
    private function buildLineup(FixtureTeamRawData $team): PointsLineup
    {
        $places = array_fill(0, 4, [0, 0, 0]);
        foreach ([1, 2, 3] as $game) {
            $place = 0;
            foreach ($team->rows as $row) {
                $gross = $row->gross();
                if (isset($gross[$game]) && $place < 4) {
                    $places[$place++][$game - 1] = $gross[$game];
                }
            }
        }

        return new PointsLineup($places, $team->hasRealPlayer);
    }
}
