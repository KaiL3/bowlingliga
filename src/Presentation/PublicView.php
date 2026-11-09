<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\League\LeagueSettings;
use Bowlingliga\Application\PublicSite\MatchdayDetail;
use Bowlingliga\Application\PublicSite\MatchdayFixture;
use Bowlingliga\Application\PublicSite\ScoreSheetTeamView;
use Bowlingliga\Application\PublicSite\MatchdayListEntry;
use Bowlingliga\Application\PublicSite\UpcomingMatchday;
use Bowlingliga\Application\PublicSite\TeamAverageList;
use Bowlingliga\Domain\Honours\Honours;
use Bowlingliga\Domain\Honours\HonourCategory;
use Bowlingliga\Domain\Honours\Gender;
use Bowlingliga\Domain\AverageList\AverageList;
use Bowlingliga\Domain\Table\Table;
use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\Translator;

/**
 * View rendering of the public (reading) views (R1, R2, R13.8/10, R14, R15,
 * R16, R33.7).
 *
 * Generates context-aware escaped HTML, which the {@see Layout} renderer
 * embeds into a complete, responsive page. The look (style "Lane" or
 * "Bento", light/dark) is determined solely by the stylesheet via CSS
 * variables; the structure is the same for all appearances:
 *
 * - content sits in cards (`.card`), key figures in a result block
 *   (`.result`),
 * - wide tables sit in `.table-scroll` (R36.4); columns with `.only-wide`
 *   are hidden by the stylesheet on narrow screens, which show the extra line
 *   `.table__extra` below the team name instead,
 * - on narrow screens the stylesheet turns the score sheet into one card per
 *   player.
 *
 * Texts come from the catalogs (`public.*`, `common.*`) in the visitor's
 * language, dates and averages from the {@see Formatter}.
 *
 * All views are read-only and require no login (R1.4, R15.2). Free of side
 * effects: returns HTML strings.
 */
final class PublicView
{
    private readonly Formatter $format;

    /**
     * @param string $leagueName Heading of the home page (R38.4).
     */
    public function __construct(
        private readonly ScoreSheetCalculator $calculator = new ScoreSheetCalculator(),
        private readonly Translator $t = new Translator(),
        private readonly string $leagueName = LeagueSettings::DEFAULT_NAME,
    ) {
        $this->format = new Formatter($t);
    }

    /**
     * Renders the home page: last played matchday, league table of the current
     * season, posts and season selection (R33.7–R33.9).
     *
     * @param string                                    $messages already rendered posts ({@see CmsView})
     * @param list<array{id: int|string, name: string}> $seasons  all seasons, ascending
     */
    public function renderHomePage(
        string $messages,
        ?MatchdayDetail $lastMatchday,
        ?Table $table,
        array $seasons,
        ?string $seasonName,
        ?UpcomingMatchday $next = null,
        ?int $seasonId = null,
    ): string {
        $subtitle = $seasonName !== null
            ? sprintf('<p class="page-header__kicker">%s</p>', $this->escape($seasonName))
            : '';

        $matchday = $lastMatchday !== null
            ? $this->renderLastMatchday($lastMatchday)
            : sprintf('<section class="card"><h2>%s</h2><p class="empty">%s</p></section>', $this->text('public.last_matchday'), $this->text('public.no_matchday_played'));

        $league = $table !== null && $table->rows !== []
            ? '<section class="card"><div class="card__header"><h2>' . $this->text('public.league_table') . '</h2></div>' . $this->renderTableBody($table) . '</section>'
            : '';

        // R14.8: link to the next matchday with its fixtures and lanes.
        $upcoming = $next !== null
            ? sprintf(
                '<section class="card"><div class="card__header"><h2>%s</h2><span class="card__sub">%s</span></div>'
                . '<p><a href="/matchdays%s">%s</a></p></section>',
                $this->text('public.next_matchday'),
                $this->escape($this->format->dateLong($next->date)),
                $seasonId !== null ? '?season=' . $seasonId : '',
                $this->text('public.next_matchday_link', ['number' => $next->number]),
            )
            : '';

        return <<<HTML
            <div class="page-header">{$subtitle}<h1>{$this->escape($this->leagueName)}</h1></div>
            {$upcoming}
            {$matchday}
            {$league}
            {$messages}
            {$this->renderSeasonSelection($seasons)}
            HTML;
    }

