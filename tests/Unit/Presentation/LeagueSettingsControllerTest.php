<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Application\League\LeagueSettings;
use Bowlingliga\Presentation\AdminView;
use Bowlingliga\Presentation\LeagueSettingsController;
use Bowlingliga\Presentation\LeagueSettingsView;
use Bowlingliga\Security\Validator;
use Bowlingliga\Tests\Unit\Application\League\InMemoryLeagueSettingsStore;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the admin area "Liga" ({@see LeagueSettingsController},
 * {@see LeagueSettingsView}).
 *
 * Validates: R38.3, R5.1, R22.5
 */
final class LeagueSettingsControllerTest extends TestCase
{
    private const CSRF = '<input type="hidden" name="csrf_token" value="t">';

    private InMemoryLeagueSettingsStore $store;
    private SwitchableWriteAccess $access;
    private LeagueSettingsController $controller;

    protected function setUp(): void
    {
        $this->store      = new InMemoryLeagueSettingsStore('Pin & Spare Liga');
        $this->access     = new SwitchableWriteAccess(true);
        $this->controller = new LeagueSettingsController(
            new LeagueSettings($this->store, new Validator(), $this->access),
            new LeagueSettingsView(),
        );
    }

    public function testFormShowsTheStoredNameEscapedWithCsrfField(): void
    {
        $html = $this->controller->edit(self::CSRF);

        self::assertStringContainsString('action="/admin/league/save"', $html);
        self::assertStringContainsString(self::CSRF, $html);
        self::assertStringContainsString('value="Pin &amp; Spare Liga"', $html);
        self::assertStringNotContainsString('class="notice', $html, 'No message without saving.');
    }

    public function testConfirmationAfterTheRedirect(): void
    {
        self::assertStringContainsString('Der Name der Liga wurde gespeichert.', $this->controller->edit(self::CSRF, saved: true));
    }

    public function testSavingRedirectsBackToTheForm(): void
    {
        $result = $this->controller->save(['name' => ' Bowlingliga Musterstadt ']);

        self::assertTrue($result->successful);
        self::assertSame(LeagueSettingsController::AFTER_SAVE, $result->redirect);
        self::assertSame('Bowlingliga Musterstadt', $this->store->name);
    }

    public function testInvalidNameKeepsTheStoredOneAndPrefillsTheInput(): void
    {
        $result = $this->controller->save(['name' => '']);

        self::assertFalse($result->successful);
        self::assertStringContainsString('Name der Liga', $result->message);
        self::assertSame('Pin & Spare Liga', $this->store->name);

        $html = $this->controller->edit(self::CSRF, false, '<b>neu</b>', $result->message);
        self::assertStringContainsString('value="&lt;b&gt;neu&lt;/b&gt;"', $html);
    }

    public function testWithoutLoginNothingIsSaved(): void
    {
        $this->access->allowed = false;

        $result = $this->controller->save(['name' => 'Andere Liga']);

        self::assertFalse($result->successful);
        self::assertSame('Pin & Spare Liga', $this->store->name);
    }

    public function testAreaAppearsInTheAdminBarAndOverview(): void
    {
        $view = new AdminView();

        self::assertContains(['href' => '/admin/league', 'text' => 'Liga'], $view->barEntries());
        self::assertStringContainsString('href="/admin/league"', $view->renderOverview());
    }
}
