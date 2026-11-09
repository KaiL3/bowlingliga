<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\ResultEntry\OverviewEntry;
use Bowlingliga\Domain\Handicap\HandicapChainPreview;
use Bowlingliga\Domain\Points\PointsRules;
use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\Translator;

/**
 * Renders the result entry of the admin area (task 35.3, R28.12).
 *
 * - Overview of the fixtures: at the top the next matchday with fixtures to
 *   fill in as tiles, below all matchdays as collapsible sections with
 *   progress (R30.1–R30.3).
 * - Score sheet page: virtual score sheet per team with a row of 3 net fields
 *   for every roster player, blind flag per game – also for a player serving
 *   as blind player – (R21.2, R22.1, R27, R30.4–R30.13), fields needing review highlighted
 *   (R30.9/R30.11), upload of the original score sheet with camera capture
 *   (R3.1, R32.4, R36.6). The input fields open a numeric keypad on mobile
 *   devices; on narrow screens each row becomes a player card (R36.7).
 *
 * All writing forms carry a CSRF token (R22.5); dynamic values are escaped.
 * Free of side effects; returns HTML fragments.
 */
final class ResultEntryView
{
    use ViewTexts;

    private readonly Formatter $format;

    private readonly ValueLabels $labels;

    public function __construct(private readonly Translator $t = new Translator())
    {
        $this->format = new Formatter($t);
        $this->labels = new ValueLabels($t);
    }

    /**
     * @param list<OverviewEntry> $entries
     */
    public function renderOverview(array $entries): string
    {
        if ($entries === []) {
            return AdminComponents::pageHeader($this->plain('admin.area.results.short'), $this->plain('admin.area.results.title'))
                . '<p class="notice">' . $this->text('results.no_fixtures', [], ['link' => '<a href="/admin/season-planning">' . $this->text('admin.area.planning.title') . '</a>']) . '</p>';
        }

        /** @var array<int, list<OverviewEntry>> $matchdays matchday id → fixtures, in chronological order */
        $matchdays = [];
        foreach ($entries as $e) {
            $matchdays[$e->matchdayId][] = $e;
        }

        $open = count(array_filter($entries, static fn (OverviewEntry $e): bool => $e->isToBeFilled()));
        $header  = AdminComponents::pageHeader(
            $this->plain('admin.area.results.short'),
            $this->plain('admin.area.results.title'),
            '<p class="page-header__meta">' . $this->text('results.open_count', ['open' => $open, 'total' => count($entries)]) . '</p>',
        );

        $nextOne = null;
        foreach ($matchdays as $fixtures) {
            foreach ($fixtures as $e) {
                if ($e->isToBeFilled()) {
                    $nextOne = $fixtures;
                    break 2;
                }
            }
        }

        return $header . $this->renderNextMatchday($nextOne) . $this->renderAllMatchdays($matchdays);
    }

    public function renderMissingFixture(): string
    {
        return AdminComponents::pageHeader($this->plain('results.fixture_not_found'), $this->plain('admin.area.results.title'))
            . '<p class="error" role="alert">' . $this->text('results.fixture_not_found_text') . '</p><p><a href="/admin/results">' . $this->text('results.to_overview') . '</a></p>';
    }

