<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\TeamProfile\TeamFixtureResult;
use Bowlingliga\Application\TeamProfile\TeamMatchday;
use Bowlingliga\Application\TeamProfile\TeamProfile;
use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\Translator;

/**
 * Team page (R41), public and in the admin area: key figures of the season,
 * season selection, league position, points and team average as charts,
 * the games of the season and – passed in by the caller, since it depends on
 * the public player data (R40) – the average list.
 *
 * Read-only, free of side effects: returns HTML; every output is escaped.
 */
final class TeamProfileView
{
    use ViewTexts;

    private readonly Formatter $format;

    public function __construct(
        private readonly Translator $t = new Translator(),
        private readonly SvgLineChart $chart = new SvgLineChart(),
    ) {
        $this->format = new Formatter($t);
    }

    /**
     * @param callable(int): string $teamUrl     Address of a team page by team season id.
     * @param string                $averageList Already escaped HTML of the average list card.
     * @param string                $footer      Already escaped HTML below the page (e.g. a back link).
     */
    public function render(TeamProfile $profile, callable $teamUrl, string $averageList = '', string $footer = ''): string
    {
        $html = sprintf(
            '<div class="page-header"><p class="page-header__kicker">%s</p><h1>%s</h1></div>',
            $this->escape($profile->seasonName),
            $this->escape($profile->teamName),
        );

        $html .= $this->seasonSelection($profile, $teamUrl);
        $html .= $this->keyFigures($profile);

        if ($profile->results() === []) {
            $html .= '<section class="card"><p class="empty">' . $this->text('team.no_results', ['season' => $profile->seasonName]) . '</p></section>';
        } else {
            $html .= $this->charts($profile);
            $html .= $this->games($profile, $teamUrl);
        }

        return $html . $averageList . $footer;
    }

    /**
     * Season selection (R41.2): one link per season of the same team, the
     * shown one marked; not needed for a single season.
     *
     * @param callable(int): string $teamUrl
     */
    private function seasonSelection(TeamProfile $profile, callable $teamUrl): string
    {
        if (count($profile->seasons) < 2) {
            return '';
        }

        $links = '';
        foreach (array_reverse($profile->seasons) as $season) {
            $links .= sprintf(
                '<li><a class="season-picker__link" href="%s"%s>%s</a></li>',
                $this->escape($teamUrl($season['team_season_id'])),
                $season['team_season_id'] === $profile->teamSeasonId ? ' aria-current="page"' : '',
                $this->escape($season['season_name']),
            );
        }

        return sprintf('<nav class="card" aria-label="%s"><ul class="season-picker">%s</ul></nav>', $this->text('team.season_selection'), $links);
    }

    /** Key figures of the season (R41.3). */
    private function keyFigures(TeamProfile $profile): string
    {
        $average = fn (?float $value): string => $value === null ? '—' : $this->escape($this->format->number($value, 2));
        $whole   = static fn (?int $value): string => $value === null ? '—' : (string) $value;

        $figures = [
            [$this->text('team.rank'), $profile->rank === null ? '—' : $this->text('team.rank_of', ['rank' => $profile->rank, 'count' => $profile->teamCount])],
            [$this->text('common.points'), $profile->points() . '<small>' . $this->text('team.bonus') . ' ' . $profile->bonusPoints() . '</small>'],
            [$this->text('team.average_net'), $average($profile->averageNet())],
            [$this->text('team.average_played'), $average($profile->playedAverageNet())],
            [$this->text('team.average_gross'), $average($profile->averageGross())],
            [$this->text('team.pins_gross'), (string) $profile->grossPins()],
            [$this->text('team.record'), sprintf(
                '%d · %d · %d',
                $profile->count(TeamFixtureResult::WIN),
                $profile->count(TeamFixtureResult::DRAW),
                $profile->count(TeamFixtureResult::LOSS),
            )],
            [$this->text('team.best_game'), $whole($profile->bestGame())],
            [$this->text('team.best_series'), $whole($profile->bestSeries())],
            [$this->text('team.blind_games'), (string) $profile->blindGames()],
            [$this->text('player.matchdays'), (string) count($profile->results())],
        ];

        $html = '';
        foreach ($figures as [$label, $value]) {
            $html .= '<div><dt>' . $label . '</dt><dd>' . $value . '</dd></div>';
        }

        return sprintf(
            '<section class="card" aria-labelledby="h-team-season"><h2 id="h-team-season">%s</h2><dl class="key-figures">%s</dl></section>',
            $this->escape($profile->seasonName),
            $html,
        );
    }

