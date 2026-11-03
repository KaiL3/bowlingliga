<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Domain\Printing\PrintScoreSheetHeader;
use Bowlingliga\Domain\Printing\PrintScoreSheetService;
use Bowlingliga\Domain\Printing\PrintScoreSheetPlayer;
use Bowlingliga\Domain\Printing\PrintScoreSheetTeam;
use Bowlingliga\Printing\MpdfPrintScoreSheetRenderer;
use Bowlingliga\Presentation\PrintController;
use Bowlingliga\Presentation\PrintScoreSheetDataSource;
use Bowlingliga\Presentation\PrintScoreSheetResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see PrintController} (task 35.4).
 *
 * Validates: R28.13, R19.4, R19.5
 *
 * Checks the wiring of the pure printed score sheet service via an in-memory
 * data source: success (PDF or HTML fallback depending on mPDF availability,
 * R19.4), error for a missing lane assignment (R19.5) and error for a fixture
 * that cannot be found.
 */
final class PrintControllerTest extends TestCase
{
    private PrintController $controller;

    protected function setUp(): void
    {
        $this->controller = new PrintController(
            new InMemoryPrintDataSource(),
            new PrintScoreSheetService(),
            new MpdfPrintScoreSheetRenderer(),
        );
    }

    public function testGeneratesScoreSheetForValidFixture(): void
    {
        // Fixture 1: regular with lane assignment.
        $result = $this->controller->generate(1);

        self::assertFalse($result->isError());
        self::assertNotSame('', $result->content);

        // Depending on mPDF availability a PDF (R19.4) or the HTML fallback (R19.4).
        $expectedType = (new MpdfPrintScoreSheetRenderer())->isAvailable()
            ? PrintScoreSheetResult::TYPE_PDF
            : PrintScoreSheetResult::TYPE_HTML;
        self::assertSame($expectedType, $result->type);
    }

    public function testMissingLaneAssignmentReturnsError(): void
    {
        // Fixture 2: regular without lane assignment (R19.5).
        $result = $this->controller->generate(2);

        self::assertTrue($result->isError());
        self::assertStringContainsString('Bahnzuordnung', $result->content);
    }

    public function testUnknownFixtureReturnsError(): void
    {
        $result = $this->controller->generate(999);

        self::assertTrue($result->isError());
        self::assertStringContainsString('unvollständig', $result->content);
    }

    // --- Printing by matchday ---

    public function testSelectionPreselectsNextOpenMatchday(): void
    {
        $html = $this->controller->selection(1);

        self::assertStringContainsString('name="matchday"', $html);
        self::assertStringContainsString('<option value="11" selected>Spieltag 2 · Mi, 14.01.2026 · 2 Paarungen</option>', $html);
        self::assertStringContainsString('Spieltag 1 · Mi, 07.01.2026 · 1 Paarung · gespielt', $html);
        self::assertStringContainsString('Spieltag 4 · Mi, 28.01.2026 · Positionday · 0 Paarungen', $html);
        self::assertStringContainsString('target="_blank"', $html, 'The PDF opens in a new tab.');
        self::assertStringContainsString('<select id="print-language" name="language"><option value="de" selected>Deutsch</option><option value="en">English</option></select>', $html, 'Without a language of the season the installation default is preselected.');
    }

    public function testSelectionWithoutMatchdaysPointsToSeasonPlanning(): void
    {
        $html = $this->controller->selection(2);

        self::assertStringContainsString('noch keine Spieltage', $html);
        self::assertStringContainsString('href="/admin/season-planning"', $html);
    }

    public function testSelectionShowsErrorMessage(): void
    {
        $html = $this->controller->selection(1, 'Keine <Bahnzuordnung>');

        self::assertStringContainsString('<p class="error" role="alert">Keine &lt;Bahnzuordnung&gt;</p>', $html);
    }

    public function testMatchdayPrintsAllFixtures(): void
    {
        $result = $this->controller->generateForMatchday(11);

        self::assertFalse($result->isError());
        self::assertNotSame('', $result->content);
    }

    public function testMatchdayWithoutLaneAssignmentReturnsError(): void
    {
        $result = $this->controller->generateForMatchday(12);

        self::assertTrue($result->isError());
        self::assertStringContainsString('Bahnzuordnung', $result->content);
    }

    public function testMatchdayWithoutFixturesReturnsError(): void
    {
        $result = $this->controller->generateForMatchday(13);

        self::assertTrue($result->isError());
        self::assertStringContainsString('keine Teampaarungen', $result->content);
    }

