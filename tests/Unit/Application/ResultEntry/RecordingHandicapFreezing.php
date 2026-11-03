<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Application\ResultEntry;

use Bowlingliga\Application\ResultEntry\HandicapFreezing;
use Bowlingliga\Domain\Handicap\HandicapChainPreview;

/**
 * In-memory fake of the {@see HandicapFreezing} port.
 */
final class RecordingHandicapFreezing implements HandicapFreezing
{
    /** @var list<int> matchdays for which handicaps were frozen */
    public array $frozen = [];

    /** @var list<HandicapChainPreview> applied previews */
    public array $applied = [];

    public ?HandicapChainPreview $preview = null;

    public function freezeHandicaps(int $matchdayId): void
    {
        $this->frozen[] = $matchdayId;
    }

    public function recalculateChain(int $seasonId, int $fromMatchdayCounter): HandicapChainPreview
    {
        return $this->preview ??= new HandicapChainPreview($fromMatchdayCounter, []);
    }

    public function applyChain(HandicapChainPreview $preview): void
    {
        $this->applied[] = $preview;
    }
}
