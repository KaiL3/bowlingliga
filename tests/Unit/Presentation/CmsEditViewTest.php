<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Presentation\CmsEditView;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see CmsEditView} (task 35.6).
 *
 * Validates: R28.16, R28.17, R22.5
 *
 * Checks lists, forms, CSRF fields and the escaping of the form values.
 */
final class CmsEditViewTest extends TestCase
{
    private const CSRF = '<input type="hidden" name="csrf_token" value="t">';

    private CmsEditView $view;

    protected function setUp(): void
    {
        $this->view = new CmsEditView();
    }

    public function testHomePageEditListsPostsWithActions(): void
    {
        $html = $this->view->renderHomePageEdit(self::CSRF, [
            ['id' => 7, 'title' => 'Willkommen', 'text' => '<p>Text</p>'],
        ]);

        self::assertStringContainsString('Willkommen', $html);
        self::assertStringContainsString('href="/admin/home-page?edit=7"', $html);
        self::assertStringContainsString('action="/admin/home-page/remove"', $html);
        self::assertStringContainsString('name="id" value="7"', $html);
    }

    public function testWithoutContentNoteAndCreateForm(): void
    {
        $html = $this->view->renderHomePageEdit(self::CSRF, []);

        self::assertStringContainsString('noch keine Startseiten-Inhalte', $html);
        self::assertStringContainsString('action="/admin/home-page/create"', $html);
    }

    public function testAllFormsCarryCsrfToken(): void
    {
        $html = $this->view->renderHomePageEdit(self::CSRF, [
            ['id' => 1, 'title' => 'A', 'text' => 'A'],
        ]);

        // Entfernen-Formular + Anlegeformular.
        self::assertSame(2, substr_count($html, self::CSRF));
        self::assertStringContainsString(self::CSRF, $this->view->renderRulesEdit(self::CSRF, ''));
    }

    public function testEditFormContainsIdAndCancel(): void
    {
        $html = $this->view->renderHomePageEdit(
            self::CSRF,
            [],
            ['id' => 3, 'title' => 'T', 'text' => 'X'],
        );

        self::assertStringContainsString('action="/admin/home-page/update"', $html);
        self::assertStringContainsString('<input type="hidden" name="id" value="3">', $html);
        self::assertStringContainsString('Abbrechen', $html);
    }

    public function testFormValuesAreEscaped(): void
    {
        $html = $this->view->renderHomePageEdit(
            self::CSRF,
            [],
            ['id' => null, 'title' => '"><script>x</script>', 'text' => '</textarea><script>y</script>'],
        );

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;/textarea&gt;', $html);
    }

    public function testRulesTextIsEscapedInTextarea(): void
    {
        $html = $this->view->renderRulesEdit(self::CSRF, '<p>Regel</p>');

        self::assertStringContainsString('action="/admin/rules/save"', $html);
        self::assertStringContainsString('&lt;p&gt;Regel&lt;/p&gt;</textarea>', $html);
    }

    public function testMessagesAreShownAndEscaped(): void
    {
        $html = $this->view->renderRulesEdit(self::CSRF, '', 'Gespeichert <b>', 'Fehler <i>');

        self::assertStringContainsString('Gespeichert &lt;b&gt;', $html);
        self::assertStringContainsString('Fehler &lt;i&gt;', $html);
    }
}
