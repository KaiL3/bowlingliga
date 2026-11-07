<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Application\PlayerProfile\PlayerProfile;
use Bowlingliga\Application\PlayerProfile\PlayerSeasonSummary;
use Bowlingliga\Domain\PlayerProfile\PlayerHistory;
use Bowlingliga\Domain\PlayerProfile\PlayerHistoryPoint;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Presentation\PlayerProfileView;
use PHPUnit\Framework\TestCase;

/**
 * Unit test of the player page view.
 *
 * Validates: R39.2, R39.3, R39.4, R39.5
 */
final class PlayerProfileViewTest extends TestCase
{
    private PlayerProfileView $view;

    protected function setUp(): void
    {
        $this->view = new PlayerProfileView();
    }

    public function testThePageShowsNameTeamKeyFiguresChartsAndTeams(): void
    {
        $html = $this->render($this->profile());

        self::assertStringContainsString('<h1>Anna &lt;A&gt;</h1>', $html, 'The name is escaped.');
        self::assertStringContainsString('Pins &amp; Co · weiblich', $html);
        self::assertStringContainsString('<dd>165,00</dd>', $html, 'Average of the selected season.');
        self::assertStringContainsString('<dd>20</dd>', $html, 'Last frozen handicap.');
        self::assertSame(2, substr_count($html, '<svg class="chart"'), 'Handicap and average chart.');
        self::assertStringContainsString('aria-label="Handicap je Spieltag in der Saison 2026/27"', $html);
        self::assertStringContainsString('<a href="/matchday/201">1</a>', $html, 'The value table links the matchday.');
        self::assertStringContainsString('<a href="/team/21">Pins &amp; Co</a>', $html);
        self::assertStringContainsString('<a href="/team/11">Adler</a>', $html);
        self::assertLessThan(strpos($html, '/team/11'), strpos($html, '/team/21'), 'Newest season first.');
        self::assertStringNotContainsString('R39', $html);
    }

    public function testTheSeasonSelectionMarksTheShownSeason(): void
    {
        $html = $this->render($this->profile());

        self::assertStringContainsString('<a class="season-picker__link" href="/player/7?season=2" aria-current="page">2026/27</a>', $html);
        self::assertStringContainsString('<a class="season-picker__link" href="/player/7?season=1">2025/26</a>', $html);
    }

    public function testASingleSeasonNeedsNoSelection(): void
    {
        $profile = $this->profile();
        $single  = new PlayerProfile(7, 'Anna', 'female', [$profile->seasons[0]], 2, $profile->history);

        self::assertStringNotContainsString('season-picker', $this->render($single));
    }

    public function testWithoutGamesInTheSeasonAMessageReplacesTheCharts(): void
    {
        $profile = $this->profile();
        $empty   = new PlayerProfile(7, 'Anna', 'female', $profile->seasons, 2, new PlayerHistory());

        $html = $this->render($empty);

        self::assertStringContainsString('In der Saison 2026/27 gibt es noch keine Spiele.', $html);
        self::assertStringNotContainsString('<svg', $html);
        self::assertStringContainsString('h-player-teams', $html, 'The teams stay.');
    }

    public function testAPlayerWithoutSeasonsGetsAMessageAndTheFooter(): void
    {
        $html = $this->view->render(new PlayerProfile(8, 'Ben', 'male', [], null, new PlayerHistory()), static fn (int $s): string => '', '<p>back</p>');

        self::assertStringContainsString('männlich', $html);
        self::assertStringContainsString('Dieser Spieler war noch in keinem Team.', $html);
        self::assertStringEndsWith('<p>back</p>', $html);
    }

    public function testEnglishTexts(): void
    {
        $html = (new PlayerProfileView(new Translator('en')))->render($this->profile(), static fn (int $s): string => '/player/7?season=' . $s);

        self::assertStringContainsString('Handicap history', $html);
        self::assertStringContainsString('Average of the evening', $html);
    }

    private function render(PlayerProfile $profile): string
    {
        return $this->view->render($profile, static fn (int $s): string => '/player/7?season=' . $s);
    }

    private function profile(): PlayerProfile
    {
        return new PlayerProfile(
            7,
            'Anna <A>',
            'female',
            [
                new PlayerSeasonSummary(2, '2026/27', 21, 'Pins & Co', 2, 6, 165.0, 20),
                new PlayerSeasonSummary(1, '2025/26', 11, 'Adler', 10, 30, 158.4, 34),
            ],
            2,
            new PlayerHistory([
                new PlayerHistoryPoint(201, 1, '2026-09-02', 150.0, 150.0, 3, 40),
                new PlayerHistoryPoint(202, 2, '2026-09-09', 165.0, 180.0, 3, 20),
            ]),
        );
    }

    public function testANoteExplainsThePreviousSeasonInTheAverage(): void
    {
        $profile = $this->profile();
        self::assertStringNotContainsString('Vorsaison', $this->render($profile));

        $withPrevious = new PlayerProfile(7, 'Anna', 'female', $profile->seasons, 2, $profile->history, 5);
        self::assertStringContainsString('Bis Spieltag 5 zählt die Vorsaison im Schnitt mit.', $this->render($withPrevious));
    }
}
