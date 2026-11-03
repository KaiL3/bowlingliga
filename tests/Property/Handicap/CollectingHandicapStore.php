<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Property\Handicap;

use Bowlingliga\Domain\Handicap\FrozenHandicapStore;

/**
 * In-memory implementation of the persistence port {@see FrozenHandicapStore}
 * with upsert semantics per matchday/player (like the real raw data table,
 * R12.12).
 *
 * Serves the property tests of the handicap chain (stability/determinism) as a
 * database-free replacement. In addition to pure storage it offers a read via
 * {@see self::value()}, to check the persisted frozen handicaps in the test.
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

    /**
     * Returns the currently stored handicap value for matchday/player, or null
     * if none is frozen (yet).
     */
    public function value(int $matchdayId, int $playerId): ?int
    {
        foreach ($this->entries as $entry) {
            if ($entry['spieltagId'] === $matchdayId && $entry['spielerId'] === $playerId) {
                return $entry['hdcWert'];
            }
        }

        return null;
    }
}
