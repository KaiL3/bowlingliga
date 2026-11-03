<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\Domain\Message;
use Bowlingliga\Presentation\SeasonPlanningReader;

/**
 * Fake of the {@see SeasonPlanningReader} port with fixed data that can be
 * changed at runtime.
 */
final class FixedSeasonPlanningReader implements SeasonPlanningReader
{
    /**
     * @param array<string, mixed>|null       $seasonData
     * @param array<int, string>              $names
     * @param list<array<string, mixed>>      $plan
     */
    public function __construct(
        public ?array $seasonData,
        public array $names = [0 => 'Geisterteam'],
        public array $plan = [],
        public ?Message $reason = null,
    ) {
    }

    public function season(int $seasonId): ?array
    {
        return $this->seasonData !== null && $this->seasonData['id'] === $seasonId ? $this->seasonData : null;
    }

    public function teamNames(int $seasonId): array
    {
        return $this->names;
    }

    public function storedPlan(int $seasonId): array
    {
        return $this->plan;
    }

    public function reasonAgainstReplanning(int $seasonId): ?Message
    {
        return $this->reason;
    }
}
