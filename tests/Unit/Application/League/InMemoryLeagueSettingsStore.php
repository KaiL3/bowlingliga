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

    public function __construct(public ?string $name = null, public bool $players = false)
    {
    }

    public function leagueName(): ?string
    {
        return $this->name;
    }

    public function playersPublic(): bool
    {
        return $this->players;
    }

    public function save(string $name, bool $playersPublic): void
    {
        $this->name    = $name;
        $this->players = $playersPublic;
        $this->saves++;
    }
}
