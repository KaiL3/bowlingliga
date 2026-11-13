<?php

declare(strict_types=1);

/**
 * Publishes the rich text editor TinyMCE: copies the files the editor of the
 * admin area loads from the Composer packages tinymce/tinymce and
 * mklkj/tinymce-i18n (German language pack) to public/assets/vendor/tinymce/,
 * the only place the web server delivers from.
 *
 * Runs by itself after `composer install` and `composer update` (scripts in
 * composer.json), so a deployment needs nothing extra; by hand:
 *   php bin/publish-tinymce.php
 *
 * The target directory is replaced completely. TinyMCE is used under the
 * GPL-2.0-or-later (license_key 'gpl'); its license.md is published along.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** Files of tinymce/tinymce that the editor loads (see public/assets/js/rich-text.js). */
const TINYMCE_FILES = [
    'license.md',
    'notices.txt',
    'tinymce.min.js',
    'themes/silver/theme.min.js',
    'models/dom/model.min.js',
    'icons/default/icons.min.js',
    'skins/ui/oxide/skin.min.css',
    'skins/ui/oxide/content.min.css',
    'skins/ui/oxide-dark/skin.min.css',
    'skins/ui/oxide-dark/content.min.css',
    'skins/content/default/content.min.css',
    'skins/content/dark/content.min.css',
    'plugins/autolink/plugin.min.js',
    'plugins/image/plugin.min.js',
    'plugins/link/plugin.min.js',
    'plugins/lists/plugin.min.js',
];

/** Language packs of mklkj/tinymce-i18n (folder langs<major version>), published under langs/. */
const LANGUAGE_FILES = ['de.js'];

$root    = dirname(__DIR__);
$tinymce = "$root/vendor/tinymce/tinymce";
$i18n    = "$root/vendor/mklkj/tinymce-i18n";
$target  = "$root/public/assets/vendor/tinymce";

$version = json_decode((string) @file_get_contents("$tinymce/package.json"), true)['version'] ?? null;
if (! is_string($version) || ! is_dir($i18n)) {
    fwrite(STDERR, "TinyMCE or its language pack is missing in vendor/ – run composer install first.\n");
    exit(1);
}
$major = explode('.', $version)[0];

removeDirectory($target);
foreach (TINYMCE_FILES as $file) {
    copyFile("$tinymce/$file", "$target/$file");
}
foreach (LANGUAGE_FILES as $file) {
    copyFile("$i18n/langs$major/$file", "$target/langs/$file");
}

echo "TinyMCE $version published to public/assets/vendor/tinymce/.\n";

function copyFile(string $from, string $to): void
{
    if (! is_file($from)) {
        fwrite(STDERR, "Missing in the package: $from\n");
        exit(1);
    }
    if (! is_dir(dirname($to))) {
        mkdir(dirname($to), 0755, true);
    }
    copy($from, $to);
}

function removeDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}
