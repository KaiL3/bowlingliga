<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\Positionday;

use Bowlingliga\Application\Positionday\SeededPositiondayFixture;
use Bowlingliga\Application\Positionday\PositiondayFixtureStore;

/**
 * Fake of the {@see PositiondayFixtureStore} port for the unit tests, without
 * a database.
 */
final class RecordingPositiondayStore implements PositiondayFixtureStore
{
    /** @var list<SeededPositiondayFixture>|null */
    public ?array $saved = null;

    public ?int $matchdayId = null;

    public bool $throwsError = false;

    public function saveFixtures(int $matchdayId, array $fixtures): void
    {
        if ($this->throwsError) {
            throw new \RuntimeException('Simulierter Speicherfehler.');
        }

        $this->matchdayId = $matchdayId;
        $this->saved = $fixtures;
    }
}