    /**
     * Renders a season's matchday list (R14.2–R14.7, R1.3).
     *
     * Played matchdays are shown as link to the detail view (R14.4); those not
     * played yet as a non-clickable entry with tooltip (R14.5, R14.6) and the
     * marker "without results" (R1.3).
     *
     * With a next matchday the page has two tabs: the next matchday with its
     * fixtures and lanes (R14.8) and all matchdays.
     *
     * @param list<MatchdayListEntry> $entries sorted chronologically (R14.2)
     */
    public function renderMatchdayList(array $entries, ?UpcomingMatchday $next = null): string
    {
        $title = '<h1>' . $this->text('public.matchdays') . '</h1>';
        if ($entries === []) {
            // R14.7: no matchdays exist.
            return $title . '<p class="empty">' . $this->text('public.no_matchdays') . '</p>';
        }

        $rows = '';
        foreach ($entries as $entry) {
            $rows .= $this->renderMatchdayEntry($entry);
        }

        $list = '<ol class="matchday-list">' . $rows . '</ol>';
        if ($next === null) {
            return $title . $list;
        }

        // Same tab component as the matchday view: without JavaScript both
        // panels are visible one below the other.
        return <<<HTML
            {$title}
            <div class="tabs" data-tabs>
                <div class="tabs__bar" role="tablist" aria-label="{$this->text('public.matchdays')}">
                    <button type="button" class="tabs__tab tabs__tab--active" id="matchdays-tab-next" role="tab" aria-controls="matchdays-panel-next" aria-selected="true">{$this->text('public.next_matchday')}</button>
                    <button type="button" class="tabs__tab" id="matchdays-tab-all" role="tab" aria-controls="matchdays-panel-all" aria-selected="false">{$this->text('public.all_matchdays')}</button>
                </div>
                <div class="tabs__panels">
                    <section class="tabs__panel" id="matchdays-panel-next" role="tabpanel" aria-labelledby="matchdays-tab-next">{$this->renderUpcoming($next)}</section>
                    <section class="tabs__panel" id="matchdays-panel-all" role="tabpanel" aria-labelledby="matchdays-tab-all">{$list}</section>
                </div>
            </div>
            <script src="/assets/js/tabs.js" defer></script>
            HTML;
    }

    /**
     * The next matchday: fixtures sorted by lane pair, or why they are not
     * fixed yet (positionday, lot draw) (R14.8).
     */
    private function renderUpcoming(UpcomingMatchday $next): string
    {
        $items = '';
        foreach ($next->fixtures as $fixture) {
            $items .= sprintf(
                '<tr><td class="table__lanes">%s</td><td>%s</td><td class="table__lanes">%s</td><td>%s</td></tr>',
                $fixture['home_lane'] ?? '–',
                $this->escape($fixture['home']),
                $fixture['away_lane'] ?? '–',
                $this->escape($fixture['away']),
            );
        }

        $lane = $this->text('common.lane');
        $team = $this->text('common.team');
        $content = match (true) {
            $items !== '' => "<table class=\"table table--lanes\"><thead><tr><th scope=\"col\">{$lane}</th><th scope=\"col\">{$team}</th><th scope=\"col\">{$lane}</th><th scope=\"col\">{$team}</th></tr></thead><tbody>" . $items . '</tbody></table>',
            $next->type === UpcomingMatchday::TYPE_LOT_DRAW => '<p class="empty">' . $this->text('public.upcoming_lot_draw') . '</p>',
            $next->type === UpcomingMatchday::TYPE_POSITIONDAY => '<p class="empty">' . $this->text('public.upcoming_positionday') . '</p>',
            default => '<p class="empty">' . $this->text('public.upcoming_open') . '</p>',
        };

        return sprintf(
            '<section class="card"><div class="card__header"><h2>%s</h2><span class="card__sub">%s</span></div>%s</section>',
            $this->text('common.matchday_number', ['number' => $next->number]),
            $this->escape($this->format->dateLong($next->date)),
            $content,
        );
    }

