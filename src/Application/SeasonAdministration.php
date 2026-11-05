<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\I18n\Languages;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\WriteAccess;

/**
 * Writing use case: managing seasons including their configuration
 * (R8.1–8.17, R12.1–12.7).
 *
 * Covers creating a season (master data + configuration), changing master
 * data and configuration, and deleting. On creation – if a previous season
 * exists – entry fee, blind fee and maximum handicap are prefilled from the
 * previous season (R8.15, R12.4) and the participating teams with their
 * per-season team names are copied (R8.7). Without a previous season the
 * defaults apply (target score 190, maximum handicap 64; R12.2/R12.3/R12.5).
 *
 * "Before season start" rules: configuration changes (entry fee, blind fee,
 * maximum handicap) and deleting are only allowed as long as the season has
 * not reached its start date (R8.10, R8.16, R12.6/R12.7). The point in time
 * of the check can be injected via the clock (deterministically testable).
 *
 * Every writing call is protected by {@see WriteAccess} (R5.1).
 */
final class SeasonAdministration
{
    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /**
     * @param callable(): \DateTimeImmutable|null $clock Optional clock for
     *        deterministic tests; without it the current time applies.
     */
    public function __construct(
        private readonly SeasonStore $seasons,
        private readonly SeasonConfigStore $config,
        private readonly TeamSeasonStore $teamSeasons,
        private readonly TeamStore $teams,
        private readonly Validator $validator,
        private readonly WriteAccess $writeAccess,
        ?callable $clock = null,
        private readonly ?RosterReader $rosterReader = null,
        private readonly ?RosterStore $rosterStore = null,
        private readonly ?ScoreSheetFileCleanup $scoreSheetFiles = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Creates a season including its configuration and returns its id (R8.1,
     * R8.11, R8.13, R12.1–12.3).
     *
     * If a previous season exists, its configuration (fees, handicap, points
     * rules, R17.11) is prefilled from it unless the caller passes different values, and its
     * teams are copied (R8.7, R8.15, R12.4). The caller can specify the
     * configuration explicitly; the prefill only applies to values not passed.
     *
     * @param SeasonConfiguration|null $configuration Explicit configuration;
     *        null = derive from the previous season or the defaults.
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws ValidationException for an invalid name or date range (R8.2).
     */
    public function createSeason(
        string $name,
        string $startDate,
        string $endDate,
        ?int $matchdayCount = null,
        ?SeasonConfiguration $configuration = null,
    ): int {
        $this->ensureWriteAccess();

        $name = $this->validator->checkName($name, 'field.season_name');
        $this->ensureDateRange($startDate, $endDate);

        $previousSeason    = $this->determinePreviousSeason($startDate);
        $configuration ??= $this->deriveConfiguration($previousSeason);

        $seasonId = $this->seasons->create($name, $startDate, $endDate, $matchdayCount);

        $this->config->save(
            $seasonId,
            $configuration->laneCount,
            $configuration->entryFee,
            $configuration->blindFee,
            $configuration->maxHandicapValue,
            $configuration->handicapFormula,
            $configuration->targetScore,
            $configuration->ghostScore,
            $configuration->previousSeasonMatchdays,
            $configuration->positiondayInterval,
            $configuration->points,
        );

        // R8.7: copy the previous season's teams including team names; the
        // language of the printed score sheets is inherited as well.
        if ($previousSeason !== null) {
            $this->copyTeamsFromPreviousSeason((int) $previousSeason['id'], $seasonId);
            $language = $previousSeason['score_sheet_language'] ?? null;
            if (is_string($language) && Languages::isSupported($language)) {
                $this->seasons->setScoreSheetLanguage($seasonId, $language);
            }
        }

        return $seasonId;
    }

    /**
     * Changes the master data of a season; valid as long as the validity rules
     * (name, date range) are met (R8.5). The language of the printed score
     * sheets can be changed at any time; null keeps it.
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws SeasonAdministrationException if the season does not exist.
     * @throws ValidationException for an invalid name/date range (R8.2).
     */
    public function updateSeason(
        int $seasonId,
        string $name,
        string $startDate,
        string $endDate,
        ?int $matchdayCount = null,
        ?string $scoreSheetLanguage = null,
    ): void {
        $this->ensureWriteAccess();
        $this->ensureSeasonExists($seasonId);

        $name = $this->validator->checkName($name, 'field.season_name');
        $this->ensureDateRange($startDate, $endDate);
        if ($scoreSheetLanguage !== null && ! Languages::isSupported($scoreSheetLanguage)) {
            throw ValidationException::forField('field.score_sheet_language', 'validation.invalid_value');
        }

        $this->seasons->update($seasonId, $name, $startDate, $endDate, $matchdayCount);
        if ($scoreSheetLanguage !== null) {
            $this->seasons->setScoreSheetLanguage($seasonId, $scoreSheetLanguage);
        }
    }

    /**
     * Changes the configuration of a season; only allowed as long as the season
     * has not started yet (R8.16, R12.6/R12.7).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws SeasonAdministrationException if the season does not exist or has
     *         already started (R12.7).
     */
    public function updateConfiguration(int $seasonId, SeasonConfiguration $configuration): void
    {
        $this->ensureWriteAccess();
        $season = $this->ensureSeasonExists($seasonId);

        if ($this->isStarted($season)) {
            throw SeasonAdministrationException::seasonAlreadyStarted();
        }

        $this->config->save(
            $seasonId,
            $configuration->laneCount,
            $configuration->entryFee,
            $configuration->blindFee,
            $configuration->maxHandicapValue,
            $configuration->handicapFormula,
            $configuration->targetScore,
            $configuration->ghostScore,
            $configuration->previousSeasonMatchdays,
            $configuration->positiondayInterval,
            $configuration->points,
        );
    }

    /**
     * Deletes a season including its per-season assignments; only allowed as
     * long as the season has not started yet (R8.9, R8.10).
     *
     * The configuration and the dependent assignments are removed via the
     * database's `ON DELETE CASCADE` foreign keys; the configuration is also
     * removed explicitly, so that the deletion is complete even without the
     * cascade.
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws SeasonAdministrationException if the season does not exist or has
     *         already started (R8.10).
     */
    public function deleteSeason(int $seasonId): void
    {
        $this->ensureWriteAccess();
        $season = $this->ensureSeasonExists($seasonId);

        if ($this->isStarted($season)) {
            throw SeasonAdministrationException::seasonAlreadyStarted();
        }

        $this->config->delete($seasonId);
        $this->seasons->delete($seasonId);

        // The cascade has removed the score sheet records of the season's
        // fixtures; their files go as well (R3.15).
        $this->scoreSheetFiles?->removeOrphanedFiles();
    }

    /**
     * Copies all teams of the previous season including their per-season team
     * names into the new season (R8.7). For each copied participation a new
     * team identity is created and the team name assigned to the new season.
     */
    private function copyTeamsFromPreviousSeason(int $previousSeasonId, int $newSeasonId): void
    {
        foreach ($this->teamSeasons->findAllForSeason($previousSeasonId) as $assignment) {
            // The ghost team is no participating team; the season planning creates
            // it anew for an odd number of teams (R9.6, R20).
            if ((int) ($assignment['is_ghost_team'] ?? 0) === 1) {
                continue;
            }

            // The same team keeps playing: keep the team identity.
            $teamId = isset($assignment['team_id']) ? (int) $assignment['team_id'] : $this->teams->create();
            $newAssignment = $this->teamSeasons->create($teamId, $newSeasonId, (string) $assignment['team_name']);

            // Copy team memberships (roster) (R8.7).
            if ($this->rosterReader !== null && $this->rosterStore !== null) {
                foreach ($this->rosterReader->findPlayerIdsForTeamSeason((int) $assignment['id']) as $playerId) {
                    $this->rosterStore->create($newAssignment, (int) $playerId);
                }
            }
        }
    }

    /**
     * Derives the configuration of a new season: prefilled from the previous
     * season (entry fee, blind fee, maximum handicap; R8.15, R12.4) or –
     * without a previous season – from the defaults (R12.2/R12.3/R12.5).
     *
     * @param array<string, mixed>|null $previousSeason
     */
    private function deriveConfiguration(?array $previousSeason): SeasonConfiguration
    {
        if ($previousSeason !== null) {
            $preconfig = $this->config->find((int) $previousSeason['id']);
            if ($preconfig !== null) {
                return SeasonConfiguration::generate(
                    $this->validator,
                    (int) $preconfig['lane_count'],
                    (float) $preconfig['entry_fee'],
                    (float) $preconfig['blind_fee'],
                    (int) $preconfig['max_handicap'],
                    $preconfig['handicap_formula'] !== null ? (string) $preconfig['handicap_formula'] : null,
                    (int) $preconfig['target_score'],
                    (int) ($preconfig['ghost_score'] ?? SeasonConfiguration::DEFAULT_GHOST_SCORE),
                    (int) ($preconfig['previous_season_matchdays'] ?? SeasonConfiguration::DEFAULT_PREVIOUS_SEASON_MATCHDAYS),
                    (int) ($preconfig['positionday_interval'] ?? SeasonConfiguration::DEFAULT_POSITIONDAY_INTERVAL),
                    SeasonConfiguration::pointsRulesFromRow($preconfig),
                );
            }
        }

        // Without a previous season or its configuration: defaults.
        // Lane count 2 as the smallest valid value; the administrator adjusts it
        // afterwards (R8.11).
        return SeasonConfiguration::generate(
            $this->validator,
            2,
            0.0,
            0.0,
        );
    }

    /**
     * Determines the previous season for the start date of the new season: the
     * season with the latest start date before the new start date (R8.7).
     *
     * @return array<string, mixed>|null
     */
    private function determinePreviousSeason(string $newStartDate): ?array
    {
        $fresh = \DateTimeImmutable::createFromFormat('Y-m-d', $newStartDate);
        if ($fresh === false) {
            return null;
        }

        $previousSeason       = null;
        $previousSeasonStart  = null;
        foreach ($this->seasons->findAllSeasons() as $season) {
            $start = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $season['start_date']);
            if ($start === false || $start >= $fresh) {
                continue;
            }

            if ($previousSeasonStart === null || $start > $previousSeasonStart) {
                $previousSeason      = $season;
                $previousSeasonStart = $start;
            }
        }

        return $previousSeason;
    }

