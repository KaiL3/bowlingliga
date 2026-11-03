<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\SeasonAdministrationException;
use Bowlingliga\Application\MatchdayManagement;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\AccessDeniedException;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the writing use case of the matchday management (task 21.3).
 *
 * Validates: R8.3, R8.4, R8.5, R8.18, R8.19, R5.1
 *
 * Checks creating a matchday with a unique number and a date within the
 * season, the rejection of a duplicate number or a date outside the season,
 * changing, the lot draw flag only before the season start and the access
 * protection. Without a database; the time is fixed for the "started" rule.
 */
final class MatchdayManagementTest extends TestCase
{
    private const SEASON_ID = 1;

    private InMemorySeasonRepository $seasons;
    private InMemoryMatchdayRepository $matchdays;
    private SwitchableWriteAccess $access;
    private \DateTimeImmutable $currentTime;
    private MatchdayManagement $management;

    protected function setUp(): void
    {
        $this->seasons   = new InMemorySeasonRepository();
        $this->matchdays = new InMemoryMatchdayRepository();
        $this->access   = new SwitchableWriteAccess(true);
        $this->currentTime     = new \DateTimeImmutable('2026-06-01');
        $this->management = new MatchdayManagement(
            $this->matchdays,
            $this->seasons,
            $this->access,
            fn (): \DateTimeImmutable => $this->currentTime,
        );
    }

    /**
     * Creates a season that starts in the future (not started yet).
     */
    private function seasonInFuture(): void
    {
        $this->seasons->stash(self::SEASON_ID, 'Saison', '2026-09-01', '2027-05-31');
    }

    /**
     * Creates a season that has already started (start before today).
     */
    private function seasonStarted(): void
    {
        $this->seasons->stash(self::SEASON_ID, 'Laufend', '2026-01-01', '2026-12-31');
    }

    // --- R8.3: Anlegen ---

    public function testMatchdayIsCreatedWithNumberAndDate(): void
    {
        $this->seasonInFuture();

        $id = $this->management->createMatchday(self::SEASON_ID, 1, '2026-09-05');

        $matchday = $this->matchdays->find($id);
        self::assertNotNull($matchday);
        self::assertSame(1, $matchday['matchday_number']);
        self::assertSame('2026-09-05', $matchday['date']);
        self::assertSame(MatchdayManagement::TYPE_REGULAR, $matchday['matchday_type']);
    }

    // --- R8.4: eindeutige Nummer ---

    public function testDuplicateMatchdayNumberIsRejected(): void
    {
        $this->seasonInFuture();
        $this->management->createMatchday(self::SEASON_ID, 1, '2026-09-05');

        try {
            $this->management->createMatchday(self::SEASON_ID, 1, '2026-09-12');
            self::fail('A duplicate matchday number should have been rejected (R8.4).');
        } catch (SeasonAdministrationException) {
            // expected
        }

        self::assertCount(1, $this->matchdays->findAllForSeason(self::SEASON_ID));
    }

    // --- R8.4: date within the season ---

    public function testDateBeforeSeasonStartIsRejected(): void
    {
        $this->seasonInFuture();

        $this->expectException(SeasonAdministrationException::class);
        $this->management->createMatchday(self::SEASON_ID, 1, '2026-08-31');
    }

    public function testDateAfterSeasonEndIsRejected(): void
    {
        $this->seasonInFuture();

        $this->expectException(SeasonAdministrationException::class);
        $this->management->createMatchday(self::SEASON_ID, 1, '2027-06-01');
    }

    public function testDateAtSeasonBoundaryIsValid(): void
    {
        $this->seasonInFuture();

        $firstItemId = $this->management->createMatchday(self::SEASON_ID, 1, '2026-09-01'); // Startdatum
        $lastId = $this->management->createMatchday(self::SEASON_ID, 2, '2027-05-31'); // Enddatum

        self::assertNotNull($this->matchdays->find($firstItemId));
        self::assertNotNull($this->matchdays->find($lastId));
    }

    // --- R8.18: valid type ---

    public function testInvalidMatchdayTypeIsRejected(): void
    {
        $this->seasonInFuture();

        $this->expectException(ValidationException::class);
        $this->management->createMatchday(self::SEASON_ID, 1, '2026-09-05', 'unbekannt');
    }

    // --- R8.5: change ---

    public function testMatchdayCanBeChanged(): void
    {
        $this->seasonInFuture();
        $id = $this->management->createMatchday(self::SEASON_ID, 1, '2026-09-05');

        $this->management->updateMatchday($id, '2026-09-12', MatchdayManagement::TYPE_POSITIONDAY);

        $matchday = $this->matchdays->find($id);
        self::assertSame('2026-09-12', $matchday['date']);
        self::assertSame(MatchdayManagement::TYPE_POSITIONDAY, $matchday['matchday_type']);
    }

    // --- R8.19: lot draw only before the season start ---

    public function testMatchdayCanBeMarkedAsLotDrawBeforeSeasonStart(): void
    {
        $this->seasonInFuture();
        $id = $this->management->createMatchday(self::SEASON_ID, 1, '2026-09-05');

        $this->management->markAsLotDraw($id);

        self::assertSame(MatchdayManagement::TYPE_LOT_DRAW, $this->matchdays->find($id)['matchday_type']);
    }

    public function testLotDrawAfterSeasonStartIsRejected(): void
    {
        $this->seasonStarted();
        // Create the matchday directly (date within the running season).
        $id = $this->matchdays->create(self::SEASON_ID, 1, '2026-07-01', MatchdayManagement::TYPE_REGULAR);

        try {
            $this->management->markAsLotDraw($id);
            self::fail('Marking a lot draw after the season start should have been rejected (R8.19).');
        } catch (SeasonAdministrationException) {
            // expected
        }

        self::assertSame(MatchdayManagement::TYPE_REGULAR, $this->matchdays->find($id)['matchday_type']);
    }

    // --- R5.1: Zugriffsschutz ---

    public function testWithoutWriteAccessNothingIsCreated(): void
    {
        $this->seasonInFuture();
        $this->access->allowed = false;

        $this->expectException(AccessDeniedException::class);
        $this->management->createMatchday(self::SEASON_ID, 1, '2026-09-05');
    }

    public function testCreatingForNonExistentSeasonIsRejected(): void
    {
        $this->expectException(SeasonAdministrationException::class);
        $this->management->createMatchday(999, 1, '2026-09-05');
    }
}


