<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\I18n\Formatter;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Security\AccountLogStore;
use Bowlingliga\Security\Permission;

/**
 * Renders the master data management of the admin area (R28.10).
 *
 * Shows per master data area (players, teams, seasons, matchdays, admin
 * accounts) the existing records as a list in a card and next to it a form for
 * creating; remove actions are in the respective row. The areas are on tabs: a
 * bar with links (`?tab=…`) selects the area and only that one is shown –
 * also without JavaScript; after an action its tab stays selected. Teams and
 * matchdays belong to a season, which can be switched via a small selection.
 *
 * All writing forms carry a hidden CSRF token following the synchronizer token
 * pattern (R22.5); all dynamic values are escaped context-aware.
 *
 * The renderer is free of side effects and returns an HTML fragment; embedding
 * it into the page layout is up to the caller. The server-side validation and
 * the domain rules live in the use cases (task 21); this view only provides
 * the interface.
 *
 * @phpstan-type Player array{id: int, name: string, gender: string}
 * @phpstan-type TeamSeason array{id: int, team_name: string, season_id: int}
 * @phpstan-type Season array{id: int, name: string}
 * @phpstan-type Matchday array{id: int, matchday_number: int, date: string, matchday_type: string}
 * @phpstan-type Account array{id: int, username: string}
 */
final class MasterDataView
{
    use ViewTexts;

    private readonly Formatter $format;

    private readonly ValueLabels $labels;

    public function __construct(private readonly Translator $t = new Translator())
    {
        $this->format = new Formatter($t);
        $this->labels = new ValueLabels($t);
    }

    /** @var array<string, string> tab key → catalog key of the label, in display order */
    public const TABS = [
        'players'   => 'master_data.tab.players',
        'seasons'   => 'master_data.tab.seasons',
        'teams'     => 'master_data.tab.teams',
        'matchdays' => 'master_data.tab.matchdays',
        'accounts'  => 'master_data.tab.accounts',
        'import'    => 'master_data.tab.import',
    ];

    /**
     * Renders the complete master data interface.
     *
     * @param string                     $csrfField Prepared hidden CSRF field (R22.5).
     * @param list<array<string, mixed>> $player    All players.
     * @param list<array<string, mixed>> $teams     Team season assignments of the selected season.
     * @param list<array<string, mixed>> $seasons   All seasons.
     * @param list<array<string, mixed>> $matchdays Matchdays of the selected season.
     * @param list<array<string, mixed>> $accounts  All admin accounts.
     * @param int|null                   $seasonId  Selected season (for matchday/team forms).
     * @param string|null                $message   Success message.
     * @param string|null                $error     Error message.
     * @param string                     $tab       Selected tab ({@see self::TABS}); unknown = players.
     * @param string                     $importArea Content of the import tab (from the {@see PlayerImportController}).
     * @param array{permissions: array<int, list<string>>, log: list<array{created_at: string, actor_name: string|null, target_name: string, action: string, permission: string|null}>, self: int|null}|null $accountAdmin
     *        Permissions, log and own account id if the logged-in account may
     *        manage accounts; null hides the tab of the admin accounts (R23.10).
     */
    public function render(
        string $csrfField,
        array $player,
        array $teams,
        array $seasons,
        array $matchdays,
        array $accounts,
        ?int $seasonId,
        ?string $message = null,
        ?string $error = null,
        array $roster = [],
        string $tab = 'players',
        string $importArea = '',
        ?array $accountAdmin = null,
    ): string {
        $tabs = $accountAdmin !== null ? self::TABS : array_diff_key(self::TABS, ['accounts' => true]);
        $tab  = isset($tabs[$tab]) ? $tab : 'players';

        $area = match ($tab) {
            'seasons'   => $this->renderSeasonArea($csrfField, $seasons),
            'teams'     => $this->renderTeamArea($csrfField, $teams, $seasons, $seasonId, $roster, $player),
            'matchdays' => $this->renderMatchdayArea($csrfField, $matchdays, $seasons, $seasonId),
            'accounts'    => $this->renderAccountsArea($csrfField, $accounts, (array) $accountAdmin),
            'import'    => $importArea,
            default     => $this->renderPlayerArea($csrfField, $player),
        };

        return AdminComponents::pageHeader($this->plain('admin.area.master_data.title'), $this->plain('layout.admin_area'))
            . $this->renderTabBar($tabs, $tab, $seasonId)
            . AdminComponents::notes($message, $error)
            . $area;
    }

