<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application;

use Bowlingliga\Application\PreviousSeasonRecordManagement;
use Bowlingliga\Application\PreviousSeasonRecordManagementException;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\AccessDeniedException;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the writing use case of the previous season records (task
 * 22.2/22.3).
 *
 * Validates: R29.1, R29.4, R29.6, R29.7, R29.8, R29.9, R29.11, R5.1
 *
 * Checks entering/changing/removing previous season records per season, the
 * rule for when they can be entered (season not started yet), the validation
 * rejections (R29.4/29.6/29.7) and the access protection (R5.1). Persistence
 * and context are replaced without a database by an in-memory fake; the
 * validation uses the real {@see Validator}.
 */
final class PreviousSeasonRecordManagementTest extends TestCase
{
    private const SEASON = 1;
    private const LATER_SEASON = 2;

    private InMemoryPreviousSeasonRecord $records;
    private SwitchableWriteAccess $access;
    private PreviousSeasonRecordManagement $management;

    protected function setUp(): void
    {
        $this->records  = new InMemoryPreviousSeasonRecord();
        $this->access    = new SwitchableWriteAccess(true);
        $this->management = new PreviousSeasonRecordManagement(
            $this->records,
            $this->records,
            new Validator(),
            $this->access,
        );
    }

    // --- R29.1 / R29.8: enter and change ---

    public function testValidRecordIsSaved(): void
    {
        $this->management->enterRecord(self::SEASON, 1, 10, 1800);

        $saved = $this->records->find(self::SEASON, 1);
        self::assertNotNull($saved);
        self::assertSame(10, $saved['game_count']);
        self::assertSame(1800, $saved['net_pins_total']);
    }

    public function testRecordCanBeChanged(): void
    {
        $this->management->enterRecord(self::SEASON, 1, 10, 1800);
        $this->management->enterRecord(self::SEASON, 1, 12, 2000);

        $saved = $this->records->find(self::SEASON, 1);
        self::assertSame(12, $saved['game_count']);
        self::assertSame(2000, $saved['net_pins_total']);
        self::assertSame(1, $this->records->quantity(), 'The upsert must not create a second record per player and season.');
    }

    public function testRecordCanBeRemoved(): void
    {
        $this->management->enterRecord(self::SEASON, 1, 10, 1800);

        $this->management->removeRecord(self::SEASON, 1);

        self::assertNull($this->records->find(self::SEASON, 1));
    }

    // --- R29.11: one record per player and season ---

    public function testRecordsOfDifferentSeasonsAreIndependent(): void
    {
        $this->management->enterRecord(self::SEASON, 1, 10, 1800);
        $this->management->enterRecord(self::LATER_SEASON, 1, 30, 5100);

        $this->management->removeRecord(self::LATER_SEASON, 1);

        self::assertSame(['game_count' => 10, 'net_pins_total' => 1800], $this->records->find(self::SEASON, 1));
        self::assertNull($this->records->find(self::LATER_SEASON, 1));
    }

    // --- R29.4 / R29.6 / R29.7: Validierungsablehnungen ---

    public function testNegativeCountIsRejectedAndNotSaved(): void
    {
        try {
            $this->management->enterRecord(self::SEASON, 1, -1, 0);
            self::fail('A negative number should have been rejected (R29.4).');
        } catch (ValidationException $exception) {
            self::assertSame('field.previous_games', $exception->field);
        }

        self::assertSame(0, $this->records->quantity(), 'With an invalid number nothing may be saved (R29.4).');
    }

    public function testNegativeSumIsRejected(): void
    {
        try {
            $this->management->enterRecord(self::SEASON, 1, 5, -1);
            self::fail('A negative sum should have been rejected (R29.6).');
        } catch (ValidationException $exception) {
            self::assertSame('field.previous_pins', $exception->field);
        }

        self::assertSame(0, $this->records->quantity());
    }

    public function testImplausibleAverageIsRejected(): void
    {
        try {
            // 4 games, 1204 pins -> average 301 > 300 (R29.7).
            $this->management->enterRecord(self::SEASON, 1, 4, 1204);
            self::fail('An implausible average should have been rejected (R29.7).');
        } catch (ValidationException $exception) {
            self::assertSame('field.previous_record', $exception->field);
        }

        self::assertSame(0, $this->records->quantity());
    }

    // --- R29.9: no change after the start of the season ---

    public function testChangeAfterStartOfTheSeasonRejected(): void
    {
        $this->records->startedSeasons = [self::SEASON];

        try {
            $this->management->enterRecord(self::SEASON, 1, 10, 1800);
            self::fail('After the season start nothing may be changed (R29.9).');
        } catch (PreviousSeasonRecordManagementException) {
            // expected
        }

        self::assertSame(0, $this->records->quantity());
    }

    public function testALaterSeasonStaysOpenWhileAnEarlierOneHasStarted(): void
    {
        $this->records->startedSeasons = [self::SEASON];

        $this->management->enterRecord(self::LATER_SEASON, 1, 10, 1800);

        self::assertNotNull($this->records->find(self::LATER_SEASON, 1), 'Only the own season counts (R29.9).');
    }

    public function testRemovingAfterStartOfTheSeasonRejected(): void
    {
        // Enter before the start, then mark the start.
        $this->management->enterRecord(self::SEASON, 1, 10, 1800);
        $this->records->startedSeasons = [self::SEASON];

        $this->expectException(PreviousSeasonRecordManagementException::class);
        $this->management->removeRecord(self::SEASON, 1);
    }

    // --- R5.1: Zugriffsschutz ---

    public function testWithoutWriteAccessNothingIsSaved(): void
    {
        $this->access->allowed = false;

        try {
            $this->management->enterRecord(self::SEASON, 1, 10, 1800);
            self::fail('Without write access the action should have been rejected (R5.1).');
        } catch (AccessDeniedException) {
            // expected
        }

        self::assertSame(0, $this->records->quantity(), 'Without write access no data may be written (R5.1).');
    }
}
