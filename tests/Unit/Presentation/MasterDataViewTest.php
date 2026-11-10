<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Presentation\MasterDataView;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the master data interface of the admin area (task 35.1).
 *
 * Validates: R28.10, R22.5
 *
 * Checks that all four master data areas (players, teams, seasons/matchdays,
 * admin accounts) are rendered with their records and create forms, that
 * every writing form carries the CSRF field (R22.5) and that dynamic values
 * are escaped context-aware.
 */
final class MasterDataViewTest extends TestCase
{
    private MasterDataView $view;

    protected function setUp(): void
    {
        $this->view = new MasterDataView();
    }

    /**
     * Renders like for an account that may manage accounts, unless
     * $manageAccounts is false.
     */
    private function render(?string $message = null, ?string $error = null, string $tab = 'players', bool $manageAccounts = true): string
    {
        return $this->view->render(
            '<input type="hidden" name="csrf_token" value="tok">',
            [['id' => 1, 'name' => 'Anna', 'gender' => 'female']],
            [['id' => 7, 'team_name' => 'Pins & Co', 'season_id' => 3]],
            [['id' => 3, 'name' => 'Saison 2026']],
            [['id' => 9, 'matchday_number' => 2, 'date' => '2026-01-14', 'matchday_type' => 'regular']],
            [['id' => 5, 'username' => 'admin']],
            3,
            $message,
            $error,
            [],
            $tab,
            '',
            $manageAccounts ? [
                'permissions' => [5 => ['accounts.manage']],
                'log'         => [['created_at' => '2026-11-10 19:30:00', 'actor_name' => null, 'target_name' => 'admin', 'action' => 'created', 'permission' => null]],
                'self'        => 5,
            ] : null,
        );
    }

    public function testTheAccountsShowTheirPermissionsAndTheLog(): void
    {
        $html = $this->render(null, null, 'accounts');

        self::assertStringContainsString('admin <span class="badge">eigenes Konto</span>', $html);
        self::assertStringContainsString('action="/admin/master-data/account-permissions"', $html);
        self::assertStringContainsString('<input type="hidden" name="account_id" value="5">', $html);
        self::assertStringContainsString('<label class="permission-form__option"><input type="checkbox" name="permissions[]" value="accounts.manage" checked> Adminkonten verwalten</label>', $html);
        self::assertStringContainsString('Kommandozeile hat das Konto admin angelegt.', $html, 'Without an actor the command line created it.');
        self::assertStringContainsString('10.11.2026 19:30', $html);
        self::assertStringContainsString('Ein neues Konto darf alle Bereiche bearbeiten, aber keine Adminkonten verwalten.', $html);
    }

    public function testWithoutThePermissionThereIsNoAccountsTab(): void
    {
        $html = $this->render(null, null, 'accounts', false);

        self::assertStringNotContainsString('tab=accounts', $html);
        self::assertStringNotContainsString('account-create', $html);
        self::assertStringContainsString('tab=players&amp;season=3" aria-current="page"', $html, 'Falls back to the players.');
    }

    /**
     * All tabs rendered one after another (for checks across all areas).
     */
    private function allTabs(): string
    {
        $html = '';
        foreach (array_keys(MasterDataView::TABS) as $tab) {
            $html .= $this->render(null, null, $tab);
        }

        return $html;
    }

    /**
     * R6.8: only players without a team in this season are offered for the
     * roster selection; legacy data on several rosters is highlighted.
     */
    public function testRosterSelectionOnlyPlayersWithoutTeamAndMultipleNote(): void
    {
        $html = $this->view->render(
            '<input type="hidden" name="csrf_token" value="tok">',
            [
                ['id' => 1, 'name' => 'Anna', 'gender' => 'female'],
                ['id' => 2, 'name' => 'Bert', 'gender' => 'male'],
                ['id' => 3, 'name' => 'Carla', 'gender' => 'female'],
            ],
            [['id' => 7, 'team_name' => 'Adler', 'season_id' => 3], ['id' => 8, 'team_name' => 'Falken', 'season_id' => 3]],
            [['id' => 3, 'name' => 'Saison 2026']],
            [],
            [],
            3,
            null,
            null,
            [
                ['roster_id' => 11, 'team_season_id' => 7, 'player_id' => 1, 'name' => 'Anna'],
                ['roster_id' => 12, 'team_season_id' => 7, 'player_id' => 3, 'name' => 'Carla'],
                ['roster_id' => 13, 'team_season_id' => 8, 'player_id' => 3, 'name' => 'Carla'],
            ],
            'teams',
        );

        // Only Bert is free: exactly one option per team, Anna and Carla never.
        self::assertSame(2, substr_count($html, '<option value="2">Bert</option>'));
        self::assertStringNotContainsString('<option value="1">', $html);
        self::assertStringNotContainsString('<option value="3">', $html);

        self::assertStringContainsString('In mehreren Kadern dieser Saison', $html);
        self::assertStringContainsString('<li>Carla: Adler, Falken</li>', $html);
        self::assertSame(2, substr_count($html, 'class="chip chip--warning"'));
    }

