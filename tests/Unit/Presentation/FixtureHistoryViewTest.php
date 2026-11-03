<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Application\ResultEntry\FixtureLogEntry;
use Bowlingliga\Presentation\FixtureHistoryView;
use PHPUnit\Framework\TestCase;

/**
 * Card "Verlauf": who, what, value before → after, everything escaped.
 */
final class FixtureHistoryViewTest extends TestCase
{
    private const TEAMS = ['home' => 'Adler', 'away' => 'Falken'];

    public function testEntriesOfTeamsAndAdminAreShown(): void
    {
        $html = (new FixtureHistoryView())->render([
            new FixtureLogEntry('2026-10-21 21:05:09', null, 'sam', 'net', 11, 'Anna Berg', 2, '160', '165'),
            new FixtureLogEntry('2026-10-21 20:40:00', 'home', null, 'rejected', detail: 'Spiel 2 stimmt nicht'),
            new FixtureLogEntry('2026-10-21 20:15:00', 'away', null, 'net', 11, 'Anna Berg', 2, null, '160'),
            new FixtureLogEntry('2026-10-21 20:00:00', 'home', null, 'lineup', 11, 'Anna Berg', 2, null, 'spielt'),
        ], self::TEAMS);

        self::assertStringContainsString('<td>21.10.2026 21:05:09</td><td>Admin sam</td><td>Ergebnis</td><td>Anna Berg</td><td>S2</td><td>160 → 165</td>', $html);
        self::assertStringContainsString('<td>Adler (Heim)</td><td>Zur Korrektur zurückgegeben: Spiel 2 stimmt nicht</td>', $html);
        self::assertStringContainsString('<td>Falken (Gast)</td><td>Ergebnis</td><td>Anna Berg</td><td>S2</td><td>– → 160</td>', $html);
        self::assertStringContainsString('<td>Aufstellung</td><td>Anna Berg</td><td>S2</td><td>– → spielt</td>', $html);
        self::assertStringContainsString('4 Einträge', $html);
    }

    public function testValuesAreEscaped(): void
    {
        $html = (new FixtureHistoryView())->render([
            new FixtureLogEntry('2026-10-21 20:40:00', 'home', null, 'rejected', detail: '<script>alert(1)</script>'),
        ], ['home' => '<b>Adler</b>', 'away' => 'Falken']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>Adler</b>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testWithoutEntries(): void
    {
        self::assertStringContainsString('Noch keine Einträge.', (new FixtureHistoryView())->render([], self::TEAMS));
    }
}