    /**
     * Renders the detail view of a played matchday (R15.1, R2.1, R15.3,
     * R15.4).
     *
     * The fixtures of the matchday are shown as tabs, named after the lane
     * pair; the tab content shows the result, the digital score sheet of both
     * teams and their average lists. The matchday table applies to the whole
     * matchday and appears once below the tabs (R15.3).
     *
     * The tabs are fully usable without JavaScript (all panels visible); the
     * locally included script `/assets/js/tabs.js` hides the panels not
     * selected when JavaScript is active.
     *
     * Without public player data a tab only shows the result with the team
     * sums per game (R40.2).
     */
    public function renderMatchdayDetail(MatchdayDetail $detail, bool $withPlayers = true): string
    {
        $tab = '';
        $panels = '';
        foreach ($detail->fixtures as $index => $fixture) {
            $tab .= $this->renderTab($fixture, $index);
            $panels .= $withPlayers
                ? $this->renderTabContent($fixture, $index, $detail->seasonId)
                : $this->renderTeamTabContent($fixture, $index);
        }

        $tabs = <<<HTML
            <div class="tabs" data-tabs>
                <div class="tabs__bar" role="tablist" aria-label="{$this->text('public.fixtures_of_matchday')}">
                    {$tab}
                </div>
                <div class="tabs__panels">
                    {$panels}
                </div>
            </div>
            HTML;

        // One overlay for the original score sheets of all fixtures (R15.6).
        $lightbox = (new Lightbox($this->t))->dialog();

        $matchdayTable = '<section class="card"><h2>' . $this->text('public.day_table') . '</h2>'
            . $this->renderTableBody($detail->matchdayTable)
            . '</section>';

        return <<<HTML
            <div class="page-header">
                <p class="page-header__kicker">{$this->escape($this->format->dateLong($detail->date))}</p>
                <h1>{$this->text('common.matchday_number', ['number' => $detail->number])}</h1>
            </div>
            {$tabs}
            {$matchdayTable}
            {$lightbox}
            <script src="/assets/js/tabs.js" defer></script>
            HTML;
    }

    /**
     * Renders a season's league table (R16).
     */
    public function renderLeagueTable(Table $table): string
    {
        return '<h1>' . $this->text('public.league_table') . '</h1><section class="card">' . $this->renderTableBody($table) . '</section>';
    }

    /**
     * Team page: a team's current average list, reachable via the team name
     * in the league and matchday table (R2).
     */
    public function renderTeamAverageList(TeamAverageList $team, bool $withPlayers = true): string
    {
        $backLink = sprintf('<p><a class="more" href="/table?season=%d">%s</a></p>', $team->seasonId, $this->text('public.back_to_table'));
        $standing = $team->standingMatchdayCounter !== null
            ? $this->t->t('public.standing_after', [
                'number' => $team->standingMatchdayCounter,
                'date'   => $this->format->dateLong((string) $team->standingDate),
            ])
            : $this->t->t('public.no_matchday_yet');

        $blank = $team->standingMatchdayCounter !== null
            ? $this->t->t('public.team_no_averages')
            : $this->t->t('public.no_matchday_played');

        $header = sprintf(
            '<div class="page-header"><p class="page-header__kicker">%s</p><h1>%s</h1></div>',
            $this->escape($standing),
            $this->escape($team->teamLabel),
        );

        if (! $withPlayers) {
            // R40.2: the average list consists of players only.
            return $header . $this->playersHiddenCard() . $backLink;
        }

        return $header
            . $this->renderAverageList($this->escape($team->teamLabel), $team->averageList, $this->text('public.average_list'), $blank, $team->seasonId)
            . $backLink;
    }

    /**
     * Renders a season's honours if they are publicly visible (R13.8, R13.10).
     *
     * Before the end date the honours are not shown to an anonymous visitor; a
     * note appears instead (R13.8). Without public player data a note
     * replaces them as well, since every honour names a player (R40.2).
     */
    public function renderHonours(Honours $honours, bool $withPlayers = true): string
    {
        $title = '<h1>' . $this->text('public.honours') . '</h1>';
        if (! $withPlayers) {
            return $title . '<section class="card"><p class="empty">' . $this->text('public.honours_not_public') . '</p></section>';
        }
        if (! $honours->isPubliclyVisible()) {
            // R13.8: no public display before the end date.
            return $title . '<section class="card"><p class="empty">' . $this->text('public.honours_later') . '</p></section>';
        }

        return $title . $this->renderHonourCategories($honours);
    }

    /**
     * Renders all honour categories per gender without a visibility check. The
     * check is up to the caller: publicly {@see renderHonours()}, in the admin
     * area the honours preview (R13.9, R28.14).
     */
    public function renderHonourCategories(Honours $honours): string
    {
        $cards = '';
        foreach (HonourCategory::cases() as $category) {
            foreach ([Gender::MALE, Gender::FEMALE] as $gender) {
                $cards .= $this->renderHonourCategory($honours, $category, $gender);
            }
        }

        return $cards === '' ? '' : '<div class="grid grid--honours">' . $cards . '</div>';
    }

    // ---------------------------------------------------------------------
    // Home page
    // ---------------------------------------------------------------------

