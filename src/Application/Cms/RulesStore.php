<?php

declare(strict_types=1);

namespace Bowlingliga\Application\Cms;

/**
 * Port for persisting the rules text (R34.3, R34.4).
 *
 * Decouples {@see RulesManagement} from the concrete persistence technology.
 * There is at most one rules content; the implementation creates or updates
 * it (upsert). The port is implemented by an adapter based on the
 * `rules` table; in tests an in-memory fake is enough.
 */
interface RulesStore
{
    /**
     * Saves the (sanitized) text content of the rules; creates it or updates
     * the existing one (R34.3, R34.4).
     */
    public function save(string $text): void;
}
