<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\League\LeagueSettings;
use Bowlingliga\I18n\Languages;
use Bowlingliga\I18n\Translator;

/**
 * Common responsive base layout of all delivered HTML pages (R36.1, R36.8).
 *
 * Generates a complete HTML5 document with:
 * - the viewport meta tag `width=device-width, initial-scale=1` in every page
 *   (R36.1),
 * - only local assets included (stylesheet, fonts, script) without depending
 *   on external/cloud-based services (R36.8),
 * - the {@see Appearance} chosen by the visitor as `data-style` and
 *   `data-mode` on the `<html>` element (styles "Lane"/"Bento", each light,
 *   dark or automatic),
 * - a main navigation with icons, which appears as a bar at the bottom on
 *   narrow screens (via the stylesheet),
 * - the appearance and language toggle in the footer; it works without
 *   JavaScript as a form (POST `/appearance`) and immediately with
 *   JavaScript,
 * - every text of the frame in the visitor's language ({@see Translator}),
 *   `lang` on the `<html>` element accordingly.
 *
 * The page content (already rendered HTML) is inserted unchanged; the
 * context-aware escaping of the individual values happens in the respective
 * views.
 *
 * Free of side effects: returns the page as string; output is up to the
 * caller.
 */
final class Layout
{
    /** Path to the local stylesheet (relative to the web root, R36.8). */
    public const STYLESHEET = '/assets/css/app.css';

    /** Path to the local script of the appearance toggle. */
    public const SCRIPT = '/assets/js/appearance.js';

    /** @var array<string, string> style → locally shipped font (for preload) */
    private const FONTS = [
        'lane'  => '/assets/fonts/space-grotesk.woff2',
        'bento' => '/assets/fonts/plus-jakarta-sans.woff2',
    ];

    private readonly Appearance $appearance;

    /**
     * @param string $leagueName Name of the league in header and footer (R38.4).
     */
    public function __construct(
        ?Appearance $appearance = null,
        private readonly Translator $t = new Translator(),
        private readonly string $leagueName = LeagueSettings::DEFAULT_NAME,
    ) {
        $this->appearance = $appearance ?? Appearance::standard();
    }

    /**
     * Renders a complete HTML page with common header, navigation and footer
     * (R36.1, R36.8).
     *
     * @param string $title   Page title (escaped).
     * @param string $content Already rendered, safe HTML of the page content.
     * @param list<array{href: string, text: string, icon?: string}> $navigation
     *        Top-level navigation entries (text is escaped); empty for pages
     *        without navigation.
     * @param string|null $currentAddress Address of the requested page (path
     *        including query); marks the matching navigation item and serves
     *        the toggle as return target.
     * @param list<array{href: string, text: string}> $adminNavigation Areas of
     *        the admin bar; only passed with an existing login, otherwise
     *        empty (then no bar).
     * @param array{seasons: list<array{id: int|string, name: string}>, selected: int|null, current: int|null}|null $adminSeason
     *        Season selection below the admin bar: all seasons, the one the
     *        admin works with and the current one (a hint appears if they
     *        differ). Null for no selection.
     */
    public function render(
        string $title,
        string $content,
        array $navigation = [],
        ?string $currentAddress = null,
        array $adminNavigation = [],
        ?array $adminSeason = null,
    ): string {
        $titleEscaped = $this->escape($title);
        $nav          = $this->renderNavigation($navigation, $currentAddress);
        $toggle   = $this->renderToggle($currentAddress ?? '/');
        $style         = $this->escape($this->appearance->style);
        $mode        = $this->escape($this->appearance->mode);
        $font      = $this->escape(self::FONTS[$this->appearance->style]);
        $mark        = $this->logo();
        // The logo leads to the home page like the first navigation item (with the selected season).
        $home        = $this->escape($navigation[0]['href'] ?? '/');
        $adminBar  = $this->renderAdminBar($adminNavigation, $currentAddress, $adminSeason);
        $language  = $this->escape($this->t->language());
        $skip      = $this->escape($this->t->t('layout.skip_link'));
        $league    = $this->escape($this->leagueName);
        $source    = $this->escape($this->t->t('layout.source_code'));

        return <<<HTML
            <!DOCTYPE html>
            <html lang="{$language}" data-style="{$style}" data-mode="{$mode}">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="color-scheme" content="light dark">
                <title>{$titleEscaped}</title>
                <link rel="icon" href="/favicon.ico" sizes="32x32">
                <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
                <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
                <link rel="preload" href="{$font}" as="font" type="font/woff2" crossorigin>
                <link rel="stylesheet" href="{$this->escape(self::versioned(self::STYLESHEET))}">
                <script src="{$this->escape(self::versioned(self::SCRIPT))}" defer></script>
            </head>
            <body>
                <a class="skip-link" href="#content">{$skip}</a>
                <header class="header">
                    <div class="header__inner">
                        <a class="header__brand" href="{$home}">{$mark}<span>{$league}</span></a>
                        {$nav}
                    </div>
                </header>
                {$adminBar}
                <main id="content" class="content">
                    {$content}
                </main>
                <footer class="footer">
                    <div class="footer__inner">
                        <div class="footer__about">
                            <span class="footer__brand">{$league}</span>
                            <a class="footer__source" href="https://github.com/KaiL3/bowlingliga">{$source}</a>
                        </div>
                        {$toggle}
                    </div>
                </footer>
            </body>
            </html>
            HTML;
    }

