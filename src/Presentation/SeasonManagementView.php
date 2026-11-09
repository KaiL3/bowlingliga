<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\Schedule\Postponement;
use Bowlingliga\Application\SeasonConfiguration;
use Bowlingliga\Domain\Handicap\HandicapFormula;
use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\Languages;
use Bowlingliga\I18n\Translator;

/**
 * View of the admin area "Saison": master data, configuration and
 * positiondays of a season, each as a card with a form.
 *
 * All values are escaped context-aware; every form contains the CSRF field
 * (R22.5).
 */
final class SeasonManagementView
{
    use ViewTexts;

    private readonly Formatter $format;

    public function __construct(private readonly Translator $t = new Translator())
    {
        $this->format = new Formatter($t);
    }

    public function withoutSeason(): string
    {
        return AdminComponents::pageHeader($this->plain('admin.area.season.title'), $this->plain('layout.admin_area'))
            . '<p class="notice">' . $this->text('season.none', [], ['link' => '<a href="/admin/master-data">' . $this->text('admin.area.master_data.title') . '</a>']) . '</p>';
    }

    /**
     * @param array{id: int, name: string, start_date: string, end_date: string, score_sheet_language?: string} $season
     * @param array{lane_count: int, entry_fee: string, blind_fee: string, max_handicap: int, handicap_formula: string|null, target_score: int, ghost_score: int, previous_season_matchdays: int, positionday_interval: int, win_points?: int, draw_points?: int, loss_points?: int, bonus_threshold?: int, bonus_step?: int, bonus_maximum?: int}|null $configuration
     * @param list<array{id: int, number: int, date: string, ready: bool, locked: bool, pairings: list<string>}> $positiondays
     * @param array<string, mixed>|null $prefill
     * @param list<array{id: int, number: int, date: string, locked: bool, fixtures: list<array{home: int, away: int}>}> $lotDraws
     * @param list<array{id: int, name: string}> $teams
     * @param array{matchdays: list<array{id: int, season_id: int, number: int, date: string, type: string, is_cancelled: bool, has_results: bool, is_complete: bool, can_reinstate?: bool}>, preview: Postponement|null}|null $schedule
     */
    public function page(
        string $csrfField,
        array $season,
        ?array $configuration,
        array $positiondays,
        bool $hasStarted,
        ?string $message,
        ?string $error,
        ?array $prefill,
        array $lotDraws = [],
        array $teams = [],
        ?array $schedule = null,
    ): string {
        $header = AdminComponents::pageHeader(
            $season['name'],
            $this->plain('admin.area.season.title'),
            sprintf(
                '<p class="page-header__meta">%s – %s · %s</p>',
                $this->e($this->format->date($season['start_date'])),
                $this->e($this->format->date($season['end_date'])),
                $this->text($hasStarted ? 'season.started' : 'season.not_started'),
            ),
        );

        return $header . AdminComponents::notes($message, $error)
            . '<div class="grid grid--work">'
            . $this->configuration($csrfField, $season['id'], $configuration, $hasStarted, $prefill)
            . $this->masterData($csrfField, $season, $prefill)
            . '</div>'
            . ($schedule !== null ? $this->schedule($csrfField, $season['id'], $schedule['matchdays'], $schedule['preview']) : '')
            . $this->positiondays($csrfField, $positiondays)
            . $this->lotDraws($csrfField, $lotDraws, $teams, $prefill);
    }

