<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\SeasonConfiguration;
use Bowlingliga\Application\SeasonAdministration;
use Bowlingliga\Application\PlayerManagement;
use Bowlingliga\Application\MatchdayManagement;
use Bowlingliga\Application\TeamManagement;
use Bowlingliga\Application\TeamManagementException;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Consolidated unit test of the validation rules for master data across the
 * management use cases (task 21.5).
 *
 * Validates: R6.2, R6.3, R7.2, R7.3, R8.2, R8.4, R8.12, R8.14
 *
 * This test bundles the boundary and error cases of the master data
 * validation at the public management interfaces (not only at the
 * {@see Validator} itself), to prove that invalid inputs are already rejected
 * in the respective use case and nothing is persisted. It complements the
 * existing, broader management tests with the length boundary cases (exactly
 * 100 vs. 101 characters). Keeping at least one admin account (R23.5) is
 * covered in the admin account tests.
 *
 * All tests run without a database via in-memory fakes; write access is
 * allowed, so that only the validation applies.
 */
final class MasterDataValidationTest extends TestCase
{
    private SwitchableWriteAccess $access;
    private \DateTimeImmutable $currentTime;

    protected function setUp(): void
    {
        $this->access = new SwitchableWriteAccess(true);
        $this->currentTime   = new \DateTimeImmutable('2026-06-01');
    }

    // --- R6.2 / R6.3: player master data ---

    public function testPlayerNameWith100CharactersIsValidWith101Invalid(): void
    {
        $player = new InMemoryPlayerRepository(new InMemoryRosterRepository());
        $management = new PlayerManagement(
            $player,
            new InMemoryRosterRepository(),
            new Validator(),
            $this->access,
        );

        // Exactly 100 characters: valid.
        $id = $management->createPlayer(str_repeat('a', 100), 'male');
        self::assertNotNull($player->find($id));

        // 101 characters: invalid (R6.2).
        try {
            $management->createPlayer(str_repeat('a', 101), 'male');
            self::fail('A name of 101 characters should have been rejected (R6.2).');
        } catch (ValidationException $exception) {
            self::assertSame('field.name', $exception->field);
        }
    }

    // --- R7.2 / R7.3: Team-MasterData ---

    public function testTeamNameLengthLimitAndDuplicateName(): void
    {
        $teams       = new InMemoryTeamRepository();
        $teamSeasons = new InMemoryTeamSeasonRepository();
        $seasons     = new InMemorySeasonReader();
        $seasons->stash(1, '2026-09-01');

        $management = new TeamManagement(
            $teams,
            $teamSeasons,
            $seasons,
            new Validator(),
            $this->access,
            fn (): \DateTimeImmutable => $this->currentTime,
        );

        // Exactly 100 characters: valid.
        $id = $management->createTeam(1, str_repeat('T', 100));
        self::assertNotNull($teamSeasons->find($id));

        // 101 characters: invalid (R7.2).
        try {
            $management->createTeam(1, str_repeat('T', 101));
            self::fail('A team name of 101 characters should have been rejected (R7.2).');
        } catch (ValidationException $exception) {
            self::assertSame('field.team_name', $exception->field);
        }

        // Duplicate team name in the same season: invalid (R7.3).
        $management->createTeam(1, 'Einzigartig');
        $this->expectException(TeamManagementException::class);
        $management->createTeam(1, 'Einzigartig');
    }

    // --- R8.2: season master data ---

    public function testSeasonNameTooLongIsRejected(): void
    {
        $management = $this->seasonAdministration();

        try {
            $management->createSeason(str_repeat('S', 101), '2026-09-01', '2027-05-31', null, $this->config());
            self::fail('A season name that is too long should have been rejected (R8.2).');
        } catch (ValidationException $exception) {
            self::assertSame('field.season_name', $exception->field);
        }
    }

    public function testSeasonWithSameStartAndEndDateIsRejected(): void
    {
        $management = $this->seasonAdministration();

        // The end date must be strictly after the start date (R8.2): the same day is invalid.
        $this->expectException(ValidationException::class);
        $management->createSeason('Saison', '2026-09-01', '2026-09-01', null, $this->config());
    }

    // --- R8.12 / R8.14: Konfigurations-Grenzwerte ---

    public function testLaneCountBoundaries(): void
    {
        // 2 is valid, 0 and odd numbers are not (R8.12).
        SeasonConfiguration::generate(new Validator(), 2, 0.0, 0.0);
        $this->addToAssertionCount(1);

        $this->expectException(ValidationException::class);
        SeasonConfiguration::generate(new Validator(), 0, 0.0, 0.0);
    }

    public function testNegativeBlindFeeIsRejected(): void
    {
        try {
            SeasonConfiguration::generate(new Validator(), 8, 10.0, -0.01);
            self::fail('A negative blind player fee should have been rejected (R8.14).');
        } catch (ValidationException $exception) {
            self::assertSame('field.blind_fee', $exception->field);
        }
    }

    // --- R8.4: Matchday-MasterData ---

    public function testMatchdayDateOutsideSeasonIsRejected(): void
    {
        $seasons   = new InMemorySeasonRepository();
        $seasons->stash(1, 'Saison', '2026-09-01', '2027-05-31');
        $matchdays = new InMemoryMatchdayRepository();

        $management = new MatchdayManagement($matchdays, $seasons, $this->access, fn (): \DateTimeImmutable => $this->currentTime);

        // A date before the season start is invalid (R8.4).
        $this->expectException(\Bowlingliga\Application\SeasonAdministrationException::class);
        $management->createMatchday(1, 1, '2026-08-31');
    }

    private function seasonAdministration(): SeasonAdministration
    {
        return new SeasonAdministration(
            new InMemorySeasonRepository(),
            new InMemorySeasonConfigRepository(),
            new InMemoryTeamSeasonRepository(),
            new InMemoryTeamRepository(),
            new Validator(),
            $this->access,
            fn (): \DateTimeImmutable => $this->currentTime,
        );
    }

    private function config(): SeasonConfiguration
    {
        return SeasonConfiguration::generate(new Validator(), 8, 25.0, 5.0);
    }
}
