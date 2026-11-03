<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\Translator;

/**
 * Views of the result entry by the teams (R37): the guided team page reached
 * via the QR code (game 1–3, overview, confirmation) and the admin cards.
 * Every value is escaped; the texts come from the catalog (`team_entry.*`).
 *
 * The team page works without JavaScript (one form per game with buttons to
 * go on and back); `team-entry.js` only hides the net field of players who do
 * not play and refreshes the confirmation step.
 */
final class TeamEntryView
{
    private const GAMES = [1, 2, 3];

    /** Mark of a player in a game => catalog key. */
    private const MARKS = ['' => 'team_entry.mark.none', 'plays' => 'team_entry.mark.plays', 'blind' => 'team_entry.mark.blind'];

    private readonly Formatter $format;

    public function __construct(private readonly Translator $t = new Translator())
    {
        $this->format = new Formatter($t);
    }

    /**
     * Complete team page.
     *
     * @param array<string, mixed> $v view data, see {@see TeamEntryController::viewData()}
     */
    public function renderPage(array $v, ?string $message, ?string $error): string
    {
        $header = '<header class="page-header"><p class="page-header__kicker">' . $this->text('team_entry.kicker') . '</p>'
            . '<h1>' . $this->e($v['own']['name']) . ' – ' . $this->e($v['opponent']['name']) . '</h1>'
            . '<p class="page-header__lead">' . $this->e($v['title']) . '</p></header>';

        $refresh = $v['step'] === TeamEntryController::STEP_STATUS && ! $v['state']['finished'] ? '1' : '0';

        return $header
            . sprintf(
                '<div id="team-entry" class="team-entry js-team-entry" data-code="%s" data-step="%d" data-refresh="%s">',
                $this->e($v['code']),
                (int) $v['step'],
                $refresh,
            )
            . $this->renderSheet($v, $message, $error)
            . '</div>'
            . '<script src="/assets/js/team-entry.js" defer></script>';
    }

    /** Message for an invalid, foreign or expired code. */
    public function renderUnavailable(string $error): string
    {
        return '<header class="page-header"><p class="page-header__kicker">' . $this->text('team_entry.kicker') . '</p>'
            . '<h1>' . $this->text('team_entry.not_possible') . '</h1></header>'
            . '<p class="error" role="alert">' . $this->e($error) . '</p>';
    }

    /**
     * The replaceable part of the page (refreshed by `team-entry.js` on the
     * confirmation step).
     *
     * @param array<string, mixed> $v
     */
    public function renderSheet(array $v, ?string $message, ?string $error): string
    {
        $content = match (true) {
            $v['step'] <= 3                                   => $this->renderGame($v),
            $v['step'] === TeamEntryController::STEP_OVERVIEW => $this->renderOverview($v),
            default                                           => $this->renderStatus($v),
        };

        return AdminComponents::notes($message, $error)
            . $this->renderSteps($v)
            . $this->renderBanners($v)
            . $content;
    }