    /**
     * @param array{id: int, name: string, start_date: string, end_date: string} $season
     * @param array<string, mixed>|null $prefill
     */
    private function masterData(string $csrfField, array $season, ?array $prefill): string
    {
        $value = fn (string $field, string $standard): string => $this->e(
            isset($prefill[$field]) && is_string($prefill[$field]) && isset($prefill['area']) && $prefill['area'] === 'master-data'
                ? $prefill[$field]
                : $standard
        );

        $chosen = $value('score_sheet_language', (string) ($season['score_sheet_language'] ?? Languages::DEFAULT));
        $languages = '';
        // Every language in its own name, as in the switch of the footer.
        foreach (Languages::names() as $code => $name) {
            $languages .= sprintf('<option value="%s"%s>%s</option>', $code, $code === $chosen ? ' selected' : '', AdminComponents::e($name));
        }

        return <<<HTML
            <section class="card">
                <div class="card__header"><h2>{$this->text('season.name_and_period')}</h2></div>
                <form class="form" method="post" action="/admin/season/master-data">
                    {$csrfField}
                    <input type="hidden" name="season_id" value="{$season['id']}">
                    <div class="form__field">
                        <label for="season-name">{$this->text('field.name')}</label>
                        <input type="text" id="season-name" name="name" value="{$value('name', $season['name'])}" maxlength="100" required>
                    </div>
                    <div class="form__field">
                        <label for="season-start">{$this->text('field.start_date')}</label>
                        <input type="date" id="season-start" name="start_date" value="{$value('start_date', $season['start_date'])}" required>
                    </div>
                    <div class="form__field">
                        <label for="season-end">{$this->text('field.end_date')}</label>
                        <input type="date" id="season-end" name="end_date" value="{$value('end_date', $season['end_date'])}" required>
                    </div>
                    <div class="form__field">
                        <label for="season-language">{$this->text('season.score_sheet_language')}</label>
                        <select id="season-language" name="score_sheet_language" aria-describedby="season-language-note">{$languages}</select>
                        <small id="season-language-note">{$this->text('season.score_sheet_language_hint')}</small>
                    </div>
                    <button type="submit" class="button">{$this->text('season.save_master_data')}</button>
                </form>
            </section>
            HTML;
    }

