<?php

declare(strict_types=1);

namespace Bowlingliga\Application\TeamEntry;

/**
 * Port for the result entry by the teams (R37): switch per season, fixture
 * context, state per side, entered lineup and nets, protocol.
 *
 * Implemented by {@see \Bowlingliga\Persistence\TeamEntry\TeamEntryRepository};
 * in tests an in-memory fake is enough.
 */
interface TeamEntryStore
{
    public function isEnabled(int $seasonId): bool;

    public function setEnabled(int $seasonId, bool $enabled): void;

    /**
     * Fixture with matchday, status and both teams including their roster.
     *
     * `is_cancelled` (R8.20) may be missing and then counts as false.
     *
     * @return array{id: int, matchday_id: int, season_id: int, matchday_number: int, date: string,
     *               is_cancelled?: bool, lanes: string|null, status: string, ghost_score: int,
     *               home: array{team_season_id: int, team_name: string, is_ghost_team: bool, players: array<int, string>}|null,
     *               away: array{team_season_id: int, team_name: string, is_ghost_team: bool, players: array<int, string>}|null}|null
     */
    public function fixture(int $fixtureId): ?array;

    /** @return array{home: TeamEntrySide, away: TeamEntrySide} */
    public function sides(int $fixtureId): array;

    /**
     * Lineups and nets as entered by each team for itself; a blind entry
     * (player drops out) has no net, its value is derived.
     *
     * @return list<array{team_season_id: int, player_id: int, game_number: int, is_blind: bool, net: int|null}>
     */
    public function games(int $fixtureId): array;

    /** Locks the fixture's team entry against concurrent changes (inside a transaction). */
    public function lock(int $fixtureId): void;

    /** Sets the participation of a player in a game; null removes it including its net. */
    public function setParticipation(int $fixtureId, int $teamSeasonId, int $playerId, int $gameNumber, ?bool $isBlind): void;

    public function setNet(int $fixtureId, int $playerId, int $gameNumber, ?int $net): void;

    public function setSubmitted(int $fixtureId, string $side, bool $submitted): void;

    public function setConfirmed(int $fixtureId, string $side): void;

    public function setRejection(int $fixtureId, string $side, ?string $note): void;

    /** Ends the team entry of the fixture for both sides. */
    public function close(int $fixtureId): void;

    /** Raises the code version of both sides; printed codes become invalid. */
    public function renewCodes(int $fixtureId): void;

    /** A step in the history of the fixture; side null = admin. */
    public function log(int $fixtureId, ?string $side, string $action, string $detail = ''): void;

    /**
     * One changed value in the history: `lineup` (spielt/blind/–) or `net`;
     * null = no value.
     */
    public function logChange(int $fixtureId, string $side, string $action, int $playerId, int $gameNumber, ?string $before, ?string $after): void;

    /**
     * Fixtures of a season with team entry activity that are neither complete
     * nor closed.
     *
     * @return list<array{fixture_id: int, matchday_number: int, date: string, home: string, away: string}>
     */
    public function activeFixtures(int $seasonId): array;
}
