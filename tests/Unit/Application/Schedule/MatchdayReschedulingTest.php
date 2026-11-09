<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Schedule;

use Bowlingliga\Application\Schedule\MatchdayRescheduling;
use Bowlingliga\Application\Schedule\MatchdayScheduleException;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Tests\Unit\Application\ImmediateTransaction;
use Bowlingliga\Tests\Unit\Application\SwitchableWriteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Cancelling and postponing whole matchdays.
 *
 * Validates: R8.20, R8.21
 */
final class MatchdayReschedulingTest extends TestCase
{
    private InMemoryMatchdaySchedule $store;
    private SwitchableWriteAccess $access;
    private MatchdayRescheduling $rescheduling;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        // Matchdays 1–5 weekly from Wednesday, 2026-09-02.
        $this->store = InMemoryMatchdaySchedule::weekly(5);
        $this->today = new \DateTimeImmutable('2026-09-01');
        $this->access = new SwitchableWriteAccess(true);
        $this->rescheduling = new MatchdayRescheduling($this->store, $this->access, new ImmediateTransaction(), fn (): \DateTimeImmutable => $this->today);
    }

    public function testACancelledMatchdayKeepsItsDateAndCanBeTakenBack(): void
    {
        $this->rescheduling->cancel(3);

        self::assertTrue($this->store->matchdays[3]['is_cancelled']);
        self::assertSame('2026-09-16', $this->store->matchdays[3]['date'], 'The dates stay.');
        self::assertSame('2026-09-23', $this->store->matchdays[4]['date']);

        $this->rescheduling->reinstate(3);
        self::assertFalse($this->store->matchdays[3]['is_cancelled']);
    }

    /** R8.20: only a matchday in the future, and only while no later one has results. */
    public function testACancellationCanOnlyBeTakenBackForAFutureMatchdayWithoutLaterResults(): void
    {
        $this->rescheduling->cancel(3);
        $this->rescheduling->cancel(2);

        $this->today = new \DateTimeImmutable('2026-09-16');
        self::assertFalse($this->canReinstate(3), 'On its own day it is no longer in the future.');
        try {
            $this->rescheduling->reinstate(3);
            self::fail('A past matchday cannot be reinstated.');
        } catch (MatchdayScheduleException $e) {
            self::assertSame('error.schedule.not_in_future', $e->message()->key);
        }

        $this->today = new \DateTimeImmutable('2026-09-01');
        $this->store->matchdays[4]['has_results'] = true;
        self::assertFalse($this->canReinstate(2));
        try {
            $this->rescheduling->reinstate(2);
            self::fail('A later matchday with results prevents taking the cancellation back.');
        } catch (MatchdayScheduleException $e) {
            self::assertSame(['number' => 2, 'later' => 4], $e->message()->params);
        }
        self::assertTrue($this->store->matchdays[2]['is_cancelled']);
    }

    private function canReinstate(int $id): bool
    {
        return array_column($this->rescheduling->matchdays(InMemoryMatchdaySchedule::SEASON), 'can_reinstate', 'id')[$id];
    }

    public function testAMatchdayWithResultsCannotBeCancelled(): void
    {
        $this->store->matchdays[2]['has_results'] = true;

        $this->expectExceptionObject(MatchdayScheduleException::resultsEntered(2));
        $this->rescheduling->cancel(2);
    }

    public function testCancellingTwiceOrTakingBackANormalMatchdayIsRejected(): void
    {
        $this->rescheduling->cancel(2);
        try {
            $this->rescheduling->cancel(2);
            self::fail('A cancelled matchday cannot be cancelled again.');
        } catch (MatchdayScheduleException $e) {
            self::assertSame('error.schedule.already_cancelled', $e->message()->key);
        }

        $this->expectExceptionObject(MatchdayScheduleException::notCancelled(3));
        $this->rescheduling->reinstate(3);
    }

    public function testPostponingMovesTheMatchdayAllLaterOnesAndTheSeasonEnd(): void
    {
        $this->store->matchdays[5]['is_cancelled'] = true;

        $preview = $this->rescheduling->preview(3, '2026-09-30');

        self::assertSame(14, $preview->days);
        self::assertSame([3, 4, 5], array_column($preview->moves, 'number'), 'Only the matchday and the later ones, cancelled ones too.');
        self::assertSame(['2026-09-30', '2026-10-07', '2026-10-14'], array_column($preview->moves, 'new'));
        self::assertSame('2027-07-14', $preview->newSeasonEnd);
        self::assertSame('2026-09-16', $this->store->matchdays[3]['date'], 'The preview saves nothing.');

        $this->rescheduling->postpone(3, '2026-09-30');

        self::assertSame('2026-09-09', $this->store->matchdays[2]['date'], 'Earlier matchdays stay.');
        self::assertSame('2026-09-30', $this->store->matchdays[3]['date']);
        self::assertSame('2026-10-14', $this->store->matchdays[5]['date']);
        self::assertSame('2027-07-14', $this->store->seasonEnd);
    }

    public function testPostponingNeedsALaterValidDateAndAnOpenMatchday(): void
    {
        foreach (['2026-09-16' => 'not_later', '2026-09-01' => 'not_later', '2026-02-30' => 'invalid_date', 'morgen' => 'invalid_date'] as $date => $reason) {
            try {
                $this->rescheduling->preview(3, $date);
                self::fail($date . ' must be rejected.');
            } catch (MatchdayScheduleException $e) {
                self::assertSame('error.schedule.' . $reason, $e->message()->key, $date);
            }
        }

        $this->store->matchdays[2]['is_complete'] = true;
        $this->store->matchdays[4]['is_cancelled'] = true;
        foreach ([2 => 'complete', 4 => 'already_cancelled'] as $id => $reason) {
            try {
                $this->rescheduling->postpone($id, '2026-12-02');
                self::fail('Matchday ' . $id . ' must not be postponed.');
            } catch (MatchdayScheduleException $e) {
                self::assertSame('error.schedule.' . $reason, $e->message()->key);
            }
        }
        self::assertSame('2027-06-30', $this->store->seasonEnd, 'Nothing moved.');
    }

    public function testAMatchdayWithResultsButNotCompleteCanStillBePostponed(): void
    {
        $this->store->matchdays[3]['has_results'] = true;

        $this->rescheduling->postpone(3, '2026-09-23');

        self::assertSame('2026-09-23', $this->store->matchdays[3]['date']);
    }

    public function testUnknownMatchdayAndMissingWriteAccess(): void
    {
        try {
            $this->rescheduling->cancel(99);
            self::fail('An unknown matchday must be rejected.');
        } catch (MatchdayScheduleException $e) {
            self::assertSame('error.schedule.not_found', $e->message()->key);
        }

        $this->access->allowed = false;
        $this->expectException(AccessDeniedException::class);
        $this->rescheduling->cancel(1);
    }
}
