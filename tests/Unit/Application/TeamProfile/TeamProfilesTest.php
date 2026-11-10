<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\TeamProfile;

use Bowlingliga\Application\Aggregation\FixtureRawData;
use Bowlingliga\Application\Aggregation\FixtureTeamRawData;
use Bowlingliga\Application\Aggregation\SheetRowResults;
use Bowlingliga\Application\Aggregation\TableAggregator;
use Bowlingliga\Application\TeamProfile\TeamFixtureResult;
use Bowlingliga\Application\TeamProfile\TeamProfiles;
use Bowlingliga\Tests\Unit\Application\Aggregation\FixedTableRawDataReader;
use PHPUnit\Framework\TestCase;

/**
 * Unit test of the team page use case: results per matchday, standing after
 * each matchday, key figures and the average without blind games.
 *
 * Adler (11) beats Falken (12) on matchday 1 with one blind game, matchday 2
 * is cancelled, on matchday 3 Adler loses to the ghost team while Falken has
 * no fixture; matchday 4 is not played yet.
 *
 * Validates: R41.1, R41.3, R41.4, R41.5, R41.6
 */
final class TeamProfilesTest extends TestCase
{
    private TeamProfiles $profiles;

    protected function setUp(): void
    {
        $reader = new InMemoryTeamProfileReader();
        $reader->teams[11] = ['team_season_id' => 11, 'team_id' => 1, 'season_id' => 5, 'season_name' => '2025/26', 'team_name' => 'Adler'];
        $reader->teams[12] = ['team_season_id' => 12, 'team_id' => 2, 'season_id' => 5, 'season_name' => '2025/26', 'team_name' => 'Falken'];
        $reader->seasons[1] = [
            ['team_season_id' => 3, 'season_id' => 4, 'season_name' => '2024/25'],
            ['team_season_id' => 11, 'season_id' => 5, 'season_name' => '2025/26'],
        ];
        $reader->matchdays[5] = [
            ['id' => 101, 'number' => 1, 'date' => '2025-09-03', 'played' => true, 'cancelled' => false],
            ['id' => 102, 'number' => 2, 'date' => '2025-09-10', 'played' => false, 'cancelled' => true],
            ['id' => 103, 'number' => 3, 'date' => '2025-09-17', 'played' => true, 'cancelled' => false],
            ['id' => 104, 'number' => 4, 'date' => '2025-09-24', 'played' => false, 'cancelled' => false],
        ];

        $adlerFirst = self::team(11, 'Adler', [
            new SheetRowResults([1 => 150, 2 => 150, 3 => 150], 10),
            new SheetRowResults([1 => 150, 2 => 150, 3 => 150], 10),
            new SheetRowResults([1 => 150, 2 => 150, 3 => 150], 10),
            // Game 3 is a blind game (R27.12).
            new SheetRowResults([1 => 150, 2 => 150, 3 => 120], 10, [3]),
        ]);
        $falken = self::team(12, 'Falken', array_fill(0, 4, new SheetRowResults([1 => 140, 2 => 140, 3 => 140], 10)));
        $adlerThird = self::team(11, 'Adler', array_fill(0, 4, new SheetRowResults([1 => 150, 2 => 150, 3 => 150], 10)));
        $ghosts = new FixtureTeamRawData(0, 'Geisterteam', true, true, array_fill(0, 4, new SheetRowResults([1 => 200, 2 => 200, 3 => 200], 0)));

        $raw = new FixedTableRawDataReader(
            [
                new FixtureRawData($adlerFirst, $falken, 101),
                new FixtureRawData($ghosts, $adlerThird, 103),
            ],
            [],
            [11 => 'Adler', 12 => 'Falken'],
        );
        $this->profiles = new TeamProfiles($reader, $raw, new TableAggregator($raw));
    }

    public function testUnknownTeamsAndTheGhostTeamHaveNoPage(): void
    {
        self::assertNull($this->profiles->profile(99));
    }

    public function testEveryMatchdayShowsResultAndStanding(): void
    {
        $profile = $this->profiles->profile(11);
        self::assertNotNull($profile);
        self::assertSame([1, 2, 3], array_map(static fn ($m): int => $m->matchdayNumber, $profile->matchdays), 'Unplayed matchdays are left out.');

        [$first, $cancelled, $third] = $profile->matchdays;

        self::assertSame(12, $first->result?->opponentTeamSeasonId);
        self::assertSame([TeamFixtureResult::WIN, TeamFixtureResult::WIN, TeamFixtureResult::WIN], $first->result->outcomes);
        self::assertSame(6, $first->result->basePoints);
        self::assertSame(1, $first->result->bonusPoints);
        self::assertSame(1, $first->result->opponentPoints);
        self::assertSame(1, $first->rank);
        self::assertSame(7, $first->points);

        self::assertTrue($cancelled->cancelled);
        self::assertNull($cancelled->result);

        self::assertNull($third->result?->opponentTeamSeasonId, 'The ghost team has no page to link.');
        self::assertSame('Geisterteam', $third->result?->opponentName);
        self::assertSame(3, $third->result->count(TeamFixtureResult::LOSS));
        self::assertSame(2, $third->rank, 'The ghost team leads with 6 + 4 points.');
        self::assertSame(8, $third->points);
        self::assertSame(10, $third->leaderPoints);
    }

    public function testKeyFiguresSumTheSeason(): void
    {
        $profile = $this->profiles->profile(11);
        self::assertNotNull($profile);

        self::assertSame(2, $profile->rank);
        self::assertSame(3, $profile->teamCount);
        self::assertSame(8, $profile->points());
        self::assertSame(2, $profile->bonusPoints());
        self::assertSame(3, $profile->count(TeamFixtureResult::WIN));
        self::assertSame(3, $profile->count(TeamFixtureResult::LOSS));
        self::assertSame(640, $profile->bestGame());
        self::assertSame(1920, $profile->bestSeries());
        self::assertSame(24, $profile->games());
        self::assertSame(1, $profile->blindGames());
        self::assertSame('2025/26', $profile->seasonName);
        self::assertCount(2, $profile->seasons);
    }

    public function testBlindGamesLeaveOutPinsAndCountWithoutBlind(): void
    {
        $profile = $this->profiles->profile(11);
        self::assertNotNull($profile);

        // With blind games like the table: (1770 + 1800) / 24.
        self::assertEqualsWithDelta(148.75, $profile->averageNet(), 0.001);
        // Without: (1650 + 1800) / 23 – the blind game does not pull the average.
        self::assertEqualsWithDelta(150.0, $profile->playedAverageNet(), 0.001);

        self::assertEqualsWithDelta(147.5, $profile->matchdays[0]->averageNet, 0.001);
        self::assertEqualsWithDelta(150.0, $profile->matchdays[0]->playedAverageNet, 0.001);
    }

    public function testAMatchdayWithoutOwnFixtureStillMovesTheStanding(): void
    {
        $profile = $this->profiles->profile(12);
        self::assertNotNull($profile);

        $third = $profile->matchdays[2];
        self::assertFalse($third->cancelled);
        self::assertNull($third->result);
        self::assertSame(3, $third->rank);
        self::assertCount(1, $profile->results());
    }

    /** @param list<SheetRowResults> $rows */
    private static function team(int $id, string $name, array $rows): FixtureTeamRawData
    {
        return new FixtureTeamRawData($id, $name, false, true, $rows);
    }
}
