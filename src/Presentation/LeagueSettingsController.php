<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\League\LeagueSettings;
use Bowlingliga\I18n\Translator;

/**
 * Controller of the admin area "Liga" (R38).
 *
 * Renders the form and saves the league name via {@see LeagueSettings},
 * which checks write access (R5.1) and validates (R38.3). After saving the
 * front controller redirects to the form (the new name must already appear
 * in the page header). CSRF protection (R22.5) is enforced by the front
 * controller before an action runs.
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
     * @param string|null $inputName Submitted name after an error (prefills the form).
     */
    public function edit(string $csrfField, bool $saved = false, ?string $inputName = null, ?string $error = null): string
    {
        return $this->view->renderEdit(
            $csrfField,
            $inputName ?? $this->settings->name(),
            $saved ? $this->t->t('league.saved') : null,
            $error,
        );
    }

    /**
     * Saves the league name (R38.3).
     *
     * @param array<string, mixed> $input Raw form data (usually $_POST).
     */
    public function save(array $input): ActionResult
    {
        try {
            $this->settings->rename((string) ($input['name'] ?? ''));

            return ActionResult::success($this->t->t('league.saved'), self::AFTER_SAVE);
        } catch (\RuntimeException $e) {
            // Access, validation and repository errors carry messages for the user.
            return ActionResult::error($this->t->error($e));
        }
    }
}
