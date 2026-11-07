<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\PlayerProfile;

use Bowlingliga\Application\PlayerProfile\PlayerProfiles;
use Bowlingliga\Application\PlayerProfile\PlayerSeasonRawData;
use Bowlingliga\Domain\Average\Game;
use Bowlingliga\Domain\PlayerProfile\PlayerMatchdayInput;
use PHPUnit\Framework\TestCase;

/**
 * Unit test of the player page use case: choice of the season and the team
 * history.
 *
 * Validates: R39.2, R39.5
 */
final class PlayerProfilesTest extends TestCase
{
    private InMemoryPlayerProfileReader $reader;
    private PlayerProfiles $profiles;

    protected function setUp(): void
    {
        $this->reader = new InMemoryPlayerProfileReader();
        $this->reader->players[7] = ['id' => 7, 'name' => 'Anna', 'gender' => 'female'];
        $this->reader->seasons[7] = [
            ['season_id' => 1, 'season_name' => '2024/25', 'team_season_id' => 11, 'team_name' => 'Adler'],
            ['season_id' => 2, 'season_name' => '2025/26', 'team_season_id' => 21, 'team_name' => 'Falken'],
        ];
        $this->reader->raw[7][1] = new PlayerSeasonRawData([
            new PlayerMatchdayInput(101, 1, '2024-09-04', [new Game(150), new Game(150), new Game(150)], 40),
        ]);
        $this->reader->raw[7][2] = new PlayerSeasonRawData([
            new PlayerMatchdayInput(201, 1, '2025-09-03', [new Game(170), new Game(170), new Game(170)], 24),
            new PlayerMatchdayInput(202, 2, '2025-09-10', [new Game(190), new Game(190), new Game(190)], 20),
        ], [], null, 0);
        $this->profiles = new PlayerProfiles($this->reader);
    }

    public function testAnUnknownPlayerHasNoPage(): void
    {
        self::assertNull($this->profiles->profile(99, null, null));
    }

    public function testTheRequestedSeasonWins(): void
    {
        $profile = $this->profiles->profile(7, 1, 2);

        self::assertSame(1, $profile?->selectedSeasonId);
        self::assertSame('Adler', $profile?->selectedSeason()?->teamName);
        self::assertCount(1, $profile->history->points);
    }

    public function testWithoutAChoiceTheCurrentSeasonIsShown(): void
    {
        self::assertSame(2, $this->profiles->profile(7, null, 2)?->selectedSeasonId);
    }

    public function testASeasonWithoutThePlayerFallsBackToTheirLatestSeason(): void
    {
        self::assertSame(2, $this->profiles->profile(7, 5, 6)?->selectedSeasonId);
    }

    public function testTheTeamsAreListedNewestFirstWithTheirFigures(): void
    {
        $profile = $this->profiles->profile(7, null, null);

        self::assertNotNull($profile);
        self::assertSame(['Falken', 'Adler'], array_map(static fn ($s): string => $s->teamName, $profile->seasons));
        $latest = $profile->seasons[0];
        self::assertSame(2, $latest->matchdaysPlayed);
        self::assertSame(6, $latest->games);
        self::assertEqualsWithDelta(180.0, $latest->average, 0.0001);
        self::assertSame(20, $latest->handicap);
    }

    public function testAPlayerWithoutSeasonsHasAnEmptyPage(): void
    {
        $this->reader->players[8] = ['id' => 8, 'name' => 'Ben', 'gender' => 'male'];

        $profile = $this->profiles->profile(8, null, 2);

        self::assertNotNull($profile);
        self::assertNull($profile->selectedSeasonId);
        self::assertNull($profile->selectedSeason());
        self::assertTrue($profile->history->isEmpty());
    }

    public function testThePageTellsUpToWhichMatchdayThePreviousSeasonCounts(): void
    {
        self::assertNull($this->profiles->profile(7, 2, null)?->previousSeasonUntil, 'Switched off (0) in this season.');
        self::assertNull($this->profiles->profile(7, 1, null)?->previousSeasonUntil, 'No previous season.');

        $this->reader->raw[7][1] = new PlayerSeasonRawData(
            $this->reader->raw[7][1]->matchdays,
            [new Game(140, true)],
            new \Bowlingliga\Domain\Average\PreviousSeasonRecord(30, 5400),
            5,
        );
        self::assertSame(5, $this->profiles->profile(7, 1, null)?->previousSeasonUntil);

        $this->reader->raw[7][1] = new PlayerSeasonRawData($this->reader->raw[7][1]->matchdays, [new Game(140, true)], null, 5);
        self::assertNull($this->profiles->profile(7, 1, null)?->previousSeasonUntil, 'Only blind games do not count.');
    }
}
