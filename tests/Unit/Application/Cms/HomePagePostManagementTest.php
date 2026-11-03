<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Cms;

use Bowlingliga\Application\Cms\HomePagePostManagement;
use Bowlingliga\Security\HtmlSanitizer;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see HomePagePostManagement} (task 29.1).
 *
 * Validates: R33.1, R33.2, R33.3, R33.5, R33.6, R33.10, R33.11, R5.1
 *
 * Checks create/change/remove, the validation of title (R33.5) and text
 * content (R33.6), the sanitizing (R33.11) and the access protection (R5.1).
 * Persistence is an in-memory fake; validator and HtmlSanitizer are used for
 * real.
 */
final class HomePagePostManagementTest extends TestCase
{
    private InMemoryHomePagePostStore $store;
    private SwitchableWriteAccess $access;
    private HtmlSanitizer $sanitizer;
    private HomePagePostManagement $management;

    protected function setUp(): void
    {
        $this->store  = new InMemoryHomePagePostStore();
        $this->access   = new SwitchableWriteAccess(true);
        $this->sanitizer = new HtmlSanitizer();
        $this->management = new HomePagePostManagement(
            $this->store,
            new Validator(),
            $this->sanitizer,
            $this->access,
        );
    }

    public function testContentIsCreated(): void
    {
        $id = $this->management->add('Willkommen', 'Die Saison startet bald.');

        self::assertSame(1, $this->store->quantity());
        self::assertSame('Willkommen', $this->store->contents[$id]['title']);
    }

    public function testEmptyTitleIsRejected(): void
    {
        try {
            $this->management->add('   ', 'Text');
            self::fail('An empty title should have been rejected (R33.5).');
        } catch (ValidationException $exception) {
            self::assertSame('field.title', $exception->field);
        }
        self::assertSame(0, $this->store->quantity());
    }

    public function testTooLongTitleIsRejected(): void
    {
        try {
            $this->management->add(str_repeat('a', 201), 'Text');
            self::fail('A title of 201 characters should have been rejected (R33.5).');
        } catch (ValidationException $exception) {
            self::assertSame('field.title', $exception->field);
        }
    }

    public function testEmptyTextIsRejected(): void
    {
        try {
            $this->management->add('Titel', '   ');
            self::fail('An empty text should have been rejected (R33.6).');
        } catch (ValidationException $exception) {
            self::assertSame('field.text_content', $exception->field);
        }
    }

    public function testMaliciousCodeIsRemoved(): void
    {
        if (! $this->sanitizer->isAvailable()) {
            self::markTestSkipped('HTML Purifier is not available; sanitizing test skipped.');
        }

        $id = $this->management->add(
            'Titel <script>alert(1)</script>',
            'Hallo <script>alert(2)</script> <b>Welt</b>',
        );

        $saved = $this->store->contents[$id];
        self::assertStringNotContainsString('<script>', $saved['title'], 'The title must not contain script code (R33.11).');
        self::assertStringNotContainsString('<script>', $saved['text'], 'The text must not contain script code (R33.11).');
        self::assertStringContainsString('<b>Welt</b>', $saved['text'], 'Allowed markup is kept.');
    }

    public function testChangeAndRemove(): void
    {
        $id = $this->management->add('Titel', 'Text');

        $this->management->change($id, 'Neuer Titel', 'Neuer Text');
        self::assertSame('Neuer Titel', $this->store->contents[$id]['title']);

        $this->management->remove($id);
        self::assertSame(0, $this->store->quantity());
    }

    public function testWithoutWriteAccessNoChange(): void
    {
        $this->access->allowed = false;

        $this->expectException(AccessDeniedException::class);
        $this->management->add('Titel', 'Text');
    }
}
