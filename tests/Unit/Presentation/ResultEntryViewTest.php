<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Application\ResultEntry\OverviewEntry;
use Bowlingliga\Presentation\ResultEntryView;
use PHPUnit\Framework\TestCase;

/**
 * Overview of the result entry (R30.1–R30.3): next open matchday as tiles,
 * all matchdays with progress.
 */
final class ResultEntryViewTest extends TestCase
{
    public function testNextOpenMatchdayIsOnTopWithTiles(): void
    {
        $html = (new ResultEntryView())->renderOverview([
            new OverviewEntry(1, 10, 1, 'Adler', 'Falken', OverviewEntry::COMPLETE, '2026-09-02', '1/2'),
            new OverviewEntry(2, 10, 1, 'Eulen', 'Kojak', OverviewEntry::COMPLETE, '2026-09-02', '3/4'),
            new OverviewEntry(3, 11, 2, 'Adler', 'Eulen', OverviewEntry::IN_PROGRESS, '2026-09-09', '1/2'),
            new OverviewEntry(4, 11, 2, 'Falken', 'Kojak', OverviewEntry::OPEN, '2026-09-09', null),
        ]);

        self::assertStringContainsString('2 von 4 Paarungen sind noch auszufüllen.', $html);
        self::assertStringContainsString('<h2>Spieltag 2</h2>', $html, 'Matchday 2 is the first with open fixtures.');
        self::assertStringContainsString('Nächster offener Spieltag · Mi 09.09.2026', $html);
        self::assertSame(2, substr_count($html, 'class="tile tile--pairing'));
        self::assertStringContainsString('<span class="tile__kicker">Bahn 1/2</span>', $html);
        self::assertStringContainsString('<span class="tile__kicker">ohne Bahn</span>', $html);
        self::assertStringContainsString('Weiter erfassen', $html);

        self::assertStringContainsString('<span class="progress progress--complete">2/2 erfasst</span>', $html);
        self::assertStringContainsString('<span class="progress progress--in-progress">0/2 erfasst</span>', $html);
    }

    public function testEverythingEntered(): void
    {
        $html = (new ResultEntryView())->renderOverview([
            new OverviewEntry(1, 10, 1, 'Adler', 'Falken', OverviewEntry::COMPLETE, '2026-09-02', '1/2'),
        ]);

        self::assertStringContainsString('Alles erfasst', $html);
        self::assertStringNotContainsString('tile--pairing', $html);
    }

    public function testNamesAreEscaped(): void
    {
        $html = (new ResultEntryView())->renderOverview([
            new OverviewEntry(1, 10, 1, '<b>Adler</b>', 'Falken', OverviewEntry::OPEN),
        ]);

        self::assertStringNotContainsString('<b>Adler</b>', $html);
        self::assertStringContainsString('&lt;b&gt;Adler&lt;/b&gt;', $html);
    }
}
