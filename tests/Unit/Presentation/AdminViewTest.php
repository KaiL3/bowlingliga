<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Presentation\AdminView;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the views of the admin area access (task 28.2).
 *
 * Validates: R28.8, R28.9, R28.10
 *
 * Checks the login form (including the embedded CSRF field and error message)
 * and the admin area overview, which lists the functional areas bundled as
 * navigation items.
 */
final class AdminViewTest extends TestCase
{
    private AdminView $view;

    protected function setUp(): void
    {
        $this->view = new AdminView();
    }

    /**
     * R28.8: the login form provides the fields and takes over the prepared
     * CSRF field.
     */
    public function testLoginFormContainsFieldsAndCsrfField(): void
    {
        $csrf = '<input type="hidden" name="csrf_token" value="abc123">';

        $html = $this->view->renderLogin($csrf);

        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('action="/admin/login"', $html);
        self::assertStringContainsString('name="username"', $html);
        self::assertStringContainsString('name="password"', $html);
        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString($csrf, $html, 'The CSRF field must be embedded (R22.5).');
    }

    /**
     * R28.9: accessing a protected admin item without login asks the user to
     * log in.
     */
    public function testLoginNoteIsShown(): void
    {
        $html = $this->view->renderLogin('<input>', null, true);

        self::assertStringContainsString('role="status"', $html);
        self::assertStringContainsString('melden Sie sich an', $html);
    }

    /**
     * R5.3/R23.8: an error message of a previous login attempt is output
     * escaped.
     */
    public function testErrorMessageIsShownAndEscaped(): void
    {
        $html = $this->view->renderLogin('<input>', 'Die Anmeldedaten sind ungültig.');

        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('Die Anmeldedaten sind ungültig.', $html);
    }

    /**
     * Without an error message and without a note neither alert nor status
     * appears.
     */
    public function testWithoutErrorAndNoteNoMessages(): void
    {
        $html = $this->view->renderLogin('<input>');

        self::assertStringNotContainsString('role="alert"', $html);
        self::assertStringNotContainsString('role="status"', $html);
    }

    /**
     * R28.10: the admin area overview lists the functional areas bundled as
     * navigation items.
     */
    public function testOverviewListsFunctionalAreas(): void
    {
        $html = $this->view->renderOverview();

        self::assertStringContainsString('Adminbereich', $html);
        self::assertStringContainsString('/admin/master-data', $html);
        self::assertStringContainsString('/admin/season-planning', $html);
        self::assertStringContainsString('/admin/results', $html);
        self::assertStringContainsString('/admin/print', $html);
        self::assertStringContainsString('/admin/honours', $html);
        self::assertStringContainsString('/admin/home-page', $html);
        self::assertStringContainsString('/admin/rules', $html);
    }

    /**
     * The admin bar contains the same functional areas as the overview.
     */
    public function testBarEntriesMatchTheOverview(): void
    {
        $overview = $this->view->renderOverview();

        foreach ($this->view->barEntries() as $entry) {
            if ($entry['href'] !== '/admin') {
                self::assertStringContainsString('href="' . $entry['href'] . '"', $overview, $entry['text']);
            }
        }
    }

    /**
     * The overview offers a way to log out (R5.5).
     */
    public function testOverviewOffersLogout(): void
    {
        $html = $this->view->renderOverview();

        self::assertStringContainsString('action="/admin/logout"', $html);
        self::assertStringContainsString('method="post"', $html);
    }
}
