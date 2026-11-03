<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\TeamManagement;
use Bowlingliga\Application\TeamManagementException;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\AccessDeniedException;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the writing use case of the team management (task 21.2).
 *
 * Validates: R7.1, R7.2, R7.3, R7.4, R7.5, R7.6, R7.7, R5.1
 *
 * Checks creating (team identity + per-season team name), the length and
 * uniqueness rule of the team name, changing (identity stays), removing from a
 * season not started yet as well as the rejection for an already started
 * season and a non-existent assignment. The access protection (R5.1) is
 * checked as well. Persistence and access protection are replaced without a
 * database by in-memory fakes and a switchable stub; the time is fixed for the
 * "started" check.
 */
final class TeamManagementTest extends TestCase
{
    private InMemoryTeamRepository $teams;
    private InMemoryTeamSeasonRepository $teamSeasons;
    private InMemorySeasonReader $seasons;
    private SwitchableWriteAccess $access;
    private \DateTimeImmutable $currentTime;
    private TeamManagement $management;

    protected function setUp(): void
    {
        $this->teams       = new InMemoryTeamRepository();
        $this->teamSeasons = new InMemoryTeamSeasonRepository();
        $this->seasons     = new InMemorySeasonReader();
        $this->access     = new SwitchableWriteAccess(true);
        $this->currentTime       = new \DateTimeImmutable('2026-06-01');
        $this->management  = new TeamManagement(
            $this->teams,
            $this->teamSeasons,
            $this->seasons,
            new Validator(),
            $this->access,
            fn (): \DateTimeImmutable => $this->currentTime,
        );
    }

    // --- R7.1: creating produces a team identity + per-season team name ---

    public function testTeamIsCreatedWithIdentityAndTeamName(): void
    {
        // The season starts in the future.
        $this->seasons->stash(1, '2026-09-01');

        $teamSeasonId = $this->management->createTeam(1, 'Die Pins');

        $assignment = $this->teamSeasons->find($teamSeasonId);
        self::assertNotNull($assignment);
        self::assertSame(1, $assignment['season_id']);
        self::assertSame('Die Pins', $assignment['team_name']);
        // Exactly one team identity was created.
        self::assertSame(1, $this->teams->quantity());
        self::assertNotNull($this->teams->find((int) $assignment['team_id']));
    }

    // --- R7.2: team name length ---

    public function testEmptyTeamNameIsRejected(): void
    {
        $this->seasons->stash(1, '2026-09-01');

        try {
            $this->management->createTeam(1, '   ');
            self::fail('An empty team name should have been rejected (R7.2).');
        } catch (ValidationException $exception) {
            self::assertSame('field.team_name', $exception->field);
        }

        self::assertSame(0, $this->teams->quantity());
        self::assertSame(0, $this->teamSeasons->quantity());
    }

    // --- R7.3: unique per season ---

    public function testDuplicateTeamNameInSameSeasonIsRejected(): void
    {
        $this->seasons->stash(1, '2026-09-01');
        $this->management->createTeam(1, 'Die Pins');

        $this->expectException(TeamManagementException::class);
        $this->management->createTeam(1, 'die pins'); // equal ignoring case
    }

    public function testSameTeamNameInOtherSeasonIsAllowed(): void
    {
        $this->seasons->stash(1, '2026-09-01');
        $this->seasons->stash(2, '2027-09-01');

        $this->management->createTeam(1, 'Die Pins');
        $id2 = $this->management->createTeam(2, 'Die Pins');

        self::assertNotNull($this->teamSeasons->find($id2));
        self::assertSame(2, $this->teams->quantity(), 'Every season participation gets its own team identity.');
    }

    // --- R7.4: changing keeps the identity unchanged ---

    public function testTeamNameCanBeChangedIdentityStays(): void
    {
        $this->seasons->stash(1, '2026-09-01');
        $teamSeasonId = $this->management->createTeam(1, 'Alte Namen');
        $teamIdBefore = (int) $this->teamSeasons->find($teamSeasonId)['team_id'];

        $this->management->renameTeam($teamSeasonId, 'Neuer Name');

        $assignment = $this->teamSeasons->find($teamSeasonId);
        self::assertSame('Neuer Name', $assignment['team_name']);
        self::assertSame($teamIdBefore, (int) $assignment['team_id'], 'The team identity must stay unchanged (R7.4).');
    }

    public function testChangingToExistingNameIsRejected(): void
    {
        $this->seasons->stash(1, '2026-09-01');
        $this->management->createTeam(1, 'Team A');
        $idB = $this->management->createTeam(1, 'Team B');

        $this->expectException(TeamManagementException::class);
        $this->management->renameTeam($idB, 'Team A');
    }

    public function testUnchangedNameOnChangeIsAllowed(): void
    {
        $this->seasons->stash(1, '2026-09-01');
        $id = $this->management->createTeam(1, 'Team A');

        // The same name must not collide with itself.
        $this->management->renameTeam($id, 'Team A');

        self::assertSame('Team A', $this->teamSeasons->find($id)['team_name']);
    }

    public function testChangingANonExistentAssignmentIsRejected(): void
    {
        $this->expectException(TeamManagementException::class);
        $this->management->renameTeam(999, 'Egal');
    }

    // --- R7.6: removing from a season not started yet ---

    public function testTeamCanBeRemovedFromNotStartedSeason(): void
    {
        $this->seasons->stash(1, '2026-09-01'); // Start in the future
        $teamSeasonId = $this->management->createTeam(1, 'Die Pins');

        $this->management->removeTeamFromSeason($teamSeasonId);

        self::assertNull($this->teamSeasons->find($teamSeasonId));
    }

    // --- R7.7: rejection for an already started season ---

    public function testRemovingFromStartedSeasonIsRejected(): void
    {
        // Start date before "today" (2026-06-01) => started.
        $this->seasons->stash(1, '2026-01-01');
        $teamSeasonId = $this->management->createTeam(1, 'Die Pins');

        try {
            $this->management->removeTeamFromSeason($teamSeasonId);
            self::fail('Removing from a started season should have been rejected (R7.7).');
        } catch (TeamManagementException) {
            // expected
        }

        self::assertNotNull($this->teamSeasons->find($teamSeasonId), 'The assignment must remain unchanged (R7.7).');
    }

    public function testRemovingOnStartDayIsRejected(): void
    {
        // Start date == today => counts as started (start date reached).
        $this->seasons->stash(1, '2026-06-01');
        $teamSeasonId = $this->management->createTeam(1, 'Die Pins');

        $this->expectException(TeamManagementException::class);
        $this->management->removeTeamFromSeason($teamSeasonId);
    }

    // --- R7.5: non-existent team/assignment ---

    public function testRemovingANonExistentAssignmentIsRejected(): void
    {
        $this->expectException(TeamManagementException::class);
        $this->management->removeTeamFromSeason(999);
    }

    // --- R5.1: Zugriffsschutz ---

    public function testWithoutWriteAccessNothingIsCreated(): void
    {
        $this->seasons->stash(1, '2026-09-01');
        $this->access->allowed = false;

        $this->expectException(AccessDeniedException::class);
        try {
            $this->management->createTeam(1, 'Die Pins');
        } finally {
            self::assertSame(0, $this->teams->quantity(), 'Without write access nothing may be written (R5.1).');
        }
    }
}


