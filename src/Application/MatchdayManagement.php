<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Security\WriteAccess;
use Bowlingliga\Security\ValidationException;
use Bowlingliga\Security\AccessDeniedException;

/**
 * Writing use case: managing the matchdays of a season (R8.3–8.5, R8.18,
 * R8.19).
 *
 * Covers creating a matchday with a unique number and a date within the
 * season's start/end date (R8.3/R8.4), changing date and type (R8.5) and
 * marking a matchday as lot draw as long as the season has not started yet
 * (R8.19). Every matchday has exactly one matchday type: "regulär",
 * "Positionday" or "Losentscheid" (R8.18).
 *
 * Every writing call is protected by {@see WriteAccess} (R5.1); the point in
 * time for the "started" rule can be injected via the clock (deterministically
 * testable).
 */
final class MatchdayManagement
{
    public const TYPE_REGULAR     = 'regular';
    public const TYPE_POSITIONDAY  = 'positionday';
    public const TYPE_LOT_DRAW = 'lot_draw';

    private const VALID_TYPES = [
        self::TYPE_REGULAR,
        self::TYPE_POSITIONDAY,
        self::TYPE_LOT_DRAW,
    ];

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    /**
     * @param callable(): \DateTimeImmutable|null $clock Optional clock for
     *        deterministic tests; without it the current time applies.
     */
    public function __construct(
        private readonly MatchdayStore $matchdays,
        private readonly SeasonStore $seasons,
        private readonly WriteAccess $writeAccess,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Creates a matchday with a unique number and a valid date and returns its
     * id (R8.3).
     *
     * @param 'regular'|'positionday'|'lot_draw' $matchdayType
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws SeasonAdministrationException if the season does not exist, the
     *         number is already taken or the date is outside the season (R8.4).
     * @throws ValidationException for an invalid date or matchday type.
     */
    public function createMatchday(
        int $seasonId,
        int $matchdayCounter,
        string $date,
        string $matchdayType = self::TYPE_REGULAR,
    ): int {
        $this->ensureWriteAccess();

        $season = $this->ensureSeasonExists($seasonId);
        $this->checkType($matchdayType);
        $this->ensureDateInSeason($date, $season);
        $this->ensureNumberUnique($seasonId, $matchdayCounter, null);

        return $this->matchdays->create($seasonId, $matchdayCounter, $date, $matchdayType);
    }

    /**
     * Changes date and type of a matchday; the date must be within the season
     * (R8.5).
     *
     * @param 'regular'|'positionday'|'lot_draw' $matchdayType
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws SeasonAdministrationException if matchday/season are missing or
     *         the date is outside the season (R8.4).
     * @throws ValidationException for an invalid date or matchday type.
     */
    public function updateMatchday(int $matchdayId, string $date, string $matchdayType): void
    {
        $this->ensureWriteAccess();

        $matchday = $this->matchdays->find($matchdayId);
        if ($matchday === null) {
            throw SeasonAdministrationException::matchdayNotFound();
        }

        $season = $this->ensureSeasonExists((int) $matchday['season_id']);
        $this->checkType($matchdayType);
        $this->ensureDateInSeason($date, $season);

        $this->matchdays->update($matchdayId, $date, $matchdayType);
    }

    /**
     * Marks a matchday as lot draw as long as the season has not reached its
     * start date (R8.19).
     *
     * Date and other properties stay unchanged; only the matchday type is set
     * to "Losentscheid".
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws SeasonAdministrationException if matchday/season are missing or
     *         the season has already started.
     */
    public function markAsLotDraw(int $matchdayId): void
    {
        $this->ensureWriteAccess();

        $matchday = $this->matchdays->find($matchdayId);
        if ($matchday === null) {
            throw SeasonAdministrationException::matchdayNotFound();
        }

        $season = $this->ensureSeasonExists((int) $matchday['season_id']);
        if ($this->isStarted($season)) {
            throw SeasonAdministrationException::seasonAlreadyStarted();
        }

        $this->matchdays->update($matchdayId, (string) $matchday['date'], self::TYPE_LOT_DRAW);
    }

    /**
     * Checks that the matchday type is one of the allowed values (R8.18).
     *
     * @throws ValidationException for an invalid type.
     */
    private function checkType(string $matchdayType): void
    {
        if (! in_array($matchdayType, self::VALID_TYPES, true)) {
            throw ValidationException::forField(
                'field.matchday_type',
                'validation.matchday_type'
            );
        }
    }

    /**
     * Ensures that the date is within the season's start/end date (R8.3/R8.4).
     *
     * @param array<string, mixed> $season
     *
     * @throws ValidationException for an invalid date.
     * @throws SeasonAdministrationException if the date is outside the season.
     */
    private function ensureDateInSeason(string $date, array $season): void
    {
        $day = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($day === false) {
            throw ValidationException::forField('field.date', 'validation.date');
        }

        $start = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $season['start_date']);
        $end  = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $season['end_date']);
        if ($start === false || $end === false) {
            throw SeasonAdministrationException::dateOutsideSeason();
        }

        $day   = $day->setTime(0, 0);
        $start = $start->setTime(0, 0);
        $end  = $end->setTime(0, 0);

        if ($day < $start || $day > $end) {
            throw SeasonAdministrationException::dateOutsideSeason();
        }
    }

    /**
     * Ensures that the matchday number is unique within the season (R8.4).
     *
     * @throws SeasonAdministrationException if the number is already taken.
     */
    private function ensureNumberUnique(int $seasonId, int $matchdayCounter, ?int $ownId): void
    {
        foreach ($this->matchdays->findAllForSeason($seasonId) as $existingEntry) {
            if ($ownId !== null && (int) $existingEntry['id'] === $ownId) {
                continue;
            }

            if ((int) $existingEntry['matchday_number'] === $matchdayCounter) {
                throw SeasonAdministrationException::matchdayNumberTaken($matchdayCounter);
            }
        }
    }

    /**
     * @param array<string, mixed> $season
     */
    private function isStarted(array $season): bool
    {
        $start = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $season['start_date']);
        if ($start === false) {
            return true;
        }

        return $start->setTime(0, 0) <= $this->currentTime()->setTime(0, 0);
    }

    /**
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
