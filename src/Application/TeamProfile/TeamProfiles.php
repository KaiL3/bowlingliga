<?php

declare(strict_types=1);

namespace Bowlingliga\Application\TeamProfile;

use Bowlingliga\Application\Aggregation\FixtureRawData;
use Bowlingliga\Application\Aggregation\FixtureTeamRawData;
use Bowlingliga\Application\Aggregation\TableAggregator;
use Bowlingliga\Application\Aggregation\TableRawDataReader;
use Bowlingliga\Domain\Points\PointsRules;
use Bowlingliga\Domain\Points\PointsService;
use Bowlingliga\Domain\Table\Table;
use Bowlingliga\Domain\Table\TableRow;

/**
 * Reading use case of the team page (R41): builds the results per matchday
 * and the standing after each matchday from the same raw data and rules as
 * the league table (R16, R17, R35), so both always agree.
 */
final class TeamProfiles
{
    public function __construct(
        private readonly TeamProfileReader $reader,
        private readonly TableRawDataReader $raw,
        private readonly TableAggregator $tables,
        private readonly PointsService $points = new PointsService(),
    ) {
    }

    /** The team page, or null for an unknown team season or the ghost team (R41.1). */
    public function profile(int $teamSeasonId): ?TeamProfile
    {
        $team = $this->reader->team($teamSeasonId);
        if ($team === null) {
            return null;
        }

        $seasonId = $team['season_id'];
        $rules    = $this->raw->pointsRulesForSeason($seasonId);
        $preset   = $this->raw->findTeamsForSeason($seasonId);

        /** @var array<int, list<FixtureRawData>> $byMatchday */
        $byMatchday = [];
        foreach ($this->raw->findFixturesForSeason($seasonId) as $fixture) {
            $byMatchday[$fixture->matchdayId][] = $fixture;
        }

        $counted   = [];
        $matchdays = [];
        $table     = null;
        $sums      = ['net' => 0, 'games' => 0, 'playedNet' => 0, 'playedGames' => 0];
        foreach ($this->reader->matchdays($seasonId) as $matchday) {
            if ($matchday['cancelled']) {
                $matchdays[] = new TeamMatchday($matchday['id'], $matchday['number'], $matchday['date'], true);
                continue;
            }
            $fixtures = $byMatchday[$matchday['id']] ?? [];
            if (! $matchday['played'] || $fixtures === []) {
                continue;
            }

            // Standing after this matchday: the league table of all matchdays so far (R41.4).
            array_push($counted, ...$fixtures);
            $table    = $this->tables->tableFrom($counted, $preset, $rules);
            $standing = self::rowOf($table, $teamSeasonId);
            $result   = $this->result($fixtures, $teamSeasonId, $rules);

            if ($result !== null) {
                $sums['net']         += $result->netPins;
                $sums['games']       += $result->games;
                $sums['playedNet']   += $result->playedNetPins;
                $sums['playedGames'] += $result->playedGames;
            }

            $matchdays[] = new TeamMatchday(
                $matchday['id'],
                $matchday['number'],
                $matchday['date'],
                false,
                $result,
                $standing?->rank,
                $standing?->points,
                $table->rows === [] ? null : $table->rows[0]->points,
                $sums['games'] > 0 ? $sums['net'] / $sums['games'] : null,
                $sums['playedGames'] > 0 ? $sums['playedNet'] / $sums['playedGames'] : null,
            );
        }

        $table ??= $this->tables->tableFrom([], $preset, $rules);

        return new TeamProfile(
            $teamSeasonId,
            $team['team_id'],
            $seasonId,
            $team['season_name'],
            $team['team_name'],
            $this->reader->seasons($team['team_id']),
            $matchdays,
            $counted === [] ? null : self::rowOf($table, $teamSeasonId)?->rank,
            count($table->rows),
        );
    }

    /**
     * The team's result on a matchday, null if it had no fixture there.
     *
     * @param list<FixtureRawData> $fixtures
     */
    private function result(array $fixtures, int $teamSeasonId, PointsRules $rules): ?TeamFixtureResult
    {
        foreach ($fixtures as $fixture) {
            if (! $fixture->home->isGhostTeam && $fixture->home->teamSeasonId === $teamSeasonId) {
                return $this->resultOf($fixture->home, $fixture->away, $rules);
            }
            if (! $fixture->away->isGhostTeam && $fixture->away->teamSeasonId === $teamSeasonId) {
                return $this->resultOf($fixture->away, $fixture->home, $rules);
            }
        }

        return null;
    }

    private function resultOf(FixtureTeamRawData $team, FixtureTeamRawData $opponent, PointsRules $rules): TeamFixtureResult
    {
        $own   = $this->tables->buildLineup($team);
        $other = $this->tables->buildLineup($opponent);

        $outcomes = [];
        $gross    = [];
        foreach ([1, 2, 3] as $game) {
            $mine       = $this->points->teamGrossGame($own, $game);
            $theirs     = $this->points->teamGrossGame($other, $game);
            $gross[]    = $mine;
            $outcomes[] = $mine > $theirs ? TeamFixtureResult::WIN : ($mine === $theirs ? TeamFixtureResult::DRAW : TeamFixtureResult::LOSS);
        }

        // Without a real player the evening is lost with 0 points (R21.6, R27.3).
        $total = $this->points->totalPoints($own, $other, $rules->base, $rules->bonus);
        $bonus = $own->hasRealPlayer() ? $this->points->bonusPoints($this->points->grossTotal($own), $rules->bonus) : 0;

        $net = $grossPins = $games = $playedNet = $playedGames = 0;
        foreach ($team->rows as $row) {
            $net         += $row->sumNet();
            $grossPins   += $row->sumGross();
            $games       += $row->gameCount();
            $playedNet   += array_sum($row->playedNet());
            $playedGames += count($row->playedNet());
        }

        return new TeamFixtureResult(
            $opponent->isGhostTeam ? null : $opponent->teamSeasonId,
            $opponent->teamName,
            $outcomes,
            $gross,
            $total - $bonus,
            $bonus,
            $this->points->totalPoints($other, $own, $rules->base, $rules->bonus),
            $net,
            $grossPins,
            $games,
            $playedNet,
            $playedGames,
        );
    }

    private static function rowOf(Table $table, int $teamSeasonId): ?TableRow
    {
        foreach ($table->rows as $row) {
            if (! $row->isGhostTeam && $row->teamId === (string) $teamSeasonId) {
                return $row;
            }
        }

        return null;
    }
}
