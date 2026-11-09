<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Schedule;

use Bowlingliga\Application\Transaction;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\WriteAccess;

/**
 * Writing use case: cancel or postpone a whole matchday (R8.20, R8.21).
 *
 * - **Cancel:** only while no results are entered for the matchday (no draft
 *   either). It keeps its number, its fixtures are dropped without
 *   replacement and it does not count; the other dates stay. Reversible.
 * - **Postpone:** only while the matchday is not complete, to a later date;
 *   the matchday and all later ones move by the same number of days, and so
 *   does the season end. {@see preview()} shows old → new without saving.
 */
final class MatchdayRescheduling
{
    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /**
     * @param callable(): \DateTimeImmutable|null $clock today; for tests
     */
    public function __construct(
        private readonly MatchdayScheduleStore $store,
        private readonly WriteAccess $writeAccess,
        private readonly Transaction $transaction,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('today');
    }

    /**
     * The matchdays of the season with their state, for the admin;
     * `can_reinstate` tells whether a cancellation can be taken back.
     *
     * @return list<array{id: int, season_id: int, number: int, date: string, type: string, is_cancelled: bool, has_results: bool, is_complete: bool, can_reinstate: bool}>
     */
    public function matchdays(int $seasonId): array
    {
        $matchdays = $this->store->matchdays($seasonId);

        return array_map(fn (array $matchday): array => $matchday + [
            'can_reinstate' => $matchday['is_cancelled'] && $this->reinstateObstacle($matchday, $matchdays) === null,
        ], $matchdays);
    }

    public function cancel(int $matchdayId): void
    {
        $this->ensureWriteAccess();
        $this->transaction->perform(function () use ($matchdayId): void {
            $matchday = $this->matchday($matchdayId);
            if ($matchday['is_cancelled']) {
                throw MatchdayScheduleException::alreadyCancelled($matchday['number']);
            }
            if ($matchday['has_results']) {
                throw MatchdayScheduleException::resultsEntered($matchday['number']);
            }
            $this->store->setCancelled($matchdayId, true);
        });
    }

    public function reinstate(int $matchdayId): void
    {
        $this->ensureWriteAccess();
        $this->transaction->perform(function () use ($matchdayId): void {
            $matchday = $this->matchday($matchdayId);
            if (! $matchday['is_cancelled']) {
                throw MatchdayScheduleException::notCancelled($matchday['number']);
            }
            $obstacle = $this->reinstateObstacle($matchday, $this->store->matchdays($matchday['season_id']));
            if ($obstacle !== null) {
                throw $obstacle;
            }
            $this->store->setCancelled($matchdayId, false);
        });
    }

    /**
     * Why a cancellation cannot be taken back, or null (R8.20): only a
     * matchday in the future, and only while no later matchday has results –
     * otherwise an incomplete matchday would suddenly lie before entered
     * ones and undermine the order of the result entry (R30.20).
     *
     * @param array{number: int, date: string} $matchday
     * @param list<array{number: int, is_cancelled: bool, has_results: bool}> $matchdays
     */
    private function reinstateObstacle(array $matchday, array $matchdays): ?MatchdayScheduleException
    {
        if (new \DateTimeImmutable($matchday['date']) <= ($this->clock)()) {
            return MatchdayScheduleException::notInFuture($matchday['number']);
        }
        foreach ($matchdays as $later) {
            if ($later['number'] > $matchday['number'] && ! $later['is_cancelled'] && $later['has_results']) {
                return MatchdayScheduleException::laterResultsEntered($matchday['number'], $later['number']);
            }
        }

        return null;
    }

    /**
     * Calculates the postponement without saving anything (R8.21).
     *
     * @param string $newDate `YYYY-MM-DD`, later than the current date
     */
    public function preview(int $matchdayId, string $newDate): Postponement
    {
        $this->ensureWriteAccess();
        $matchday = $this->matchday($matchdayId);
        if ($matchday['is_cancelled']) {
            throw MatchdayScheduleException::alreadyCancelled($matchday['number']);
        }
        if ($matchday['is_complete']) {
            throw MatchdayScheduleException::complete($matchday['number']);
        }

        $target = \DateTimeImmutable::createFromFormat('!Y-m-d', $newDate);
        if ($target === false || $target->format('Y-m-d') !== $newDate) {
            throw MatchdayScheduleException::invalidDate();
        }
        $current = new \DateTimeImmutable($matchday['date']);
        $days = (int) $current->diff($target)->format('%r%a');
        if ($days <= 0) {
            throw MatchdayScheduleException::notLater($matchday['number']);
        }

        $moves = [];
        foreach ($this->store->matchdays($matchday['season_id']) as $other) {
            if ($other['number'] < $matchday['number']) {
                continue;
            }
            $moves[] = [
                'id'           => $other['id'],
                'number'       => $other['number'],
                'old'          => $other['date'],
                'new'          => self::plusDays($other['date'], $days),
                'is_cancelled' => $other['is_cancelled'],
            ];
        }
        $end = (string) $this->store->seasonEnd($matchday['season_id']);

        return new Postponement($matchday['season_id'], $matchdayId, $matchday['number'], $days, $moves, $end, self::plusDays($end, $days));
    }

    /**
     * Postpones the matchday as shown in {@see preview()} (R8.21).
     *
     * @param string $newDate `YYYY-MM-DD`, later than the current date
     */
    public function postpone(int $matchdayId, string $newDate): Postponement
    {
        return $this->transaction->perform(function () use ($matchdayId, $newDate): Postponement {
            $postponement = $this->preview($matchdayId, $newDate);
            $this->store->moveDates($postponement->seasonId, $postponement->newDates(), $postponement->newSeasonEnd);

            return $postponement;
        });
    }

    /**
     * @return array{id: int, season_id: int, number: int, date: string, type: string, is_cancelled: bool, has_results: bool, is_complete: bool}
     */
    private function matchday(int $matchdayId): array
    {
        $seasonId = $this->store->seasonOf($matchdayId);
        foreach ($seasonId !== null ? $this->store->matchdays($seasonId) : [] as $matchday) {
            if ($matchday['id'] === $matchdayId) {
                return $matchday;
            }
        }

        throw MatchdayScheduleException::notFound();
    }

    private static function plusDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify('+' . $days . ' days')->format('Y-m-d');
    }

    private function ensureWriteAccess(): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
    }
}
