<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\PlayerProfile\PlayerProfile;
use Bowlingliga\Application\PlayerProfile\PlayerSeasonSummary;
use Bowlingliga\Domain\PlayerProfile\PlayerHistoryPoint;
use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\Translator;

/**
 * Player page (R39), public and in the admin area: key figures of the
 * selected season, season selection, handicap and average history as charts
 * with the values as a table, and the teams per season.
 *
 * Read-only, free of side effects: returns HTML; every output is escaped.
 */
final class PlayerProfileView
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
     * @param callable(int): string $seasonUrl Address of the page for a season.
     * @param string                $footer    Already escaped HTML below the page (e.g. a back link).
     */
    public function render(PlayerProfile $profile, callable $seasonUrl, string $footer = ''): string
    {
        $selected = $profile->selectedSeason();
        $kicker   = $this->text('common.gender.' . ($profile->gender === 'female' ? 'female' : 'male'));
        if ($selected !== null) {
            $kicker = $this->escape($selected->teamName) . ' · ' . $kicker;
        }

        $html = sprintf(
            '<div class="page-header"><p class="page-header__kicker">%s</p><h1>%s</h1></div>',
            $kicker,
            $this->escape($profile->name),
        );

        if ($selected === null) {
            return $html . '<section class="card"><p class="empty">' . $this->text('player.no_seasons') . '</p></section>' . $footer;
        }

        $html .= $this->seasonSelection($profile, $seasonUrl);
        $html .= $this->keyFigures($selected);

        if ($profile->history->isEmpty()) {
            $html .= '<section class="card"><p class="empty">' . $this->text('player.no_games', ['season' => $selected->seasonName]) . '</p></section>';
        } else {
            $html .= $this->charts($profile->history->points, $selected->seasonName, $profile->previousSeasonUntil);
            $html .= $this->valueTable($profile->history->points);
        }

        return $html . $this->teams($profile, $seasonUrl) . $footer;
    }

    /**
     * Season selection (R39.2): one link per season of the player, the shown
     * one marked; not needed for a single season.
     *
     * @param callable(int): string $seasonUrl
     */
    private function seasonSelection(PlayerProfile $profile, callable $seasonUrl): string
    {
        if (count($profile->seasons) < 2) {
            return '';
        }

        $links = '';
        foreach ($profile->seasons as $season) {
            $links .= sprintf(
                '<li><a class="season-picker__link" href="%s"%s>%s</a></li>',
                $this->escape($seasonUrl($season->seasonId)),
                $season->seasonId === $profile->selectedSeasonId ? ' aria-current="page"' : '',
                $this->escape($season->seasonName),
            );
        }

        return sprintf('<nav class="card" aria-label="%s"><ul class="season-picker">%s</ul></nav>', $this->text('player.season_selection'), $links);
    }

    private function keyFigures(PlayerSeasonSummary $season): string
    {
        $figures = [
            [$this->text('player.average'), $season->average === null ? '—' : $this->escape($this->format->number($season->average, 2))],
            [$this->text('common.hdc'), $season->handicap === null ? '—' : (string) $season->handicap],
            [$this->text('common.games'), (string) $season->games],
            [$this->text('player.matchdays'), (string) $season->matchdaysPlayed],
        ];

        $html = '';
        foreach ($figures as [$label, $value]) {
            $html .= '<div><dt>' . $label . '</dt><dd>' . $value . '</dd></div>';
        }

        return sprintf(
            '<section class="card" aria-labelledby="h-player-season"><h2 id="h-player-season">%s</h2><dl class="key-figures">%s</dl></section>',
            $this->escape($season->seasonName),
            $html,
        );
    }

    /**
     * Handicap as steps (it holds for a whole matchday) and the average after
     * each matchday as a line with the evening's mean as dots (R39.3, R39.4).
     *
     * @param list<PlayerHistoryPoint> $points
     * @param int|null                 $previousSeasonUntil see {@see PlayerProfile::$previousSeasonUntil}
     */
    private function charts(array $points, string $seasonName, ?int $previousSeasonUntil): string
    {
        $note = $previousSeasonUntil === null
            ? ''
            : '<p class="chart__caption">' . $this->text('player.previous_season_note', ['number' => $previousSeasonUntil]) . '</p>';

        $labels    = array_map(static fn (PlayerHistoryPoint $p): string => (string) $p->matchdayNumber, $points);
        $handicaps = array_map(static fn (PlayerHistoryPoint $p): ?float => $p->handicap === null ? null : (float) $p->handicap, $points);
        $averages  = array_map(static fn (PlayerHistoryPoint $p): ?float => $p->average, $points);
        $evenings  = array_map(static fn (PlayerHistoryPoint $p): ?float => $p->dayAverage, $points);

        $handicapChart = $this->chart->render($this->plain('player.chart_handicap', ['season' => $seasonName]), $labels, $handicaps, [], true);
        $averageChart  = $this->chart->render($this->plain('player.chart_average', ['season' => $seasonName]), $labels, $averages, $evenings);

        $handicapCard = $handicapChart === ''
            ? '<p class="empty">' . $this->text('player.no_handicap') . '</p>'
            : $handicapChart;

        return <<<HTML
            <div class="chart-grid">
                <section class="card" aria-labelledby="h-player-hdc">
                    <h2 id="h-player-hdc">{$this->text('player.handicap_history')}</h2>
                    {$handicapCard}
                    <p class="chart__caption">{$this->text('player.axis_matchday')}</p>
                </section>
                <section class="card" aria-labelledby="h-player-average">
                    <h2 id="h-player-average">{$this->text('player.average_history')}</h2>
                    {$averageChart}
                    <p class="chart__caption"><span class="chart__key chart__key--line"></span>{$this->text('player.average')} <span class="chart__key chart__key--dot"></span>{$this->text('player.evening_average')} · {$this->text('player.axis_matchday')}</p>
                    {$note}
                </section>
            </div>
            HTML;
    }

    /**
     * The values of the charts per matchday, collapsed (R39.3, R39.4).
     *
     * @param list<PlayerHistoryPoint> $points
     */
    private function valueTable(array $points): string
    {
        $rows = '';
        foreach ($points as $point) {
            $rows .= sprintf(
                '<tr><td><a href="/matchday/%d">%d</a></td><td class="text">%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
                $point->matchdayId,
                $point->matchdayNumber,
                $this->escape($this->format->date($point->date)),
                $point->handicap === null ? '—' : (string) $point->handicap,
                $point->games,
                $point->dayAverage === null ? '—' : $this->escape($this->format->number($point->dayAverage, 2)),
                $point->average === null ? '—' : $this->escape($this->format->number($point->average, 2)),
            );
        }

        return <<<HTML
            <section class="card">
                <details>
                    <summary>{$this->text('player.values')}</summary>
                    <div class="table-scroll">
                        <table class="table table--numbers">
                            <thead>
                                <tr><th scope="col">{$this->text('player.matchday')}</th><th scope="col" class="text">{$this->text('player.date')}</th><th scope="col">{$this->text('common.hdc')}</th><th scope="col">{$this->text('common.games')}</th><th scope="col">{$this->text('player.evening_average')}</th><th scope="col">{$this->text('player.average')}</th></tr>
                            </thead>
                            <tbody>{$rows}</tbody>
                        </table>
                    </div>
                </details>
            </section>
            HTML;
    }

    /**
     * Teams per season, newest first (R39.5).
     *
     * @param callable(int): string $seasonUrl
     */
    private function teams(PlayerProfile $profile, callable $seasonUrl): string
    {
        $rows = '';
        foreach ($profile->seasons as $season) {
            $rows .= sprintf(
                '<tr%s><td class="text"><a href="%s">%s</a></td><td class="text"><a href="/team/%d">%s</a></td><td class="only-wide">%d</td><td>%d</td><td>%s</td><td>%s</td></tr>',
                $season->seasonId === $profile->selectedSeasonId ? ' class="is-selected"' : '',
                $this->escape($seasonUrl($season->seasonId)),
                $this->escape($season->seasonName),
                $season->teamSeasonId,
                $this->escape($season->teamName),
                $season->matchdaysPlayed,
                $season->games,
                $season->average === null ? '—' : $this->escape($this->format->number($season->average, 2)),
                $season->handicap === null ? '—' : (string) $season->handicap,
            );
        }

        return <<<HTML
            <section class="card" aria-labelledby="h-player-teams">
                <h2 id="h-player-teams">{$this->text('player.teams')}</h2>
                <div class="table-scroll">
                    <table class="table table--numbers">
                        <thead>
                            <tr><th scope="col" class="text">{$this->text('player.season')}</th><th scope="col" class="text">{$this->text('common.team')}</th><th scope="col" class="only-wide">{$this->text('player.matchdays')}</th><th scope="col">{$this->text('common.games')}</th><th scope="col">{$this->text('player.average')}</th><th scope="col">{$this->text('common.hdc')}</th></tr>
                        </thead>
                        <tbody>{$rows}</tbody>
                    </table>
                </div>
            </section>
            HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
