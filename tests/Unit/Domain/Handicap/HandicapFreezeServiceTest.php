<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Domain\Handicap;

use Bowlingliga\Domain\Handicap\FrozenHandicapStore;
use Bowlingliga\Domain\Handicap\HandicapFreezeService;
use Bowlingliga\Domain\Handicap\HandicapFormula;
use Bowlingliga\Domain\Handicap\HandicapModule;
use Bowlingliga\Domain\Handicap\HandicapStatus;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for freezing the handicap as raw data (task 8.4).
 *
 * Validates: R12.9, R12.12, R35.5
 *
 * R12.9: for a player's very first matchday (neither a played game
 * nor a previous season record before) the average state at the end of the
 * matchday applies; in all other cases (R12.8) the state immediately before
 * the matchday. The {@see HandicapFreezeService} leaves choosing the state to
 * the caller and freezes the value calculated from it.
 *
 * R12.12: a calculated handicap is stored permanently, assigned to
 * the matchday (here: frozen via the persistence port).
 *
 * R35.5: the frozen handicap is raw data and only written through
 * freezing (no cache). The test checks that writing only happens when a valid
 * handicap exists and exactly once per freezing operation.
 *
 * Persistence is replaced by a slim in-memory implementation of the
 * {@see FrozenHandicapStore} port, so that the test runs without a database
 * and still uses the real {@see HandicapModule} (no mock calculation).
 */
final class HandicapFreezeServiceTest extends TestCase
{
    private const TARGET_SCORE     = 200;
    private const MAX_HANDICAP = 64;

    public function testRegularCaseFreezesTheValueCalculatedFromTheStandingBeforeTheMatchday(): void
    {
        $store = new CollectingHandicapStore();
        $service  = new HandicapFreezeService(new HandicapModule(), $store);

        // Average 150 => HDC = (200 - 150) * 0,8 = 40 (R12.8).
        $result = $service->freezeHandicap(
            playerId: 7,
            matchdayId: 3,
            average: 150.0,
            targetScore: self::TARGET_SCORE,
            formula: HandicapFormula::standard(),
            maxHandicap: self::MAX_HANDICAP,
            isFirstMatchday: false,
        );

        self::assertSame(HandicapStatus::CALCULATED, $result->status());
        self::assertSame(40, $result->handicap());

        // R12.12/R35.5: exactly one frozen raw record per matchday/player.
        self::assertCount(1, $store->entries);
        self::assertSame(['spieltagId' => 3, 'spielerId' => 7, 'hdcWert' => 40], $store->entries[0]);
    }

    public function testFirstMatchdayFreezesTheValueCalculatedFromTheStandingAtTheEndOfTheMatchday(): void
    {
        $store = new CollectingHandicapStore();
        $service  = new HandicapFreezeService(new HandicapModule(), $store);

        // First matchday special case (R12.9): the caller passes the state at the
        // end of the matchday (here 130) => HDC = (200 - 130) * 0.8 = 56.
        $result = $service->freezeHandicap(
            playerId: 42,
            matchdayId: 1,
            average: 130.0,
            targetScore: self::TARGET_SCORE,
            formula: HandicapFormula::standard(),
            maxHandicap: self::MAX_HANDICAP,
            isFirstMatchday: true,
        );

        self::assertSame(HandicapStatus::CALCULATED, $result->status());
        self::assertSame(56, $result->handicap());
        self::assertCount(1, $store->entries);
        self::assertSame(['spieltagId' => 1, 'spielerId' => 42, 'hdcWert' => 56], $store->entries[0]);
    }

    public function testLimitedHandicapIsFrozenLimited(): void
    {
        $store = new CollectingHandicapStore();
        $service  = new HandicapFreezeService(new HandicapModule(), $store);

        // Average 100 => roh 80, aber Maximal_Handicap 64 (R12.10) => 64.
        $result = $service->freezeHandicap(
            playerId: 1,
            matchdayId: 2,
            average: 100.0,
            targetScore: self::TARGET_SCORE,
            formula: HandicapFormula::standard(),
            maxHandicap: self::MAX_HANDICAP,
        );

        self::assertSame(64, $result->handicap());
        self::assertSame(64, $store->entries[0]['hdcWert']);
    }

    public function testMissingFormulaFreezesNothing(): void
    {
        $store = new CollectingHandicapStore();
        $service  = new HandicapFreezeService(new HandicapModule(), $store);

        // R12.13: without a formula no handicap => nothing is frozen.
        $result = $service->freezeHandicap(
            playerId: 1,
            matchdayId: 2,
            average: 150.0,
            targetScore: self::TARGET_SCORE,
            formula: null,
            maxHandicap: self::MAX_HANDICAP,
        );

        self::assertSame(HandicapStatus::MISSING_FORMULA, $result->status());
        self::assertFalse($result->hasHandicap());
        self::assertSame([], $store->entries);
    }

    public function testMissingAverageFreezesNothing(): void
    {
        $store = new CollectingHandicapStore();
        $service  = new HandicapFreezeService(new HandicapModule(), $store);

        // R12.14: no average => "without handicap" => nothing is frozen.
        $result = $service->freezeHandicap(
            playerId: 1,
            matchdayId: 2,
            average: null,
            targetScore: self::TARGET_SCORE,
            formula: HandicapFormula::standard(),
            maxHandicap: self::MAX_HANDICAP,
        );

        self::assertSame(HandicapStatus::WITHOUT_HANDICAP, $result->status());
        self::assertSame([], $store->entries);
    }

    public function testFreezingAgainOverwritesTheValueForMatchdayAndPlayer(): void
    {
        $store = new CollectingHandicapStore();
        $service  = new HandicapFreezeService(new HandicapModule(), $store);

        $service->freezeHandicap(1, 5, 150.0, self::TARGET_SCORE, HandicapFormula::standard(), self::MAX_HANDICAP);
        // Corrected average (e.g. after a later correction, R12.18):
        // HDC = (200 - 160) * 0.8 = 32.
        $service->freezeHandicap(1, 5, 160.0, self::TARGET_SCORE, HandicapFormula::standard(), self::MAX_HANDICAP);

        // The port reproduces the upsert per matchday/player: one entry, new value.
        self::assertCount(1, $store->entries);
        self::assertSame(32, $store->entries[0]['hdcWert']);
    }
}

/**
 * In-memory implementation of the persistence port with upsert semantics per
 * matchday/player (like the real raw data table, R12.12).
 */
final class CollectingHandicapStore implements FrozenHandicapStore
{
    /** @var list<array{matchdayId: int, playerId: int, hdcValue: int}> */
    public array $entries = [];

    public function save(int $matchdayId, int $playerId, int $hdcValue): void
    {
        foreach ($this->entries as $index => $entry) {
            if ($entry['spieltagId'] === $matchdayId && $entry['spielerId'] === $playerId) {
                $this->entries[$index]['hdcWert'] = $hdcValue;

                return;
            }
        }

        $this->entries[] = ['spieltagId' => $matchdayId, 'spielerId' => $playerId, 'hdcWert' => $hdcValue];
    }
}
