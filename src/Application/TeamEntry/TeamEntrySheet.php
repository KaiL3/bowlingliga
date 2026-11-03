<?php

declare(strict_types=1);

namespace Bowlingliga\Application\TeamEntry;

/**
 * Read model of the team entry page for one side (R37): fixture, state of
 * both sides, the lineups with nets entered by each team and what this side
 * may do now.
 */
final class TeamEntrySheet
{
    /**
     * @param array<string, mixed> $fixture see {@see TeamEntryStore::fixture()}
     * @param list<array{team_season_id: int, player_id: int, game_number: int, is_blind: bool, net: int|null}> $games
     */
    public function __construct(
        public readonly string $code,
        public readonly string $side,
        public readonly array $fixture,
        public readonly TeamEntrySide $own,
        public readonly TeamEntrySide $opponent,
        public readonly array $games,
        public readonly bool $finished,
    ) {
    }

    /** @return array{team_season_id: int, team_name: string, is_ghost_team: bool, players: array<int, string>} */
    public function ownTeam(): array
    {
        return $this->fixture[$this->side];
    }

    /** @return array{team_season_id: int, team_name: string, is_ghost_team: bool, players: array<int, string>} */
    public function opponentTeam(): array
    {
        return $this->fixture[TeamEntrySide::opposite($this->side)];
    }

    /**
     * Entries of a team: [player id][game] => ['is_blind' => bool, 'net' => int|null].
     *
     * @return array<int, array<int, array{is_blind: bool, net: int|null}>>
     */
    public function participation(int $teamSeasonId): array
    {
        $result = [];
        foreach ($this->games as $game) {
            if ($game['team_season_id'] === $teamSeasonId) {
                $result[$game['player_id']][$game['game_number']] = ['is_blind' => $game['is_blind'], 'net' => $game['net']];
            }
        }

        return $result;
    }

    /** Against the ghost team the own entry is the confirmation (R37.10). */
    public function ghostOpponent(): bool
    {
        return $this->opponentTeam()['is_ghost_team'];
    }

    /** The own lineup and results can be changed until they are sent (R37.3). */
    public function canEdit(): bool
    {
        return ! $this->finished && ! $this->own->submitted;
    }

    public function canSubmit(): bool
    {
        return $this->canEdit();
    }

    /** A sent entry can be withdrawn as long as the opponent has not confirmed it. */
    public function canWithdraw(): bool
    {
        return ! $this->ghostOpponent() && ! $this->finished && $this->own->submitted && ! $this->opponent->confirmed;
    }

    /** The opponent's sent entry waits for this side's confirmation (R37.6). */
    public function canConfirm(): bool
    {
        return ! $this->ghostOpponent() && ! $this->finished && $this->opponent->submitted && ! $this->own->confirmed;
    }
}