    public function testTabBarContainsAllAreas(): void
    {
        $html = $this->render();

        foreach (['players' => 'Spieler', 'seasons' => 'Saisons', 'teams' => 'Teams', 'matchdays' => 'Spieltage', 'accounts' => 'Adminkonten'] as $key => $text) {
            self::assertStringContainsString('href="/admin/master-data?tab=' . $key . '&amp;season=3"', $html, $text);
            self::assertStringContainsString('>' . $text . '</a>', $html);
        }
        self::assertSame(1, substr_count($html, 'aria-current="page"'));
        self::assertStringContainsString('tab=players&amp;season=3" aria-current="page"', $html, 'Default: players.');
    }

    public function testOnlyTheSelectedAreaIsRendered(): void
    {
        $html = $this->render(null, null, 'accounts');

        self::assertStringContainsString('action="/admin/master-data/account-create"', $html);
        self::assertStringNotContainsString('action="/admin/master-data/player-create"', $html);
        self::assertStringContainsString('tab=accounts&amp;season=3" aria-current="page"', $html);
    }

    public function testUnknownTabShowsPlayers(): void
    {
        self::assertStringContainsString('action="/admin/master-data/player-create"', $this->render(null, null, 'gibtsnicht'));
    }

    public function testRecordsAreShown(): void
    {
        $html = $this->allTabs();

        self::assertStringContainsString('Anna', $html);
        self::assertStringContainsString('admin', $html);
        self::assertStringContainsString('Saison 2026', $html);
        self::assertStringContainsString('Spieltag 2<span class="data-list__aside">Mi 14.01.2026 · regulär</span>', $html);
    }

    public function testTeamNameIsEscaped(): void
    {
        $html = $this->render(null, null, 'teams');

        self::assertStringContainsString('Pins &amp; Co', $html);
        self::assertStringNotContainsString('Pins & Co', $html);
    }

    public function testEveryFormCarriesCsrfField(): void
    {
        $html = $this->allTabs();

        // For each of the five create actions plus the remove buttons the
        // CSRF field must be embedded (R22.5).
        self::assertGreaterThanOrEqual(
            5,
            substr_count($html, 'name="csrf_token"'),
            'Every writing form must carry a CSRF field (R22.5).'
        );
    }

    public function testCreateActionsAsFormTargets(): void
    {
        $html = $this->allTabs();

        self::assertStringContainsString('action="/admin/master-data/player-create"', $html);
        self::assertStringContainsString('action="/admin/master-data/season-create"', $html);
        self::assertStringContainsString('action="/admin/master-data/team-create"', $html);
        self::assertStringContainsString('action="/admin/master-data/matchday-create"', $html);
        self::assertStringContainsString('action="/admin/master-data/account-create"', $html);
    }

    public function testSuccessMessageIsShown(): void
    {
        $html = $this->render('Spieler wurde angelegt.', null);

        self::assertStringContainsString('role="status"', $html);
        self::assertStringContainsString('Spieler wurde angelegt.', $html);
    }

    public function testErrorMessageIsShown(): void
    {
        $html = $this->render(null, 'Der Benutzername ist bereits vergeben.');

        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('Der Benutzername ist bereits vergeben.', $html);
    }

    /** R39.1: a player's name leads to their page in the admin area. */
    public function testPlayerNamesLinkToThePlayerPage(): void
    {
        self::assertStringContainsString('<a href="/admin/master-data/player?id=1">Anna</a>', $this->render());
    }
}