    /**
     * Admin card on the virtual score sheet: state of both sides, links and QR
     * codes for the teams, takeover and new codes.
     *
     * @param array<string, mixed> $state see {@see TeamEntryController::adminFixtureCard()}
     */
    public function renderAdminFixture(array $state, string $csrfField): string
    {
        $sides = '';
        foreach (['home', 'away'] as $side) {
            $item = $state[$side];
            $qr = $item['qr'] !== null
                ? '<div class="team-entry__qr" aria-hidden="true">' . $item['qr'] . '</div>'
                : '';
            $link = $item['url'] !== null
                ? '<a href="' . $this->e($item['url']) . '" target="_blank" rel="noopener">' . $this->text('team_entry.admin.open_page') . '</a>'
                : '';
            $sides .= sprintf(
                '<div class="team-entry__admin-side">%s<div><h3>%s</h3><p>%s</p>%s%s</div></div>',
                $qr,
                $this->e($item['name']),
                $this->e($item['status']),
                $item['rejection'] !== null
                    ? '<p class="notice notice--check">' . $this->text('team_entry.admin.rejected', ['note' => $item['rejection']]) . '</p>'
                    : '',
                $link,
            );
        }

        $fixtureId = (int) $state['fixture_id'];
        $takeOver = $this->text('team_entry.admin.take_over');
        $newCodes = $this->text('team_entry.admin.new_codes');
        $hint = $this->text('team_entry.admin.take_over_hint');
        $actions = $state['closed']
            ? '<p class="notice">' . $this->text('team_entry.admin.closed') . '</p>'
            : <<<HTML
                <div class="action-bar">
                    <form method="post" action="/admin/results/team-entry-take-over">
                        {$csrfField}<input type="hidden" name="fixture_id" value="{$fixtureId}">
                        <button type="submit" class="button">{$takeOver}</button>
                    </form>
                    <form method="post" action="/admin/results/team-entry-codes">
                        {$csrfField}<input type="hidden" name="fixture_id" value="{$fixtureId}">
                        <button type="submit" class="button button--secondary">{$newCodes}</button>
                    </form>
                </div>
                <p class="card__sub">{$hint}</p>
                HTML;

        return AdminComponents::card(
            $this->t->t('team_entry.admin.card'),
            '<div class="team-entry__admin">' . $sides . '</div>' . $actions,
            $this->t->t('team_entry.admin.net_count', ['count' => (int) $state['net_count']]),
        );
    }

    /**
     * Admin card on the results overview: switch for the season and fixtures
     * whose team entry is running.
     *
     * @param list<array{fixture_id: int, matchday_number: int, date: string, home: string, away: string}> $active
     */
    public function renderAdminSeason(int $seasonId, bool $enabled, array $active, string $csrfField): string
    {
        $checked = $enabled ? ' checked' : '';
        $switch = $this->text('team_entry.admin.switch');
        $apply = $this->text('common.apply');
        $form = <<<HTML
            <form class="form" method="post" action="/admin/results/team-entry">
                {$csrfField}
                <input type="hidden" name="season_id" value="{$seasonId}">
                <div class="form__field"><label class="checkbox"><input type="checkbox" name="enabled" value="1"{$checked}> {$switch}</label></div>
                <button type="submit" class="button button--secondary">{$apply}</button>
            </form>
            HTML;

        $list = '';
        foreach ($active as $fixture) {
            $list .= sprintf(
                '<li><a href="/admin/results/score-sheet?fixture=%d">%s</a></li>',
                $fixture['fixture_id'],
                $this->text('team_entry.admin.fixture_line', [
                    'number' => $fixture['matchday_number'],
                    'date'   => $this->format->date($fixture['date']),
                    'home'   => $fixture['home'],
                    'away'   => $fixture['away'],
                ]),
            );
        }
        $running = $list !== ''
            ? '<h3>' . $this->text('team_entry.admin.running') . '</h3><ul class="data-list">' . $list . '</ul>'
            : ($enabled ? '<p class="card__sub">' . $this->text('team_entry.admin.none_running') . '</p>' : '');

        return AdminComponents::card($this->t->t('team_entry.admin.card'), $form . $running);
    }

    // --- Parts of the team page ------------------------------------------------

    /** @param array<string, mixed> $v */
    private function renderSteps(array $v): string
    {
        $labels = [];
        foreach (self::GAMES as $game) {
            $labels[$game] = $this->text('common.game_number', ['number' => $game]);
        }
        $labels[TeamEntryController::STEP_OVERVIEW] = $this->text('team_entry.step.overview');
        if (! $v['state']['ghost']) {
            $labels[TeamEntryController::STEP_STATUS] = $this->text('team_entry.step.confirmation');
        }

        $items = '';
        foreach ($labels as $step => $label) {
            $done = match (true) {
                $step <= 3                                   => $v['complete'][$step],
                $step === TeamEntryController::STEP_OVERVIEW => $v['state']['own_submitted'],
                default                                      => $v['state']['final'],
            };
            $current = $step === $v['step'];
            $class = 'wizard-steps__item' . ($current ? ' is-current' : '') . ($done ? ' is-done' : '');
            $inner = '<span class="wizard-steps__number" aria-hidden="true">' . ($done && ! $current ? '✓' : $step) . '</span>'
                . '<span class="wizard-steps__label">' . $label
                . ($done ? '<span class="visually-hidden"> ' . $this->text('team_entry.step.done') . '</span>' : '') . '</span>';
            $items .= $current
                ? '<li class="' . $class . '" aria-current="step">' . $inner . '</li>'
                : '<li class="' . $class . '"><a href="' . $this->e(TeamEntryController::stepUrl($v['code'], $step)) . '">' . $inner . '</a></li>';
        }

        return '<nav class="wizard-steps" aria-label="' . $this->text('team_entry.steps') . '"><ol>' . $items . '</ol></nav>';
    }

