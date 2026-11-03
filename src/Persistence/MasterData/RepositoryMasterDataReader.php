<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence\MasterData;

use Bowlingliga\Persistence\AdminAccountRepository;
use Bowlingliga\Persistence\RosterRepository;
use Bowlingliga\Persistence\SeasonRepository;
use Bowlingliga\Persistence\PlayerRepository;
use Bowlingliga\Persistence\MatchdayRepository;
use Bowlingliga\Persistence\TeamSeasonRepository;
use Bowlingliga\Presentation\MasterDataReader;

/**
 * Production read adapter for the master data overview of the admin area
 * (task 35.1).
 *
 * Implements the {@see MasterDataReader} port via the existing raw data
 * repositories; all access is read-only and returns the persisted records
 * unchanged (R4.5). The adapter only bundles the reads needed for display
 * and thus keeps the {@see \Bowlingliga\Presentation\MasterDataController}
 * free of the concrete persistence technology.
 */
final class RepositoryMasterDataReader implements MasterDataReader
{
    public function __construct(
        private readonly PlayerRepository $player,
        private readonly TeamSeasonRepository $teamSeasons,
        private readonly SeasonRepository $seasons,
        private readonly MatchdayRepository $matchdays,
        private readonly AdminAccountRepository $accounts,
        private readonly RosterRepository $roster,
    ) {
    }

    public function allPlayers(): array
    {
        return $this->player->findAllPlayers();
    }

    public function seasonTeams(int $seasonId): array
    {
        return $this->teamSeasons->findAllForSeason($seasonId);
    }

    public function allSeasons(): array
    {
        return $this->seasons->findAllSeasons();
    }

    public function seasonMatchdays(int $seasonId): array
    {
        return $this->matchdays->findAllForSeason($seasonId);
    }

    public function allAccounts(): array
    {
        return $this->accounts->findAllAccounts();
    }

    public function seasonRoster(int $seasonId): array
    {
        return $this->roster->findSeasonRosterWithNames($seasonId);
    }
}