    /**
     * Bar of the master data tabs as links; the selected season is kept.
     *
     * @param array<string, string> $tabs
     */
    private function renderTabBar(array $tabs, string $active, ?int $seasonId): string
    {
        $links = '';
        foreach ($tabs as $key => $label) {
            $href = '/admin/master-data?tab=' . $key . ($seasonId !== null ? '&season=' . $seasonId : '');
            $links .= sprintf(
                '<a class="tab-bar__link" href="%s"%s>%s</a>',
                $this->escape($href),
                $key === $active ? ' aria-current="page"' : '',
                $this->text($label),
            );
        }

        return '<nav class="tab-bar" aria-label="' . $this->text('master_data.areas') . '">' . $links . '</nav>';
    }

    /**
     * List on the left, create form on the right (below each other on narrow
     * screens).
     */
    private function workArea(string $items, string $form): string
    {
        return '<div class="grid grid--work">' . $items . $form . '</div>';
    }

    /**
     * @param list<array<string, mixed>> $player
     */
    private function renderPlayerArea(string $csrfField, array $player): string
    {
        $rows = '';
        foreach ($player as $s) {
            $rows .= $this->row(
                // The name leads to the player page (R39.1).
                sprintf('<a href="/admin/master-data/player?id=%d">%s</a>', (int) $s['id'], $this->escape((string) $s['name'])),
                $this->escape($this->labels->gender((string) $s['gender'])),
                $this->deleteButton($csrfField, 'player-remove', 'player_id', (int) $s['id'], (string) $s['name']),
            );
        }

        $items = AdminComponents::card(
            $this->plain('master_data.tab.players'),
            $this->items($rows, 'master_data.no_players', true),
            $this->plain('master_data.player_count', ['count' => count($player)]),
        );

        $form = AdminComponents::card($this->plain('master_data.new_player'), <<<HTML
            <form class="form" method="post" action="/admin/master-data/player-create">
                {$csrfField}
                <div class="form__field">
                    <label for="player-name">{$this->text('field.name')}</label>
                    <input type="text" id="player-name" name="name" maxlength="100" required>
                </div>
                <div class="form__field">
                    <label for="player-gender">{$this->text('field.gender')}</label>
                    <select id="player-gender" name="gender">
                        <option value="male">{$this->text('value.gender.male')}</option>
                        <option value="female">{$this->text('value.gender.female')}</option>
                    </select>
                </div>
                <button type="submit" class="button">{$this->text('master_data.create_player')}</button>
            </form>
            HTML);

        return $this->workArea($items, $form);
    }

    /**
     * @param list<array<string, mixed>> $seasons
     */
    private function renderSeasonArea(string $csrfField, array $seasons): string
    {
        $rows = '';
        foreach ($seasons as $season) {
            $rows .= $this->row(
                $this->escape((string) $season['name']),
                isset($season['start_date'], $season['end_date'])
                    ? $this->escape($this->format->date((string) $season['start_date']) . ' – ' . $this->format->date((string) $season['end_date']))
                    : '',
                $this->deleteButton($csrfField, 'season-remove', 'season_id', (int) $season['id'], (string) $season['name']),
            );
        }

        $items = AdminComponents::card(
            $this->plain('master_data.tab.seasons'),
            $this->items($rows, 'master_data.no_seasons'),
            $this->plain('master_data.seasons_hint'),
        );

        $form = AdminComponents::card($this->plain('master_data.new_season'), <<<HTML
            <form class="form" method="post" action="/admin/master-data/season-create">
                {$csrfField}
                <div class="form__field">
                    <label for="season-name">{$this->text('field.name')}</label>
                    <input type="text" id="season-name" name="name" maxlength="100" required>
                </div>
                <div class="form__field">
                    <label for="season-start">{$this->text('field.start_date')}</label>
                    <input type="date" id="season-start" name="start_date" required>
                </div>
                <div class="form__field">
                    <label for="season-end">{$this->text('field.end_date')}</label>
                    <input type="date" id="season-end" name="end_date" required>
                </div>
                <button type="submit" class="button">{$this->text('master_data.create_season')}</button>
            </form>
            HTML);

        return $this->workArea($items, $form);
    }

