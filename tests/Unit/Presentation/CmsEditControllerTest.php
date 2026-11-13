<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Application\Cms\RulesManagement;
use Bowlingliga\Application\Cms\HomePagePostManagement;
use Bowlingliga\Presentation\CmsEditController;
use Bowlingliga\Presentation\CmsEditView;
use Bowlingliga\Security\HtmlSanitizer;
use Bowlingliga\Security\Validator;
use Bowlingliga\Tests\Unit\Application\Cms\InMemoryRulesStore;
use Bowlingliga\Tests\Unit\Application\Cms\InMemoryHomePagePostStore;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see CmsEditController} (task 35.6).
 *
 * Validates: R28.16, R28.17, R33.1, R33.2, R33.3, R33.11, R34.3, R34.8, R5.1
 *
 * Checks running the writing CMS actions through the real use cases (task 29)
 * against in-memory stores: success messages, translating domain exceptions
 * into error messages (without state change), the access protection (R5.1)
 * and prefilling the forms.
 */
final class CmsEditControllerTest extends TestCase
{
    private const CSRF = '<input type="hidden" name="csrf_token" value="t">';

    private SwitchableWriteAccess $access;
    private InMemoryHomePagePostStore $homePage;
    private InMemoryRulesStore $rules;
    private CmsEditController $controller;

    protected function setUp(): void
    {
        $sanitizer = new HtmlSanitizer();
        if (! $sanitizer->isAvailable()) {
            self::markTestSkipped('HTML Purifier is not available.');
        }

        $this->access     = new SwitchableWriteAccess(true);
        $this->homePage  = new InMemoryHomePagePostStore();
        $this->rules = new InMemoryRulesStore();
        $validator         = new Validator();

        $this->controller = new CmsEditController(
            new HomePagePostManagement($this->homePage, $validator, $sanitizer, $this->access),
            new RulesManagement($this->rules, $validator, $sanitizer, $this->access),
            new InMemoryCmsReader($this->homePage, $this->rules),
            new CmsEditView(),
        );
    }

    public function testCreateHomePagePostReturnsSuccess(): void
    {
        $result = $this->controller->processHomePage('create', [
            'title' => 'Willkommen',
            'text'  => '<p>Die Saison startet.</p>',
        ]);

        self::assertTrue($result->successful);
        self::assertSame(1, $this->homePage->quantity());
    }

    public function testInvalidTitleReturnsErrorWithoutChange(): void
    {
        $result = $this->controller->processHomePage('create', [
            'title' => '   ',
            'text'  => 'Text',
        ]);

        self::assertFalse($result->successful);
        self::assertStringContainsString('Titel', $result->message);
        self::assertSame(0, $this->homePage->quantity());
    }

    public function testMaliciousCodeIsRemovedOnCreate(): void
    {
        $this->controller->processHomePage('create', [
            'title' => '<script>alert(1)</script>',
            'text'  => '<p>Hallo</p><script>alert(2)</script><img src=x alt="Bild" onerror=alert(3)>',
        ]);

        $saved = $this->homePage->contents[1];
        self::assertStringNotContainsString('<script', $saved['title']);
        self::assertStringNotContainsString('<script', $saved['text']);
        self::assertStringNotContainsString('onerror', $saved['text']);
        self::assertStringContainsString('<p>Hallo</p>', $saved['text']);
    }

    /**
     * R42.6: every image needs a description; otherwise nothing is saved.
     */
    public function testAnImageWithoutDescriptionIsNotSaved(): void
    {
        $result = $this->controller->processHomePage('create', [
            'title' => 'Mit Bild',
            'text'  => '<p>Hallo</p><img src="/media/' . str_repeat('a', 64) . '.jpg">',
        ]);

        self::assertFalse($result->successful);
        self::assertStringContainsString('Jedes Bild braucht eine Bildbeschreibung', $result->message);
        self::assertSame(0, $this->homePage->quantity());
    }

