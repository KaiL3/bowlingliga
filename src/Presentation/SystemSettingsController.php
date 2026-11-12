<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\PasswordPolicySettings;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\AccountKind;
use Bowlingliga\Security\PermissionDeniedException;
use Bowlingliga\Security\ValidationException;

/**
 * The system area of a system admin (R23.10): password policies (R23.15).
 */
final class SystemSettingsController
{
    /** Follow-up page after saving; `saved` names the kind of account. */
    public const AFTER_SAVE = '/admin/system?saved=';

    public function __construct(
        private readonly PasswordPolicySettings $settings,
        private readonly SystemSettingsView $view,
        private readonly Translator $t = new Translator(),
    ) {
    }

    /**
     * @param array<string, mixed>|null $input Submitted form after an error.
     */
    public function page(string $csrfField, ?string $saved = null, ?array $input = null, ?string $error = null): string
    {
        try {
            $policies = $this->settings->policies();
        } catch (AccessDeniedException | PermissionDeniedException $e) {
            return AdminComponents::pageHeader($this->t->t('admin.area.system.title'), $this->t->t('layout.admin_area'))
                . AdminComponents::notes(null, $this->t->error($e));
        }

        $kind    = $saved !== null ? AccountKind::tryFrom($saved) : null;
        $message = $kind !== null ? $this->t->t('system.policy_saved', ['kind' => $this->t->t($kind->label())]) : null;
        $kept    = $input !== null ? [(string) ($input['account_kind'] ?? '') => $input] : [];

        return $this->view->render($csrfField, $policies, $message, $error, $kept);
    }

    /**
     * @param array<string, mixed> $input Raw form data (usually $_POST).
     */
    public function savePolicy(array $input): ActionResult
    {
        try {
            $kind = $this->settings->save(
                (string) ($input['account_kind'] ?? ''),
                $input['min_bits'] ?? null,
                $input['max_age_days'] ?? null,
                $input['history_size'] ?? null,
            );

            return ActionResult::success($this->t->t('system.policy_saved', ['kind' => $this->t->t($kind->label())]), self::AFTER_SAVE . $kind->value);
        } catch (AccessDeniedException | PermissionDeniedException | ValidationException $e) {
            return ActionResult::error($this->t->error($e));
        }
    }
}
