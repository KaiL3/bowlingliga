<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

/**
 * Shared components of the admin pages in the design of the public pages
 * (styles "Lane"/"Bento", light/dark; R36.1–R36.5).
 *
 * Returns small, escaped HTML fragments: page header, card, messages and
 * status chip. The corresponding classes are in the section
 * "Adminbereich" of `app.css`. Free of side effects.
 */
final class AdminComponents
{
    /** @var array<string, string> completion status → modifier of the status chip */
    private const STATUS = [
        'open'        => 'open',
        'in_progress' => 'in-progress',
        'complete'    => 'complete',
    ];

    /**
     * Page header: small kicker line, title and optionally a line with
     * additional details or actions (already safe HTML).
     */
    public static function pageHeader(string $title, ?string $via = null, string $extraHtml = ''): string
    {
        return '<header class="page-header page-header--admin">'
            . ($via !== null ? '<p class="page-header__kicker">' . self::e($via) . '</p>' : '')
            . '<h1>' . self::e($title) . '</h1>'
            . ($extraHtml !== '' ? '<div class="page-header__extra">' . $extraHtml . '</div>' : '')
            . '</header>';
    }

    /**
     * Card with heading; `$lower` appears small next to the title, `$content`
     * is already safe HTML.
     */
    public static function card(string $title, string $content, string $lower = '', string $cssClass = ''): string
    {
        return sprintf(
            '<section class="card%s"><div class="card__header"><h2>%s</h2>%s</div>%s</section>',
            $cssClass !== '' ? ' ' . self::e($cssClass) : '',
            self::e($title),
            $lower !== '' ? '<span class="card__sub">' . self::e($lower) . '</span>' : '',
            $content,
        );
    }

    /**
     * Success and error message (each escaped), if present.
     */
    public static function notes(?string $message, ?string $error): string
    {
        $html = '';
        if ($message !== null) {
            $html .= sprintf('<p class="notice notice--ok" role="status">%s</p>', self::e($message));
        }
        if ($error !== null) {
            $html .= sprintf('<p class="error" role="alert">%s</p>', self::e($error));
        }

        return $html;
    }

    /**
     * Status chip of the completion status of a fixture (R30.2–R30.4).
     */
    public static function status(string $status, ValueLabels $labels): string
    {
        $modifier = self::STATUS[$status] ?? null;

        return sprintf(
            '<span class="status%s">%s</span>',
            $modifier !== null ? ' status--' . $modifier : '',
            self::e($labels->completionStatus($status)),
        );
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
