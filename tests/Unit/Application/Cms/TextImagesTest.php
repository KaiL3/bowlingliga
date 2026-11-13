<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Cms;

use Bowlingliga\Application\Cms\TextImages;
use Bowlingliga\Security\HtmlSanitizer;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Tests\GermanText;
use Bowlingliga\Tests\Unit\Application\Media\InMemoryContentImageStore;
use PHPUnit\Framework\TestCase;

/**
 * Validates: R42.4, R42.6
 *
 * A text is saved only with images that have a description and exist in the
 * image store; otherwise it is refused with the address, instead of the
 * sanitizer dropping the image silently.
 */
final class TextImagesTest extends TestCase
{
    private InMemoryContentImageStore $store;
    private TextImages $images;
    private string $known;

    protected function setUp(): void
    {
        $this->store = new InMemoryContentImageStore();
        $this->known = str_repeat('a', 64);
        $this->store->add($this->known, 'image/jpeg', 10, 10, 100, 'team.jpg', new \DateTimeImmutable());
        $this->images = new TextImages(new HtmlSanitizer(), $this->store);
    }

    public function testImagesOfTheStoreWithDescriptionPass(): void
    {
        $this->images->check('<p>Text</p><figure><img src="/media/' . $this->known . '.jpg" alt="Team"></figure>', 'field.text_content');
        $this->images->check('<p>Ohne Bild</p>', 'field.text_content');

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function refusedSources(): iterable
    {
        yield 'another server'       => ['https://example.org/bild.jpg', 'https://example.org/bild.jpg'];
        yield 'changed by hand'      => ['/media/bild.jpg', '/media/bild.jpg'];
        yield 'unknown hash'         => ['/media/' . str_repeat('b', 64) . '.jpg', '/media/' . str_repeat('b', 64) . '.jpg'];
        yield 'data address'         => ['data:image/png;base64,AAAA', 'data:image/png;base64,AAAA'];
        yield 'empty'                => ['', ''];
        yield 'long address, quoted' => ['https://example.org/' . str_repeat('x', 100) . '.jpg', 'https://example.org/' . str_repeat('x', 60) . '…'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedSources')]
    public function testAnImageFromElsewhereIsRefusedWithItsAddress(string $source, string $quoted): void
    {
        try {
            $this->images->check('<p><img src="' . $source . '" alt="Bild"></p>', 'field.text_content');
            self::fail('Refused: ' . $source);
        } catch (ValidationException $e) {
            self::assertSame(
                'Textinhalt: Das Bild „' . $quoted . '“ stammt nicht aus dem Bereich Bilder. Bitte über „Hochladen“ oder die Bildauswahl im Bild-Dialog einsetzen.',
                GermanText::of($e),
            );
        }
    }

    public function testAnImageWithoutDescriptionIsRefusedFirst(): void
    {
        $this->expectException(ValidationException::class);
        $this->images->check('<img src="https://example.org/a.jpg">', 'field.text_content');
    }

    public function testWithoutStoreOnlyTheAddressIsChecked(): void
    {
        (new TextImages(new HtmlSanitizer()))->check('<img src="/media/' . str_repeat('c', 64) . '.png" alt="x">', 'field.text_content');

        $this->addToAssertionCount(1);
    }
}