    /**
     * Result block of the last played matchday: one result per fixture, with a
     * link to the detail view.
     */
    private function renderLastMatchday(MatchdayDetail $detail): string
    {
        $results = '';
        foreach ($detail->fixtures as $fixture) {
            $results .= $this->renderResult($fixture, false);
        }

        return sprintf(
            '<section class="start-matchday" aria-label="%s">'
            . '<div class="card__header"><h2>%s <span class="card__sub">%s</span></h2>'
            . '<a class="more" href="/matchday/%d">%s</a></div>%s</section>',
            $this->text('public.last_matchday'),
            $this->text('common.matchday_number', ['number' => $detail->number]),
            $this->escape($this->format->dateLong($detail->date)),
            $detail->matchdayId,
            $this->text('public.to_matchday'),
            $results,
        );
    }

    /**
     * @param list<array{id: int|string, name: string}> $seasons
     */
    private function renderSeasonSelection(array $seasons): string
    {
        if ($seasons === []) {
            return '<section class="card"><p class="empty">' . $this->text('public.no_seasons') . '</p></section>';
        }

        $points = '';
        foreach (array_reverse($seasons) as $season) {
            $points .= sprintf(
                '<li><a class="season-picker__link" href="/matchdays?season=%d">%s</a></li>',
                (int) $season['id'],
                $this->escape((string) $season['name']),
            );
        }

        return '<section class="card"><h2>' . $this->text('public.seasons') . '</h2><ul class="season-picker">' . $points . '</ul></section>';
    }

    // ---------------------------------------------------------------------
    // Matchday detail
    // ---------------------------------------------------------------------

    /**
     * Renders a tab button (role="tab") for a fixture.
     */
    private function renderTab(MatchdayFixture $fixture, int $index): string
    {
        $active = $index === 0;

        return sprintf(
            '<button type="button" class="tabs__tab%s" id="fixture-tab-%d" role="tab" aria-controls="fixture-panel-%d" aria-selected="%s">'
            . '%s<span class="tabs__teams">%s – %s</span></button>',
            $active ? ' tabs__tab--active' : '',
            $index,
            $index,
            $active ? 'true' : 'false',
            $fixture->lanes !== null
                ? $this->text('common.lanes', ['lanes' => $fixture->lanes])
                : $this->text('public.fixture_versus', ['home' => $fixture->home->teamName ?? '—', 'away' => $fixture->away->teamName ?? '—']),
            $this->escape($fixture->home->teamName ?? '—'),
            $this->escape($fixture->away->teamName ?? '—'),
        );
    }

    /**
     * Renders the content of a tab: result, digital score sheet of both teams,
     * link to the original score sheet and both average lists.
     */
    private function renderTabContent(MatchdayFixture $fixture, int $index, ?int $seasonId = null): string
    {
        $homeName = $this->escape($fixture->home->teamName ?? '—');
        $awayName = $this->escape($fixture->away->teamName ?? '—');

        $original = $fixture->hasOriginalScoreSheet()
            // R15.6: an image opens in an overlay, a PDF in a new window.
            ? '<p>' . (new Lightbox($this->t))->link(
                (int) $fixture->originalScoreSheetId,
                $fixture->originalScoreSheetMimeType ?? 'image/jpeg',
                $this->t->t('public.original_score_sheet'),
                ($fixture->home->teamName ?? '—') . ' – ' . ($fixture->away->teamName ?? '—'),
                'action-button action-button--secondary',
            ) . '</p>'
            // R15.4: no original score sheet exists.
            : '<p class="notice">' . $this->text('public.no_original_score_sheet') . '</p>';

        return <<<HTML
            <section class="tabs__panel" id="fixture-panel-{$index}" role="tabpanel" aria-labelledby="fixture-tab-{$index}">
                {$this->renderResult($fixture, true)}
                <h2 class="section">{$this->text('public.score_sheet')}</h2>
                <div class="grid grid--2">
                    {$this->renderScoreSheetTeam($fixture->home)}
                    {$this->renderScoreSheetTeam($fixture->away)}
                </div>
                {$original}
                <div class="grid grid--2">
                    {$this->renderAverageList($homeName, $fixture->averageListHome, seasonId: $seasonId)}
                    {$this->renderAverageList($awayName, $fixture->averageListAway, seasonId: $seasonId)}
                </div>
            </section>
            HTML;
    }

