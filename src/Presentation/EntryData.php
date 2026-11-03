<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

/**
 * Data access port of the result entry in the admin area (task 35.3).
 *
 * Supplies the {@see ResultEntryController} with the context of a fixture,
 * the rosters of the teams involved, player names and the uploaded original
 * score sheets. Read-only.
 *
 * In production the
 * {@see \Bowlingliga\Persistence\ResultEntry\EntryDataRepository}
 * implements this port.
 */
interface EntryData
{
    /**
     * Context of a fixture, or null if it does not exist.
     *
     * @return array{id: int, matchday_id: int, matchday_number: int, date: string, matchday_type: string,
     *               season_id: int, status: string, lanes: string|null, ghost_score: int,
     *               home: array{team_season_id: int, team_name: string, is_ghost_team: bool}|null,
     *               away: array{team_season_id: int, team_name: string, is_ghost_team: bool}|null}|null
     */
    public function teamFixture(int $fixtureId): ?array;

    /**
     * Roster of a team (alphabetical).
     *
     * @return list<array{player_id: int, name: string}>
     */
    public function roster(int $teamSeasonId): array;

    /**
     * Ids of the uploaded original score sheets of the fixture.
     *
     * @return list<int>
     */
    public function originalScoreSheet(int $fixtureId): array;

    /**
     * Number and date of a season's matchdays, indexed by matchday id (labels
     * of the handicap chain preview).
     *
     * @return array<int, array{number: int, date: string}>
     */
    public function seasonMatchdays(int $seasonId): array;

    /**
     * Names of the given players, indexed by player id.
     *
     * @param list<int> $playerIds
     *
     * @return array<int, string>
     */
    public function playerNames(array $playerIds): array;
}
