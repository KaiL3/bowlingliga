<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\I18n\Translator;
use Composer\InstalledVersions;
use Bowlingliga\Security\HtmlSanitizer;

/**
 * Markup that turns a text area for content into the rich text editor
 * (TinyMCE, public/assets/js/rich-text.js). The editor offers exactly the
 * elements the server keeps ({@see HtmlSanitizer::ALLOWED_ELEMENTS});
 * without JavaScript the text area stays as it is.
 */
final class RichTextEditor
{
    private const EDITOR = '/assets/vendor/tinymce/tinymce.min.js';
    private const SETUP  = '/assets/js/rich-text.js';

    /**
     * Attributes for the <textarea> (with a leading space). The names of the
     * paragraph formats come from the catalog: TinyMCE does not translate
     * names it is given.
     */
    public static function attributes(Translator $t): string
    {
        $version = self::version();
        $formats = $t->t('editor.paragraph') . '=p';
        foreach ([2, 3, 4] as $level) {
            $formats .= '; ' . $t->t('editor.heading', ['level' => $level]) . '=h' . $level;
        }

        return sprintf(
            ' data-rich-text data-allowed="%s" data-block-formats="%s" data-editor-version="%s"',
            self::e(HtmlSanitizer::ALLOWED_ELEMENTS),
            self::e($formats),
            self::e($version),
        );
    }

    /**
     * Version of the installed TinyMCE package: names the editor files in
     * their URLs, so browsers keep them until the next update of TinyMCE
     * (the files are copied anew on every deployment).
     */
    private static function version(): string
    {
        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('tinymce/tinymce')
            ? (string) InstalledVersions::getPrettyVersion('tinymce/tinymce')
            : '';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Script tags of the editor; once per page, after the text areas.
     */
    public static function scripts(): string
    {
        return sprintf(
            '<script src="%s" defer></script><script src="%s" defer></script>',
            htmlspecialchars(self::EDITOR . '?v=' . rawurlencode(self::version()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars(Layout::versioned(self::SETUP), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }
}
