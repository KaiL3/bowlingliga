<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\SeasonPlanning\PlanningPreview;
use Bowlingliga\Domain\Message;
use Bowlingliga\Domain\SeasonPlanner\Matchday;
use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\Translator;

/**
 * Renders the season planning including the lane assignment in the admin area
 * (task 35.2, R28.11).
 *
 * Covers:
 * - the overview with the planning basis of the season, the entry into the
 *   automatic planning and the stored schedule including lane assignment and
 *   positiondays (R9.14); before the season start with correction forms for
 *   date and matchday type (including lot draw, R8.19, R32),
 * - the preview of a generated plan with warnings (R9.12, R18.9), the
 *   deviation overview of the lane assignment (R18.10) and the apply action,
 *   which requires explicit confirmation when there are warnings.
 *
 * All writing forms carry a CSRF token (R22.5); dynamic values are escaped.
 * Free of side effects; returns HTML fragments.
 */
final class SeasonPlanningView
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
     * @param array{id: int, name: string, start_date: string, end_date: string, team_count: int,
     *              matchday_count: int|null, lane_count: int|null, started: bool}|null $season
     * @param list<array{id: int, matchday_number: int, date: string, matchday_type: string,
     *                   is_played: bool,
     *                   pairings: list<array{home: string, away: string, lanes: string|null, status: string}>}> $plan
     */
    public function renderOverview(
        string $csrfField,
        ?array $season,
        array $plan,
        ?Message $reasonAgainstReplanning,
        ?string $message = null,
        ?string $error = null,
    ): string {
        $notes = $this->renderNotes($message, $error);

        if ($season === null) {
            return AdminComponents::pageHeader($this->plain('admin.area.planning.title'), $this->plain('layout.admin_area')) . $notes
                . '<p class="notice">' . $this->text('planning.no_season', [], ['link' => '<a href="/admin/master-data">' . $this->text('admin.area.master_data.title') . '</a>']) . '</p>';
        }

        return AdminComponents::pageHeader($season['name'], $this->plain('admin.area.planning.title'))
            . $notes
            . '<div class="grid grid--work">'
            . $this->renderBasics($season)
            . $this->renderLanding($season, $plan !== [], $reasonAgainstReplanning)
            . '</div>'
            . $this->renderStoredPlan($csrfField, $season, $plan);
    }

    /**
     * Renders the preview of a generated plan that is not saved yet.
     *
     * @param array{id: int, name: string} $season
     * @param array<int, string>           $teamNames Team season id => name (0 = ghost team)
     */
    public function renderPreview(
        string $csrfField,
        array $season,
        PlanningPreview $preview,
        array $teamNames,
        string $fingerprint,
        bool $replacesExistingPlan,
        ?string $error = null,
    ): string {
        $html = AdminComponents::pageHeader($this->plain('planning.preview'), $this->plain('admin.area.planning.title') . ' · ' . $season['name'])
            . $this->renderNotes(null, $error)
            . '<p class="notice" role="status">' . $this->text('planning.not_saved') . '</p>';

        if ($replacesExistingPlan) {
            $html .= '<p class="error" role="alert">' . $this->text('planning.replaces') . '</p>';
        }

        foreach ($preview->warnings as $warning) {
            $html .= sprintf('<p class="error" role="alert"><strong>%s</strong> %s</p>', $this->text('planning.warning'), $this->messageText($warning));
        }

        $html .= $this->renderDeviations($preview->laneDeviations, $teamNames)
            . $this->renderPreviewPlan($preview, $teamNames, (int) $season['id']);

        $confirmation = $preview->hasWarnings()
            ? '<label class="choice action-bar__confirmation">'
              . '<input type="checkbox" name="confirmed" value="1" required> '
              . $this->text('planning.confirm_warnings') . '</label>'
            : '';

        $types = '';
        foreach ($preview->matchdays as $matchday) {
            $types .= sprintf('<input type="hidden" name="types[%d]" value="%s">', $matchday->number, $this->escape($matchday->type));
        }

        $html .= sprintf(
            '<form class="action-bar" method="post" action="/admin/season-planning/apply">%s'
            . '<input type="hidden" name="season_id" value="%d">' . $types
            . '<input type="hidden" name="fingerprint" value="%s">%s'
            . '<button type="submit" class="button">' . $this->text('planning.apply') . '</button> '
            . '<a class="button button--secondary" href="/admin/season-planning?season=%d">' . $this->text('common.cancel') . '</a>'
            . '</form>',
            $csrfField,
            $season['id'],
            $this->escape($fingerprint),
            $confirmation,
            $season['id'],
        );

        return $html;
    }

    /**
     * @param array{start_date: string, end_date: string, team_count: int, matchday_count: int|null, lane_count: int|null} $season
     */
    private function renderBasics(array $season): string
    {
        $teamCount = $season['team_count'];
        $lanes = $season['lane_count'] !== null
            ? $this->plain('planning.lane_count', ['lanes' => $season['lane_count'], 'pairs' => intdiv($season['lane_count'], 2)])
            : $this->plain('planning.not_configured');

        $rows = [
            $this->plain('planning.period')         => $this->format->date($season['start_date']) . ' – ' . $this->format->date($season['end_date']),
            $this->plain('master_data.tab.teams')    => $teamCount . ($teamCount % 2 === 1 ? ' ' . $this->plain('planning.odd_teams') : ''),
            $this->plain('planning.matchday_count') => $season['matchday_count'] !== null ? (string) $season['matchday_count'] : $this->plain('planning.not_determinable'),
            $this->plain('field.lane_count')        => $lanes,
        ];

        $html = '<section class="card" aria-labelledby="h-basics"><div class="card__header"><h2 id="h-basics">' . $this->text('planning.basics') . '</h2></div><dl class="key-figures">';
        foreach ($rows as $term => $value) {
            $html .= sprintf('<div><dt>%s</dt><dd>%s</dd></div>', $this->escape($term), $this->escape($value));
        }

        return $html . '</dl></section>';
    }

    /**
     * @param array{id: int} $season
     */
    private function positiondayHint(int $interval): string
    {
        return match ($interval) {
            0       => $this->text('planning.positionday_none'),
            1       => $this->text('planning.positionday_every'),
            10      => $this->text('planning.positionday_tenth'),
            default => $this->text('planning.positionday_nth', ['n' => $interval]),
        };
    }

    private function renderLanding(array $season, bool $planExists, ?Message $reason): string
    {
        $html = '<section class="card" aria-labelledby="h-create"><div class="card__header"><h2 id="h-create">' . $this->text('planning.automatic') . '</h2></div>';

        if ($reason !== null) {
            return $html . sprintf('<p class="empty">%s</p>', $this->messageText($reason)) . '</section>';
        }

        $html .= $planExists
            ? '<p>' . $this->text('planning.plan_exists') . '</p>'
            : '<p>' . $this->text('planning.creates') . ' '
              . $this->positiondayHint((int) ($season['positionday_interval'] ?? Matchday::DEFAULT_POSITIONDAY_INTERVAL)) . '</p>';

        return $html . sprintf(
            '<form class="form" method="get" action="/admin/season-planning/preview">'
            . '<input type="hidden" name="season" value="%d">'
            . '<button type="submit" class="button">%s</button></form></section>',
            $season['id'],
            $this->text($planExists ? 'planning.replan' : 'planning.create_preview'),
        );
    }

    /**
     * @param array{id: int, started: bool} $season
     * @param list<array{id: int, matchday_number: int, date: string, matchday_type: string,
     *                   is_played: bool,
     *                   pairings: list<array{home: string, away: string, lanes: string|null, status: string}>}> $plan
     */
    private function renderStoredPlan(string $csrfField, array $season, array $plan): string
    {
        $html = '<section class="card" aria-labelledby="h-plan"><div class="card__header"><h2 id="h-plan">' . $this->text('planning.stored') . '</h2></div>';

        if ($plan === []) {
            return $html . '<p class="empty">' . $this->text('planning.none_stored') . '</p></section>';
        }

        $correctable = ! $season['started'];
        $html .= $correctable
            ? '<p class="empty">' . $this->text('planning.correctable') . '</p>'
            : '<p class="empty">' . $this->text('planning.read_only') . '</p>';

        $html .= '<div class="table-scroll"><table class="table planning__table"><thead><tr>'
            . '<th scope="col">' . $this->text('public.matchday') . '</th><th scope="col">' . $this->text('planning.date_type') . '</th><th scope="col">' . $this->text('planning.fixtures_lanes') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($plan as $matchday) {
            $html .= sprintf(
                '<tr><th scope="row">%d</th><td>%s</td><td>%s</td></tr>',
                $matchday['matchday_number'],
                $correctable && ! $matchday['is_played']
                    ? $this->renderCorrectionForm($csrfField, $season['id'], $matchday)
                    : $this->escape($this->format->date($matchday['date'], true) . ' · ' . $this->labels->matchdayType($matchday['matchday_type'])),
                $this->renderFixtures($matchday['pairings'], $matchday['matchday_type']),
            );
        }

        return $html . '</tbody></table></div></section>';
    }

    /**
     * @param array{id: int, date: string, matchday_type: string} $matchday
     */
    private function renderCorrectionForm(string $csrfField, int $seasonId, array $matchday): string
    {
        // The type is chosen in the preview ("Neu planen"); here only the date
        // can be corrected (R9.14).
        $typeField = '<span>' . $this->escape($this->labels->matchdayType($matchday['matchday_type'])) . '</span>';

        return sprintf(
            '<form class="inline-form planning__correction" method="post" action="/admin/season-planning/matchday">%s'
            . '<input type="hidden" name="season_id" value="%d">'
            . '<input type="hidden" name="matchday_id" value="%d">'
            . '<input type="date" name="date" value="%s" required aria-label="%s"> %s '
            . '<button type="submit" class="button button--secondary">%s</button></form>',
            $csrfField,
            $seasonId,
            $matchday['id'],
            $this->escape($matchday['date']),
            $this->text('planning.date_of_matchday', ['id' => $matchday['id']]),
            $typeField,
            $this->text('common.save'),
        );
    }

    /**
     * @param list<array{home: string, away: string, lanes: string|null, status: string}> $fixtures
     */
    private function renderFixtures(array $fixtures, string $type): string
    {
        if ($fixtures === []) {
            return match ($type) {
                'positionday' => '<em>' . $this->text('planning.fixtures_from_standings') . '</em>',
                'lot_draw'    => '<em>' . $this->text('planning.fixtures_drawn') . '</em>',
                default       => '<em>' . $this->text('planning.no_fixtures') . '</em>',
            };
        }

        $points = '';
        foreach ($fixtures as $p) {
            $points .= sprintf(
                '<li>%s – %s%s</li>',
                $this->escape($p['home']),
                $this->escape($p['away']),
                $p['lanes'] !== null ? ' <span class="planning__lane">(' . $this->text('common.lanes', ['lanes' => $p['lanes']]) . ')</span>' : '',
            );
        }

        return '<ul class="planning__pairings">' . $points . '</ul>';
    }

    /**
     * @param array<int, string> $teamNames
     */
    private function renderPreviewPlan(PlanningPreview $preview, array $teamNames, int $seasonId): string
    {
        $html = '<section class="card"><div class="card__header"><h2>' . $this->text('planning.schedule') . '</h2>'
            . '<span class="card__sub">' . $this->text('planning.schedule_hint') . '</span></div>'
            . sprintf('<form method="get" action="/admin/season-planning/preview"><input type="hidden" name="season" value="%d">', $seasonId)
            . '<div class="table-scroll"><table class="table planning__table"><thead><tr>'
            . '<th scope="col">' . $this->text('public.matchday') . '</th><th scope="col">' . $this->text('master_data.type') . '</th><th scope="col">' . $this->text('planning.fixtures_lanes') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($preview->matchdays as $matchday) {
            $fixtures = [];
            foreach ($matchday->fixtures as $p) {
                $fixtures[] = [
                    'home'   => $teamNames[$p->homeTeamSeasonId ?? 0] ?? '?',
                    'away'   => $teamNames[$p->awayTeamSeasonId ?? 0] ?? '?',
                    'lanes' => $p->lanePairIndex !== null
                        ? (($p->lanePairIndex - 1) * 2 + 1) . '/' . (($p->lanePairIndex - 1) * 2 + 2)
                        : null,
                    'status' => 'open',
                ];
            }

            $html .= sprintf(
                '<tr><th scope="row">%d</th><td>%s</td><td>%s</td></tr>',
                $matchday->number,
                $this->typeSelection($matchday->number, $matchday->type),
                $this->renderFixtures($fixtures, $matchday->type),
            );
        }

        return $html . '</tbody></table></div>'
            . '<button type="submit" class="button button--secondary">' . $this->text('planning.recalculate') . '</button></form></section>';
    }

    private function typeSelection(int $number, string $selected): string
    {
        $options = '';
        foreach (Matchday::TYPES as $type) {
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                $this->escape($type),
                $type === $selected ? ' selected' : '',
                $this->escape($this->labels->matchdayType($type)),
            );
        }

        return sprintf('<select name="types[%d]" aria-label="%s">%s</select>', $number, $this->text('planning.type_of_matchday', ['number' => $number]), $options);
    }

    /**
     * Deviation overview of the lane assignment per team: usage per lane pair
     * and spread (R18.10).
     *
     * @param array<int, array{usage: array<int, int>, spread: int}> $deviations
     * @param array<int, string>                                           $teamNames
     */
    private function renderDeviations(array $deviations, array $teamNames): string
    {
        if ($deviations === []) {
            return '';
        }

        $lanePairs = [];
        foreach ($deviations as $entry) {
            foreach (array_keys($entry['usage']) as $index) {
                $lanePairs[$index] = true;
            }
        }
        $lanePairs = array_keys($lanePairs);
        sort($lanePairs);

        $header = '';
        foreach ($lanePairs as $index) {
            $header .= '<th scope="col">' . $this->text('common.lanes', ['lanes' => (($index - 1) * 2 + 1) . '/' . (($index - 1) * 2 + 2)]) . '</th>';
        }

        $rows = '';
        foreach ($deviations as $teamId => $entry) {
            $cells = '';
            foreach ($lanePairs as $index) {
                $cells .= sprintf('<td>%d</td>', $entry['usage'][$index] ?? 0);
            }
            $rows .= sprintf(
                '<tr><th scope="row">%s</th>%s<td>%d</td></tr>',
                $this->escape($teamNames[$teamId] ?? ($this->plain('common.team') . ' ' . $teamId)),
                $cells,
                $entry['spread'],
            );
        }

        return '<section class="card"><div class="card__header"><h2>' . $this->text('planning.deviations') . '</h2></div>'
            . '<div class="table-scroll"><table class="table"><thead><tr><th scope="col">' . $this->text('common.team') . '</th>'
            . $header . '<th scope="col">' . $this->text('planning.spread') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
    }

    private function renderNotes(?string $message, ?string $error): string
    {
        return AdminComponents::notes($message, $error);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