    /**
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $seasons
     */
    private function renderTeamArea(
        string $csrfField,
        array $teams,
        array $seasons,
        ?int $seasonId,
        array $roster = [],
        array $player = [],
    ): string {
        $cards = '';
        foreach ($teams as $team) {
            $isGhostTeam = (int) ($team['is_ghost_team'] ?? 0) === 1;
            $cards .= sprintf(
                '<li class="team-card"><div class="team-card__header"><h3>%s%s</h3>%s</div>%s</li>',
                // The team page shows the statistics of the season (R41.1); the ghost team has none.
                $isGhostTeam
                    ? $this->escape((string) $team['team_name'])
                    : sprintf('<a href="/admin/master-data/team?id=%d">%s</a>', (int) $team['id'], $this->escape((string) $team['team_name'])),
                $isGhostTeam ? ' <span class="badge">' . $this->text('master_data.ghost_team') . '</span>' : '',
                $this->deleteButton($csrfField, 'team-remove', 'team_season_id', (int) $team['id'], (string) $team['team_name']),
                $isGhostTeam
                    ? '<p class="empty">' . $this->text('master_data.ghost_lineup') . '</p>'
                    : $this->renderRoster($csrfField, (int) $team['id'], $roster, $player),
            );
        }

        $assigned = array_flip(array_map(static fn (array $e): int => (int) $e['player_id'], $roster));
        $free     = array_filter($player, static fn (array $s): bool => ! isset($assigned[(int) $s['id']]));
        $noneFree = $teams !== [] && $free === []
            ? '<p class="empty">' . $this->text('master_data.all_assigned') . '</p>'
            : '';

        $content = $this->renderMultipleAssignments($teams, $roster) . $noneFree
            . ($cards !== ''
                ? '<ul class="team-cards">' . $cards . '</ul>'
                : '<p class="empty">' . $this->text('master_data.no_teams') . '</p>');

        $seasonField = $this->seasonSelection($seasons, $seasonId, 'team-season', 'season_id');

        return AdminComponents::card($this->plain('master_data.teams_and_rosters'), $content, $this->plain('master_data.team_count', ['count' => count($teams)]))
            . AdminComponents::card($this->plain('master_data.new_team'), <<<HTML
                <form class="form form--grid" method="post" action="/admin/master-data/team-create">
                    {$csrfField}
                    <div class="form__field">
                        <label for="team-season">{$this->text('layout.season')}</label>
                        {$seasonField}
                    </div>
                    <div class="form__field">
                        <label for="team-name">{$this->text('field.team_name')}</label>
                        <input type="text" id="team-name" name="team_name" maxlength="100" required>
                    </div>
                    <div class="form__actions"><button type="submit" class="button">{$this->text('master_data.create_team')}</button></div>
                </form>
                HTML);
    }