    /**
     * Notes that matter on every step: the own entry was sent back, the
     * opponent's entry waits for confirmation.
     *
     * @param array<string, mixed> $v
     */
    private function renderBanners(array $v): string
    {
        $s = $v['state'];
        if ($s['finished']) {
            return '';
        }

        $team = $v['opponent']['name'];
        $html = '';
        if ($s['opponent_rejection'] !== null && ! $s['own_submitted']) {
            $html .= '<p class="notice notice--check" role="status">'
                . $this->text('team_entry.banner.rejected', ['team' => $team, 'note' => $s['opponent_rejection']]) . '</p>';
        }
        if ($s['can_confirm'] && $v['step'] !== TeamEntryController::STEP_STATUS) {
            $link = '<a href="' . $this->e(TeamEntryController::stepUrl($v['code'], TeamEntryController::STEP_STATUS)) . '">'
                . $this->text('team_entry.step.confirmation') . '</a>';
            $html .= '<p class="notice" role="status">' . $this->text('team_entry.banner.confirm', ['team' => $team], ['link' => $link]) . '</p>';
        }

        return $html;
    }

    /** @param array<string, mixed> $v */
    private function renderGame(array $v): string
    {
        $game = $v['game'];
        $n = (int) $game['number'];
        $code = $this->hiddenCode($v['code']);

        $intro = $n === 1
            ? $this->text('team_entry.game.intro_first')
            : $this->text('team_entry.game.intro_next', ['game' => $n]);
        $takenOver = $game['taken_over']
            ? '<p class="notice" role="status">' . $this->text('team_entry.game.taken_over', ['game' => $n - 1]) . '</p>'
            : '';

        $players = '';
        foreach ($game['rows'] as $row) {
            $players .= $this->renderPlayer($row, $n);
        }

        $fill = '';
        foreach ($game['fill'] as $position) {
            $value = $position['net'] !== null ? (string) (int) $position['net'] : '–';
            $hint = match (true) {
                $position['net'] === null => 'team_entry.fill.pending',
                $position['ghost_value']  => 'team_entry.fill.ghost_value',
                $position['from_evening'] => 'team_entry.fill.from_evening',
                default                   => 'team_entry.fill.average_minus',
            };
            $fill .= '<li class="wizard-fill__item"><span>' . $this->e($position['text']) . '<small>' . $this->text($hint) . '</small></span>'
                . '<strong>' . $value . '</strong></li>';
        }
        $filled = count($game['fill']);
        $fillSection = $fill !== ''
            ? '<h3 class="wizard-fill__title">' . $this->text('team_entry.fill.title') . '</h3><ul class="wizard-fill">' . $fill . '</ul>'
            : '';

        $count = $this->t->t('team_entry.game.count', ['playing' => (int) $game['playing'], 'blind' => $filled]);
        $next = $n < 3 ? $this->text('team_entry.game.next', ['game' => $n + 1]) : $this->text('team_entry.game.to_overview');
        $nextStep = $n + 1;
        $back = $n > 1
            ? sprintf('<button type="submit" name="next" value="%d" class="button button--secondary">%s</button>', $n - 1, $this->text('team_entry.game.back', ['game' => $n - 1]))
            : '';

        $card = AdminComponents::card(
            $this->t->t('team_entry.game.title', ['game' => $n]),
            '<p class="card__sub">' . $intro . '</p>' . $takenOver
            . '<ul class="wizard-players">' . $players . '</ul>' . $fillSection,
            $count,
        );

        return <<<HTML
            <form class="wizard__form js-wizard-game" method="post" action="/entry/save">
                {$code}
                <input type="hidden" name="game" value="{$n}">
                {$card}
                <div class="wizard__nav">
                    <button type="submit" name="next" value="{$nextStep}" class="button">{$next}</button>
                    {$back}
                </div>
            </form>
            {$this->renderStanding($v, $n)}
            HTML;
    }