    /**
     * Preview of the handicap chain recalculation from the matchday of the
     * fixture: per changed handicap matchday, player, previous and new value.
     * It is only applied after explicit confirmation (R12.18, R12.19).
     *
     * @param array<string, mixed>                           $fixture   Context of the fixture
     * @param array<int, array{number: int, date: string}> $matchdays Matchday id → number, date
     * @param array<int, string>                             $names     Player id → name
     */
    public function renderHandicapChain(
        string $csrfField,
        array $fixture,
        HandicapChainPreview $preview,
        array $matchdays,
        array $names,
        ?string $error = null,
    ): string {
        $tp   = (int) $fixture['id'];
        $header = $this->handicapChainHeader($fixture);

        if (! $preview->hasChanges()) {
            return $header . AdminComponents::notes(null, $error) . AdminComponents::card(
                $this->plain('results.no_changes'),
                '<p class="empty">' . $this->text('results.no_changes_text') . '</p>'
                . sprintf('<p><a class="button button--secondary" href="/admin/results/score-sheet?fixture=%d">%s</a></p>', $tp, $this->text('results.to_score_sheet')),
            );
        }

        $rows      = '';
        $applied = 0;
        foreach ($preview->changesOnly() as $a) {
            $matchday = $matchdays[$a->matchdayId] ?? null;
            $applied += $a->newHandicap !== null ? 1 : 0;

            $rows .= sprintf(
                '<tr><td>%s</td><td class="only-wide">%s</td><td class="text">%s</td><td>%s</td><td><strong>%s</strong></td></tr>',
                $matchday !== null ? (string) $matchday['number'] : '?',
                $matchday !== null ? $this->escape($this->format->date($matchday['date'])) : '',
                $this->escape($names[$a->playerId] ?? $this->plain('common.player_number', ['number' => $a->playerId])),
                $a->previousHandicap !== null ? (string) $a->previousHandicap : '–',
                $a->newHandicap !== null
                    ? (string) $a->newHandicap
                    : '<span class="badge" title="' . $this->text('results.stays_title') . '">' . $this->text('results.stays') . '</span>',
            );
        }

        $table = '<div class="table-scroll"><table class="table table--numbers chain__table"><thead><tr>'
            . '<th scope="col">' . $this->text('public.matchday') . '</th><th scope="col" class="only-wide">' . $this->text('field.date') . '</th><th scope="col" class="text">' . $this->text('common.player') . '</th>'
            . '<th scope="col">' . $this->text('results.hdc_before') . '</th><th scope="col">' . $this->text('results.hdc_new') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<p class="empty">' . $this->text('results.chain_note') . '</p>';

        $form = sprintf(
            '<form class="action-bar" method="post" action="/admin/results/handicap-chain">%s'
            . '<input type="hidden" name="fixture_id" value="%d"><input type="hidden" name="fingerprint" value="%s">'
            . '<label class="choice action-bar__confirmation"><input type="checkbox" name="confirmed" value="1" required> '
            . $this->text('results.changes_checked') . '</label>'
            . '<a class="button button--secondary" href="/admin/results/score-sheet?fixture=%d">' . $this->text('results.discard') . '</a>'
            . '<button type="submit" class="button">' . $this->text('results.apply_handicaps') . '</button></form>',
            $csrfField,
            $tp,
            $this->escape($preview->fingerprint()),
            $tp,
        );

        return $header . AdminComponents::notes(null, $error)
            . AdminComponents::card(
                $this->plain('import.preview'),
                $table,
                $this->plain($applied === 1 ? 'results.one_change' : 'results.changes', ['count' => $applied, 'number' => $preview->fromMatchdayCounter]),
            )
            . $form;
    }

