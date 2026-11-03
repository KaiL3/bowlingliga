<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\ResultEntry\FixtureHistory;
use Bowlingliga\Security\AccessDeniedException;

/**
 * Card "Verlauf" below the virtual score sheet (admin area only). Without an
 * admin login it renders nothing at all; the check lives in
 * {@see FixtureHistory}.
 */
final class FixtureHistoryController
{
    public function __construct(
        private readonly FixtureHistory $history,
        private readonly EntryData $data,
        private readonly FixtureHistoryView $view,
    ) {
    }

    public function card(int $fixtureId): string
    {
        $fixture = $this->data->teamFixture($fixtureId);
        if ($fixture === null || $fixture['home'] === null || $fixture['away'] === null) {
            return '';
        }

        try {
            $entries = $this->history->entries($fixtureId);
        } catch (AccessDeniedException) {
            return '';
        }

        return $this->view->render($entries, ['home' => $fixture['home']['team_name'], 'away' => $fixture['away']['team_name']]);
    }
}
