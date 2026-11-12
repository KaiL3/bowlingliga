<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\I18n\Translator;

/**
 * Strength display below a new password field (R23.16), like the quality bar
 * of KeePass: a bar up to 128 bits and the bits as number, red below the
 * minimum of the policy, green from it on.
 *
 * The script asks the server for the bits while typing, so the display shows
 * exactly the value the server enforces. Without script only the minimum is
 * shown as hint.
 */
final class PasswordStrengthMeter
{
    /** Endpoint that rates a password (POST, CSRF token, JSON answer). */
    public const ENDPOINT = '/admin/password-strength';

    /**
     * @param string   $inputId          Id of the new password field.
     * @param int|null $minBits          Minimum of the policy, null if there is none.
     * @param string   $usernameInputId  Id of the field holding the username
     *                                   (counts as known to an attacker), or ''.
     */
    public static function html(Translator $t, string $inputId, ?int $minBits, string $usernameInputId = ''): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $hint = $minBits !== null
            ? '<small>' . $e($t->t('password_strength.minimum', ['min' => $minBits])) . '</small>'
            : '';

        return sprintf(
            '<div class="strength" data-strength-for="%s"%s data-user-field="%s" data-text="%s" hidden>'
            . '<div class="strength__bar"><span class="strength__fill"></span></div>'
            . '<p class="strength__text" aria-live="polite"></p></div>%s'
            . '<script src="%s" defer></script>',
            $e($inputId),
            $minBits !== null ? ' data-min="' . $minBits . '"' : '',
            $e($usernameInputId),
            $e($t->t('password_strength.bits')),
            $hint,
            $e(Layout::versioned('/assets/js/password-strength.js')),
        );
    }
}