    /**
     * Preview cannot be calculated (e.g. missing matchday context).
     *
     * @param array<string, mixed> $fixture
     */
    public function renderHandicapChainError(array $fixture, string $error): string
    {
        return $this->handicapChainHeader($fixture) . AdminComponents::notes(null, $error);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function handicapChainHeader(array $fixture): string
    {
        return AdminComponents::pageHeader(
            $this->plain('results.chain_title'),
            $this->plain('common.matchday_number', ['number' => (int) $fixture['matchday_number']]) . ' · ' . $fixture['home']['team_name'] . ' – ' . $fixture['away']['team_name'],
            sprintf(
                '<p class="page-header__meta">%s</p>'
                . '<p class="page-header__actions"><a class="button button--secondary" href="/admin/results/score-sheet?fixture=%d">← %s</a></p>',
                $this->text('results.chain_from', ['number' => (int) $fixture['matchday_number']]),
                (int) $fixture['id'],
                $this->text('results.to_score_sheet'),
            ),
        );
    }

    /**
     * @param array<string, mixed>                $fixture    Context of the fixture
     * @param array<string, array<string, mixed>> $teams      View data per side ('home', 'away')
     * @param array<string, array<string, mixed>> $calculated Derived values per side ({@see ScoreSheetCalculator})
     * @param array{factor: float, target_score: int, max: int}|null $formula Handicap formula for the live calculation
     * @param list<int>                           $originals  Ids of uploaded original score sheets
     * @param PointsRules|null                    $points     Points rules of the season for the live calculation (R17.11)
     */
    public function renderScoreSheet(
        string $csrfField,
        array $fixture,
        array $teams,
        array $calculated,
        ?array $formula,
        array $originals,
        bool $rework,
        ?string $message = null,
        ?string $error = null,
        ?PointsRules $points = null,
    ): string {
        $tp = (int) $fixture['id'];
        $points ??= PointsRules::standard();

        $meta = sprintf(
            '<p class="page-header__meta">%s%s %s</p>'
            . '<p class="page-header__actions"><a class="button button--secondary" href="/admin/results">← ' . $this->text('admin.overview') . '</a>'
            . '<a class="button button--secondary" href="/admin/print?fixture=%d" target="_blank" rel="noopener">' . $this->text('results.print_sheet') . '</a></p>',
            $this->escape($this->format->date((string) $fixture['date'], true)),
            $fixture['lanes'] !== null ? ' · ' . $this->text('common.lanes', ['lanes' => (string) $fixture['lanes']]) : '',
            AdminComponents::status((string) $fixture['status'], $this->labels),
            $tp,
        );
        $header = AdminComponents::pageHeader(
            $teams['home']['team_name'] . ' – ' . $teams['away']['team_name'],
            $this->plain('common.matchday_number', ['number' => (int) $fixture['matchday_number']]),
            $meta,
        );

        $notes = AdminComponents::notes($message, $error);
        if ($rework) {
            $notes .= '<p class="notice notice--check" role="alert" id="review-note">' . $this->text('results.review_note') . '</p>';
        }

        $resultFields = '';
        foreach ($teams as $page => $team) {
            $resultFields .= $this->renderResultTeam($page, $team, $calculated[$page]);
        }
        $resultFields = sprintf(
            '<div class="sheet__teams js-score-sheet" data-ghost="%d" data-points="%d,%d,%d" data-bonus="%d,%d,%d" data-text-wrong-blind="%s" data-text-blind-player="%s" data-text-blind="%s"%s>%s</div>',
            (int) $fixture['ghost_score'],
            $points->base->win,
            $points->base->draw,
            $points->base->loss,
            $points->bonus->threshold,
            $points->bonus->step,
            $points->bonus->maximum,
            $this->text('results.wrong_blind'),
            $this->text('results.blind_player'),
            $this->text('common.blind'),
            $formula !== null
                ? sprintf(' data-factor="%s" data-target="%d" data-max="%d"', $formula['factor'], $formula['target_score'], $formula['max'])
                : '',
            $resultFields,
        );

        $removal = $fixture['status'] !== 'open'
            ? sprintf(
                '<form class="entry__remove" method="post" action="/admin/results/remove">%s'
                . '<input type="hidden" name="fixture_id" value="%d">'
                . '<button type="submit" class="button button--danger">' . $this->text('results.remove_all') . '</button></form>',
                $csrfField,
                $tp,
            )
            : '';

        // Correction of a complete fixture: recalculating the handicap chain
        // only on explicit request, with a preview (R12.17, R12.18).
        $chain = $fixture['status'] === 'complete'
            ? '<label class="choice entry__chain"><input type="checkbox" name="recalculate_chain" value="1"> '
              . $this->text('results.recalculate_chain') . '</label>'
            : '';

        return $header . $notes
            . '<section class="entry" aria-labelledby="h-results"><div class="card__header"><h2 id="h-results">' . $this->text('results.virtual_sheet') . '</h2>'
            . '<span class="card__sub">' . $this->text('results.virtual_sheet_hint') . '</span></div>'
            . sprintf(
                '<form method="post" action="/admin/results/save">%s<input type="hidden" name="fixture_id" value="%d">%s'
                . '<div class="entry__actions entry__actions--fixed">%s'
                . '<button type="submit" name="action" value="draft" class="button button--secondary">' . $this->text('results.save_draft') . '</button> '
                . '<button type="submit" name="action" value="confirm" class="button">' . $this->text('results.confirm_save') . '</button>'
                . '</div></form>',
                $csrfField,
                $tp,
                $resultFields,
                $chain,
            )
            . $removal . '</section>'
            . $this->renderOriginals($csrfField, $tp, $originals)
            . '<dialog class="dialog js-blind-dialog" aria-labelledby="blind-dialog-title"><form method="dialog">'
            . '<h2 class="dialog__title" id="blind-dialog-title">' . $this->text('results.blind_not_allowed') . '</h2>'
            . '<p class="dialog__text js-blind-dialog-text"></p>'
            . '<div class="dialog__actions"><button type="submit" class="button">' . $this->text('common.ok') . '</button></div></form></dialog>'
            . '<script src="/assets/js/score-sheet.js" defer></script>';
    }

    /**
     * @param list<OverviewEntry>|null $fixtures
     */
    private function renderNextMatchday(?array $fixtures): string
    {
        if ($fixtures === null) {
            return AdminComponents::card(
                $this->plain('results.all_done'),
                '<p class="empty">' . $this->text('results.all_done_text') . '</p>',
            );
        }

        $firstOne  = $fixtures[0];
        $tiles = '';
        foreach ($fixtures as $e) {
            $tiles .= sprintf(
                '<li><a class="tile tile--pairing%s" href="/admin/results/score-sheet?fixture=%d">'
                . '<span class="tile__kicker">%s</span>'
                . '<span class="tile__title">%s – %s</span>'
                . '<span class="tile__footer">%s<span class="tile__action">%s →</span></span></a></li>',
                $e->isToBeFilled() ? '' : ' tile--done',
                $e->fixtureId,
                $this->escape($this->lane($e)),
                $this->escape($e->homeName ?? $this->plain('results.open_slot')),
                $this->escape($e->awayName ?? $this->plain('results.open_slot')),
                AdminComponents::status($e->completionStatus, $this->labels),
                $this->action($e),
            );
        }

        return AdminComponents::card(
            $this->plain('common.matchday_number', ['number' => $firstOne->matchdayCounter]),
            '<ul class="tiles">' . $tiles . '</ul>',
            $this->plain('results.next_open') . ($firstOne->date !== null ? ' · ' . $this->format->date($firstOne->date, true) : ''),
            'card--highlight',
        );
    }

    /**
     * @param array<int, list<OverviewEntry>> $matchdays
     */
    private function renderAllMatchdays(array $matchdays): string
    {
        $rows = '';
        foreach ($matchdays as $fixtures) {
            $firstOne  = $fixtures[0];
            $done  = count(array_filter($fixtures, static fn (OverviewEntry $e): bool => ! $e->isToBeFilled()));
            $total  = count($fixtures);
            $started = array_filter($fixtures, static fn (OverviewEntry $e): bool => $e->completionStatus !== OverviewEntry::OPEN) !== [];
            $state = $done === $total ? 'complete' : ($started ? 'in-progress' : 'open');

            $items = '';
            foreach ($fixtures as $e) {
                $items .= sprintf(
                    '<li class="data-list__row"><span class="data-list__main">%s – %s'
                    . '<span class="data-list__aside">%s</span></span>%s'
                    . '<span class="data-list__actions"><a class="button button--secondary" href="/admin/results/score-sheet?fixture=%d">%s</a></span></li>',
                    $this->escape($e->homeName ?? $this->plain('results.open_slot')),
                    $this->escape($e->awayName ?? $this->plain('results.open_slot')),
                    $this->escape($this->lane($e)),
                    AdminComponents::status($e->completionStatus, $this->labels),
                    $e->fixtureId,
                    $this->action($e),
                );
            }

            $rows .= sprintf(
                '<li><details class="collapsible"><summary class="collapsible__header">'
                . '<span class="collapsible__title">%s</span><span class="collapsible__aside">%s</span>'
                . '<span class="progress progress--%s">%s</span></summary>'
                . '<ul class="data-list">%s</ul></details></li>',
                $this->text('common.matchday_number', ['number' => $firstOne->matchdayCounter]),
                $this->escape($firstOne->date !== null ? $this->format->date($firstOne->date, true) : ''),
                $state,
                $this->text('results.progress', ['done' => $done, 'total' => $total]),
                $items,
            );
        }

        return AdminComponents::card($this->plain('results.all_matchdays'), '<ul class="collapsible-list">' . $rows . '</ul>');
    }

    private function lane(OverviewEntry $e): string
    {
        return $e->lanes !== null ? $this->plain('common.lanes', ['lanes' => $e->lanes]) : $this->plain('results.without_lane');
    }

    private function action(OverviewEntry $e): string
    {
        return match ($e->completionStatus) {
            OverviewEntry::OPEN        => $this->text('results.enter'),
            OverviewEntry::IN_PROGRESS => $this->text('results.continue'),
            default                    => $this->text('results.view_correct'),
        };
    }

    /**
     * Renders a team's score sheet in the layout of the paper score sheet: one
     * row per roster player with AVG, HDC, G, game 1–3 and total net/gross;
     * below the number of results per game (exactly 4,
     * players are substituted between games only), the net, HDC and gross sum,
     * game, bonus and total points. The derived values come from the
     * {@see ScoreSheetCalculator}; the script `score-sheet.js` recalculates
     * them live on every input.
     *
     * The `st-*` classes and `data-label` serve the card view on the phone:
     * there each row becomes a player card with labelled values.
     *
     * @param array<string, mixed> $team
     * @param array<string, mixed> $values Calculated values of the team
     */
    private function renderResultTeam(string $page, array $team, array $values): string
    {
        $name   = $this->escape((string) $team['team_name']);
        $ghosts = (bool) $team['is_ghost_team'];

        if (! $ghosts && $team['roster'] === [] && ! $this->hasPlayerRows($team['rows'])) {
            return sprintf(
                '<div class="sheet card"><h3 class="sheet__team">%s</h3><p class="notice">%s</p></div>',
                $name,
                $this->text('results.no_roster', [], ['link' => '<a href="/admin/master-data?tab=teams">' . $this->text('admin.area.master_data.title') . '</a>']),
            );
        }

        $rows = '';
        foreach ($team['rows'] as $key => $r) {
            $rows .= $this->renderResultRow($page, (string) $key, $r, $values['rows'][$key], $name);
        }

        $sumRow = function (string $title, string $key, string $cssClass, string $total) use ($values): string {
            $cells = '';
            foreach ([1, 2, 3] as $num) {
                $cells .= sprintf('<td class="sheet__number js-%s" data-number="%d">%s</td>', $cssClass, $num, $this->numeral($values['games'][$num][$key]));
            }

            return sprintf(
                '<tr class="st-sum"><th scope="row" colspan="5">%s</th>%s<td class="sheet__number js-%s-total">%s</td><td class="st-empty"></td></tr>',
                $title,
                $cells,
                $cssClass,
                $total,
            );
        };

        // Number of results per game: exactly 4 players are on the lanes.
        $counts = '';
        foreach ([1, 2, 3] as $num) {
            $count = (int) $values['games'][$num]['count'];
            $counts .= sprintf(
                '<td class="sheet__number sheet__count js-count%s" data-number="%d">%d/4</td>',
                $count > 4 ? ' sheet__count--over' : ($count === 4 ? ' sheet__count--ok' : ''),
                $num,
                $count,
            );
        }

        // Column headers of the sums only for the card view on the phone.
        $footer = '<tr class="st-header" aria-hidden="true"><td colspan="5"></td><td>1</td><td>2</td><td>3</td><td>Σ</td><td class="st-empty"></td></tr>'
            . ($ghosts ? '' : sprintf('<tr class="st-sum st-count"><th scope="row" colspan="5">%s</th>%s<td class="st-empty"></td><td class="st-empty"></td></tr>', $this->text('results.per_game'), $counts))
            . $sumRow($this->text('results.net_sum'), 'net', 'sum-net', $this->numeral($values['net_total']))
            . $sumRow($this->text('results.hdc_total'), 'hdc', 'sum-hdc', $this->numeral($values['hdc_total']))
            . $sumRow($this->text('results.gross_sum'), 'gross', 'sum-gross', $this->numeral($values['gross_total']))
            . $sumRow($this->text('results.game_points'), 'points', 'sum-points', $this->numeral($values['game_points']))
            . sprintf('<tr class="st-points"><th scope="row" colspan="8">%s</th><td class="sheet__number js-bonus">%s</td><td class="st-empty"></td></tr>', $this->text('results.bonus_points'), $this->numeral($values['bonus']))
            . sprintf('<tr class="st-points sheet__total"><th scope="row" colspan="8">%s</th><td class="sheet__number js-total">%s</td><td class="st-empty"></td></tr>', $this->text('results.total_points'), $this->numeral($values['total']));

        return sprintf(
            '<div class="sheet card js-team" data-page="%s"><h3 class="sheet__team">%s%s</h3>%s'
            . '<div class="table-scroll"><table class="table sheet__table"><thead><tr>'
            . '<th scope="col">' . $this->text('common.player') . '</th><th scope="col">AVG</th><th scope="col">HDC</th><th scope="col">G</th>'
            . '<th scope="col">' . $this->text('common.blind') . '</th><th scope="col">1</th><th scope="col">2</th><th scope="col">3</th>'
            . '<th scope="col">' . $this->text('results.total_net') . '</th><th scope="col">' . $this->text('results.total_gross') . '</th>'
            . '</tr></thead><tbody>%s</tbody><tfoot>%s</tfoot></table></div></div>',
            $this->escape($page),
            $name,
            $ghosts ? ' <span class="card__sub">' . $this->text('results.ghost_values', ['pins' => (int) $team['ghost_score']]) . '</span>' : '',
            $ghosts ? '' : $this->renderHint($team['blind_order']),
            $rows,
            $footer,
        );
    }

    /**
     * Hint above a team's score sheet; once players are entered with the
     * order of the blind players (R27.4).
     *
     * @param list<array{name: string, games: int}> $order
     */
    private function renderHint(array $order): string
    {
        $blind = $this->text('results.blind_rule');
        if ($order !== []) {
            $blind = $this->text('results.blind_order', [], ['players' => implode(', ', array_map(
                fn (array $c): string => $this->text($c['games'] === 1 ? 'results.player_one_game' : 'results.player_games', ['name' => $c['name'], 'games' => $c['games']]),
                $order,
            ))]);
        }

        return '<p class="sheet__hint">' . $this->text('results.sheet_hint') . '<br>' . $blind . '</p>';
    }

    /**
     * One row of a team's score sheet: a roster player, a ghost team row or a
     * blind slot without a player of its own (R27.18).
     *
     * @param array<string, mixed> $r          View data of the row
     * @param array<string, mixed> $calculated Calculated values of the row
     */
    private function renderResultRow(string $page, string $key, array $r, array $calculated, string $teamName): string
    {
        $playerName = $this->escape((string) $r['name']);
        $fieldName  = match ($r['kind']) {
            'player' => sprintf('net[%s][%d]', $this->escape($page), (int) $r['player_id']),
            'slot'   => sprintf('slot[%s][%d]', $this->escape($page), (int) $r['slot']),
            default  => '',
        };

        $fields = '';
        foreach ($r['fields'] as $num => $field) {
            if ($r['kind'] === 'ghost') {
                $fields .= sprintf('<td class="sheet__number st-game js-fix" data-label="%3$s" data-number="%1$d" data-value="%2$d">%2$d</td>', $num, (int) $field['value'], $this->text('common.game_number', ['number' => $num]));
                continue;
            }

            // Blind per game (R27.5): switch below the field; a blind slot is blind as a whole.
            $switcher = $r['kind'] === 'slot' ? '' : sprintf(
                    '<label class="st-b" title="%6$s"><input class="js-blind" type="checkbox" data-number="%3$d" '
                    . 'name="blind[%1$s][%2$d][%3$d]" value="1"%4$s aria-label="%5$s %6$s"><span>%7$s</span></label>',
                    $this->escape($page),
                    (int) $r['player_id'],
                    $num,
                    $field['blind'] ? ' checked' : '',
                    $playerName,
                    $this->text('public.game_blind', ['number' => $num]),
                    $this->text('common.blind'),
                );

            $fields .= sprintf(
                '<td class="st-game" data-label="%9$s"><input class="score-sheet__field js-net%1$s" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" '
                . 'data-number="%3$d" name="%2$s[%3$d]" value="%4$s" aria-label="%5$s %6$s %9$s"%7$s>%8$s</td>',
                ($field['check'] ? ' score-sheet__field--check' : '') . ($field['blind'] ? ' score-sheet__field--blind' : ''),
                $fieldName,
                $num,
                $this->escape((string) $field['value']),
                $teamName,
                $playerName,
                $field['check'] ? ' aria-describedby="review-note"' : '',
                $switcher,
                $this->text('common.game_number', ['number' => $num]),
            );
        }

        // Status of the row: player serving as blind player (R27.4); kept up
        // to date by score-sheet.js.
        $blind = sprintf(
            '<td class="sheet__blind st-blind js-blind-badge">%s</td>',
            $this->isBlindPlayer($r) ? '<span class="badge badge--blind" title="' . $this->text('results.blind_player') . '">' . $this->text('common.blind') . '</span>' : '',
        );

        // Player id, name and games played so far (column G) for checking a
        // blind player directly when ticking (R27.4).
        $player = $r['kind'] === 'player'
            ? sprintf(' data-player="%d" data-name="%s" data-games="%d"', (int) $r['player_id'], $playerName, (int) $r['games'])
            : '';

        return sprintf(
            '<tr class="js-row"%s data-hdc="%s" data-first="%d" data-avg="%s" data-blind="%d">'
            . '<th scope="row" class="st-name">%s%s</th>'
            . '<td class="sheet__number st-avg" data-label="AVG">%s</td>'
            . '<td class="sheet__number js-hdc st-hdc" data-label="HDC">%s</td>'
            . '<td class="sheet__number st-g" data-label="G">%s</td>%s%s'
            . '<td class="sheet__number js-net-total st-net" data-label="' . $this->text('common.net') . '">%s</td>'
            . '<td class="sheet__number js-gross-total st-gross" data-label="' . $this->text('common.gross') . '">%s</td></tr>',
            $player,
            $r['hdc'] !== null ? (string) $r['hdc'] : '',
            $r['first_value'] ? 1 : 0,
            $r['average'] !== null ? (string) $r['average'] : '',
            $r['kind'] === 'slot' ? 1 : 0,
            $r['kind'] === 'slot' ? $playerName . ' <small>' . $this->text('results.like_ghost') . '</small>' : $playerName,
            $r['reference'] !== null ? ' <small class="js-reference">' . $this->text('results.reference', ['number' => $r['reference']]) . '</small>' : '',
            // Two decimals as in the league's spreadsheet, so that the HDC can be recalculated.
            $r['average'] !== null ? $this->format->number((float) $r['average'], 2) : '',
            $this->numeral($calculated['hdc']),
            $r['games'] !== null ? (string) $r['games'] : '',
            $blind,
            $fields,
            $this->numeral($calculated['net_total']),
            $this->numeral($calculated['gross_total']),
        );
    }

    /**
     * A row whose games are all blind: the player serves as blind player; a
     * blind slot always.
     *
     * @param array<string, mixed> $r
     */
    private function isBlindPlayer(array $r): bool
    {
        $entered = array_filter($r['fields'], static fn (array $f): bool => $f['value'] !== '');

        return $r['kind'] === 'slot'
            || ($r['kind'] === 'player' && $entered !== [] && array_filter($entered, static fn (array $f): bool => ! $f['blind']) === []);
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     */
    private function hasPlayerRows(array $rows): bool
    {
        foreach ($rows as $r) {
            if ($r['kind'] === 'player') {
                return true;
            }
        }

        return false;
    }

    private function numeral(?int $value): string
    {
        return $value !== null ? (string) $value : '';
    }

    /**
     * @param list<int> $originals
     */
    private function renderOriginals(string $csrfField, int $tp, array $originals): string
    {
        $items = $originals === []
            ? '<p class="empty">' . $this->text('results.no_original') . '</p>'
            : '<ul class="data-list">' . implode('', array_map(
                fn (int $id): string => sprintf(
                    '<li class="data-list__row"><span class="data-list__main">%2$s</span>'
                    . '<span class="data-list__actions"><a class="button button--secondary" href="/score-sheet/%1$d" target="_blank" rel="noopener">%3$s</a></span></li>',
                    $id,
                    $this->text('results.score_sheet_number', ['number' => $id]),
                    $this->text('results.open'),
                ),
                $originals
            )) . '</ul>';

        return '<section class="card" aria-labelledby="h-original"><div class="card__header"><h2 id="h-original">' . $this->text('results.original') . '</h2>'
            . '<span class="card__sub">' . $this->text('results.original_hint') . '</span></div>'
            . $items
            . sprintf(
                '<form class="form js-sheet-crop" method="post" action="/admin/results/upload" enctype="multipart/form-data"'
                . ' data-crop-working="%3$s" data-crop-unreadable="%4$s" data-crop-unsupported="%5$s">%1$s'
                . '<input type="hidden" name="fixture_id" value="%2$d">'
                . '<input type="hidden" name="cropped" value="">'
                . '<div class="form__field"><label for="upload-file">' . $this->text('results.upload_label') . '</label>'
                . '<input id="upload-file" type="file" name="score_sheet" accept="image/jpeg,image/png,application/pdf" required>'
                . '<noscript><small>' . $this->text('results.crop_needs_javascript') . '</small></noscript></div>'
                . $this->renderCropPanel()
                . '<button type="submit" class="button">' . $this->text('results.upload') . '</button></form>',
                $csrfField,
                $tp,
                $this->text('results.crop_working'),
                $this->text('results.crop_unreadable'),
                $this->text('results.crop_unsupported'),
            )
            . '</section>'
            . '<script src="' . $this->escape(Layout::versioned('/assets/js/sheet-crop.js')) . '" defer></script>';
    }

    /**
     * Area in which the photo is cropped to the sheet before the upload; shown
     * by sheet-crop.js once a photo is chosen (R3.21).
     */
    private function renderCropPanel(): string
    {
        return '<div class="sheet-crop" hidden>'
            . '<p class="sheet-crop__hint">' . $this->text('results.crop_hint') . '</p>'
            . '<canvas class="sheet-crop__canvas" aria-label="' . $this->text('results.crop_area') . '"></canvas>'
            . '<div class="sheet-crop__actions">'
            . '<button type="button" class="button button--secondary" data-crop-rotate>' . $this->text('results.crop_rotate') . '</button>'
            . '<button type="button" class="button button--secondary" data-crop-reset>' . $this->text('results.crop_reset') . '</button>'
            . '</div>'
            . '<p class="sheet-crop__status" role="status" aria-live="polite"></p>'
            . '</div>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
