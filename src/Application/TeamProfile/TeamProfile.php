<?php

declare(strict_types=1);

namespace Bowlingliga\Application\TeamProfile;

/**
 * The team page of one team in one season (R41): seasons of the team, the
 * matchdays with results and standing, and the key figures summed from them.
 *
 * Pure, immutable read model.
 */
final class TeamProfile
{
    /**
     * @param list<array{team_season_id: int, season_id: int, season_name: string}> $seasons   oldest first (R41.2)
     * @param list<TeamMatchday>                                                    $matchdays by number (R41.6)
     * @param int|null                                                              $rank      current rank, null before the first played matchday
     * @param int                                                                   $teamCount rows of the league table, ghost team included
     */
    public function __construct(
        public readonly int $teamSeasonId,
        public readonly int $teamId,
        public readonly int $seasonId,
        public readonly string $seasonName,
        public readonly string $teamName,
        public readonly array $seasons,
        public readonly array $matchdays,
        public readonly ?int $rank,
        public readonly int $teamCount,
    ) {
    }

    /**
     * The fixtures the team played, in matchday order.
     *
     * @return list<TeamFixtureResult>
     */
    public function results(): array
    {
        $results = [];
        foreach ($this->matchdays as $matchday) {
            if ($matchday->result !== null) {
                $results[] = $matchday->result;
            }
        }

        return $results;
    }

    /**
     * Matchdays that count for the history: played by the league (R41.4).
     *
     * @return list<TeamMatchday>
     */
    public function playedMatchdays(): array
    {
        return array_values(array_filter($this->matchdays, static fn (TeamMatchday $m): bool => $m->isPlayed()));
    }

    public function points(): int
    {
        return $this->sum(static fn (TeamFixtureResult $r): int => $r->totalPoints());
    }

    public function bonusPoints(): int
    {
        return $this->sum(static fn (TeamFixtureResult $r): int => $r->bonusPoints);
    }

    public function netPins(): int
    {
        return $this->sum(static fn (TeamFixtureResult $r): int => $r->netPins);
    }

    public function grossPins(): int
    {
        return $this->sum(static fn (TeamFixtureResult $r): int => $r->grossPins);
    }

    public function games(): int
    {
        return $this->sum(static fn (TeamFixtureResult $r): int => $r->games);
    }

    public function blindGames(): int
    {
        return $this->games() - $this->sum(static fn (TeamFixtureResult $r): int => $r->playedGames);
    }

    /** Net average per game like the league table (R16.1), blind games included. */
    public function averageNet(): ?float
    {
        $games = $this->games();

        return $games > 0 ? $this->netPins() / $games : null;
    }

    /** Gross average per game like the league table (R16.1). */
    public function averageGross(): ?float
    {
        $games = $this->games();

        return $games > 0 ? $this->grossPins() / $games : null;
    }

    /**
     * Net average of the games actually played: blind games leave out both
     * their pins and their count, so they do not pull the average (R41.5).
     */
    public function playedAverageNet(): ?float
    {
        $games = $this->sum(static fn (TeamFixtureResult $r): int => $r->playedGames);

        return $games > 0 ? $this->sum(static fn (TeamFixtureResult $r): int => $r->playedNetPins) / $games : null;
    }

    /** Single games won, drawn or lost over the season (R17.3). */
    public function count(string $outcome): int
    {
        return $this->sum(static fn (TeamFixtureResult $r): int => $r->count($outcome));
    }

    /** Best team game (gross), null without results. */
    public function bestGame(): ?int
    {
        $results = $this->results();

        return $results === [] ? null : max(array_map(static fn (TeamFixtureResult $r): int => $r->bestGame(), $results));
    }

    /** Best evening (gross total of the three games, R17.5), null without results. */
    public function bestSeries(): ?int
    {
        $results = $this->results();

        return $results === [] ? null : max(array_map(static fn (TeamFixtureResult $r): int => array_sum($r->teamGross), $results));
    }

    /** @param callable(TeamFixtureResult): int $value */
    private function sum(callable $value): int
    {
        return array_sum(array_map($value, $this->results()));
    }
}