    /**
     * @param array{lane_count: int, entry_fee: string, blind_fee: string, max_handicap: int, handicap_formula: string|null, target_score: int, ghost_score: int, previous_season_matchdays: int, positionday_interval: int, win_points?: int, draw_points?: int, loss_points?: int, bonus_threshold?: int, bonus_step?: int, bonus_maximum?: int}|null $configuration
     * @param array<string, mixed>|null $prefill
     */
    private function configuration(string $csrfField, int $seasonId, ?array $configuration, bool $hasStarted, ?array $prefill): string
    {
        $configuration ??= [
            'lane_count' => 2, 'entry_fee' => '0.00', 'blind_fee' => '0.00',
            'max_handicap' => SeasonConfiguration::DEFAULT_MAX_HANDICAP, 'handicap_formula' => null,
            'target_score' => SeasonConfiguration::DEFAULT_TARGET_SCORE, 'ghost_score' => SeasonConfiguration::DEFAULT_GHOST_SCORE,
            'previous_season_matchdays' => SeasonConfiguration::DEFAULT_PREVIOUS_SEASON_MATCHDAYS,
            'positionday_interval' => SeasonConfiguration::DEFAULT_POSITIONDAY_INTERVAL,
        ];
        // R17.11: points rules of the season, defaults for older rows.
        $points = SeasonConfiguration::pointsRulesFromRow($configuration);

        $values = [
            'lane_count'           => (string) $configuration['lane_count'],
            'entry_fee'            => $this->amount($configuration['entry_fee']),
            'blind_fee' => $this->amount($configuration['blind_fee']),
            'max_handicap'     => (string) $configuration['max_handicap'],
            'handicap_factor'      => $this->factor($configuration['handicap_formula']),
            'target_score'             => (string) $configuration['target_score'],
            'ghost_score'              => (string) $configuration['ghost_score'],
            'previous_season_matchdays' => (string) $configuration['previous_season_matchdays'],
            'positionday_interval'     => (string) $configuration['positionday_interval'],
            'win_points'               => (string) $points->base->win,
            'draw_points'              => (string) $points->base->draw,
            'loss_points'              => (string) $points->base->loss,
            'bonus_threshold'          => (string) $points->bonus->threshold,
            'bonus_step'               => (string) $points->bonus->step,
            'bonus_maximum'            => (string) $points->bonus->maximum,
        ];

        if ($hasStarted) {
            $formula = $values['handicap_factor'] === ''
                ? $this->plain('season.without_handicap')
                : $this->plain('season.formula', ['factor' => $values['handicap_factor'], 'max' => $values['max_handicap']]);

            return sprintf(
                '<section class="card"><div class="card__header"><h2>' . $this->text('season.configuration') . '</h2></div>'
                // R12.7: no longer changeable after the season start.
                . '<p class="notice">' . $this->text('season.configuration_locked') . '</p>'
                . '<dl class="key-figures">'
                . '<div><dt>' . $this->text('field.lane_count') . '</dt><dd>%s</dd></div><div><dt>' . $this->text('field.entry_fee') . '</dt><dd>%s €</dd></div>'
                . '<div><dt>' . $this->text('field.blind_fee') . '</dt><dd>%s €</dd></div>'
                . '<div><dt>' . $this->text('season.target_score') . '</dt><dd>%s</dd></div><div><dt>' . $this->text('season.handicap') . '</dt><dd>%s</dd></div>'
                . '<div><dt>' . $this->text('master_data.ghost_team') . '</dt><dd>%s</dd></div><div><dt>' . $this->text('season.previous_in_average') . '</dt><dd>%s</dd></div>'
                . '<div><dt>' . $this->text('value.matchday_type.positionday') . '</dt><dd>%s</dd></div>'
                . '<div><dt>' . $this->text('season.game_points') . '</dt><dd>%s</dd></div><div><dt>' . $this->text('season.bonus_points') . '</dt><dd>%s</dd></div></dl></section>',
                $this->e($values['lane_count']),
                $this->e($values['entry_fee']),
                $this->e($values['blind_fee']),
                $this->e($values['target_score']),
                $this->e($formula),
                $this->text('season.pins_per_game', ['pins' => $values['ghost_score']]),
                $this->e($this->previousSeasonText((int) $values['previous_season_matchdays'])),
                $this->e($this->positiondayText((int) $values['positionday_interval'])),
                $this->text('season.game_points_value', ['win' => $values['win_points'], 'draw' => $values['draw_points'], 'loss' => $values['loss_points']]),
                $points->bonus->maximum === 0
                    ? $this->text('season.no_bonus_points')
                    : $this->text('season.bonus_scale', ['threshold' => $values['bonus_threshold'], 'step' => $values['bonus_step'], 'maximum' => $values['bonus_maximum']]),
            );
        }

        if (($prefill['area'] ?? null) === 'configuration') {
            foreach (array_keys($values) as $field) {
                if (isset($prefill[$field]) && is_string($prefill[$field])) {
                    $values[$field] = $prefill[$field];
                }
            }
        }
        $w = array_map(fn (string $v): string => $this->e($v), $values);

        return <<<HTML
            <section class="card">
                <div class="card__header"><h2>{$this->text('season.configuration')}</h2><span class="card__sub">{$this->text('season.changeable_until_start')}</span></div>
                <form class="form form--grid" method="post" action="/admin/season/configuration">
                    {$csrfField}
                    <input type="hidden" name="season_id" value="{$seasonId}">
                    <div class="form__field">
                        <label for="config-lanes">{$this->text('field.lane_count')}</label>
                        <input type="number" id="config-lanes" name="lane_count" value="{$w['lane_count']}" min="2" step="2" inputmode="numeric" required>
                        <small>{$this->text('season.lane_count_hint')}</small>
                    </div>
                    <div class="form__field">
                        <label for="config-entry-fee">{$this->text('field.entry_fee')} (€)</label>
                        <input type="text" id="config-entry-fee" name="entry_fee" value="{$w['entry_fee']}" inputmode="decimal" required>
                    </div>
                    <div class="form__field">
                        <label for="config-fee">{$this->text('field.blind_fee')} (€)</label>
                        <input type="text" id="config-fee" name="blind_fee" value="{$w['blind_fee']}" inputmode="decimal" required>
                    </div>
                    <div class="form__field">
                        <label for="config-target-score">{$this->text('season.target_score')}</label>
                        <input type="number" id="config-target-score" name="target_score" value="{$w['target_score']}" min="0" max="300" inputmode="numeric" required>
                    </div>
                    <div class="form__field">
                        <label for="config-factor">{$this->text('field.handicap_factor')}</label>
                        <input type="text" id="config-factor" name="handicap_factor" value="{$w['handicap_factor']}" inputmode="decimal" aria-describedby="config-factor-note">
                    </div>
                    <div class="form__field">
                        <label for="config-max">{$this->text('field.max_handicap')}</label>
                        <input type="number" id="config-max" name="max_handicap" value="{$w['max_handicap']}" min="0" max="300" inputmode="numeric" required>
                    </div>
                    <p class="form__wide empty" id="config-factor-note">{$this->text('season.factor_hint')}</p>
                    <div class="form__field">
                        <label for="config-ghost">{$this->text('season.ghost_pins')}</label>
                        <input type="number" id="config-ghost" name="ghost_score" value="{$w['ghost_score']}" min="0" max="300" inputmode="numeric" required>
                    </div>
                    <div class="form__field">
                        <label for="config-previous">{$this->text('season.previous_until')}</label>
                        <input type="number" id="config-previous" name="previous_season_matchdays" value="{$w['previous_season_matchdays']}" min="0" max="99" inputmode="numeric" required aria-describedby="config-previous-note">
                        <small id="config-previous-note">{$this->text('season.previous_hint')}</small>
                    </div>
                    <div class="form__field">
                        <label for="config-positionday">{$this->text('season.positionday_every')}</label>
                        <input type="number" id="config-positionday" name="positionday_interval" value="{$w['positionday_interval']}" min="0" max="99" inputmode="numeric" required aria-describedby="config-positionday-note">
                        <small id="config-positionday-note">{$this->text('season.positionday_hint')}</small>
                    </div>
                    <h3 class="form__wide">{$this->text('season.points')}</h3>
                    <div class="form__field">
                        <label for="config-win">{$this->text('field.win_points')}</label>
                        <input type="number" id="config-win" name="win_points" value="{$w['win_points']}" min="0" max="10" inputmode="numeric" required aria-describedby="config-points-note">
                    </div>
                    <div class="form__field">
                        <label for="config-draw">{$this->text('field.draw_points')}</label>
                        <input type="number" id="config-draw" name="draw_points" value="{$w['draw_points']}" min="0" max="10" inputmode="numeric" required aria-describedby="config-points-note">
                    </div>
                    <div class="form__field">
                        <label for="config-loss">{$this->text('field.loss_points')}</label>
                        <input type="number" id="config-loss" name="loss_points" value="{$w['loss_points']}" min="0" max="10" inputmode="numeric" required aria-describedby="config-points-note">
                    </div>
                    <div class="form__field">
                        <label for="config-bonus-threshold">{$this->text('field.bonus_threshold')}</label>
                        <input type="number" id="config-bonus-threshold" name="bonus_threshold" value="{$w['bonus_threshold']}" min="0" max="9999" inputmode="numeric" required aria-describedby="config-points-note">
                    </div>
                    <div class="form__field">
                        <label for="config-bonus-step">{$this->text('field.bonus_step')}</label>
                        <input type="number" id="config-bonus-step" name="bonus_step" value="{$w['bonus_step']}" min="1" max="1000" inputmode="numeric" required aria-describedby="config-points-note">
                    </div>
                    <div class="form__field">
                        <label for="config-bonus-maximum">{$this->text('field.bonus_maximum')}</label>
                        <input type="number" id="config-bonus-maximum" name="bonus_maximum" value="{$w['bonus_maximum']}" min="0" max="20" inputmode="numeric" required aria-describedby="config-points-note">
                    </div>
                    <p class="form__wide empty" id="config-points-note">{$this->text('season.points_hint')}</p>
                    <div class="form__actions"><button type="submit" class="button">{$this->text('season.save_configuration')}</button></div>
                </form>
            </section>
            HTML;
    }