    /**
     * Content of a tab without public player data (R40.2): only the result
     * with the team sums per game; no score sheet, no original score sheet,
     * no average lists.
     */
    private function renderTeamTabContent(MatchdayFixture $fixture, int $index): string
    {
        return <<<HTML
            <section class="tabs__panel" id="fixture-panel-{$index}" role="tabpanel" aria-labelledby="fixture-tab-{$index}">
                {$this->renderResult($fixture, true)}
                <p class="notice">{$this->text('public.players_not_public')}</p>
            </section>
            HTML;
    }

    /** Card in place of player data that is not public (R40.2). */
    private function playersHiddenCard(): string
    {
        return '<section class="card"><p class="empty">' . $this->text('public.players_not_public') . '</p></section>';
    }

    /**
     * Result block of a fixture: gross of both teams, points (game plus bonus
     * points, R17) and optionally the gross per game. Calculated via the
     * {@see ScoreSheetCalculator}, so that the same rules as during entry
     * apply.
     */
    private function renderResult(MatchdayFixture $fixture, bool $withGames): string
    {
        $values = $this->calculator->calculate([
            'home' => ['rows' => $this->calculatorRows($fixture->home)],
            'away' => ['rows' => $this->calculatorRows($fixture->away)],
        ], null, $fixture->points);

        $home = $values['home'];
        $away = $values['away'];
        $homeFirst = ($home['total'] ?? 0) >= ($away['total'] ?? 0);

        $games = '';
        if ($withGames) {
            foreach ([1, 2, 3] as $num) {
                $games .= sprintf(
                    '<li class="result__game">%s <strong>%s : %s</strong></li>',
                    $this->text('common.game_number', ['number' => $num]),
                    $this->numeral($home['games'][$num]['gross']),
                    $this->numeral($away['games'][$num]['gross']),
                );
            }
            $games = '<ul class="result__games">' . $games . '</ul>';
        }

        return sprintf(
            '<div class="result">'
            . '<div class="result__team%s"><span class="result__name">%s</span><span class="result__pins">%s</span><span class="result__net">%s</span></div>'
            . '<div class="result__points" aria-label="%s">%s<span> : </span>%s</div>'
            . '<div class="result__team result__team--away%s"><span class="result__name">%s</span><span class="result__pins">%s</span><span class="result__net">%s</span></div>'
            . '%s</div>',
            $homeFirst ? '' : ' result__team--behind',
            $this->escape($fixture->home->teamName ?? '—'),
            $this->numeral($home['gross_total']),
            $this->text('public.net_value', ['value' => $this->numeral($home['net_total'])]),
            $this->text('common.points'),
            $this->numeral($home['total']),
            $this->numeral($away['total']),
            $homeFirst ? ' result__team--behind' : '',
            $this->escape($fixture->away->teamName ?? '—'),
            $this->numeral($away['gross_total']),
            $this->text('public.net_value', ['value' => $this->numeral($away['net_total'])]),
            $games,
        );
    }

    /**
     * @return list<array{hdc: int, first_value: bool, blind: bool, values: array<int, int|null>}>
     */
    private function calculatorRows(ScoreSheetTeamView $team): array
    {
        $rows = [];
        foreach ($team->rows as $row) {
            $rows[] = [
                'hdc'         => $row->handicap(),
                'first_value' => false,
                'blind'       => $row->isBlind,
                'values'      => [1 => $row->net[1] ?? null, 2 => $row->net[2] ?? null, 3 => $row->net[3] ?? null],
            ];
        }

        return $rows;
    }

