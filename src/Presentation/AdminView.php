<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\I18n\Translator;

/**
 * Renders the views of the admin area access (R28.8, R28.9, R28.10).
 *
 * Covers only the access to the admin area and its overview:
 * - the login form as entry to the admin area (R28.8),
 * - the admin area overview, which lists the functional areas bundled as
 *   tiles (R28.10),
 * - the entries of the admin bar, which the {@see Layout} shows on all pages
 *   of the admin area.
 *
 * The login form carries a hidden CSRF token following the synchronizer token
 * pattern (R22.5). All dynamic values are escaped context-aware. The renderer
 * is free of side effects and returns HTML fragments; embedding them into the
 * page layout is up to the caller.
 */
final class AdminView
{
    use ViewTexts;

    /** Session key of the season the admin works with (selection below the admin bar). */
    public const SESSION_SEASON = 'admin_season_id';

    /**
     * Functional areas in display order: path => name, which is also the
     * icon and the catalog key `admin.area.<name>.short|title|text` (short
     * name in the bar, title and description in the overview).
     *
     * @var array<string, string>
     */
    private const AREAS = [
        '/admin/results'         => 'results',
        '/admin/master-data'     => 'master_data',
        '/admin/season'          => 'season',
        '/admin/season-planning' => 'planning',
        '/admin/print'           => 'printing',
        '/admin/honours'         => 'honours',
        '/admin/home-page'       => 'posts',
        '/admin/rules'           => 'rules',
        '/admin/league'          => 'league',
    ];

    public function __construct(private readonly Translator $t = new Translator())
    {
    }

    /**
     * Renders the login form (R28.8).
     *
     * @param string      $csrfFieldHtml Prepared hidden CSRF field (from the
     *                                   CsrfTokenHelper, R22.5).
     * @param string|null $error         Optional error message of a previous
     *                                   login attempt, already phrased for the
     *                                   user.
     * @param bool        $loginNote     Indicates that the login is required
     *                                   because of a protected admin access
     *                                   (R28.9).
     */
    public function renderLogin(
        string $csrfFieldHtml,
        ?string $error = null,
        bool $loginNote = false,
    ): string {
        $note = $loginNote
            ? '<p class="notice" role="status">' . $this->text('admin.login_note') . '</p>'
            : '';

        $errorMessage = $error !== null
            ? sprintf('<p class="error" role="alert">%s</p>', $this->escape($error))
            : '';

        return <<<HTML
            <div class="login">
                <header class="page-header page-header--admin">
                    <p class="page-header__kicker">{$this->text('layout.admin_area')}</p>
                    <h1>{$this->text('admin.login')}</h1>
                </header>
                {$note}
                {$errorMessage}
                <section class="card">
                    <form class="form form--login" method="post" action="/admin/login">
                        {$csrfFieldHtml}
                        <div class="form__field">
                            <label for="username">{$this->text('admin.username')}</label>
                            <input type="text" id="username" name="username" autocomplete="username" required>
                        </div>
                        <div class="form__field">
                            <label for="password">{$this->text('admin.password')}</label>
                            <input type="password" id="password" name="password" autocomplete="current-password" required>
                        </div>
                        <button type="submit" class="button">{$this->text('admin.log_in')}</button>
                    </form>
                </section>
            </div>
            HTML;
    }

    /**
     * Second step of the login with active two-factor login: only the code
     * from the authenticator app (R23.7, R23.8).
     */
    public function renderSecondFactor(string $csrfFieldHtml, ?string $error = null): string
    {
        $errorMessage = $error !== null
            ? sprintf('<p class="error" role="alert">%s</p>', $this->escape($error))
            : '';

        return <<<HTML
            <div class="login">
                <header class="page-header page-header--admin">
                    <p class="page-header__kicker">{$this->text('admin.login_step_two')}</p>
                    <h1>{$this->text('admin.code_from_app')}</h1>
                </header>
                {$errorMessage}
                <section class="card">
                    <p class="empty">{$this->text('admin.enter_code')}</p>
                    <form class="form" method="post" action="/admin/login/code">
                        {$csrfFieldHtml}
                        <div class="form__field">
                            <label for="totp">{$this->text('admin.code')}</label>
                            <input type="text" id="totp" name="totp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
                        </div>
                        <button type="submit" class="button">{$this->text('admin.log_in')}</button>
                    </form>
                    <form method="post" action="/admin/login/code" class="login__cancel">
                        {$csrfFieldHtml}
                        <button type="submit" name="cancel" value="1" class="button button--secondary">{$this->text('common.cancel')}</button>
                    </form>
                </section>
            </div>
            HTML;
    }

