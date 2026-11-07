<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\League\LeagueSettings;
use Bowlingliga\I18n\Translator;

/**
 * Controller of the admin area "Liga" (R38, R40).
 *
 * Renders the form and saves the league name and the player visibility via
 * {@see LeagueSettings}, which checks write access (R5.1) and validates
 * (R38.3). After saving the front controller redirects to the form (the new
 * name must already appear in the page header). CSRF protection (R22.5) is
 * enforced by the front controller before an action runs.
 */
final class LeagueSettingsController
{
    /** Follow-up page after saving; `saved` shows the confirmation. */
    public const AFTER_SAVE = '/admin/league?saved=1';

    public function __construct(
        private readonly LeagueSettings $settings,
        private readonly LeagueSettingsView $view,
        private readonly Translator $t = new Translator(),
    ) {
    }

    /**
     * @param array<string, mixed>|null $input Submitted form after an error (prefills the form).
     */
    public function edit(string $csrfField, bool $saved = false, ?array $input = null, ?string $error = null): string
    {
        return $this->view->renderEdit(
            $csrfField,
            $input !== null ? (string) ($input['name'] ?? '') : $this->settings->name(),
            $input !== null ? self::playersPublicFrom($input) : $this->settings->playersPublic(),
            $saved ? $this->t->t('league.saved') : null,
            $error,
        );
    }

    /**
     * Saves the league name (R38.3) and the player visibility (R40.1).
     *
     * @param array<string, mixed> $input Raw form data (usually $_POST).
     */
    public function save(array $input): ActionResult
    {
        try {
            $this->settings->save((string) ($input['name'] ?? ''), self::playersPublicFrom($input));

            return ActionResult::success($this->t->t('league.saved'), self::AFTER_SAVE);
        } catch (\RuntimeException $e) {
            // Access, validation and repository errors carry messages for the user.
            return ActionResult::error($this->t->error($e));
        }
    }

    /**
     * An unticked checkbox is not submitted at all.
     *
     * @param array<string, mixed> $input
     */
    private static function playersPublicFrom(array $input): bool
    {
        return ($input['players_public'] ?? null) === '1';
    }
}
