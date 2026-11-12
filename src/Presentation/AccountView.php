<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\I18n\Translator;
use Bowlingliga\Security\AccountManagement;

/**
 * Renders "Mein Konto": two-factor login (status, setup with QR code,
 * disabling), password change (R23.3, R23.6–R23.9) and logging out all other
 * sessions.
 *
 * All forms carry the CSRF field (R22.5); dynamic values are escaped. The QR
 * SVG comes from {@see QrCode} and is embedded unchanged. Free of side
 * effects.
 */
final class AccountView
{
    use ViewTexts;

    public function __construct(private readonly Translator $t = new Translator())
    {
    }

    /**
     * @param array{secret: string, uri: string, qr: string}|null $setup setup in progress
     * @param int|null $minBits Minimum strength of the password policy (R23.15).
     */
    public function render(
        string $csrfField,
        string $username,
        bool $totpActive,
        ?array $setup,
        ?string $message = null,
        ?string $error = null,
        ?int $minBits = null,
    ): string {
        return AdminComponents::pageHeader(
            $this->plain('layout.my_account'),
            $this->plain('layout.admin_area'),
            '<p class="page-header__meta">' . $this->text('account.logged_in_as', [], ['user' => '<strong>' . $this->e($username) . '</strong>']) . '</p>',
        )
            . AdminComponents::notes($message, $error)
            . '<div class="grid grid--work">'
            . $this->renderTwoFactor($csrfField, $totpActive, $setup)
            . $this->renderPassword($csrfField, $username, $minBits)
            . $this->renderSessions($csrfField)
            . '</div>';
    }

    public function renderError(string $error): string
    {
        return AdminComponents::pageHeader($this->plain('layout.my_account'), $this->plain('layout.admin_area')) . AdminComponents::notes(null, $error);
    }

    /**
     * @param array{secret: string, uri: string, qr: string}|null $setup
     */
    private function renderTwoFactor(string $csrfField, bool $active, ?array $setup): string
    {
        $status = $active
            ? '<span class="status status--complete">' . $this->text('account.active') . '</span>'
            : '<span class="status">' . $this->text('account.not_active') . '</span>';

        if ($active) {
            $content = <<<HTML
                <p>{$this->text('account.totp_active_text')}</p>
                <details class="collapsible">
                    <summary class="collapsible__header"><span class="collapsible__title">{$this->text('account.totp_disable_title')}</span></summary>
                    <p class="empty">{$this->text('account.totp_disable_text')}</p>
                    <form class="form" method="post" action="/admin/account/totp-deactivate">
                        {$csrfField}
                        <div class="form__field">
                            <label for="totp-off-password">{$this->text('admin.password')}</label>
                            <input type="password" id="totp-off-password" name="password" autocomplete="current-password" required>
                        </div>
                        <div class="form__field">
                            <label for="totp-off-code">{$this->text('account.current_code')}</label>
                            <input type="text" id="totp-off-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
                        </div>
                        <button type="submit" class="button button--danger">{$this->text('account.disable')}</button>
                    </form>
                </details>
                HTML;
        } elseif ($setup !== null) {
            $content = $this->renderSetup($csrfField, $setup);
        } else {
            $content = <<<HTML
                <p>{$this->text('account.totp_intro')}</p>
                <form method="post" action="/admin/account/totp-start">
                    {$csrfField}
                    <button type="submit" class="button">{$this->text('account.totp_set_up')}</button>
                </form>
                HTML;
        }

        return sprintf(
            '<section class="card" aria-labelledby="h-2fa"><div class="card__header"><h2 id="h-2fa">%s</h2>%s</div>%s</section>',
            $this->text('account.totp_title'),
            $status,
            $content,
        );
    }

    /**
     * @param array{secret: string, uri: string, qr: string} $setup
     */
    private function renderSetup(string $csrfField, array $setup): string
    {
        // Secret in groups of four for easier typing.
        $groups = $this->e(implode(' ', str_split($setup['secret'], 4)));
        $uri     = $this->e($setup['uri']);

        return <<<HTML
            <ol class="setup">
                <li>
                    {$this->text('account.setup_step_one')}
                    <div class="setup__qr" role="img" aria-label="{$this->text('account.setup_qr_label')}">{$setup['qr']}</div>
                    <p class="empty">{$this->text('account.setup_by_hand')}</p>
                    <p class="setup__secret"><code>{$groups}</code></p>
                    <p><a class="button button--secondary setup__app" href="{$uri}">{$this->text('account.setup_open_app')}</a></p>
                </li>
                <li>
                    {$this->text('account.setup_step_two')}
                    <form class="form setup__code" method="post" action="/admin/account/totp-activate">
                        {$csrfField}
                        <div class="form__field">
                            <label for="totp-code">{$this->text('admin.code')}</label>
                            <input type="text" id="totp-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
                        </div>
                        <button type="submit" class="button">{$this->text('account.activate')}</button>
                    </form>
                </li>
            </ol>
            <form method="post" action="/admin/account/totp-cancel">
                {$csrfField}
                <button type="submit" class="button button--secondary">{$this->text('account.setup_cancel')}</button>
            </form>
            HTML;
    }

    private function renderPassword(string $csrfField, string $username, ?int $minBits): string
    {
        $min   = AccountManagement::PASSWORD_MIN;
        $name  = $this->e($username);
        $meter = PasswordStrengthMeter::html($this->t, 'pw-new', $minBits, 'pw-username');

        return <<<HTML
            <section class="card" aria-labelledby="h-password">
                <div class="card__header"><h2 id="h-password">{$this->text('account.change_password')}</h2></div>
                <form class="form" method="post" action="/admin/account/password">
                    {$csrfField}
                    <input type="text" id="pw-username" name="username" value="{$name}" autocomplete="username" hidden>
                    <div class="form__field">
                        <label for="pw-current">{$this->text('account.current_password')}</label>
                        <input type="password" id="pw-current" name="current" autocomplete="current-password" required>
                    </div>
                    <div class="form__field">
                        <label for="pw-new">{$this->text('account.new_password')}</label>
                        <input type="password" id="pw-new" name="new" autocomplete="new-password" minlength="{$min}" required>
                        <small>{$this->text('account.min_length', ['min' => $min])}</small>
                        {$meter}
                    </div>
                    <div class="form__field">
                        <label for="pw-repeat">{$this->text('account.repeat_password')}</label>
                        <input type="password" id="pw-repeat" name="repeat" autocomplete="new-password" minlength="{$min}" required>
                    </div>
                    <button type="submit" class="button">{$this->text('account.change_password')}</button>
                </form>
            </section>
            HTML;
    }

    private function renderSessions(string $csrfField): string
    {
        return <<<HTML
            <section class="card" aria-labelledby="h-sessions">
                <div class="card__header"><h2 id="h-sessions">{$this->text('account.sessions')}</h2></div>
                <p>{$this->text('account.sessions_text')}</p>
                <p class="empty">{$this->text('account.sessions_foreign')}</p>
                <form method="post" action="/admin/account/end-sessions">
                    {$csrfField}
                    <button type="submit" class="button button--secondary">{$this->text('account.end_sessions')}</button>
                </form>
            </section>
            HTML;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
