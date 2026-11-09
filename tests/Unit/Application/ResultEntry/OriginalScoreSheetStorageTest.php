<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\OriginalScoreSheetStorage;
use Bowlingliga\Domain\Ocr\UploadedFile;
use Bowlingliga\Domain\Ocr\OcrException;
use Bowlingliga\Domain\Ocr\OcrModule;
use Bowlingliga\Domain\ScoreSheet\ScoreSheetPhotoException;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see OriginalScoreSheetStorage} (task 25.2).
 *
 * Validates: R3.15, R3.16, R3.17, R3.2, R3.20, R5.1
 *
 * Checks the immutable storage including the assignment to the fixture
 * (R3.15/R3.16), the independence from the OCR result (R3.17), the rejection
 * of invalid formats/sizes (R3.2) and the access protection (R5.1).
 * Persistence is an in-memory fake.
 */
final class OriginalScoreSheetStorageTest extends TestCase
{
    private InMemoryOriginalScoreSheetStore $store;
    private SwitchableWriteAccess $access;
    private OriginalScoreSheetStorage $management;

    protected function setUp(): void
    {
        $this->store   = new InMemoryOriginalScoreSheetStore();
        $this->access    = new SwitchableWriteAccess(true);
        $this->management = new OriginalScoreSheetStorage($this->store, $this->access);
    }

    private function file(string $extension = 'pdf', string $mime = 'application/pdf', int $size = 2048): UploadedFile
    {
        return new UploadedFile(str_repeat('b', $size), 'zettel.' . $extension, $mime);
    }

    public function testUploadIsStoredUnchangedAndAssigned(): void
    {
        $content = str_repeat('b', 2048);

        $id = $this->management->save(42, $this->file());

        self::assertSame(1, $id);
        self::assertCount(1, $this->store->saved);
        self::assertSame(42, $this->store->saved[0]['fixture_id'], 'Assignment to the fixture (R3.16).');
        self::assertSame($content, $this->store->saved[0]['file'], 'The content is stored unchanged (R3.15).');
        self::assertSame('application/pdf', $this->store->saved[0]['mime_type']);
    }

    public function testPhotosAreStoredPreparedAsJpeg(): void
    {
        ob_start();
        imagepng(imagecreatetruecolor(30, 20));
        $png = (string) ob_get_clean();

        $this->management->save(7, new UploadedFile($png, 'zettel.png', 'image/png'));

        self::assertSame('image/jpeg', $this->store->saved[0]['mime_type'], 'Photos are stored as JPEG (R3.20).');
        self::assertStringStartsWith("\xFF\xD8", $this->store->saved[0]['file']);
    }

    public function testAnUnreadablePhotoIsRejected(): void
    {
        $this->expectException(ScoreSheetPhotoException::class);
        $this->management->save(1, new UploadedFile(str_repeat('b', 64), 'zettel.jpg', 'image/jpeg'));
    }

    public function testPdfIsAccepted(): void
    {
        $id = $this->management->save(1, $this->file('pdf', 'application/pdf'));

        self::assertSame(1, $id);
    }

    public function testUnsupportedFormatIsRejected(): void
    {
        $this->expectException(OcrException::class);
        $this->management->save(1, $this->file('gif', 'image/gif'));
    }

    public function testTooLargeFileIsRejected(): void
    {
        $this->expectException(OcrException::class);
        $this->management->save(1, $this->file('jpg', 'image/jpeg', OcrModule::MAX_SIZE_BYTES + 1));
    }

    public function testWithoutWriteAccessNothingIsSaved(): void
    {
        $this->access->allowed = false;

        try {
            $this->management->save(1, $this->file());
            self::fail('Without write access the action should have been rejected (R5.1).');
        } catch (AccessDeniedException) {
            // expected
        }

        self::assertCount(0, $this->store->saved, 'Without write access nothing may be saved (R5.1).');
    }
}