    /**
     * Lot draws: the fixtures drawn on site are entered per matchday; every
     * team plays exactly once, lanes are not stored (R32).
     *
     * @param list<array{id: int, number: int, date: string, locked: bool, fixtures: list<array{home: int, away: int}>}> $lotDraws
     * @param list<array{id: int, name: string}> $teams
     * @param array<string, mixed>|null $prefill
     */
    private function lotDraws(string $csrfField, array $lotDraws, array $teams, ?array $prefill): string
    {
        if ($lotDraws === []) {
            return '';
        }

        $names = [];
        foreach ($teams as $team) {
            $names[$team['id']] = $team['name'];
        }
        $rows = max(1, intdiv(count($teams) + 1, 2));

        $entries = '';
        foreach ($lotDraws as $day) {
            $fixtures = $day['fixtures'];
            $retry = ($prefill['area'] ?? null) === 'lot-draw' && (int) ($prefill['matchday_id'] ?? 0) === $day['id'];
            if ($retry) {
                $fixtures = [];
                for ($i = 0; $i < $rows; $i++) {
                    $fixtures[] = ['home' => (int) ($prefill['home'][$i] ?? 0), 'away' => (int) ($prefill['away'][$i] ?? 0)];
                }
            }

            if ($day['locked']) {
                $list = '';
                foreach ($day['fixtures'] as $fixture) {
                    $list .= '<li>' . $this->e(($names[$fixture['home']] ?? '?') . ' – ' . ($names[$fixture['away']] ?? '?')) . '</li>';
                }
                $content = '<ul class="planning__pairings">' . $list . '</ul><p class="empty">' . $this->text('season.lot_draw_locked') . '</p>';
            } else {
                $fields = '';
                for ($i = 0; $i < $rows; $i++) {
                    $fields .= sprintf(
                        '<div class="lot-draw__row">%s<span aria-hidden="true">–</span>%s</div>',
                        $this->teamSelection("home[{$i}]", $this->plain('season.home_label', ['fixture' => $i + 1, 'number' => $day['number']]), $teams, $fixtures[$i]['home'] ?? 0),
                        $this->teamSelection("away[{$i}]", $this->plain('season.away_label', ['fixture' => $i + 1, 'number' => $day['number']]), $teams, $fixtures[$i]['away'] ?? 0),
                    );
                }
                $content = sprintf(
                    '<form class="form lot-draw" method="post" action="/admin/season/lot-draw">%s'
                    . '<input type="hidden" name="matchday_id" value="%d">%s'
                    . '<button type="submit" class="button">%s</button></form>',
                    $csrfField,
                    $day['id'],
                    $fields,
                    $this->text($day['fixtures'] === [] ? 'season.save_fixtures' : 'season.change_fixtures'),
                );
            }

            $entries .= sprintf(
                '<li class="team-card"><div class="team-card__header"><h3>%s</h3><span class="card__sub">%s%s</span></div>%s</li>',
                $this->text('common.matchday_number', ['number' => $day['number']]),
                $this->e($this->format->date($day['date'], true)),
                $day['fixtures'] === [] ? ' · ' . $this->text('season.not_drawn') : '',
                $content,
            );
        }

        return AdminComponents::card(
            $this->plain('season.lot_draws'),
            '<ul class="team-cards">' . $entries . '</ul>',
            $this->plain('season.lot_draws_hint'),
        );
    }