    /**
     * Renders a team's digital score sheet: per row player, HDC, the net
     * values of the games played (a substituted player has a dash for the
     * other games) and the net and gross sum; finally the team sums (R2.1,
     * R22.1). On narrow screens the stylesheet turns each row into a player
     * card.
     */
    private function renderScoreSheetTeam(ScoreSheetTeamView $team): string
    {
        $netLabel = $this->text('public.net_label');
        $hdcLabel = $this->text('common.hdc');

        $rows = '';
        $gameSums  = [1 => 0, 2 => 0, 3 => 0];
        $grossSums = [1 => 0, 2 => 0, 3 => 0];
        foreach ($team->rows as $index => $row) {
            $name = $this->escape($row->playerName ?? $this->t->t('public.position', ['number' => $index + 1]));
            if ($row->isBlind) {
                $name .= ' <span class="badge badge--blind">' . $this->text('common.blind') . '</span>';
            }

            // Games marked blind individually (R27.5); games not played in this row.
            $games = '';
            foreach ([1, 2, 3] as $num) {
                if (! isset($row->net[$num])) {
                    $games .= sprintf('<td class="sc-game sc-game--none" title="%s">–</td>', $this->text('public.game_not_played', ['number' => $num]));
                    continue;
                }
                $games .= ($row->blindGames[$num] ?? false)
                    ? sprintf(
                        '<td class="sc-game sc-game--blind" title="%s">%d<span class="sc-b" aria-label=" %s">B</span></td>',
                        $this->text('public.game_blind', ['number' => $num]),
                        $row->net[$num],
                        $this->text('common.blind_lower'),
                    )
                    : sprintf('<td class="sc-game">%d</td>', $row->net[$num]);
                $gameSums[$num]  += $row->net[$num];
                $grossSums[$num] += $row->gross[$num];
            }

            $rows .= sprintf(
                '<tr><td class="sc-name">%s</td><td class="sc-hdc"><span class="sc-label">%s </span>%d</td>'
                . '%s'
                . '<td class="sc-net"><span class="sc-label">%s </span>%d</td><td class="sc-gross">%d</td></tr>',
                $name,
                $hdcLabel,
                $row->handicap(),
                $games,
                $netLabel,
                $row->sumNet(),
                $row->sumGross(),
            );
        }

        // Team sums per game: net and – because the game points are awarded by
        // gross – gross (R17).

        $totalRow = sprintf(
            '<tr class="total"><td class="sc-name">%s</td><td class="sc-hdc"></td>'
            . '<td class="sc-game">%d</td><td class="sc-game">%d</td><td class="sc-game">%d</td>'
            . '<td class="sc-net"><span class="sc-label">%s </span>%d</td><td class="sc-gross"></td></tr>'
            . '<tr class="total total--gross"><td class="sc-name">%s</td><td class="sc-hdc"></td>'
            . '<td class="sc-game">%d</td><td class="sc-game">%d</td><td class="sc-game">%d</td>'
            . '<td class="sc-net"></td><td class="sc-gross">%d</td></tr>',
            $this->text('public.team_net'),
            $gameSums[1], $gameSums[2], $gameSums[3],
            $netLabel,
            $team->sumNet(),
            $this->text('public.team_gross'),
            $grossSums[1], $grossSums[2], $grossSums[3],
            $team->sumGross(),
        );

        $name = $this->escape($team->teamName ?? $this->t->t('common.team'));

        return <<<HTML
            <section class="card sheet-card">
                <h3>{$name}</h3>
                <table class="sheet-card__table">
                    <thead>
                        <tr><th scope="col">{$this->text('common.player')}</th><th scope="col">{$hdcLabel}</th><th scope="col">1</th><th scope="col">2</th><th scope="col">3</th><th scope="col">{$this->text('common.net')}</th><th scope="col">{$this->text('common.gross')}</th></tr>
                    </thead>
                    <tbody>{$rows}{$totalRow}</tbody>
                </table>
            </section>
            HTML;
    }

    // ---------------------------------------------------------------------
    // Matchday list, average list, tables, honours
    // ---------------------------------------------------------------------

    private function renderMatchdayEntry(MatchdayListEntry $entry): string
    {
        $date = $this->escape($this->format->dateLong($entry->date));
        $number = $this->text('common.matchday_number', ['number' => $entry->number]);

        if ($entry->isPlayed) {
            // R14.4: played matchday as link to the detail view.
            return sprintf(
                '<li class="matchday-list__entry"><a class="matchday-list__row" href="/matchday/%d">'
                . '<span class="matchday-list__number">%s</span><span class="matchday-list__date">%s</span>'
                . '<span class="badge badge--played">%s</span>'
                . '<svg class="matchday-list__arrow" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>'
                . '</a></li>',
                $entry->matchdayId,
                $number,
                $date,
                $this->text('public.played'),
            );
        }

        if ($entry->isCancelled) {
            // R8.20: cancelled, its fixtures are dropped.
            return sprintf(
                '<li class="matchday-list__entry matchday-list__entry--open matchday-list__entry--cancelled"><div class="matchday-list__row">'
                . '<span class="matchday-list__number">%s</span>'
                . '<span class="matchday-list__date">%s</span><span class="badge badge--cancelled">%s</span>'
                . '</div></li>',
                $number,
                $date,
                $this->text('public.cancelled'),
            );
        }

        // R14.5/R14.6: not played, not clickable, with tooltip; R1.3: "without results".
        return sprintf(
            '<li class="matchday-list__entry matchday-list__entry--open"><div class="matchday-list__row">'
            . '<span class="matchday-list__number not-played" title="%s">%s</span>'
            . '<span class="matchday-list__date">%s</span><span class="badge marker">%s</span>'
            . '</div></li>',
            $this->text('public.not_played_yet'),
            $number,
            $date,
            $this->text('public.without_results'),
        );
    }