    /** @param array<string, mixed> $row */
    private function renderPlayer(array $row, int $game): string
    {
        $id = (int) $row['player_id'];
        $label = $this->playerLabel($row);
        $lineup = $this->text('team_entry.player.lineup');

        if (! $row['can_play'] && $row['mark'] !== 'plays') {
            // Left or dropped out earlier: can only stay blind, otherwise nothing to choose.
            if ($row['mark'] === 'blind' || $row['can_blind']) {
                $options = '';
                foreach (['', 'blind'] as $value) {
                    $options .= sprintf('<option value="%s"%s>%s</option>', $value, $value === $row['mark'] ? ' selected' : '', $this->text(self::MARKS[$value]));
                }

                return sprintf(
                    '<li class="wizard-player"><div class="wizard-player__name">%s</div><div class="wizard-player__fields">'
                    . '<label class="wizard-player__field" for="lineup-%d"><span>%s</span><select name="lineup[%d]" id="lineup-%d" class="js-mark">%s</select></label></div></li>',
                    $label, $id, $lineup, $id, $id, $options,
                );
            }

            return sprintf(
                '<li class="wizard-player is-out"><div class="wizard-player__name">%s</div><p class="wizard-player__status">%s</p></li>',
                $label,
                $this->text('team_entry.player.out', ['status' => $this->t->t('team_entry.player.status.' . $row['status'])]),
            );
        }

        $options = '';
        foreach (self::MARKS as $value => $key) {
            if ($value === 'blind' && ! $row['can_blind'] && $row['mark'] !== 'blind') {
                continue;
            }
            $options .= sprintf('<option value="%s"%s>%s</option>', $value, $value === $row['mark'] ? ' selected' : '', $this->text($key));
        }
        $net = $row['net'] !== null ? (string) (int) $row['net'] : '';

        return sprintf(
            '<li class="wizard-player"><div class="wizard-player__name">%s</div><div class="wizard-player__fields">'
            . '<label class="wizard-player__field" for="lineup-%d"><span>%s</span><select name="lineup[%d]" id="lineup-%d" class="js-mark">%s</select></label>'
            . '<label class="wizard-player__field js-net-field" for="net-%d"><span>%s</span>'
            . '<input type="number" inputmode="numeric" min="0" max="300" step="1" name="nets[%d]" id="net-%d" value="%s" aria-label="%s"></label>'
            . '</div></li>',
            $label, $id, $lineup, $id, $id, $options, $id, $this->text('common.net'), $id, $id, $this->e($net),
            $this->text('team_entry.player.net_label', ['player' => $row['name'], 'game' => $game]),
        );
    }