    /**
     * @param list<array{id: int, name: string}> $teams
     */
    private function teamSelection(string $name, string $label, array $teams, int $selected): string
    {
        $options = '<option value="0">– ' . $this->text('common.team') . ' –</option>';
        foreach ($teams as $team) {
            $options .= sprintf(
                '<option value="%d"%s>%s</option>',
                $team['id'],
                $team['id'] === $selected ? ' selected' : '',
                $this->e($team['name']),
            );
        }

        return sprintf('<select name="%s" aria-label="%s">%s</select>', $this->e($name), $this->e($label), $options);
    }

    /**
     * @param list<array{id: int, number: int, date: string, ready: bool, locked: bool, pairings: list<string>}> $positiondays
     */
    private function positiondays(string $csrfField, array $positiondays): string
    {
        if ($positiondays === []) {
            return AdminComponents::card($this->plain('season.positiondays'), '<p class="empty">' . $this->text('season.no_positionday') . '</p>');
        }

        $entries = '';
        foreach ($positiondays as $day) {
            $fixtures = $day['pairings'] === []
                ? '<p class="empty">' . $this->text('season.no_fixtures_formed') . '</p>'
                : '<ul class="planning__pairings">' . implode('', array_map(
                    fn (string $p): string => '<li>' . $this->e($p) . '</li>',
                    $day['pairings']
                )) . '</ul>';

            if ($day['locked']) {
                $action = '<p class="empty">' . $this->text('season.positionday_locked') . '</p>';
            } elseif (! $day['ready']) {
                $action = '<p class="empty">' . $this->text('season.positionday_waiting') . '</p>';
            } else {
                $action = sprintf(
                    '<form class="inline-form" method="post" action="/admin/season/positionday">%s'
                    . '<input type="hidden" name="matchday_id" value="%d">'
                    . '<button type="submit" class="button">%s</button></form>',
                    $csrfField,
                    $day['id'],
                    $this->text($day['pairings'] === [] ? 'season.form_fixtures' : 'season.form_fixtures_again'),
                );
            }

            $entries .= sprintf(
                '<li class="team-card"><div class="team-card__header"><h3>%s</h3><span class="card__sub">%s</span></div>%s%s</li>',
                $this->text('common.matchday_number', ['number' => $day['number']]),
                $this->e($this->format->date($day['date'], true)),
                $fixtures,
                $action,
            );
        }

        return AdminComponents::card(
            $this->plain('season.positiondays'),
            '<ul class="team-cards">' . $entries . '</ul>',
            $this->plain('season.positiondays_hint'),
        );
    }

