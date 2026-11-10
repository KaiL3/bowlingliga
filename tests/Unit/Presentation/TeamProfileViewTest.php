<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Application\TeamProfile\TeamFixtureResult;
use Bowlingliga\Application\TeamProfile\TeamMatchday;
use Bowlingliga\Application\TeamProfile\TeamProfile;
use Bowlingliga\Presentation\TeamProfileView;
use PHPUnit\Framework\TestCase;

/**
 * Unit test of the team page view.
 *
 * Validates: R41.2, R41.3, R41.4, R41.5, R41.6, R41.7
 */
final class TeamProfileViewTest extends TestCase
{
    private TeamProfileView $view;

    protected function setUp(): void
    {
        $this->view = new TeamProfileView();
    }

    public function testThePageShowsKeyFiguresChartsAndGames(): void
    {
        $html = $this->render($this->profile(), '<section id="list"></section>');

        self::assertStringContainsString('<h1>Pins &amp; Co</h1>', $html, 'The name is escaped.');
        self::assertStringContainsString('<dd>2 von 3</dd>', $html);
        self::assertStringContainsString('<dd>8<small>davon Bonus 2</small></dd>', $html);
        self::assertStringContainsString('<dd>148,75</dd>', $html, 'Average with blind games.');
        self::assertStringContainsString('<dd>150,00</dd>', $html, 'Average without blind games.');
        self::assertStringContainsString('<dd>3 · 0 · 3</dd>', $html);
        self::assertSame(3, substr_count($html, '<svg class="chart"'), 'Rank, points and average.');
        self::assertStringContainsString('chart__line--second', $html);
        self::assertStringContainsString('<section id="list"></section>', $html, 'The average list of the caller.');
    }

    public function testTheGamesLinkMatchdayAndOpponentButNotTheGhostTeam(): void
    {
        $html = $this->render($this->profile());

        self::assertStringContainsString('<a href="/matchday/101">1</a>', $html);
        self::assertStringContainsString('<a href="/x/12">Falken</a>', $html);
        self::assertStringContainsString('<td class="text">Geisterteam</td>', $html);
        self::assertStringContainsString('<abbr class="outcome outcome--win" title="Sieg">S</abbr>', $html);
        self::assertStringContainsString('<td class="text">abgesagt</td>', $html);
    }

    public function testSeveralSeasonsGetASelectionNewestFirst(): void
    {
        $html = $this->render($this->profile());

        self::assertStringContainsString('<a class="season-picker__link" href="/x/11" aria-current="page">2025/26</a>', $html);
        self::assertLessThan(strpos($html, '/x/3"'), strpos($html, '/x/11"'), 'Newest season first.');
    }

    public function testWithoutGamesOnlyANoteAppears(): void
    {
        $profile = new TeamProfile(11, 1, 5, '2025/26', 'Adler', [], [], null, 3);
        $html    = $this->render($profile);

        self::assertStringContainsString('In der Saison 2025/26 hat das Team noch nicht gespielt.', $html);
        self::assertStringNotContainsString('<svg', $html);
        self::assertStringContainsString('<dd>—</dd>', $html);
    }

    private function render(TeamProfile $profile, string $list = ''): string
    {
        return $this->view->render($profile, static fn (int $id): string => '/x/' . $id, $list);
    }

    private function profile(): TeamProfile
    {
        $win  = [TeamFixtureResult::WIN, TeamFixtureResult::WIN, TeamFixtureResult::WIN];
        $loss = [TeamFixtureResult::LOSS, TeamFixtureResult::LOSS, TeamFixtureResult::LOSS];

        return new TeamProfile(11, 1, 5, '2025/26', 'Pins & Co', [
            ['team_season_id' => 3, 'season_id' => 4, 'season_name' => '2024/25'],
            ['team_season_id' => 11, 'season_id' => 5, 'season_name' => '2025/26'],
        ], [
            new TeamMatchday(101, 1, '2025-09-03', false, new TeamFixtureResult(12, 'Falken', $win, [640, 640, 610], 6, 1, 1, 1770, 1890, 12, 1650, 11), 1, 7, 7, 147.5, 150.0),
            new TeamMatchday(102, 2, '2025-09-10', true),
            new TeamMatchday(103, 3, '2025-09-17', false, new TeamFixtureResult(null, 'Geisterteam', $loss, [640, 640, 640], 0, 1, 10, 1800, 1920, 12, 1800, 12), 2, 8, 10, 148.75, 150.0),
        ], 2, 3);
    }
}