    /**
     * Renders the top-level navigation as a list with icons; the entry matching
     * the current address carries `aria-current="page"`. Touch-friendly target
     * sizes are ensured by the stylesheet (R36.5).
     *
     * @param list<array{href: string, text: string, icon?: string}> $navigation
     */
    private function renderNavigation(array $navigation, ?string $currentAddress): string
    {
        if ($navigation === []) {
            return '';
        }

        $currentPath = $currentAddress !== null ? $this->pathName($currentAddress) : null;

        $entries = '';
        foreach ($navigation as $entry) {
            $href    = $this->escape($entry['href']);
            $text    = $this->escape($entry['text']);
            $icon    = $this->icon($entry['icon'] ?? '');
            $current = $currentPath !== null && $this->isActive($this->pathName($entry['href']), $currentPath)
                ? ' aria-current="page"'
                : '';

            $entries .= "<li class=\"nav__item\"><a class=\"nav__link\" href=\"{$href}\"{$current}>{$icon}<span>{$text}</span></a></li>";
        }

        return '<nav class="nav" aria-label="' . $this->escape($this->t->t('layout.main_navigation')) . "\"><ul class=\"nav__list\">{$entries}</ul></nav>";
    }

    /**
     * Renders the admin bar with the functional areas and the logout. Active is
     * the area under whose path the page lies (e.g. the score sheet under
     * "Ergebnisse"); the overview only on an exact match.
     *
     * @param list<array{href: string, text: string}> $entries
     * @param array{seasons: list<array{id: int|string, name: string}>, selected: int|null, current: int|null}|null $season
     */
    private function renderAdminBar(array $entries, ?string $currentAddress, ?array $season = null): string
    {
        if ($entries === []) {
            return '';
        }

        $pathName = $currentAddress !== null ? $this->pathName($currentAddress) : '';
        // Switching the season returns to the area of the page (a score sheet
        // belongs to one season, its area shows the other season).
        $area = $pathName === '/admin/account' ? '/admin/account' : '/admin';

        $points = '';
        foreach ($entries as $entry) {
            $target  = $this->pathName($entry['href']);
            $active = $target === '/admin' ? $pathName === '/admin' : ($pathName === $target || str_starts_with($pathName, $target . '/'));
            if ($active) {
                $area = $target;
            }

            $points .= sprintf(
                '<li><a class="admin-bar__link" href="%s"%s>%s</a></li>',
                $this->escape($entry['href']),
                $active ? ' aria-current="page"' : '',
                $this->escape($entry['text']),
            );
        }

        $accountActive = $pathName === '/admin/account' ? ' aria-current="page"' : '';
        $seasonSwitch  = $season !== null ? $this->renderAdminSeason($season, $area, $currentAddress) : '';
        $label = fn (string $key): string => $this->escape($this->t->t($key));

        return <<<HTML
            <nav class="admin-bar" aria-label="{$label('layout.admin_area')}">
                <div class="admin-bar__inner">
                    <span class="admin-bar__title">{$label('layout.admin')}</span>
                    <ul class="admin-bar__list">{$points}</ul>
                    <a class="admin-bar__link admin-bar__account" href="/admin/account"{$accountActive}>{$label('layout.my_account')}</a>
                    <form class="admin-bar__logout" method="post" action="/admin/logout">
                        <button type="submit" class="button button--secondary">{$label('layout.log_out')}</button>
                    </form>
                </div>
                {$seasonSwitch}
            </nav>
            <script src="{$this->escape(self::versioned('/assets/js/admin-bar.js'))}" defer></script>
            HTML;
    }

    /**
     * Season selection for the whole admin area (GET, the admin session keeps
     * it). With JavaScript a change applies immediately, otherwise via the
     * button. If not the current season is selected, a hint says so, so that
     * nobody enters results into the wrong season by mistake.
     *
     * @param array{seasons: list<array{id: int|string, name: string}>, selected: int|null, current: int|null} $season
     */
    private function renderAdminSeason(array $season, string $area, ?string $currentAddress): string
    {
        if ($season['seasons'] === []) {
            return '';
        }

        // Master data: stay on the open tab. Other parameters (e.g. a fixture)
        // belong to the previous season and are dropped.
        parse_str((string) parse_url((string) $currentAddress, PHP_URL_QUERY), $query);
        $tab = $area === '/admin/master-data' && is_string($query['tab'] ?? null)
            ? sprintf('<input type="hidden" name="tab" value="%s">', $this->escape($query['tab']))
            : '';

        $options = '';
        foreach (array_reverse($season['seasons']) as $entry) {
            $id = (int) $entry['id'];
            $options .= sprintf(
                '<option value="%d"%s>%s</option>',
                $id,
                $id === $season['selected'] ? ' selected' : '',
                $this->escape($id === $season['current']
                    ? $this->t->t('layout.season_current', ['season' => (string) $entry['name']])
                    : (string) $entry['name']),
            );
        }

        $other = $season['selected'] !== null && $season['selected'] !== $season['current'];
        $hint  = $other ? '<span class="admin-season__hint" role="status">' . $this->escape($this->t->t('layout.season_not_current')) . '</span>' : '';

        return sprintf(
            '<form class="admin-season%s" method="get" action="%s">%s'
            . '<label for="admin-season">%s</label><select id="admin-season" name="season">%s</select>'
            . '<button type="submit" class="button button--secondary admin-season__apply">%s</button>%s</form>',
            $other ? ' admin-season--other' : '',
            $this->escape($area),
            $tab,
            $this->escape($this->t->t('layout.season')),
            $options,
            $this->escape($this->t->t('layout.season_switch')),
            $hint,
        );
    }