    /**
     * @param list<array<string, mixed>> $matchdays
     * @param list<array<string, mixed>> $seasons
     */
    private function renderMatchdayArea(string $csrfField, array $matchdays, array $seasons, ?int $seasonId): string
    {
        $rows = '';
        foreach ($matchdays as $matchday) {
            $rows .= $this->row(
                $this->text('common.matchday_number', ['number' => (int) $matchday['matchday_number']]),
                $this->escape($this->format->date((string) $matchday['date'], true) . ' · ' . $this->labels->matchdayType($matchday['matchday_type'])),
                '',
            );
        }

        $seasonField = $this->seasonSelection($seasons, $seasonId, 'matchday-season', 'season_id');

        $items = AdminComponents::card(
            $this->plain('master_data.tab.matchdays'),
            $this->items($rows, 'master_data.no_matchdays', true),
            $this->plain('master_data.matchdays_hint'),
        );

        $form = AdminComponents::card($this->plain('master_data.new_matchday'), <<<HTML
            <form class="form" method="post" action="/admin/master-data/matchday-create">
                {$csrfField}
                <div class="form__field">
                    <label for="matchday-season">{$this->text('layout.season')}</label>
                    {$seasonField}
                </div>
                <div class="form__field">
                    <label for="matchday-number">{$this->text('master_data.number')}</label>
                    <input type="number" id="matchday-number" name="matchday_number" min="1" inputmode="numeric" required>
                </div>
                <div class="form__field">
                    <label for="matchday-date">{$this->text('field.date')}</label>
                    <input type="date" id="matchday-date" name="date" required>
                </div>
                <div class="form__field">
                    <label for="matchday-type">{$this->text('master_data.type')}</label>
                    <select id="matchday-type" name="matchday_type">
                        <option value="regular">{$this->text('value.matchday_type.regular')}</option>
                        <option value="positionday">{$this->text('value.matchday_type.positionday')}</option>
                        <option value="lot_draw">{$this->text('value.matchday_type.lot_draw')}</option>
                    </select>
                </div>
                <button type="submit" class="button">{$this->text('master_data.create_matchday')}</button>
            </form>
            HTML);

        return $this->workArea($items, $form);
    }

    /**
     * Admin accounts with their permissions, the form for a new account and
     * the log of the changes (R23.10, R23.14).
     *
     * @param list<array<string, mixed>> $accounts
     * @param array<string, mixed>       $admin    See {@see render()}, `$accountAdmin`.
     */
    private function renderAccountsArea(string $csrfField, array $accounts, array $admin): string
    {
        $rows = '';
        foreach ($accounts as $account) {
            $id   = (int) $account['id'];
            $name = (string) $account['username'];
            $own  = ($admin['self'] ?? null) === $id ? ' <span class="badge">' . $this->text('master_data.own_account') . '</span>' : '';
            $rows .= $this->row(
                $this->escape($name) . $own,
                $this->permissionForm($csrfField, $id, $name, $admin['permissions'][$id] ?? []),
                $this->deleteButton($csrfField, 'account-remove', 'account_id', $id, $name),
            );
        }

        $items = '<div>' . AdminComponents::card($this->plain('master_data.tab.accounts'), $this->items($rows, 'master_data.no_accounts'))
            . $this->accountLog($admin['log'] ?? []) . '</div>';

        $form = AdminComponents::card($this->plain('master_data.new_account'), <<<HTML
            <form class="form" method="post" action="/admin/master-data/account-create">
                {$csrfField}
                <div class="form__field">
                    <label for="account-username">{$this->text('admin.username')}</label>
                    <input type="text" id="account-username" name="username" maxlength="100" autocomplete="off" required>
                </div>
                <div class="form__field">
                    <label for="account-password">{$this->text('admin.password')}</label>
                    <input type="password" id="account-password" name="password" autocomplete="new-password" minlength="8" required>
                    <small>{$this->text('account.min_length', ['min' => 8])}</small>
                </div>
                <div class="form__field"><small>{$this->text('master_data.new_account_permissions')}</small></div>
                <button type="submit" class="button">{$this->text('master_data.create_account')}</button>
            </form>
            HTML);

        return $this->workArea($items, $form);
    }