    public function testChangeHomePagePost(): void
    {
        $id = $this->homePage->create('Alt', '<p>Alt</p>');

        $result = $this->controller->processHomePage('update', [
            'id'    => (string) $id,
            'title' => 'Neu',
            'text'  => '<p>Neu</p>',
        ]);

        self::assertTrue($result->successful);
        self::assertSame('Neu', $this->homePage->contents[$id]['title']);
    }

    public function testChangingUnknownIdReturnsErrorWithoutNewEntry(): void
    {
        $result = $this->controller->processHomePage('update', [
            'id'    => '99',
            'title' => 'Neu',
            'text'  => 'Neu',
        ]);

        self::assertFalse($result->successful);
        self::assertSame(0, $this->homePage->quantity());
    }

    public function testRemoveHomePagePost(): void
    {
        $id = $this->homePage->create('Weg', 'Weg');

        $result = $this->controller->processHomePage('remove', ['id' => (string) $id]);

        self::assertTrue($result->successful);
        self::assertSame(0, $this->homePage->quantity());
    }

    public function testWithoutWriteAccessEverythingStaysUnchanged(): void
    {
        $id = $this->homePage->create('Bleibt', 'Bleibt');
        $this->access->allowed = false;

        $create   = $this->controller->processHomePage('create', ['title' => 'X', 'text' => 'X']);
        $removal = $this->controller->processHomePage('remove', ['id' => (string) $id]);
        $ruleList    = $this->controller->saveRules(['text' => 'Regel']);

        self::assertFalse($create->successful);
        self::assertFalse($removal->successful);
        self::assertFalse($ruleList->successful);
        self::assertSame(1, $this->homePage->quantity());
        self::assertNull($this->rules->text);
    }

    public function testSavingRulesSanitizesText(): void
    {
        $result = $this->controller->saveRules([
            'text' => '<p>Regel 1</p><script>alert(1)</script>',
        ]);

        self::assertTrue($result->successful);
        self::assertSame('<p>Regel 1</p>', $this->rules->text);
    }

    public function testTooLongRulesAreRejected(): void
    {
        $result = $this->controller->saveRules(['text' => str_repeat('a', 20001)]);

        self::assertFalse($result->successful);
        self::assertNull($this->rules->text);
    }

    public function testEmptyRulesAreAllowed(): void
    {
        $result = $this->controller->saveRules(['text' => '']);

        self::assertTrue($result->successful);
        self::assertSame('', $this->rules->text);
    }

    public function testEditPrefillsFormWithDecryptedTitle(): void
    {
        // The title is stored escaped; in the form it must not appear escaped twice.
        $this->controller->processHomePage('create', ['title' => 'Tom & Jerry', 'text' => 'Text']);

        $html = $this->controller->homePageEdit(self::CSRF, 1);

        self::assertStringContainsString('value="Tom &amp; Jerry"', $html);
        self::assertStringNotContainsString('&amp;amp;', $html);
        self::assertStringContainsString('action="/admin/home-page/update"', $html);
    }

    public function testEditingUnknownIdShowsError(): void
    {
        $html = $this->controller->homePageEdit(self::CSRF, 42);

        self::assertStringContainsString('nicht gefunden', $html);
        self::assertStringContainsString('action="/admin/home-page/create"', $html);
    }

    public function testFailedInputIsKeptInTheForm(): void
    {
        $html = $this->controller->homePageEdit(
            self::CSRF,
            null,
            ['title' => '', 'text' => 'Mein langer Text'],
            null,
            'Fehler',
        );

        self::assertStringContainsString('Mein langer Text', $html);
    }

    public function testRulesEditShowsStoredText(): void
    {
        $this->rules->text = '<p>Regel</p>';

        $html = $this->controller->rulesEdit(self::CSRF);

        self::assertStringContainsString('&lt;p&gt;Regel&lt;/p&gt;', $html);
    }

    public function testUnknownActionReturnsError(): void
    {
        self::assertFalse($this->controller->processHomePage('unbekannt', [])->successful);
    }
}