    /** @param array<string, mixed> $v */
    private function renderOverview(array $v): string
    {
        $s = $v['state'];
        $code = $this->hiddenCode($v['code']);
        $team = ['team' => $v['opponent']['name']];

        $edit = '';
        if ($s['can_edit']) {
            foreach (self::GAMES as $game) {
                $edit .= sprintf(
                    '<a class="button button--secondary" href="%s">%s</a>',
                    $this->e(TeamEntryController::stepUrl($v['code'], $game)),
                    $this->text('team_entry.overview.edit_game', ['game' => $game]),
                );
            }
            $edit = '<div class="wizard__edit">' . $edit . '</div>';
        }

        $table = AdminComponents::card(
            $this->t->t('team_entry.overview.title', ['team' => $v['own']['name']]),
            $this->renderTeamTable($v['own'], $v['calculated']['own']) . $edit,
            $this->t->t('team_entry.overview.sub'),
        );

        if ($s['final']) {
            $action = '<p class="notice notice--ok" role="status">' . $this->text('team_entry.final') . '</p>';
        } elseif ($s['finished']) {
            $action = '<p class="notice" role="status">' . $this->text('team_entry.ended') . '</p>';
        } elseif ($s['can_submit']) {
            [$text, $button] = $s['ghost']
                ? [$this->text('team_entry.submit.ghost_text'), $this->text('team_entry.submit.ghost_button')]
                : [$this->text('team_entry.submit.text', $team), $this->text('team_entry.submit.button')];
            $action = $this->form('team_entry.submit.title', '/entry/submit', $code, $text, $button, 'button');
        } elseif ($s['can_withdraw']) {
            $action = $this->form(
                'team_entry.sent.title',
                '/entry/withdraw',
                $code,
                $this->text('team_entry.sent.text', $team),
                $this->text('team_entry.withdraw.button'),
                'button button--secondary',
            );
        } else {
            $action = '<p class="notice notice--ok" role="status">' . $this->text('team_entry.confirmed_by', $team) . '</p>';
        }

        return $table . $action . $this->renderStanding($v, null);
    }

    /** @param array<string, mixed> $v */
    private function renderStatus(array $v): string
    {
        $s = $v['state'];
        $code = $this->hiddenCode($v['code']);
        $team = ['team' => $v['opponent']['name']];

        if ($s['final']) {
            return '<p class="notice notice--ok" role="status">' . $this->text('team_entry.status.final') . '</p>'
                . $this->renderStanding($v, null);
        }
        if ($s['finished']) {
            return '<p class="notice" role="status">' . $this->text('team_entry.ended') . '</p>';
        }

        $overview = '<a href="' . $this->e(TeamEntryController::stepUrl($v['code'], TeamEntryController::STEP_OVERVIEW)) . '">'
            . $this->text('team_entry.status.to_overview') . '</a>';
        $items = [
            $s['own_submitted']
                ? [true, $this->text('team_entry.status.own_sent')]
                : [false, $this->text('team_entry.status.own_not_sent', [], ['link' => $overview])],
            $s['opponent_confirmed']
                ? [true, $this->text('team_entry.confirmed_by', $team)]
                : [false, $this->text($s['own_submitted'] ? 'team_entry.status.opponent_checks' : 'team_entry.status.opponent_will_confirm', $team)],
            $s['opponent_submitted'] || $s['own_confirmed']
                ? [true, $this->text('team_entry.status.opponent_sent', $team)]
                : [false, $this->text('team_entry.error.not_submitted', $team)],
            match (true) {
                $s['own_confirmed']      => [true, $this->text('team_entry.status.own_confirmed', $team)],
                $s['opponent_submitted'] => [false, $this->text('team_entry.status.own_checks', $team)],
                default                  => [false, $this->text('team_entry.status.own_will_confirm', $team)],
            },
        ];
        $open = '<span class="visually-hidden"> ' . $this->text('team_entry.status.open') . '</span>';
        $list = '';
        foreach ($items as [$done, $text]) {
            $list .= '<li class="wizard-checklist__item' . ($done ? ' is-done' : '') . '"><span class="wizard-checklist__mark" aria-hidden="true">'
                . ($done ? '✓' : '○') . '</span><span>' . $text . ($done ? '' : $open) . '</span></li>';
        }
        $checklist = AdminComponents::card(
            $this->t->t('team_entry.status.title'),
            '<p class="card__sub">' . $this->text('team_entry.status.intro') . '</p>'
            . '<ul class="wizard-checklist">' . $list . '</ul>',
        );

        $review = '';
        if ($s['can_confirm']) {
            $confirm = $this->text('team_entry.review.confirm', $team);
            $whatWrong = $this->text('team_entry.review.what_wrong');
            $reject = $this->text('team_entry.review.reject');
            $review = AdminComponents::card(
                $this->t->t('team_entry.review.title', $team),
                '<p class="card__sub">' . $this->text('team_entry.review.intro') . '</p>'
                . $this->renderTeamTable($v['opponent'], $v['calculated']['opponent'])
                . <<<HTML
                    <div class="team-entry__step">
                        <form method="post" action="/entry/confirm">
                            {$code}
                            <button type="submit" class="button">{$confirm}</button>
                        </form>
                    </div>
                    <form method="post" action="/entry/reject" class="form team-entry__step">
                        {$code}
                        <div class="form__field">
                            <label for="rejection-note">{$whatWrong}</label>
                            <textarea id="rejection-note" name="note" maxlength="500" rows="2" required></textarea>
                        </div>
                        <button type="submit" class="button button--secondary">{$reject}</button>
                    </form>
                    HTML,
            );
        } elseif ($s['own_rejection'] !== null && ! $s['opponent_submitted']) {
            $review = '<p class="notice notice--check" role="status">'
                . $this->text('team_entry.review.rejected', $team + ['note' => $s['own_rejection']]) . '</p>';
        }

        return $checklist . $review . $this->renderStanding($v, null);
    }