    /**
     * Card "Spieltage": every matchday with its state; cancel, take a
     * cancellation back or postpone with a preview (R8.20, R8.21).
     *
     * @param list<array{id: int, season_id: int, number: int, date: string, type: string, is_cancelled: bool, has_results: bool, is_complete: bool, can_reinstate?: bool}> $matchdays
     */
    private function schedule(string $csrfField, int $seasonId, array $matchdays, ?Postponement $preview): string
    {
        if ($matchdays === []) {
            return '';
        }

        $rows = '';
        foreach ($matchdays as $day) {
            [$status, $class] = match (true) {
                $day['is_cancelled'] => ['season.state_cancelled', 'status status--cancelled'],
                $day['is_complete']  => ['season.state_complete', 'status status--complete'],
                $day['has_results']  => ['season.state_in_progress', 'status status--in-progress'],
                default              => ['season.state_open', 'status'],
            };

            $actions = '';
            if ($day['is_cancelled']) {
                // Only for a matchday in the future (R8.20).
                $actions = ! ($day['can_reinstate'] ?? false) ? '' : $this->scheduleButton($csrfField, $seasonId, 'reinstate', $day['id'], 'season.reinstate', 'button--secondary');
            } elseif (! $day['is_complete']) {
                $suggestion = (new \DateTimeImmutable($day['date']))->modify('+7 days')->format('Y-m-d');
                $actions = sprintf(
                    '<form class="inline-form" method="get" action="/admin/season">'
                    . '<input type="hidden" name="season" value="%d"><input type="hidden" name="postpone" value="%d">'
                    . '<label class="visually-hidden" for="postpone-%d">%s</label>'
                    . '<input type="date" id="postpone-%d" name="date" value="%s" min="%s" required>'
                    . '<button type="submit" class="button button--secondary">%s</button></form>',
                    $seasonId,
                    $day['id'],
                    $day['id'],
                    $this->text('season.new_date'),
                    $day['id'],
                    $suggestion,
                    (new \DateTimeImmutable($day['date']))->modify('+1 day')->format('Y-m-d'),
                    $this->text('season.postpone'),
                );
                if (! $day['has_results']) {
                    $actions .= $this->scheduleButton($csrfField, $seasonId, 'cancel', $day['id'], 'season.cancel', 'button--danger');
                }
            }

            $rows .= sprintf(
                '<li class="data-list__row"><span class="data-list__main">%s<span class="data-list__aside">%s · %s</span></span>'
                . '<span class="%s">%s</span><span class="data-list__actions">%s</span></li>',
                $this->text('common.matchday_number', ['number' => $day['number']]),
                $this->e($this->format->date($day['date'], true)),
                $this->e($this->labels()->matchdayType($day['type'])),
                $class,
                $this->text($status),
                $actions,
            );
        }

        return ($preview !== null ? $this->postponementPreview($csrfField, $seasonId, $preview) : '')
            . AdminComponents::card(
                $this->plain('season.matchdays'),
                '<ul class="data-list">' . $rows . '</ul>',
                $this->plain('season.matchdays_hint'),
            );
    }