    /**
     * A navigation item is active if the page is exactly its path; the matchday
     * detail view belongs to "Spieltage", the team page to "Ligatabelle".
     */
    private function isActive(string $navPath, string $currentPath): bool
    {
        return $navPath === $currentPath
            || ($navPath === '/matchdays' && str_starts_with($currentPath, '/matchday/'))
            || ($navPath === '/table' && str_starts_with($currentPath, '/team/'));
    }

    /**
     * Renders the toggle for style, colour mode and language. Without
     * JavaScript the form is submitted; with JavaScript style and mode apply
     * immediately, a new language reloads the page, and the button is hidden.
     */
    private function renderToggle(string $back): string
    {
        $styles = '';
        foreach (Appearance::STYLES as $value => $label) {
            $styles .= $this->option($value, $label, $value === $this->appearance->style);
        }

        $modes = '';
        foreach (array_keys(Appearance::MODES) as $value) {
            $modes .= $this->option($value, $this->t->t(Appearance::MODES[$value]), $value === $this->appearance->mode);
        }

        // Every language in its own name, so that a visitor finds theirs.
        $languages = '';
        foreach (Languages::names() as $value => $name) {
            $languages .= sprintf(
                '<option value="%s" lang="%s"%s>%s</option>',
                $this->escape($value),
                $this->escape($value),
                $value === $this->t->language() ? ' selected' : '',
                $this->escape($name),
            );
        }

        $backEscaped = $this->escape($back);
        $label = fn (string $key): string => $this->escape($this->t->t($key));

        return <<<HTML
            <form class="appearance" method="post" action="/appearance" data-appearance>
                <input type="hidden" name="return_to" value="{$backEscaped}">
                <label class="appearance__field">{$label('appearance.style')}
                    <select name="style">{$styles}</select>
                </label>
                <label class="appearance__field">{$label('appearance.mode')}
                    <select name="mode">{$modes}</select>
                </label>
                <label class="appearance__field">{$label('appearance.language')}
                    <select name="language">{$languages}</select>
                </label>
                <button type="submit" class="appearance__submit">{$label('appearance.apply')}</button>
            </form>
            HTML;
    }

    private function option(string $value, string $label, bool $selected): string
    {
        return sprintf(
            '<option value="%s"%s>%s</option>',
            $this->escape($value),
            $selected ? ' selected' : '',
            $this->escape($label),
        );
    }

    /**
     * Inline icons of the navigation (line SVG in text colour, decorative).
     */
    private function icon(string $name): string
    {
        $paths = match ($name) {
            'start'     => '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
            'matchdays' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
            'table'     => '<path d="M4 6h16M4 12h16M4 18h10"/>',
            'honours'   => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/>',
            'rules'     => '<path d="M5 4h11l3 3v13H5z"/><path d="M9 10h6M9 14h6"/>',
            default     => '',
        };

        if ($paths === '') {
            return '';
        }

        return '<svg class="nav__icon" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" '
            . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
    }

    /**
     * Bildmarke: stilisierte Bowlingkugel in Textfarbe.
     */
    private function logo(): string
    {
        return '<svg class="header__logo" viewBox="0 0 28 28" width="28" height="28" aria-hidden="true">'
            . '<circle cx="14" cy="14" r="13" fill="currentColor"/>'
            . '<circle cx="10" cy="10" r="2.2" class="header__logo-hole"/>'
            . '<circle cx="15.5" cy="9" r="2.2" class="header__logo-hole"/>'
            . '<circle cx="13" cy="14" r="2.2" class="header__logo-hole"/>'
            . '</svg>';
    }

    private function pathName(string $address): string
    {
        $pathName = parse_url($address, PHP_URL_PATH);

        return is_string($pathName) && $pathName !== '' ? rtrim($pathName, '/') ?: '/' : '/';
    }

    /**
     * Escapes a value for the HTML text and attribute context (UTF-8).
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Asset path with its modification time as version (`?v=…`): the web server
     * lets browsers cache assets, so after an update they must load the new
     * version at once.
     */
    public static function versioned(string $path): string
    {
        $file = dirname(__DIR__, 2) . '/public' . $path;
        $time = is_file($file) ? filemtime($file) : false;

        return $time !== false ? $path . '?v=' . $time : $path;
    }
}