    /**
     * Table of a team: per row the nets of the games (blind marked), the gross
     * below, totals per row and per game.
     *
     * @param array<string, mixed> $team       view data of the team
     * @param array<string, mixed> $calculated calculated values of the team
     */
    private function renderTeamTable(array $team, array $calculated): string
    {
        $gameLabels = [];
        foreach (self::GAMES as $game) {
            $gameLabels[$game] = $this->text('common.game_number', ['number' => $game]);
        }
        $total = $this->text('common.total');

        $rows = '';
        foreach ($team['rows'] as $key => $row) {
            $cells = '';
            foreach (self::GAMES as $game) {
                $entry = $row['games'][$game] ?? null;
                if ($entry === null) {
                    $cells .= '<td class="team-entry__none" data-label="' . $gameLabels[$game] . '">–</td>';
                    continue;
                }
                $gross = $calculated['rows'][$key]['gross'][$game] ?? null;
                $cells .= '<td data-label="' . $gameLabels[$game] . '"><span class="team-entry__value">'
                    . ($entry['net'] !== null ? (int) $entry['net'] : '–') . '</span>'
                    . ($entry['blind'] ? ' <span class="badge badge--blind">' . $this->text('common.blind_lower') . '</span>' : '')
                    . ($gross !== null ? '<small class="team-entry__hint">' . (int) $gross . '</small>' : '')
                    . '</td>';
            }
            $rowTotal = $calculated['rows'][$key] ?? null;
            $cells .= '<td data-label="' . $total . '"><span class="team-entry__value">' . ($rowTotal['net_total'] ?? '–') . '</span>'
                . (($rowTotal['gross_total'] ?? null) !== null ? '<small class="team-entry__hint">' . (int) $rowTotal['gross_total'] . '</small>' : '')
                . '</td>';

            $name = $row['slot']
                ? $this->text('team_entry.table.slot') . ' <small>' . $this->text('team_entry.fill.ghost_value') . '</small>'
                : $this->playerLabel($row) . ($row['blind_row'] ? ' <small>' . $this->text('team_entry.table.as_blind') . '</small>' : '');
            $rows .= '<tr><th scope="row">' . $name . '</th>' . $cells . '</tr>';
        }

        $sum = '';
        foreach (self::GAMES as $game) {
            $g = $calculated['games'][$game];
            $sum .= '<td data-label="' . $gameLabels[$game] . '">' . ($g['net'] !== null
                ? '<span class="team-entry__value">' . (int) $g['net'] . '</span><small class="team-entry__hint">' . (int) $g['gross'] . '</small>'
                : '<span class="team-entry__none">' . (int) $g['count'] . '/4</span>') . '</td>';
        }
        $sum .= '<td data-label="' . $total . '">' . ($calculated['net_total'] !== null
            ? '<span class="team-entry__value">' . (int) $calculated['net_total'] . '</span><small class="team-entry__hint">' . (int) $calculated['gross_total'] . '</small>'
            : '–') . '</td>';

        $head = '';
        foreach (self::GAMES as $game) {
            $head .= '<th scope="col">' . $gameLabels[$game] . '</th>';
        }

        return '<table class="team-entry__table team-entry__table--overview"><thead><tr><th scope="col">' . $this->text('common.player') . '</th>'
            . $head . '<th scope="col">' . $total . '</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody><tfoot><tr><th scope="row">' . $this->text('common.team') . '</th>' . $sum . '</tr></tfoot></table>';
    }

