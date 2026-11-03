<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\PlayerManagement;
use Bowlingliga\Application\PlayerManagementException;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\Validator;
use Bowlingliga\Tests\GermanText;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the writing use case of the player management (task 21.1).
 *
 * Validates: R6.1, R6.2, R6.3, R6.4, R6.5, R6.6, R6.7, R25.1, R25.4, R5.1
 *
 * Checks create/change/remove of players, the per-season team assignment as
 * well as data minimisation (only name + gender) and the access protection
 * (R5.1). Persistence and access protection are replaced without a database by
 * in-memory fakes and a switchable stub; the validation uses the real
 * {@see Validator}.
 */
final class PlayerManagementTest extends TestCase
{
    private InMemoryPlayerRepository $player;
    private InMemoryRosterRepository $roster;
    private SwitchableWriteAccess $access;
    private PlayerManagement $management;

    protected function setUp(): void
    {
        $this->roster      = new InMemoryRosterRepository();
        $this->player    = new InMemoryPlayerRepository($this->roster);
        $this->access    = new SwitchableWriteAccess(true);
        $this->management = new PlayerManagement(
            $this->player,
            $this->roster,
            new Validator(),
            $this->access,
        );
    }

    // --- R6.1 / R25.1: create with data minimisation ---

    public function testPlayerIsCreatedWithNameAndGender(): void
    {
        $id = $this->management->createPlayer('  Käte Groß ', 'female');

        $saved = $this->player->find($id);
        self::assertNotNull($saved);
        // Name trimmed, only name + gender stored (data minimisation).
        self::assertSame('Käte Groß', $saved['name']);
        self::assertSame('female', $saved['gender']);
        self::assertSame(['id', 'name', 'gender'], array_keys($saved));
    }

    public function testPseudonymIsAllowed(): void
    {
        $id = $this->management->createPlayer('StrikeKing_99', 'male');

        self::assertSame('StrikeKing_99', $this->player->find($id)['name']);
    }

    // --- R6.2 / R6.3: Validierung ---

    public function testEmptyNameIsRejectedAndNotSaved(): void
    {
        try {
            $this->management->createPlayer('   ', 'male');
            self::fail('An empty name should have been rejected (R6.2).');
        } catch (ValidationException $exception) {
            self::assertSame('field.name', $exception->field);
        }

        self::assertSame(0, $this->player->quantity(), 'With an invalid name nothing may be saved (R6.2).');
    }

    public function testInvalidGenderIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->management->createPlayer('Max', 'divers');
    }

    // --- R6.4: change ---

    public function testPlayerCanBeChanged(): void
    {
        $id = $this->management->createPlayer('Max', 'male');

        $this->management->updatePlayer($id, 'Maxi', 'female');

        $saved = $this->player->find($id);
        self::assertSame('Maxi', $saved['name']);
        self::assertSame('female', $saved['gender']);
    }

    // --- R6.5 / R25.4: removing also removes roster assignments ---

    public function testRemoveDeletesPlayerAndHisRosterAssignments(): void
    {
        // Zwei Team-Saisons in verschiedenen Saisons (R6.8).
        $this->roster->seasonOfTeamSeason = [10 => 1, 11 => 2];
        $id = $this->management->createPlayer('Max', 'male');
        $this->management->assignPlayerToTeamSeason(10, $id);
        $this->management->assignPlayerToTeamSeason(11, $id);
        self::assertSame(2, $this->roster->countForPlayer($id));

        $this->management->removePlayer($id);

        self::assertNull($this->player->find($id), 'The player must be removed (R6.5).');
        self::assertSame(0, $this->roster->countForPlayer($id), 'The roster assignments must be removed as well (R6.5, R25.4).');
    }

    // --- R6.6 / R6.7: team season assignment ---

    public function testPlayerCanBeAssignedToATeamSeason(): void
    {
        $playerId = $this->management->createPlayer('Max', 'male');

        $rosterId = $this->management->assignPlayerToTeamSeason(5, $playerId);

        $entry = $this->roster->find($rosterId);
        self::assertNotNull($entry);
        self::assertSame(5, $entry['team_season_id']);
        self::assertSame($playerId, $entry['player_id']);
    }

    public function testSecondTeamInTheSameSeasonIsRejected(): void
    {
        $this->roster->seasonOfTeamSeason = [5 => 1, 6 => 1, 7 => 2];
        $playerId = $this->management->createPlayer('Max', 'male');
        $this->management->assignPlayerToTeamSeason(5, $playerId);

        try {
            $this->management->assignPlayerToTeamSeason(6, $playerId);
            self::fail('A player may belong to only one roster per season (R6.8).');
        } catch (PlayerManagementException $e) {
            self::assertStringContainsString('spielt in dieser Saison bereits für Team 5', GermanText::of($e));
        }

        self::assertSame(1, $this->roster->countForPlayer($playerId));
        // A different team is possible in another season.
        $this->management->assignPlayerToTeamSeason(7, $playerId);
        self::assertSame(2, $this->roster->countForPlayer($playerId));
    }

    public function testDuplicateAssignmentToSameTeamIsRejected(): void
    {
        $playerId = $this->management->createPlayer('Max', 'male');
        $this->management->assignPlayerToTeamSeason(5, $playerId);

        $this->expectException(PlayerManagementException::class);
        $this->expectExceptionMessage('error.player.already_in_roster');
        $this->management->assignPlayerToTeamSeason(5, $playerId);
    }

    public function testSingleTeamSeasonAssignmentCanBeRemoved(): void
    {
        $playerId = $this->management->createPlayer('Max', 'male');
        $rosterId   = $this->management->assignPlayerToTeamSeason(5, $playerId);

        $this->management->removeTeamSeasonAssignment($rosterId);

        self::assertNull($this->roster->find($rosterId));
    }

    // --- R5.1: Zugriffsschutz ---

    public function testWithoutWriteAccessNothingIsCreated(): void
    {
        $this->access->allowed = false;

        try {
            $this->management->createPlayer('Max', 'male');
            self::fail('Without write access the action should have been rejected (R5.1).');
        } catch (AccessDeniedException) {
            // expected
        }

        self::assertSame(0, $this->player->quantity(), 'Without write access no data may be written (R5.1).');
    }

    public function testWithoutWriteAccessNothingIsRemoved(): void
    {
        // Create with access, then revoke access.
        $id = $this->management->createPlayer('Max', 'male');
        $this->access->allowed = false;

        $this->expectException(AccessDeniedException::class);
        $this->management->removePlayer($id);
    }
}
