<?php

declare(strict_types=1);

namespace Bowlingliga\Application\ResultEntry;

/**
 * One entry in the history of a fixture: who changed what and when – a team
 * via its code (side home/away) or the admin (side null, actor = username).
 */
final class FixtureLogEntry
{
    /**
     * Language-neutral details of the action `final`; the view names them in
     * the visitor's language. Older entries carry a German sentence instead,
     * which stays as stored.
     */
    public const FINAL_GHOST_TEAM = 'ghost_team';

    public const FINAL_BOTH_CONFIRMED = 'both_confirmed';

    public function __construct(
        public readonly string $createdAt,
        public readonly ?string $side,
        public readonly ?string $actor,
        public readonly string $action,
        public readonly ?int $playerId = null,
        public readonly ?string $playerName = null,
        public readonly ?int $gameNumber = null,
        public readonly ?string $before = null,
        public readonly ?string $after = null,
        public readonly string $detail = '',
    ) {
    }
}