    /**
     * One checkbox per permission and a save button for an account (R23.10).
     *
     * @param list<string> $held
     */
    private function permissionForm(string $csrfField, int $id, string $name, array $held): string
    {
        $boxes = '';
        foreach (Permission::cases() as $permission) {
            $boxes .= sprintf(
                '<label class="permission-form__option"><input type="checkbox" name="permissions[]" value="%s"%s> %s</label>',
                $this->escape($permission->value),
                in_array($permission->value, $held, true) ? ' checked' : '',
                $this->text($permission->label()),
            );
        }

        return sprintf(
            '<form class="permission-form" method="post" action="/admin/master-data/account-permissions">%s'
            . '<input type="hidden" name="account_id" value="%d">'
            . '<fieldset class="permission-form__set"><legend class="visually-hidden">%s</legend>%s</fieldset>'
            . '<button type="submit" class="button button--secondary">%s</button></form>',
            $csrfField,
            $id,
            $this->text('master_data.permissions_of', ['name' => $name]),
            $boxes,
            $this->text('master_data.save_permissions'),
        );
    }

    /**
     * The latest changes to accounts and permissions (R23.14).
     *
     * @param list<array{created_at: string, actor_name: string|null, target_name: string, action: string, permission: string|null}> $entries
     */
    private function accountLog(array $entries): string
    {
        $rows = '';
        foreach ($entries as $entry) {
            $permission = Permission::tryFrom((string) $entry['permission']);
            $action     = in_array($entry['action'], [AccountLogStore::CREATED, AccountLogStore::REMOVED, AccountLogStore::GRANTED, AccountLogStore::REVOKED], true)
                ? $entry['action'] : AccountLogStore::CREATED;
            $text = $this->text('account_log.' . $action, [
                'actor'      => $entry['actor_name'] ?? $this->plain('account_log.command_line'),
                'target'     => $entry['target_name'],
                'permission' => $permission !== null ? $this->plain($permission->label()) : (string) $entry['permission'],
            ]);
            $rows .= $this->row($text, $this->escape($this->format->date(substr($entry['created_at'], 0, 10)) . ' ' . substr($entry['created_at'], 11, 5)), '');
        }

        return AdminComponents::card($this->plain('master_data.account_log'), $this->items($rows, 'master_data.no_account_log'));
    }

    /**
     * Row of a data list; main and secondary detail are already escaped.
     */
    private function row(string $main, string $beside, string $actions): string
    {
        return sprintf(
            '<li class="data-list__row"><span class="data-list__main">%s%s</span>%s</li>',
            $main,
            $beside !== '' ? '<span class="data-list__aside">' . $beside . '</span>' : '',
            $actions !== '' ? '<span class="data-list__actions">' . $actions . '</span>' : '',
        );
    }

    private function items(string $rows, string $blank, bool $grid = false): string
    {
        if ($rows === '') {
            return '<p class="empty">' . $this->text($blank) . '</p>';
        }

        return sprintf('<ul class="data-list%s">%s</ul>', $grid ? ' data-list--grid' : '', $rows);
    }

    /**
     * Renders a season selection list; the selected season is preselected.
     *
     * @param list<array<string, mixed>> $seasons
     */
    private function seasonSelection(array $seasons, ?int $seasonId, string $id, string $name): string
    {
        $optionList = '';
        foreach ($seasons as $season) {
            $id2       = (int) $season['id'];
            $selected  = $id2 === $seasonId ? ' selected' : '';
            $optionList .= sprintf(
                '<option value="%d"%s>%s</option>',
                $id2,
                $selected,
                $this->escape((string) $season['name']),
            );
        }

        return sprintf('<select id="%s" name="%s">%s</select>', $this->escape($id), $this->escape($name), $optionList);
    }

    /**
     * Renders a remove button as its own small POST form with CSRF protection
     * (R22.5).
     */
    private function deleteButton(string $csrfField, string $action, string $fieldName, int $id, string $label): string
    {
        return sprintf(
            '<form class="inline-form" method="post" action="/admin/master-data/%s">%s'
            . '<input type="hidden" name="%s" value="%d">'
            . '<button type="submit" class="button button--danger" aria-label="%s">%s</button></form>',
            $this->escape($action),
            $csrfField,
            $this->escape($fieldName),
            $id,
            $this->text('master_data.remove_label', ['name' => $label]),
            $this->text('common.remove'),
        );
    }

