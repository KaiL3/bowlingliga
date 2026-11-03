<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\TeamEntry;

use Bowlingliga\Application\TeamEntry\TeamEntrySide;
use Bowlingliga\Application\TeamEntry\TeamEntryStore;

/** In-memory fake of the {@see TeamEntryStore} port. */
final class InMemoryTeamEntryStore implements TeamEntryStore
{
    /** @var array<int, bool> season id => switched on */
    public array $enabled = [];

    /** @var array<int, array<string, mixed>> fixture id => context */
    public array $fixtures = [];

    /** @var array<int, array<string, array<string, mixed>>> fixture id => side => state */
    public array $state = [];

    /** @var array<int, array<string, array{team_season_id: int, player_id: int, game_number: int, is_blind: bool, net: int|null}>> */
    public array $games = [];

    /** @var list<array{fixture: int, side: string|null, action: string, detail: string}> */
    public array $protocol = [];

    public function isEnabled(int $seasonId): bool
    {
        return $this->enabled[$seasonId] ?? false;
    }

    public function setEnabled(int $seasonId, bool $enabled): void
    {
        $this->enabled[$seasonId] = $enabled;
    }

    public function fixture(int $fixtureId): ?array
    {
        return $this->fixtures[$fixtureId] ?? null;
    }

    public function sides(int $fixtureId): array
    {
        $sides = [];
        foreach (['home', 'away'] as $side) {
            $s = $this->state[$fixtureId][$side] ?? [];
            $sides[$side] = new TeamEntrySide(
                $side,
                $s['version'] ?? 1,
                $s['submitted'] ?? false,
                $s['confirmed'] ?? false,
                $s['rejection'] ?? null,
                $s['closed'] ?? false,
            );
        }

        return $sides;
    }

    public function games(int $fixtureId): array
    {
        $games = array_values($this->games[$fixtureId] ?? []);
        usort($games, static fn (array $a, array $b): int => [$a['team_season_id'], $a['player_id'], $a['game_number']] <=> [$b['team_season_id'], $b['player_id'], $b['game_number']]);

        return $games;
    }

    /** Thrown by {@see lock()}, e.g. to simulate a database error. */
    public ?\Throwable $lockFailure = null;

    public function lock(int $fixtureId): void
    {
        if ($this->lockFailure !== null) {
            throw $this->lockFailure;
        }
    }

    public function setParticipation(int $fixtureId, int $teamSeasonId, int $playerId, int $gameNumber, ?bool $isBlind): void
    {
        $key = $playerId . '-' . $gameNumber;
        if ($isBlind === null) {
            unset($this->games[$fixtureId][$key]);

            return;
        }
        $this->games[$fixtureId][$key] = [
            'team_season_id' => $teamSeasonId,
            'player_id'      => $playerId,
            'game_number'    => $gameNumber,
            'is_blind'       => $isBlind,
            'net'            => $this->games[$fixtureId][$key]['net'] ?? null,
        ];
    }

    public function setNet(int $fixtureId, int $playerId, int $gameNumber, ?int $net): void
    {
        $key = $playerId . '-' . $gameNumber;
        if (isset($this->games[$fixtureId][$key])) {
            $this->games[$fixtureId][$key]['net'] = $net;
        }
    }

    public function setSubmitted(int $fixtureId, string $side, bool $submitted): void
    {
        $this->state[$fixtureId][$side]['submitted'] = $submitted;
    }

    public function setConfirmed(int $fixtureId, string $side): void
    {
        $this->state[$fixtureId][$side]['confirmed'] = true;
    }

    public function setRejection(int $fixtureId, string $side, ?string $note): void
    {
        $this->state[$fixtureId][$side]['rejection'] = $note;
    }

    public function close(int $fixtureId): void
    {
        $this->state[$fixtureId]['home']['closed'] = true;
        $this->state[$fixtureId]['away']['closed'] = true;
    }

    public function renewCodes(int $fixtureId): void
    {
        foreach (['home', 'away'] as $side) {
            $this->state[$fixtureId][$side]['version'] = ($this->state[$fixtureId][$side]['version'] ?? 1) + 1;
        }
    }

    public function log(int $fixtureId, ?string $side, string $action, string $detail = ''): void
    {
        $this->protocol[] = ['fixture' => $fixtureId, 'side' => $side, 'action' => $action, 'detail' => $detail];
    }

    /** @var list<array{fixture: int, side: string, action: string, player: int, game: int, before: string|null, after: string|null}> */
    public array $changes = [];

    public function logChange(int $fixtureId, string $side, string $action, int $playerId, int $gameNumber, ?string $before, ?string $after): void
    {
        $this->changes[] = ['fixture' => $fixtureId, 'side' => $side, 'action' => $action, 'player' => $playerId, 'game' => $gameNumber, 'before' => $before, 'after' => $after];
    }

    public function activeFixtures(int $seasonId): array
    {
        return [];
    }

    /** @return list<string> logged actions in order */
    public function actions(): array
    {
        return array_column($this->protocol, 'action');
    }
}
