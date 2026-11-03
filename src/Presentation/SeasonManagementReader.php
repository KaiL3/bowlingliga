<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

/**
 * Read port of the season page in the admin area: master data, configuration
 * and positiondays of a season (R8.5, R8.16, R12, R31).
 *
 * Implemented by the
 * {@see \Bowlingliga\Persistence\SeasonManagement\SeasonManagementDataRepository};
 * in tests a fake is enough.
 */
interface SeasonManagementReader
{
    /**
     * @return array{id: int, name: string, start_date: string, end_date: string}|null
     */
    public function season(int $seasonId): ?array;

    /**
     * @return array{lane_count: int, entry_fee: string, blind_fee: string, max_handicap: int, handicap_formula: string|null, target_score: int, ghost_score: int, previous_season_matchdays: int, positionday_interval: int}|null
     */
    public function configuration(int $seasonId): ?array;

    /**
     * Positiondays of the season with their formation state: `bereit` = all
     * previous matchdays played (R31.4), `gesperrt` = results already entered
     * (R31.7), `paarungen` = formed fixtures as "Heim – Gast (Bahn x/y)".
     *
     * @return list<array{id: int, number: int, date: string, ready: bool, locked: bool, pairings: list<string>}>
     */
    public function positiondays(int $seasonId): array;

    /**
     * Lot draw matchdays of the season with the entered fixtures (team season
     * ids); `locked` = results already entered.
     *
     * @return list<array{id: int, number: int, date: string, locked: bool, fixtures: list<array{home: int, away: int}>}>
     */
    public function lotDraws(int $seasonId): array;

    /**
     * All teams of the season including the ghost team.
     *
     * @return list<array{id: int, name: string}>
     */
    public function seasonTeams(int $seasonId): array;
}