    /**
     * Renders a team's average list (R2). For an empty list a message is shown
     * (R2.4).
     *
     * @param string      $teamLabel already escaped team name
     * @param string|null $title     already escaped title (default: "average list <team>")
     */
    private function renderAverageList(
        string $teamLabel,
        AverageList $averageList,
        ?string $title = null,
        ?string $blank = null,
        ?int $seasonId = null,
    ): string {
        $title ??= $this->text('public.average_list_of', ['team' => $teamLabel], false);
        $blank ??= $this->t->t('public.no_averages_until');

        if ($averageList->isEmpty()) {
            // R2.4: no averages available.
            return sprintf('<section class="card"><h2>%s</h2><p class="empty">%s</p></section>', $title, $this->escape($blank));
        }

        $rows = '';
        foreach ($averageList->rows as $row) {
            $hdc = $row->handicap === null ? '' : (string) $row->handicap; // R2.15: empty for "without handicap".
            $gender = $this->t->t($row->gender === Gender::MALE ? 'common.gender_short.male' : 'common.gender_short.female');
            // Rounded from the exact average, like the basis of the handicap
            // (the row itself keeps two decimals).
            $exactNet = $row->numberOfGames > 0 ? $row->netPins / $row->numberOfGames : 0.0;
            $rows .= sprintf(
                '<tr><td class="text">%s</td><td class="only-wide">%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
                // The name leads to the player page in the same season (R39.1).
                sprintf(
                    '<a href="/player/%d%s">%s</a>',
                    (int) $row->playerId,
                    $seasonId !== null ? '?season=' . $seasonId : '',
                    $this->escape($row->playerName),
                ),
                $this->escape($gender),
                $this->escape($hdc),
                $row->numberOfGames,
                $this->formatValue($exactNet),
                $row->handicap === null ? '—' : $this->formatValue($exactNet + $row->handicap),
            );
        }

        $total = $averageList->total;
        $totalRow = sprintf(
            '<tr class="total"><td class="text">%s</td><td class="only-wide"></td><td></td><td>%d</td><td>%s</td><td>%s</td></tr>',
            $this->text('common.total'),
            $total->numberOfGames,
            $this->formatValue($total->averageNet),
            $this->formatValue($total->averageGross),
        );

        return <<<HTML
            <section class="card">
                <h2>{$title}</h2>
                <div class="table-scroll">
                    <table class="table table--numbers">
                        <thead>
                            <tr><th scope="col" class="text">{$this->text('common.player')}</th><th scope="col" class="only-wide">{$this->text('public.gender_short')}</th><th scope="col">{$this->text('common.hdc')}</th><th scope="col">{$this->text('common.games')}</th><th scope="col">{$this->text('common.average_net')}</th><th scope="col">{$this->text('common.average_gross')}</th></tr>
                        </thead>
                        <tbody>{$rows}{$totalRow}</tbody>
                    </table>
                </div>
            </section>
            HTML;
    }

    /**
     * Renders a league or matchday table in a uniform structure with rank
     * badges and total row (R16, R15.3). The ghost team is marked as out of
     * competition (R20).
     */
    private function renderTableBody(Table $table): string
    {
        $rows = '';
        foreach ($table->rows as $row) {
            // Podium colours only for real teams and only once games have been played.
            $rankClass = ! $row->isGhostTeam && $row->numberOfGames > 0 && $row->rank <= 3 ? ' rank--' . $row->rank : '';
            $team = $this->escape($row->teamName);
            // Real teams lead to the team page with the current average list.
            if (! $row->isGhostTeam && ctype_digit($row->teamId)) {
                $team = sprintf('<a class="table__team-link" href="/team/%s">%s</a>', $row->teamId, $team);
            }
            if ($row->isGhostTeam) {
                $team .= ' <span class="badge badge--neutral">' . $this->text('public.out_of_competition') . '</span>';
            }

            $rows .= sprintf(
                '<tr%s><td><span class="rank%s">%d</span></td>'
                . '<td class="text"><span class="table__team">%s</span><span class="table__extra">%s</span></td>'
                . '<td class="table__points">%d</td><td class="only-wide">%d</td><td class="only-wide">%d</td>'
                . '<td class="only-wide">%s</td><td>%s</td><td class="only-wide">%d</td></tr>',
                $row->isGhostTeam ? ' class="table__row--ghost"' : '',
                $rankClass,
                $row->rank,
                $team,
                $this->text('public.table_extra', ['pins' => $row->grossPins, 'average' => $this->formatValue($row->averageNet)]),
                $row->points,
                $row->netPins,
                $row->grossPins,
                $this->formatValue($row->averageNet),
                $this->formatValue($row->averageGross),
                $row->numberOfGames,
            );
        }

        $total = $table->total;
        $totalRow = sprintf(
            '<tr class="total"><td></td><td class="text">%s</td><td></td><td class="only-wide">%d</td><td class="only-wide">%d</td>'
            . '<td class="only-wide">%s</td><td>%s</td><td class="only-wide">%d</td></tr>',
            $this->text('common.total'),
            $total->netPins,
            $total->grossPins,
            $this->formatValue($total->averageNet),
            $this->formatValue($total->averageGross),
            $total->numberOfGames,
        );

        return <<<HTML
            <div class="table-scroll">
                <table class="table table--league">
                    <thead>
                        <tr><th scope="col">{$this->text('public.rank')}</th><th scope="col" class="text">{$this->text('common.team')}</th><th scope="col">{$this->text('common.points')}</th><th scope="col" class="only-wide">{$this->text('public.pins_net')}</th><th scope="col" class="only-wide">{$this->text('public.pins_gross')}</th><th scope="col" class="only-wide">{$this->text('common.average_net')}</th><th scope="col">{$this->text('common.average_gross')}</th><th scope="col" class="only-wide">{$this->text('common.games')}</th></tr>
                    </thead>
                    <tbody>{$rows}{$totalRow}</tbody>
                </table>
            </div>
            HTML;
    }