    /**
     * Last step of a login whose password has to be replaced first (R23.17):
     * the reason, the new password twice with its strength display.
     *
     * @param int|null $minBits Minimum strength of the policy (R23.15).
     */
    public function renderPasswordChange(
        string $csrfFieldHtml,
        string $reasonKey,
        string $username,
        ?int $minBits,
        ?string $error = null,
    ): string {
        $errorMessage = $error !== null
            ? sprintf('<p class="error" role="alert">%s</p>', $this->escape($error))
            : '';
        $name  = $this->escape($username);
        $min   = \Bowlingliga\Security\PasswordRules::MIN_LENGTH;
        $meter = PasswordStrengthMeter::html($this->t, 'new-password', $minBits, 'change-username');

        return <<<HTML
            <div class="login">
                <header class="page-header page-header--admin">
                    <p class="page-header__kicker">{$this->text('admin.login')}</p>
                    <h1>{$this->text('password_change.title')}</h1>
                </header>
                {$errorMessage}
                <section class="card">
                    <p>{$this->text($reasonKey)}</p>
                    <form class="form" method="post" action="/admin/login/password">
                        {$csrfFieldHtml}
                        <input type="text" id="change-username" name="username" value="{$name}" autocomplete="username" hidden>
                        <div class="form__field">
                            <label for="new-password">{$this->text('account.new_password')}</label>
                            <input type="password" id="new-password" name="new" autocomplete="new-password" minlength="{$min}" required autofocus>
                            <small>{$this->text('account.min_length', ['min' => $min])}</small>
                            {$meter}
                        </div>
                        <div class="form__field">
                            <label for="repeat-password">{$this->text('account.repeat_password')}</label>
                            <input type="password" id="repeat-password" name="repeat" autocomplete="new-password" minlength="{$min}" required>
                        </div>
                        <button type="submit" class="button">{$this->text('password_change.save')}</button>
                    </form>
                    <form method="post" action="/admin/login/password" class="login__cancel">
                        {$csrfFieldHtml}
                        <button type="submit" name="cancel" value="1" class="button button--secondary">{$this->text('common.cancel')}</button>
                    </form>
                </section>
            </div>
            HTML;
    }

    /**
     * Functional areas for the admin bar, which the {@see Layout} shows on all
     * pages of the admin area while a login exists; the system area only for
     * a system admin (R23.10).
     *
     * @return list<array{href: string, text: string}>
     */
    public function barEntries(bool $systemAdmin = false): array
    {
        $entries = [['href' => '/admin', 'text' => $this->plain('admin.overview')]];
        foreach ($this->areas($systemAdmin) as $href => $name) {
            $entries[] = ['href' => $href, 'text' => $this->plain('admin.area.' . $name . '.short')];
        }

        return $entries;
    }

    /**
     * Renders the admin area overview after a successful login and lists the
     * functional areas bundled as tiles (R28.10).
     */
    public function renderOverview(bool $systemAdmin = false): string
    {
        $tiles = '';
        // "Mein Konto" is on the right in the bar, and the last tile in the overview.
        $areas = $this->areas($systemAdmin) + ['/admin/account' => 'account'];
        foreach ($areas as $href => $name) {
            $tiles .= sprintf(
                '<li><a class="tile" href="%s"><span class="tile__icon">%s</span>'
                . '<span class="tile__title">%s</span><span class="tile__text">%s</span></a></li>',
                $this->escape($href),
                $this->icon($name),
                $this->text('admin.area.' . $name . '.title'),
                $this->text('admin.area.' . $name . '.text'),
            );
        }

        return '<header class="page-header page-header--admin"><p class="page-header__kicker">' . $this->text('layout.admin_area') . '</p><h1>' . $this->text('admin.overview') . '</h1>'
            . '<div class="page-header__extra"><p class="page-header__meta">' . $this->text('admin.welcome') . '</p>'
            . '<form class="inline-form" method="post" action="/admin/logout">'
            . '<button type="submit" class="button button--secondary">' . $this->text('layout.log_out') . '</button></form></div></header>'
            . '<nav aria-label="' . $this->text('admin.functions') . '"><ul class="tiles">' . $tiles . '</ul></nav>';
    }

    /**
     * The functional areas the logged-in account sees.
     *
     * @return array<string, string>
     */
    private function areas(bool $systemAdmin): array
    {
        return $systemAdmin ? self::AREAS + ['/admin/system' => 'system'] : self::AREAS;
    }

    /**
     * Line icons of the tiles (decorative, in text colour).
     */
    private function icon(string $name): string
    {
        $paths = match ($name) {
            'results'     => '<path d="M6 3h9l4 4v14H6z"/><path d="M9 11h7M9 15h7M9 7h4"/>',
            'master_data' => '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 4a3 3 0 0 1 0 6M18 20a6 6 0 0 0-2-4.5"/>',
            'season'      => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2"/>',
            'planning'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M8 14h3M13 14h3M8 17h3"/>',
            'printing'    => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
            'honours'     => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/>',
            'posts'       => '<path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 12h5"/>',
            'rules'       => '<path d="M5 4h11l3 3v13H5z"/><path d="M9 10h6M9 14h6"/>',
            'league'      => '<path d="M5 21V4"/><path d="M5 4h12l-3 4 3 4H5"/>',
            'system'      => '<path d="M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
            'account'     => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><path d="M12 15v2"/>',
            default       => '',
        };

        return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" '
            . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
    }

    /**
     * Escapes a value for the HTML text context (UTF-8).
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
