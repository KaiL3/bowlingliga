<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Cms;

use Bowlingliga\Application\Cms\RulesManagement;
use Bowlingliga\Security\HtmlSanitizer;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see RulesManagement} (task 29.2).
 *
 * Validates: R34.3, R34.4, R34.5, R34.7, R34.8, R5.1
 *
 * Checks saving (also of empty content, R34.3), rejecting too long content
 * (R34.5), the sanitizing (R34.8) and the access protection (R5.1).
 */
final class RulesManagementTest extends TestCase
{
    private InMemoryRulesStore $store;
    private SwitchableWriteAccess $access;
    private HtmlSanitizer $sanitizer;
    private RulesManagement $management;

    protected function setUp(): void
    {
        $this->store  = new InMemoryRulesStore();
        $this->access   = new SwitchableWriteAccess(true);
        $this->sanitizer = new HtmlSanitizer();
        $this->management = new RulesManagement(
            $this->store,
            new Validator(),
            $this->sanitizer,
            $this->access,
        );
    }

    public function testTextIsSaved(): void
    {
        $this->management->save('<p>Regel 1</p>');

        self::assertNotNull($this->store->text);
        self::assertStringContainsString('Regel 1', (string) $this->store->text);
    }

    public function testEmptyTextIsAllowed(): void
    {
        // Lower bound 0 (R34.3): empty content is allowed.
        $this->management->save('');

        self::assertSame('', $this->store->text);
    }

    public function testTooLongTextIsRejected(): void
    {
        try {
            $this->management->save(str_repeat('a', 20001));
            self::fail('A text of 20001 characters should have been rejected (R34.5).');
        } catch (ValidationException $exception) {
            self::assertSame('field.text_content', $exception->field);
        }
        self::assertNull($this->store->text);
    }

    public function testMaliciousCodeIsRemoved(): void
    {
        if (! $this->sanitizer->isAvailable()) {
            self::markTestSkipped('HTML Purifier is not available; sanitizing test skipped.');
        }

        $this->management->save('<p>Regel</p><script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', (string) $this->store->text, 'Script code is removed (R34.8).');
        self::assertStringContainsString('Regel', (string) $this->store->text);
    }

    public function testWithoutWriteAccessNoChange(): void
    {
        $this->access->allowed = false;

        $this->expectException(AccessDeniedException::class);
        $this->management->save('Text');
    }
}
