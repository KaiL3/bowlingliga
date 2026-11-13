<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Presentation\CmsEditView;
use Bowlingliga\Security\HtmlSanitizer;
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
    /**
     * Both content fields become the rich text editor, which offers exactly
     * the elements the server keeps; its scripts come once per page.
     */
    public function testContentFieldsBecomeTheRichTextEditor(): void
    {
        $pages = [
            $this->view->renderRulesEdit(self::CSRF, '<p>Regel</p>'),
            $this->view->renderHomePageEdit(self::CSRF, []),
        ];

        foreach ($pages as $html) {
            self::assertMatchesRegularExpression('/<textarea [^>]*data-rich-text data-allowed="' . preg_quote(htmlspecialchars(HtmlSanitizer::ALLOWED_ELEMENTS), '/') . '"/', $html);
            self::assertStringContainsString('data-block-formats="Absatz=p; Überschrift 2=h2; Überschrift 3=h3; Überschrift 4=h4"', $html);
            self::assertSame(1, substr_count($html, '/assets/vendor/tinymce/tinymce.min.js?v='));
            self::assertSame(1, substr_count($html, '/assets/js/rich-text.js?v='));
            self::assertStringContainsString('Formatieren über die Werkzeugleiste.', $html);
        }
    }

    /**
     * The editor files are published from Composer (bin/publish-tinymce.php): everything the
     * set-up script loads is there, with the GPL licence next to it.
     */
    public function testTheEditorFilesTheSetUpLoadsAreThere(): void
    {
        $public = dirname(__DIR__, 3) . '/public';
        $setup  = (string) file_get_contents($public . '/assets/js/rich-text.js');
        self::assertSame(1, preg_match("/plugins: '([a-z ]+)'/", $setup, $plugins));

        $files = ['tinymce.min.js', 'license.md', 'themes/silver/theme.min.js', 'models/dom/model.min.js',
            'icons/default/icons.min.js', 'langs/de.js', 'skins/ui/oxide/skin.min.css', 'skins/ui/oxide-dark/skin.min.css',
            'skins/content/default/content.min.css', 'skins/content/dark/content.min.css'];
        foreach (explode(' ', $plugins[1]) as $plugin) {
            $files[] = 'plugins/' . $plugin . '/plugin.min.js';
        }
        foreach ($files as $file) {
            self::assertFileExists($public . '/assets/vendor/tinymce/' . $file);
        }
        self::assertStringContainsString('GNU General Public License Version 2 or later', (string) file_get_contents($public . '/assets/vendor/tinymce/license.md'));
        self::assertStringContainsString("license_key: 'gpl'", $setup);
    }
}
