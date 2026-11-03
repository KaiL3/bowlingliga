<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\League;

use Bowlingliga\Application\League\LeagueSettingsStore;

/**
 * In-memory fake of the {@see LeagueSettingsStore} port; counts the saves.
 */
final class InMemoryLeagueSettingsStore implements LeagueSettingsStore
{
    public int $saves = 0;

    public function __construct(public ?string $name = null)
    {
    }

    public function leagueName(): ?string
    {
        return $this->name;
    }

    public function saveLeagueName(string $name): void
    {
        $this->name = $name;
        $this->saves++;
    }
}