    private function renderHonourCategory(Honours $honours, HonourCategory $category, Gender $gender): string
    {
        $result = $honours->result($category, $gender);
        if ($result === null) {
            return '';
        }

        $places = '';
        foreach ($result->places as $place) {
            $rank = sprintf('<span class="rank rank--%d">%d</span>', min($place->rank, 4), $place->rank);

            if ($place->isVacant()) {
                // R13.6: vacant place.
                $places .= sprintf('<li class="honour">%s<span class="honour__name honour__name--empty">%s</span></li>', $rank, $this->text('public.vacant'));
                continue;
            }

            // Every game or series is a placement of its own; if a
            // player reached the same value several times, they still appear only
            // once in that place (decision of 15.10.2026).
            $names = [];
            $value  = null;
            foreach ($place->placements as $placement) {
                $names[$placement->playerId] ??= $this->escape($placement->playerName ?? $this->t->t('common.player_number', ['number' => $placement->playerId]));
                $value = $placement->value;
            }

            $places .= sprintf(
                '<li class="honour">%s<span class="honour__name">%s</span><span class="honour__value">%d</span></li>',
                $rank,
                implode(', ', $names),
                (int) $value,
            );
        }

        return sprintf(
            '<section class="card"><h2>%s <span class="card__sub">%s</span></h2><ol class="honours">%s</ol></section>',
            $this->text($this->categoryKey($category)),
            $this->text($gender === Gender::MALE ? 'common.gender.male' : 'common.gender.female'),
            $places,
        );
    }

    private function categoryKey(HonourCategory $category): string
    {
        return match ($category) {
            HonourCategory::HIGHEST_SERIES_NET   => 'public.honour.series_net',
            HonourCategory::HIGHEST_SERIES_GROSS => 'public.honour.series_gross',
            HonourCategory::HIGHEST_GAME_NET     => 'public.honour.game_net',
            HonourCategory::HIGHEST_GAME_GROSS   => 'public.honour.game_gross',
        };
    }

    // ---------------------------------------------------------------------
    // Formatting
    // ---------------------------------------------------------------------

    /**
     * Formats an average with two decimals as in the league's spreadsheet,
     * so that the HDC can be recalculated (R2.2/R16.1, decision of
     * 17.10.2026).
     */
    private function formatValue(float $value): string
    {
        return $this->format->number($value, 2);
    }

    /**
     * Catalog text, escaped for HTML; with `$escapeParams = false` the
     * parameters are inserted as they are (already escaped HTML).
     *
     * @param array<string, string|int|float> $params
     */
    private function text(string $key, array $params = [], bool $escapeParams = true): string
    {
        if ($escapeParams) {
            return $this->escape($this->t->t($key, $params));
        }

        return str_replace(
            array_map(static fn (string $name): string => '{' . $name . '}', array_keys($params)),
            array_map('strval', array_values($params)),
            $this->escape($this->t->t($key)),
        );
    }

    private function numeral(?int $value): string
    {
        return $value === null ? '–' : (string) $value;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