    public function testLotDrawBeforeTheDrawPrintsOneHalfSheetPerTeam(): void
    {
        $result = $this->controller->generateForMatchday(20);

        self::assertFalse($result->isError(), $result->content);
        self::assertSame(3, preg_match_all('#/Type\s*/Page[^s]#', $result->content), 'One sheet per team, including the ghost team.');
    }

    public function testLotDrawWithoutLaneAssignmentIsNoError(): void
    {
        // Fixture 3: lot draw without lane numbers – allowed (R32.3).
        $result = $this->controller->generate(3);

        self::assertFalse($result->isError());
    }
}

/**
 * In-memory data source for the print controller test.
 *
 * Fixture 1: regular with lane assignment; 2: regular without lane assignment
 * (R19.5); 3: lot draw without lane numbers (R32.3). All other ids are
 * unknown.
 */
final class InMemoryPrintDataSource implements PrintScoreSheetDataSource
{
    public function header(int $fixtureId): ?PrintScoreSheetHeader
    {
        return match ($fixtureId) {
            1 => new PrintScoreSheetHeader('2026-01-15', PrintScoreSheetHeader::TYPE_REGULAR, 1, 2, 5.0, 2.5),
            2 => new PrintScoreSheetHeader('2026-01-22', PrintScoreSheetHeader::TYPE_REGULAR, null, null, 5.0, 2.5),
            3 => new PrintScoreSheetHeader('2026-02-01', PrintScoreSheetHeader::TYPE_LOT_DRAW, null, null, 5.0, 2.5),
            default => null,
        };
    }

    public function homeTeam(int $fixtureId): ?PrintScoreSheetTeam
    {
        return in_array($fixtureId, [1, 2, 3], true)
            ? new PrintScoreSheetTeam('Die Adler', [new PrintScoreSheetPlayer('Anna', 178.5, 20)])
            : null;
    }

    public function awayTeam(int $fixtureId): ?PrintScoreSheetTeam
    {
        return in_array($fixtureId, [1, 2, 3], true)
            ? new PrintScoreSheetTeam('Die Falken', [new PrintScoreSheetPlayer('Bernd', null, null)])
            : null;
    }

    /**
     * Season 1: matchday 10 played (fixture 1), matchday 11 open with fixtures
     * 1 and 3, matchday 12 without lane assignment (fixture 2), matchday 13 a
     * positionday without fixtures.
     */
    public function matchdays(int $seasonId): array
    {
        return $seasonId !== 1 ? [] : [
            ['id' => 10, 'number' => 1, 'date' => '2026-01-07', 'type' => 'regular', 'played' => true, 'pairings' => 1],
            ['id' => 11, 'number' => 2, 'date' => '2026-01-14', 'type' => 'regular', 'played' => false, 'pairings' => 2],
            ['id' => 12, 'number' => 3, 'date' => '2026-01-21', 'type' => 'regular', 'played' => false, 'pairings' => 1],
            ['id' => 13, 'number' => 4, 'date' => '2026-01-28', 'type' => 'positionday', 'played' => false, 'pairings' => 0],
        ];
    }

    public function fixtureIds(int $matchdayId): array
    {
        return match ($matchdayId) {
            10 => [1],
            11 => [1, 3],
            12 => [2],
            default => [],
        };
    }

    public function lotDrawSheets(int $matchdayId): ?array
    {
        // Matchday 20: lot draw with three teams (incl. ghost team) before the draw.
        return $matchdayId !== 20 ? null : [
            'header' => new PrintScoreSheetHeader('2030-10-02', PrintScoreSheetHeader::TYPE_LOT_DRAW, null, null, 15.0, 7.5, 4),
            'teams'  => [
                new PrintScoreSheetTeam('Adler', [new PrintScoreSheetPlayer('Anna', 171.0, 23, 12)]),
                new PrintScoreSheetTeam('Falken', [new PrintScoreSheetPlayer('Bernd', 188.0, 10, 12)]),
                new PrintScoreSheetTeam('Geisterteam', [], 190),
            ],
        ];
    }

    /** Matchday 12 belongs to a season printing in English (a test value only). */
    public function scoreSheetLanguage(int $matchdayId): ?string
    {
        return $matchdayId === 12 ? 'en' : null;
    }

    public function matchdayOfFixture(int $fixtureId): ?int
    {
        return in_array($fixtureId, [1, 2, 3], true) ? 11 : null;
    }
}
