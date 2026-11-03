<?php

declare(strict_types=1);

namespace Bowlingliga\Application\LotDraw;

use Bowlingliga\Application\Positionday\PositiondayFixtureStore;
use Bowlingliga\Application\Positionday\SeededPositiondayFixture;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\WriteAccess;

/**
 * Writing use case: enters the fixtures of a lot draw matchday (R32).
 *
 * On a lot draw the fixtures are drawn on site; afterwards the administrator
 * enters who played whom, so that the results can be recorded. Rules:
 * - Only matchdays of type lot draw, only as long as no results are entered.
 * - Every team of the season (including the ghost team) plays exactly once.
 * - The lanes are drawn on site as well, so no lane pair is stored.
 *
 * Saving replaces the previous fixtures of the matchday in one transaction
 * (the same store as for positionday fixtures).
 */
final class LotDrawManagement
{
    public function __construct(
        private readonly LotDrawReader $reader,
        private readonly PositiondayFixtureStore $store,
        private readonly WriteAccess $writeAccess,
    ) {
    }

    /**
     * @param list<array{0: int, 1: int}> $pairings Home and away team season id
     *        per fixture; incomplete rows are passed as 0.
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws LotDrawException if the fixtures are not valid.
     */
    public function savePairings(int $matchdayId, array $pairings): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }

        $matchday = $this->reader->matchday($matchdayId);
        if ($matchday === null || $matchday['type'] !== 'lot_draw') {
            throw LotDrawException::notALotDraw();
        }
        if ($this->reader->hasResults($matchdayId)) {
            throw LotDrawException::resultsEntered();
        }

        $names = [];
        foreach ($this->reader->teams($matchday['season_id']) as $team) {
            $names[$team['id']] = $team['name'];
        }
        if (count($names) % 2 === 1) {
            throw LotDrawException::oddTeamCount();
        }
        $expected = intdiv(count($names), 2);

        $pairings = array_values(array_filter($pairings, static fn (array $pair): bool => $pair[0] !== 0 || $pair[1] !== 0));
        if (count($pairings) !== $expected) {
            throw LotDrawException::incomplete($expected);
        }

        $used = [];
        $fixtures = [];
        foreach ($pairings as [$home, $away]) {
            if ($home === 0 || $away === 0) {
                throw LotDrawException::incomplete($expected);
            }
            if (! isset($names[$home], $names[$away])) {
                throw LotDrawException::unknownTeam();
            }
            if ($home === $away) {
                throw LotDrawException::sameTeam($names[$home]);
            }
            foreach ([$home, $away] as $team) {
                if (isset($used[$team])) {
                    throw LotDrawException::teamTwice($names[$team]);
                }
                $used[$team] = true;
            }
            $fixtures[] = new SeededPositiondayFixture($home, $away, null);
        }

        $this->store->saveFixtures($matchdayId, $fixtures);
    }
}
