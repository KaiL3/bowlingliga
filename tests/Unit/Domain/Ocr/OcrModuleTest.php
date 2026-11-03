<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\Ocr;

use Bowlingliga\Domain\Ocr\RecognizedGameData;
use Bowlingliga\Domain\Ocr\RecognizedGame;
use Bowlingliga\Domain\Ocr\UploadedFile;
use Bowlingliga\Domain\Ocr\OcrException;
use Bowlingliga\Domain\Ocr\OcrModule;
use Bowlingliga\Domain\Ocr\AssignmentResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the pure {@see OcrModule} (task 25.1).
 *
 * Validates: R3.2, R3.7, R3.8, R3.9, R3.10, R3.3, R3.4, R3.5, R3.18
 *
 * Checks the format/size validation (R3.2), parsing the recognized raw data
 * including the blind marker "X" vs. order digit (R3.7/8) and the review need
 * of ambiguous values (R3.9), the assignment to matchday/fixture (R3.3–5) and
 * merging split score sheets (R3.18). The OCR engine is replaced by a text
 * stub.
 */
final class OcrModuleTest extends TestCase
{
    private function file(string $extension = 'jpg', string $mime = 'image/jpeg', int $size = 1000): UploadedFile
    {
        return new UploadedFile(str_repeat('x', $size), 'zettel.' . $extension, $mime);
    }

    // --- R3.2: format/size validation ---

    public function testUnsupportedFormatIsRejected(): void
    {
        $module = new OcrModule(new FixedTextRecognizer(''));

        $this->expectException(OcrException::class);
        $module->recognizeScoreSheet($this->file('gif', 'image/gif'));
    }

    public function testTooLargeFileIsRejected(): void
    {
        $module = new OcrModule(new FixedTextRecognizer(''));

        $this->expectException(OcrException::class);
        $module->recognizeScoreSheet($this->file('jpg', 'image/jpeg', OcrModule::MAX_SIZE_BYTES + 1));
    }

    public function testFailedRecognitionThrowsException(): void
    {
        $module = new OcrModule(new FixedTextRecognizer('', throwsError: true));

        $this->expectException(OcrException::class);
        $module->recognizeScoreSheet($this->file());
    }

    // --- R3.7 / R3.8 / R3.9: Parsing ---

    public function testBlindMarkerXIsRecognized(): void
    {
        $text = "DATUM: 15.01.2026\nTEAM: Alpha\nTEAM: Beta\n1 1 178 X";
        $module = new OcrModule(new FixedTextRecognizer($text));

        $data = $module->recognizeScoreSheet($this->file());

        self::assertSame('2026-01-15', $data->date);
        self::assertSame(['Alpha', 'Beta'], $data->teamNames);
        self::assertCount(1, $data->games);
        $es = $data->games[0];
        self::assertTrue($es->isBlind, 'The mark "X" must be recognised as blind (R3.7).');
        self::assertSame(178, $es->net);
        self::assertFalse($es->needsReview);
    }

    public function testOrderDigitIsNotBlind(): void
    {
        $module = new OcrModule(new FixedTextRecognizer("2 3 154 2"));

        $data = $module->recognizeScoreSheet($this->file());

        $es = $data->games[0];
        self::assertFalse($es->isBlind, 'A digit 1–4 is an order, not blind (R3.8).');
        self::assertSame(154, $es->net);
        self::assertFalse($es->needsReview);
    }

    public function testAmbiguousMarkingNeedsReview(): void
    {
        $module = new OcrModule(new FixedTextRecognizer("1 2 150 ?"));

        $data = $module->recognizeScoreSheet($this->file());

        self::assertTrue($data->games[0]->needsReview, 'An ambiguous mark needs review (R3.9).');
        self::assertTrue($data->hasValuesNeedingReview());
    }

    public function testUnreadableNetValueNeedsReview(): void
    {
        $module = new OcrModule(new FixedTextRecognizer("1 1 - X"));

        $data = $module->recognizeScoreSheet($this->file());

        $es = $data->games[0];
        self::assertNull($es->net);
        self::assertTrue($es->needsReview, 'An unreadable net value needs review (R3.9).');
    }

