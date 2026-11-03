<?php

declare(strict_types=1);

namespace Bowlingliga\Application;

use Bowlingliga\Security\WriteAccess;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\AccessDeniedException;

/**
 * Writing use case: entering, changing and removing a player's manually
 * entered previous season record of a season (R29.1–R29.11).
 *
 * The previous season record (number of previous season games played and the
 * net pins scored in them) makes it possible to calculate the average (R11)
 * and the handicap (R12) correctly during the first matchdays of a season
 * whose previous season is not in the database – at the first start of the
 * system, or when seasons in between are missing. A record of the season
 * takes precedence over the games of the preceding season (R29.2). This gives
 * the domain rules:
 * - A record belongs to one season; changing is only allowed as long as that
 *   season has not reached its start date (R29.9, R29.11).
 * - Number of games played and net pins total must be integers ≥ 0; if the
 *   number is > 0, the derivable average must be in the range 0.00–300.00
 *   (R29.3–R29.7). The check is done by the {@see Validator}; for invalid
 *   values nothing is saved.
 *
 * Every writing call is protected by {@see WriteAccess} (R5.1); without a
 * valid session the action is skipped and the data stays unchanged.
 * Persisting happens transactionally as raw data
 * ({@see PreviousSeasonRecordStore}, R4.1, R4.3).
 */
final class PreviousSeasonRecordManagement
{
    public function __construct(
        private readonly PreviousSeasonRecordStore $records,
        private readonly PreviousSeasonContext $context,
        private readonly Validator $validator,
        private readonly WriteAccess $writeAccess,
    ) {
    }

    /**
     * Enters or changes a player's previous season record for a season
     * (R29.1, R29.8, R29.9).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws PreviousSeasonRecordManagementException if the season has
     *         already started (R29.9).
     * @throws \Bowlingliga\Security\ValidationException for an invalid count
     *         (R29.4), an invalid sum (R29.6) or an implausible derivable
     *         average (R29.7).
     */
    public function enterRecord(int $seasonId, int $playerId, int $numberOfGames, int $netPinsTotal): void
    {
        $this->ensureWriteAccess();
        $this->ensureEnterable($seasonId);
        $this->validator->checkPreviousSeasonRecord($numberOfGames, $netPinsTotal);

        $this->records->save($seasonId, $playerId, $numberOfGames, $netPinsTotal);
    }

    /**
     * Removes a player's previous season record of a season as long as it can
     * still be entered/changed (R29.9).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws PreviousSeasonRecordManagementException if the season has
     *         already started (R29.9).
     */
    public function removeRecord(int $seasonId, int $playerId): void
    {
        $this->ensureWriteAccess();
        $this->ensureEnterable($seasonId);

        $this->records->delete($seasonId, $playerId);
    }

    /**
     * Ensures that the previous season records of the season may currently be
     * entered or changed (R29.9).
     *
     * @throws PreviousSeasonRecordManagementException
     */
    private function ensureEnterable(int $seasonId): void
    {
        if ($this->context->isSeasonStarted($seasonId)) {
            throw PreviousSeasonRecordManagementException::seasonAlreadyStarted();
        }
    }

    /**
     * Ensures that write access is currently allowed (R5.1).
     *
     * @throws AccessDeniedException if there is no valid write access.
     */
    private function ensureWriteAccess(): void
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }
    }
}