    private function scheduleButton(string $csrfField, int $seasonId, string $action, int $matchdayId, string $label, string $style): string
    {
        return sprintf(
            '<form class="inline-form" method="post" action="/admin/season/%s">%s'
            . '<input type="hidden" name="season_id" value="%d"><input type="hidden" name="matchday_id" value="%d">'
            . '<button type="submit" class="button %s">%s</button></form>',
            $action,
            $csrfField,
            $seasonId,
            $matchdayId,
            $style,
            $this->text($label),
        );
    }

    /** Old → new dates of a postponement before it is saved (R8.21). */
    private function postponementPreview(string $csrfField, int $seasonId, Postponement $preview): string
    {
        $rows = '';
        foreach ($preview->moves as $move) {
            $rows .= sprintf(
                '<tr><th scope="row">%s%s</th><td>%s</td><td>%s</td></tr>',
                $this->text('common.matchday_number', ['number' => $move['number']]),
                $move['is_cancelled'] ? ' <span class="status status--cancelled">' . $this->text('season.state_cancelled') . '</span>' : '',
                $this->e($this->format->date($move['old'], true)),
                $this->e($this->format->date($move['new'], true)),
            );
        }
        $rows .= sprintf(
            '<tr><th scope="row">%s</th><td>%s</td><td>%s</td></tr>',
            $this->text('field.end_date'),
            $this->e($this->format->date($preview->oldSeasonEnd)),
            $this->e($this->format->date($preview->newSeasonEnd)),
        );

        return AdminComponents::card(
            $this->plain('season.postpone_preview', ['number' => $preview->number]),
            '<p>' . $this->text('season.postpone_preview_text', ['count' => count($preview->moves), 'days' => $preview->days]) . '</p>'
            . '<div class="table-scroll"><table class="table"><thead><tr><th scope="col">' . $this->text('public.matchday')
            . '</th><th scope="col">' . $this->text('season.old_date') . '</th><th scope="col">' . $this->text('season.new_date') . '</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . sprintf(
                '<form class="inline-form" method="post" action="/admin/season/postpone">%s'
                . '<input type="hidden" name="season_id" value="%d"><input type="hidden" name="matchday_id" value="%d"><input type="hidden" name="date" value="%s">'
                . '<button type="submit" class="button">%s</button> <a class="button button--secondary" href="/admin/season?season=%d">%s</a></form>',
                $csrfField,
                $seasonId,
                $preview->matchdayId,
                $this->e($preview->moves[0]['new']),
                $this->text('season.postpone_confirm'),
                $seasonId,
                $this->text('common.cancel'),
            ),
            '',
            'card--highlight',
        );
    }

    private function labels(): ValueLabels
    {
        return new ValueLabels($this->t);
    }

    /**
     * Factor of the stored formula for the form (decimal separator of the
     * language); empty for "without handicap".
     */
    private function factor(?string $formula): string
    {
        if ($formula === null || trim($formula) === '') {
            return '';
        }

        $standard = HandicapFormula::fromIdentifier($formula);
        if ($standard !== null) {
            return str_replace('.', $this->t->t('number.decimal'), (string) $standard->factor());
        }

        if (preg_match('/[*×x]\s*(\d+(?:[.,]\d+)?)\s*$/u', $formula, $matches) === 1) {
            return str_replace(['.', ','], $this->t->t('number.decimal'), $matches[1]);
        }

        return '';
    }

    /**
     * Description of the positionday interval, e.g. "every 10th matchday".
     */
    private function positiondayText(int $interval): string
    {
        return match ($interval) {
            0       => $this->plain('season.positionday_none'),
            1       => $this->plain('season.positionday_every_matchday'),
            default => $this->plain('season.positionday_every_nth', ['n' => $interval]),
        };
    }

    private function previousSeasonText(int $matchdays): string
    {
        return $matchdays === 0
            ? $this->plain('season.previous_never')
            : $this->plain('season.previous_until_text', ['number' => $matchdays, 'hdc' => $matchdays + 1]);
    }

    private function amount(string $value): string
    {
        return $this->format->number((float) $value, 2);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