    public function testUncertainDateNeedsReview(): void
    {
        $module = new OcrModule(new FixedTextRecognizer("DATUM: 31.02.2026"));

        $data = $module->recognizeScoreSheet($this->file());

        self::assertNull($data->date);
        self::assertTrue($data->dateNeedsReview, 'An invalid date needs review (R3.9).');
    }

    // --- R3.3 / R3.4 / R3.5: Zuordnung ---

    public function testUniqueAssignment(): void
    {
        $data = new RecognizedGameData('2026-01-15', ['Alpha', 'Beta'], []);
        $reader = new FixedAssignmentReader([
            '2026-01-15' => [
                ['matchday_id' => 7, 'fixture_id' => 70, 'team_names' => ['Alpha', 'Beta']],
                ['matchday_id' => 7, 'fixture_id' => 71, 'team_names' => ['Gamma', 'Delta']],
            ],
        ]);

        $result = (new OcrModule(new FixedTextRecognizer('')))->assign($data, $reader);

        self::assertSame(AssignmentResult::UNIQUE, $result->status);
        self::assertSame(7, $result->matchdayId);
        self::assertSame(70, $result->fixtureId);
    }

    public function testAmbiguousAssignmentOffersSelection(): void
    {
        // Team names match no fixture unambiguously -> manual choice (R3.4).
        $data = new RecognizedGameData('2026-01-15', ['Unbekannt', 'Fremd'], []);
        $reader = new FixedAssignmentReader([
            '2026-01-15' => [
                ['matchday_id' => 7, 'fixture_id' => 70, 'team_names' => ['Alpha', 'Beta']],
                ['matchday_id' => 7, 'fixture_id' => 71, 'team_names' => ['Gamma', 'Delta']],
            ],
        ]);

        $result = (new OcrModule(new FixedTextRecognizer('')))->assign($data, $reader);

        self::assertSame(AssignmentResult::AMBIGUOUS, $result->status);
        self::assertSame([70, 71], $result->candidateFixtureIds);
    }

    public function testNoMatchdayForDate(): void
    {
        $data = new RecognizedGameData('2026-01-15', ['Alpha', 'Beta'], []);
        $reader = new FixedAssignmentReader([]);

        $result = (new OcrModule(new FixedTextRecognizer('')))->assign($data, $reader);

        self::assertSame(AssignmentResult::NO_MATCHDAY, $result->status);
        self::assertNotNull($result->message);
    }

    public function testAssignmentIndependentOfTeamNameOrder(): void
    {
        $data = new RecognizedGameData('2026-01-15', ['Beta', 'Alpha'], []);
        $reader = new FixedAssignmentReader([
            '2026-01-15' => [
                ['matchday_id' => 7, 'fixture_id' => 70, 'team_names' => ['Alpha', 'Beta']],
            ],
        ]);

        $result = (new OcrModule(new FixedTextRecognizer('')))->assign($data, $reader);

        self::assertTrue($result->isUnique(), 'The order of the team names must not prevent the assignment.');
    }

    // --- R3.18: merging split score sheets ---

    public function testSplitScoreSheetsAreMerged(): void
    {
        $halfA = new RecognizedGameData('2026-01-15', ['Alpha', 'Beta'], [
            new RecognizedGame(1, 1, 178, false, false),
            new RecognizedGame(1, 2, 165, false, false),
        ]);
        $halfB = new RecognizedGameData(null, [], [
            new RecognizedGame(2, 1, 190, false, false),
            new RecognizedGame(2, 2, 172, false, false),
        ]);

        $combined = (new OcrModule(new FixedTextRecognizer('')))->mergeShared([$halfA, $halfB]);

        self::assertSame('2026-01-15', $combined->date, 'The date of the first half is kept (R3.18).');
        self::assertSame(['Alpha', 'Beta'], $combined->teamNames);
        self::assertCount(4, $combined->games, 'Both halves give a complete record (R3.18).');
    }

    public function testMergingWithoutPartsThrowsError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OcrModule(new FixedTextRecognizer('')))->mergeShared([]);
    }
}