    /**
     * Standing in gross and points for the games both teams have entered
     * completely; only up to the given game on a game step.
     *
     * @param array<string, mixed> $v
     */
    private function renderStanding(array $v, ?int $upTo): string
    {
        $own = $v['calculated']['own'];
        $opponent = $v['calculated']['opponent'];
        $ownName = $this->e($v['own']['name']);
        $opponentName = $this->e($v['opponent']['name']);
        $points = $this->text('common.points');

        $lines = '';
        foreach (self::GAMES as $game) {
            if ($upTo !== null && $game > $upTo) {
                break;
            }
            $a = $own['games'][$game];
            $b = $opponent['games'][$game];
            if ($a['gross'] === null || $b['gross'] === null) {
                continue;
            }
            $lines .= sprintf(
                '<tr><th scope="row">%s</th><td data-label="%s">%d</td><td data-label="%s">%d</td><td data-label="%s">%d : %d</td></tr>',
                $this->text('common.game_number', ['number' => $game]),
                $ownName,
                (int) $a['gross'],
                $opponentName,
                (int) $b['gross'],
                $points,
                (int) $a['points'],
                (int) $b['points'],
            );
        }
        if ($lines === '') {
            return '';
        }
        if ($upTo === null && $own['total'] !== null && $opponent['total'] !== null) {
            $lines .= sprintf(
                '<tr class="team-entry__total"><th scope="row">%s</th><td data-label="%s">%d</td><td data-label="%s">%d</td><td data-label="%s">%d : %d</td></tr>',
                $this->text('common.total'),
                $ownName,
                (int) $own['gross_total'],
                $opponentName,
                (int) $opponent['gross_total'],
                $points,
                (int) $own['total'],
                (int) $opponent['total'],
            );
        }

        return AdminComponents::card(
            $this->t->t('team_entry.standing.title'),
            '<table class="team-entry__table"><thead><tr><th scope="col">' . $this->text('team_entry.game') . '</th><th scope="col">' . $ownName
            . '</th><th scope="col">' . $opponentName . '</th><th scope="col">' . $points . '</th></tr></thead><tbody>' . $lines . '</tbody></table>',
            $this->t->t($v['state']['ghost'] ? 'team_entry.standing.sub_ghost' : 'team_entry.standing.sub'),
        );
    }

    /** @param array<string, mixed> $row */
    private function playerLabel(array $row): string
    {
        $details = [];
        if ($row['average'] !== null) {
            $details[] = 'AVG ' . $this->format->number((float) $row['average'], 0);
        }
        if ($row['hdc'] !== null) {
            $details[] = 'HDC ' . (int) $row['hdc'];
        }

        return $this->e($row['name']) . ($details !== [] ? ' <small>' . $this->e(implode(' · ', $details)) . '</small>' : '');
    }

    /** Card with one form: an explanation and one button. */
    private function form(string $titleKey, string $action, string $code, string $text, string $button, string $buttonClass): string
    {
        return AdminComponents::card($this->t->t($titleKey), <<<HTML
            <form method="post" action="{$action}" class="team-entry__step">
                {$code}
                <p>{$text}</p>
                <button type="submit" class="{$buttonClass}">{$button}</button>
            </form>
            HTML);
    }

    private function hiddenCode(string $code): string
    {
        return '<input type="hidden" name="code" value="' . $this->e($code) . '">';
    }

    /**
     * Escaped catalog text; `$params` are escaped, `$html` is inserted as it
     * is (markup built by the view, e.g. a link).
     *
     * @param array<string, string|int> $params
     * @param array<string, string>     $html
     */
    private function text(string $key, array $params = [], array $html = []): string
    {
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = $this->e((string) $value);
        }
        foreach ($html as $name => $value) {
            $replace['{' . $name . '}'] = $value;
        }

        return strtr($this->e($this->t->t($key)), $replace);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