    /**
     * League position (rank 1 on top), points with the leader's points, and
     * the team average with and without blind games plus the evening as dots
     * (R41.4, R41.5).
     */
    private function charts(TeamProfile $profile): string
    {
        $matchdays = $profile->playedMatchdays();
        $labels    = array_map(static fn (TeamMatchday $m): string => (string) $m->matchdayNumber, $matchdays);
        $season    = $profile->seasonName;

        $count = max(2, $profile->teamCount);
        $step  = $count > 8 ? 2 : 1;
        $rank  = $this->chart->render(
            $this->plain('team.chart_rank', ['season' => $season]),
            $labels,
            array_map(static fn (TeamMatchday $m): ?float => $m->rank === null ? null : (float) $m->rank, $matchdays),
            invert: true,
            range: [1.0, (float) (1 + $step * (int) ceil(($count - 1) / $step)), (float) $step],
        );
        $points = $this->chart->render(
            $this->plain('team.chart_points', ['season' => $season]),
            $labels,
            array_map(static fn (TeamMatchday $m): ?float => $m->points === null ? null : (float) $m->points, $matchdays),
            second: array_map(static fn (TeamMatchday $m): ?float => $m->leaderPoints === null ? null : (float) $m->leaderPoints, $matchdays),
        );
        $average = $this->chart->render(
            $this->plain('team.chart_average', ['season' => $season]),
            $labels,
            array_map(static fn (TeamMatchday $m): ?float => $m->averageNet, $matchdays),
            array_map(static fn (TeamMatchday $m): ?float => $m->result?->eveningAverage(), $matchdays),
            second: array_map(static fn (TeamMatchday $m): ?float => $m->playedAverageNet, $matchdays),
        );

        $axis = $this->text('player.axis_matchday');

        return <<<HTML
            <div class="chart-grid">
                <section class="card" aria-labelledby="h-team-rank">
                    <h2 id="h-team-rank">{$this->text('team.rank_history')}</h2>
                    {$rank}
                    <p class="chart__caption">{$axis}</p>
                </section>
                <section class="card" aria-labelledby="h-team-points">
                    <h2 id="h-team-points">{$this->text('team.points_history')}</h2>
                    {$points}
                    <p class="chart__caption"><span class="chart__key chart__key--line"></span>{$this->text('team.team')} <span class="chart__key chart__key--second"></span>{$this->text('team.leader')} · {$axis}</p>
                </section>
                <section class="card" aria-labelledby="h-team-average">
                    <h2 id="h-team-average">{$this->text('team.average_history')}</h2>
                    {$average}
                    <p class="chart__caption"><span class="chart__key chart__key--line"></span>{$this->text('team.with_blind')} <span class="chart__key chart__key--second"></span>{$this->text('team.without_blind')} <span class="chart__key chart__key--dot"></span>{$this->text('team.evening')} · {$axis}</p>
                </section>
            </div>
            HTML;
    }

    /**
     * The games of the season per matchday with the standing after it
     * (R41.6); cancelled matchdays are listed but do not count.
     *
     * @param callable(int): string $teamUrl
     */
    private function games(TeamProfile $profile, callable $teamUrl): string
    {
        $rows = '';
        foreach ($profile->matchdays as $matchday) {
            $number = sprintf(
                '<td><a href="/matchday/%d">%d</a></td><td class="text only-wide">%s</td>',
                $matchday->matchdayId,
                $matchday->matchdayNumber,
                $this->escape($this->format->date($matchday->date)),
            );
            $result = $matchday->result;
            if ($result === null) {
                $rows .= sprintf(
                    '<tr class="table__row--muted">%s<td class="text">%s</td><td></td><td></td><td class="only-wide"></td><td class="only-wide"></td><td>%s</td></tr>',
                    $number,
                    $this->text($matchday->cancelled ? 'team.cancelled' : 'team.bye'),
                    $matchday->rank === null ? '' : (string) $matchday->rank,
                );
                continue;
            }

            $opponent = $result->opponentTeamSeasonId === null
                ? $this->escape($result->opponentName)
                : sprintf('<a href="%s">%s</a>', $this->escape($teamUrl($result->opponentTeamSeasonId)), $this->escape($result->opponentName));

            $rows .= sprintf(
                '<tr>%s<td class="text">%s</td><td class="text">%s</td><td>%d<span class="only-wide"> : %d</span></td><td class="only-wide">%d</td><td class="only-wide">%d</td><td>%s</td></tr>',
                $number,
                $opponent,
                $this->outcomes($result),
                $result->totalPoints(),
                $result->opponentPoints,
                $result->bonusPoints,
                $result->grossPins,
                $matchday->rank === null ? '' : (string) $matchday->rank,
            );
        }

        return <<<HTML
            <section class="card" aria-labelledby="h-team-games">
                <h2 id="h-team-games">{$this->text('team.results')}</h2>
                <div class="table-scroll">
                    <table class="table table--numbers">
                        <thead>
                            <tr><th scope="col"><abbr title="{$this->text('player.matchday')}">{$this->text('team.matchday_short')}</abbr></th><th scope="col" class="text only-wide">{$this->text('player.date')}</th><th scope="col" class="text">{$this->text('team.opponent')}</th><th scope="col" class="text">{$this->text('common.games')}</th><th scope="col">{$this->text('common.points')}</th><th scope="col" class="only-wide">{$this->text('team.bonus_short')}</th><th scope="col" class="only-wide">{$this->text('team.pins_gross')}</th><th scope="col">{$this->text('team.rank')}</th></tr>
                        </thead>
                        <tbody>{$rows}</tbody>
                    </table>
                </div>
            </section>
            HTML;
    }

    /** Win, draw or loss of the three games as short letters with their meaning. */
    private function outcomes(TeamFixtureResult $result): string
    {
        $html = '';
        foreach ($result->outcomes as $outcome) {
            $html .= sprintf(
                '<abbr class="outcome outcome--%s" title="%s">%s</abbr>',
                $outcome,
                $this->text('team.outcome.' . $outcome),
                $this->text('team.outcome_short.' . $outcome),
            );
        }

        return '<span class="outcomes">' . $html . '</span>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
