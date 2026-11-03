<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Application\Aggregation\HonoursAggregator;
use Bowlingliga\Domain\Honours\Gender;
use Bowlingliga\Domain\Honours\GameResultInput;
use Bowlingliga\Presentation\HonoursPreviewController;
use Bowlingliga\Presentation\PublicView;
use Bowlingliga\Tests\Unit\Application\Aggregation\FixedHonoursRawDataReader;
use Bowlingliga\Tests\Unit\Application\InMemorySeasonReader;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the {@see HonoursPreviewController} (task 35.5).
 *
 * Validates: R28.14, R13.8, R13.9, R13.10
 *
 * Checks that administrators see the calculated honours already before the
 * end date, but not without an admin session, and that the note reflects the
 * public visibility status correctly.
 */
final class HonoursPreviewControllerTest extends TestCase
{
    private InMemorySeasonReader $seasons;

    protected function setUp(): void
    {
        $this->seasons = new InMemorySeasonReader();
        $this->seasons->stash(1, '2030-09-04', '2031-05-28');
    }

    private function controller(bool $afterEndDate, bool $isAdmin = true, bool $withResults = true): HonoursPreviewController
    {
        // A countable series (exactly 3 regular games on one evening, R13.1).
        $results = [];
        foreach ($withResults ? [200, 210, 220] : [] as $net) {
            $results[] = new GameResultInput(7, Gender::FEMALE, 1, $net, 10, playerName: 'Anna Berg');
        }

        return new HonoursPreviewController(
            new HonoursAggregator(new FixedHonoursRawDataReader($results, $afterEndDate)),
            $this->seasons,
            new PublicView(),
            new SwitchableWriteAccess($isAdmin),
        );
    }

    public function testAdminSeesHonoursBeforeEndDate(): void
    {
        $html = $this->controller(afterEndDate: false)->preview(1);

        self::assertStringContainsString('Ehrungs-Vorschau', $html);
        self::assertStringContainsString('nur für Administratoren', $html);
        self::assertStringContainsString('28.05.2031', $html);
        self::assertStringContainsString('Höchste Serie (Netto)', $html);
        self::assertStringContainsString('<span class="honour__name">Anna Berg</span><span class="honour__value">630</span>', $html);
        self::assertStringContainsString('<span class="honour__name">Anna Berg</span><span class="honour__value">660</span>', $html, 'Brutto-Serie = Netto + Handicap je Spiel.');
    }

    public function testAfterEndDateNoteOnPublicPage(): void
    {
        $html = $this->controller(afterEndDate: true)->preview(1);

        self::assertStringContainsString('öffentlich sichtbar', $html);
        self::assertStringContainsString('href="/honours?season=1"', $html);
        self::assertStringContainsString('Anna Berg', $html);
    }

    public function testWithoutAdminSessionBeforeEndDateNoDisplay(): void
    {
        $html = $this->controller(afterEndDate: false, isAdmin: false)->preview(1);

        self::assertStringContainsString('erst nach Ablauf der Saison', $html);
        self::assertStringNotContainsString('Anna Berg', $html);
    }

    public function testWithoutResultsNoteInsteadOfEmptyPlaces(): void
    {
        $html = $this->controller(afterEndDate: false, withResults: false)->preview(1);

        self::assertStringContainsString('noch keine wertbaren Ergebnisse', $html);
        self::assertStringNotContainsString('unbesetzt', $html);
    }

    public function testUnknownSeason(): void
    {
        self::assertStringContainsString('nicht gefunden', $this->controller(false)->preview(99));
        self::assertStringContainsString('keine Saison gewählt', $this->controller(false)->preview(null));
    }
}
