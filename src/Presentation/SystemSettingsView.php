<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\I18n\Translator;
use Bowlingliga\Security\AccountKind;
use Bowlingliga\Security\PasswordPolicy;

/**
 * Renders the system area of a system admin (R23.10): the password policies
 * of admins and players (R23.15), one card each.
 */
final class SystemSettingsView
{
    use ViewTexts;

    public function __construct(private readonly Translator $t = new Translator())
    {
    }

    /**
     * @param array<string, PasswordPolicy>        $policies keyed by {@see AccountKind} value
     * @param array<string, array<string, mixed>> $input    Submitted values after an error, by kind.
     */
    public function render(string $csrfField, array $policies, ?string $message = null, ?string $error = null, array $input = []): string
    {
        $cards = '';
        foreach (AccountKind::cases() as $kind) {
            $policy = $policies[$kind->value] ?? PasswordPolicy::defaultFor($kind);
            $cards .= $this->policyCard($csrfField, $kind, $policy, $input[$kind->value] ?? null);
        }

        return AdminComponents::pageHeader($this->plain('admin.area.system.title'), $this->plain('layout.admin_area'))
            . AdminComponents::notes($message, $error)
            . '<p class="empty">' . $this->text('system.policy_intro') . ' ' . $this->text('system.bits_note') . '</p>'
            . '<div class="grid grid--2">' . $cards . '</div>';
    }

    /**
     * @param array<string, mixed>|null $input
     */
    private function policyCard(string $csrfField, AccountKind $kind, PasswordPolicy $policy, ?array $input): string
    {
        $id     = 'policy-' . $kind->value;
        $value  = static fn (string $field, int $stored): string => AdminComponents::e(
            $input !== null ? (string) ($input[$field] ?? '') : (string) $stored
        );
        $minBits = $value('min_bits', $policy->minBits);
        $maxAge  = $value('max_age_days', $policy->maxAgeDays);
        $history = $value('history_size', $policy->historySize);
        $maxBits    = PasswordPolicy::MAX_BITS;
        $maxDays    = PasswordPolicy::MAX_AGE_DAYS;
        $maxHistory = PasswordPolicy::MAX_HISTORY;

        return AdminComponents::card($this->plain('system.policy_title', ['kind' => $this->plain($kind->label())]), <<<HTML
            <form class="form" method="post" action="/admin/system/password-policy">
                {$csrfField}
                <input type="hidden" name="account_kind" value="{$kind->value}">
                <div class="policy-form__numbers">
                    <div class="form__field">
                        <label for="{$id}-bits">{$this->text('field.min_bits')}</label>
                        <input type="number" id="{$id}-bits" name="min_bits" value="{$minBits}" min="0" max="{$maxBits}" step="1" inputmode="numeric" required>
                    </div>
                    <div class="form__field">
                        <label for="{$id}-age">{$this->text('field.max_age_days')}</label>
                        <input type="number" id="{$id}-age" name="max_age_days" value="{$maxAge}" min="-1" max="{$maxDays}" step="1" required aria-describedby="{$id}-age-note">
                        <small id="{$id}-age-note">{$this->text('system.max_age_note')}</small>
                    </div>
                    <div class="form__field">
                        <label for="{$id}-history">{$this->text('field.history_size')}</label>
                        <input type="number" id="{$id}-history" name="history_size" value="{$history}" min="0" max="{$maxHistory}" step="1" inputmode="numeric" required aria-describedby="{$id}-history-note">
                        <small id="{$id}-history-note">{$this->text('system.history_note')}</small>
                    </div>
                </div>
                <button type="submit" class="button">{$this->text('common.save')}</button>
            </form>
            HTML);
    }
}