    /**
     * Checks the date range of a season: valid dates and an end date strictly
     * after the start date (R8.1/R8.2).
     *
     * @throws ValidationException for an invalid or implausible date range.
     */
    private function ensureDateRange(string $startDate, string $endDate): void
    {
        $start = \DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        $end  = \DateTimeImmutable::createFromFormat('Y-m-d', $endDate);

        if ($start === false) {
            throw ValidationException::forField('field.start_date', 'validation.date');
        }

        if ($end === false) {
            throw ValidationException::forField('field.end_date', 'validation.date');
        }

        // R8.2: the end date must be after the start date.
        if ($end <= $start) {
            throw ValidationException::forField('field.end_date', 'validation.end_after_start');
        }
    }

    /**
     * Ensures that a season exists and returns it.
     *
     * @return array<string, mixed>
     *
     * @throws SeasonAdministrationException if the season does not exist.
     */
    private function ensureSeasonExists(int $seasonId): array
    {
        $season = $this->seasons->find($seasonId);
        if ($season === null) {
            throw SeasonAdministrationException::seasonNotFound();
        }

        return $season;
    }

    /**
     * Checks whether a season has already started on the current check day:
     * start date reached or passed (R8.10, R12.7).
     *
     * @param array<string, mixed> $season
     */
    private function isStarted(array $season): bool
    {
        $start = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $season['start_date']);
        if ($start === false) {
            // Treat an unreadable start date defensively as "started".
            return true;
        }

        return $start->setTime(0, 0) <= $this->currentTime()->setTime(0, 0);
    }

    private function ensureWriteAccess(): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
    }

    private function currentTime(): \DateTimeImmutable
    {
        return ($this->clock)();
    }
}