    /**
     * Note about players who are on several rosters in this season (legacy
     * data from before R6.8); they have to be removed from one team.
     *
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $roster
     */
    private function renderMultipleAssignments(array $teams, array $roster): string
    {
        $teamNames = [];
        foreach ($teams as $team) {
            $teamNames[(int) $team['id']] = (string) $team['team_name'];
        }

        $perPlayer = [];
        foreach ($roster as $entry) {
            $perPlayer[(int) $entry['player_id']]['name'] = (string) $entry['name'];
            $perPlayer[(int) $entry['player_id']]['teams'][] = $teamNames[(int) $entry['team_season_id']] ?? '?';
        }

        $rows = '';
        foreach ($perPlayer as $player) {
            if (count($player['teams']) > 1) {
                $rows .= sprintf('<li>%s: %s</li>', $this->escape($player['name']), $this->escape(implode(', ', $player['teams'])));
            }
        }

        return $rows === '' ? '' : '<div class="notice notice--check" role="alert"><strong>' . $this->text('master_data.multiple_rosters') . '</strong>'
            . '<ul>' . $rows . '</ul>' . $this->text('master_data.multiple_rosters_hint') . '</div>';
    }

    /**
     * Renders a team's roster as chips with a remove button and a form for
     * adding further players (R21.1). Only players not yet on a roster in this
     * season are offered (R6.8).
     *
     * @param list<array<string, mixed>> $roster Roster entries of the season
     * @param list<array<string, mixed>> $player All players
     */
    private function renderRoster(string $csrfField, int $teamSeasonId, array $roster, array $player): string
    {
        $members = '';
        $assigned   = [];
        foreach ($roster as $entry) {
            $assigned[(int) $entry['player_id']] = ($assigned[(int) $entry['player_id']] ?? 0) + 1;
        }

        foreach ($roster as $entry) {
            if ((int) $entry['team_season_id'] !== $teamSeasonId) {
                continue;
            }
            $members .= sprintf(
                '<li class="chip%4$s"><a class="chip__link" href="/admin/master-data/player?id=%7$d">%1$s</a><form method="post" action="/admin/master-data/roster-remove">%2$s'
                . '<input type="hidden" name="roster_id" value="%3$d">'
                . '<button type="submit" class="chip__remove" aria-label="%5$s" title="%6$s">×</button></form></li>',
                $this->escape((string) $entry['name']),
                $csrfField,
                (int) $entry['roster_id'],
                $assigned[(int) $entry['player_id']] > 1 ? ' chip--warning' : '',
                $this->text('master_data.remove_from_roster_label', ['name' => (string) $entry['name']]),
                $this->text('master_data.remove_from_roster'),
                (int) $entry['player_id'],
            );
        }

        $optionList = '';
        foreach ($player as $s) {
            if (! isset($assigned[(int) $s['id']])) {
                $optionList .= sprintf('<option value="%d">%s</option>', (int) $s['id'], $this->escape((string) $s['name']));
            }
        }

        $items = $members !== ''
            ? '<ul class="chips" aria-label="' . $this->text('master_data.roster') . '">' . $members . '</ul>'
            : '<p class="empty">' . $this->text('master_data.empty_roster') . '</p>';

        if ($optionList === '') {
            return $items;
        }

        return $items . sprintf(
            '<form class="team-card__add" method="post" action="/admin/master-data/roster-assign">%s'
            . '<input type="hidden" name="team_season_id" value="%d">'
            . '<select name="player_id" aria-label="%s">%s</select>'
            . '<button type="submit" class="button button--secondary">%s</button></form>',
            $csrfField,
            $teamSeasonId,
            $this->text('master_data.add_to_roster'),
            $optionList,
            $this->text('master_data.add'),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
