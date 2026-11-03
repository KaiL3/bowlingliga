<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Handicap;

use Eris\Generators;
use Eris\TestTrait;
use Bowlingliga\Domain\Handicap\HandicapFormula;
use Bowlingliga\Domain\Handicap\HandicapChainChange;
use Bowlingliga\Domain\Handicap\HandicapChainRecalculation;
use Bowlingliga\Domain\Handicap\HandicapChainMatchday;
use Bowlingliga\Domain\Handicap\HandicapChainPreview;
use Bowlingliga\Domain\Handicap\HandicapModule;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the determinism of the handicap chain recalculation.
 *
 * Feature: bowlingliga-website, Property 6: Determinism of the handicap chain recalculation
 *
 * Validates: R12.18
 *
 * Domain background: if an administrator triggers a recalculation of the
 * handicap chain from a matchday, the system recalculates the frozen
 * handicaps of all matchdays from this matchday in chronological order
 * (R12.18). Since the calculation is a pure function of the raw data, it must
 * be deterministic: the same raw data always gives the same chain of frozen
 * handicaps – regardless of the input order and the number of repetitions.
 *
 * The pure {@see \Bowlingliga\Domain\Handicap\HandicapChainRecalculation} is
 * tested. A chain of several matchday/player states is generated and
 * recalculated twice – once in generated, once in reversed input order. Both
 * previews must be identical in order and values.
 */
final class ChainRecalculationDeterminismPropertyTest extends TestCase
{
    use TestTrait;

    private const TARGET_SCORE     = 200;
    private const MAX_HANDICAP = 100;

    /**
     * Property 6 (determinism): for every chain and every start matchday the
     * recalculation from the same raw data always gives the same preview – the
     * same entries in the same chronological order with the same values,
     * regardless of the input order (R12.18).
     *
     * Feature: bowlingliga-website, Property 6: Determinism of the handicap chain recalculation
     *
     * Validates: R12.18
     *
     * At least 100 inputs are generated (limitTo(100)).
     */
    public function testRecalculationIsDeterministicAndOrderIndependent(): void
    {
        $module  = new HandicapModule();
        $formula = HandicapFormula::standard();
        $chains = new HandicapChainRecalculation($module);

        // A chain element: matchday counter, player id, average (in
        // hundredths), previous handicap (0..100). matchdayId is derived
        // deterministically from the counter.
        $elementGen = Generators::tuple(
            Generators::choose(1, 20),      // matchdayCounter
            Generators::choose(1, 6),       // playerId
            Generators::choose(0, 30000),   // average * 100
            Generators::choose(0, 100)      // previousHandicap
        );

        $this
            ->limitTo(100)
            ->forAll(
                Generators::seq($elementGen),
                Generators::choose(1, 20)   // fromMatchdayCounter
            )
            ->then(function (array $rawElements, int $fromMatchdayCounter) use ($chains, $formula): void {
                // Reduce the raw elements to unique (matchdayCounter, playerId) pairs,
                // since there is at most one frozen handicap per matchday/player
                // (unique constraint of the raw data). Without this
                // deduplication the order of two entries with the same
                // key would not be well defined.
                $unique = [];
                foreach ($rawElements as [$counter, $playerId, $inHundredths, $previous]) {
                    $unique[$counter . ':' . $playerId] = new HandicapChainMatchday(
                        matchdayId: $counter * 1000 + $playerId,
                        matchdayCounter: $counter,
                        playerId: $playerId,
                        average: $inHundredths / 100.0,
                        targetScore: self::TARGET_SCORE,
                        formula: $formula,
                        maxHandicap: self::MAX_HANDICAP,
                        isFirstMatchday: false,
                        previousHandicap: $previous,
                    );
                }
                $chain = array_values($unique);

                // Erste Neuberechnung in generierter Reihenfolge.
                $previewA = $chains->recalculateHandicapChain($fromMatchdayCounter, $chain);

                // Second recalculation with reversed input order: the
                // deterministic chronological sorting in the module must lead to
                // exactly the same preview (order independence).
                $previewB = $chains->recalculateHandicapChain($fromMatchdayCounter, array_reverse($chain));

                self::assertSame(
                    $this->normalise($previewA),
                    $this->normalise($previewB),
                    'The same raw data must give the same preview regardless of input order (R12.18).'
                );

                // In addition: repeating the calculation with identical input is
                // identical as well (pure function, R12.18).
                $previewC = $chains->recalculateHandicapChain($fromMatchdayCounter, $chain);
                self::assertSame(
                    $this->normalise($previewA),
                    $this->normalise($previewC),
                    'The recalculation must give the same result for identical input (R12.18).'
                );

                // Chronology invariant: the entries of the preview are sorted ascending
                // by (matchday, player).
                $before = null;
                foreach ($previewA->all() as $change) {
                    $key = [$this->matchdayCounterFrom($change), $change->playerId];
                    if ($before !== null) {
                        self::assertTrue(
                            $before <= $key,
                            'The preview must be in chronological order (R12.18).'
                        );
                    }
                    $before = $key;
                }
            });
    }

    /**
     * Derives the matchday counter from the matchdayId encoding used in the
     * test (`counter * 1000 + playerId`), to be able to check the chronological
     * order of the preview.
     */
    private function matchdayCounterFrom(HandicapChainChange $change): int
    {
        return intdiv($change->matchdayId, 1000);
    }

    /**
     * Maps a preview to a comparable, purely scalar structure, to be able to
     * compare two previews by value (including order).
     *
     * @return list<array{matchdayId: int, playerId: int, status: string, previous: int|null, neu: int|null}>
     */
    private function normalise(HandicapChainPreview $preview): array
    {
        return array_map(
            static fn (HandicapChainChange $a): array => [
                'spieltagId' => $a->matchdayId,
                'spielerId'  => $a->playerId,
                'status'     => $a->status->value,
                'previous'     => $a->previousHandicap,
                'new'        => $a->newHandicap,
            ],
            $preview->all()
        );
    }
}
