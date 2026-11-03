<?php

declare(strict_types=1);

namespace Bowlingliga\Persistence;

use Bowlingliga\Application\PreviousSeasonContext;

/**
 * Adapter of the {@see PreviousSeasonContext}: may the previous season records
 * of a season still be entered (R29.9)? Only until the season has reached its
 * start date.
 */
final class PreviousSeasonContextRepository extends AbstractRepository implements PreviousSeasonContext
{
    /**
     * @param (callable(): \DateTimeImmutable)|null $clock Current date (for tests).
     */
    public function __construct(Database $database, private readonly mixed $clock = null)
    {
        parent::__construct($database);
    }

    public function isSeasonStarted(int $seasonId): bool
    {
        $row = $this->findOne('SELECT start_date FROM season WHERE id = :id', ['id' => $seasonId]);
        if ($row === null) {
            return false;
        }

        $today = $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable('today');

        return (string) $row['start_date'] <= $today->format('Y-m-d');
    }
}
