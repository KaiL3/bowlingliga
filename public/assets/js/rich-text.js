// Rich text editor for the content of the website (home page posts, rules):
// turns every <textarea data-rich-text> into a TinyMCE editor. Without
// JavaScript the text area stays and takes the HTML as before.
//
// TinyMCE is self-hosted under public/assets/vendor/tinymce (GPL-2.0-or-later,
// license_key 'gpl', no connection to Tiny's servers). The toolbar offers only
// what the server keeps: the element list comes from the text area
// (data-allowed = HtmlSanitizer::ALLOWED_ELEMENTS), and everything else is
// removed while editing and pasting already.
(() => {
    'use strict';

    const areas = document.querySelectorAll('textarea[data-rich-text]');
    if (areas.length === 0 || typeof window.tinymce === 'undefined') {
        return;
    }

    const base = '/assets/vendor/tinymce';
    const root = document.documentElement;
    const dark = root.dataset.mode === 'dark'
        || (root.dataset.mode !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    const german = (root.lang || '').toLowerCase().startsWith('de');

    areas.forEach((area) => {
        // The editor keeps the text area hidden; the server checks the content.
        area.removeAttribute('required');

        window.tinymce.init({
            target: area,
            license_key: 'gpl',
            base_url: base,
            suffix: '.min',
            // Loaded files carry the version, so the long asset cache applies.
            cache_suffix: '?v=' + (area.dataset.editorVersion || ''),
            language: german ? 'de' : undefined,
            language_url: german ? base + '/langs/de.js' : undefined,
            skin: dark ? 'oxide-dark' : 'oxide',
            content_css: dark ? 'dark' : 'default',
            branding: false,
            promotion: false,
            menubar: false,
            toolbar_mode: 'wrap',
            elementpath: false,
            statusbar: true,
            resize: true,
            min_height: Number(area.getAttribute('rows') || 10) * 28,
            plugins: 'lists link autolink',
            toolbar: 'undo redo | blocks | bold italic underline | bullist numlist blockquote | link unlink | removeformat',
            block_formats: area.dataset.blockFormats,
            valid_elements: area.dataset.allowed,
            valid_styles: {},
            invalid_styles: '*',
            paste_data_images: false,
            link_default_protocol: 'https',
            link_target_list: false,
            link_title: true,
            convert_urls: false,
            setup: (editor) => {
                // Keep the text area current, so leaving the form or a failed
                // submit never loses what was typed.
                editor.on('change input undo redo', () => editor.save());
            },
        });
    });
})();
