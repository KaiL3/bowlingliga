// Rich text editor for the content of the website (home page posts, rules):
// turns every <textarea data-rich-text> into a TinyMCE editor. Without
// JavaScript the text area stays and takes the HTML as before.
//
// TinyMCE is self-hosted under public/assets/vendor/tinymce (GPL-2.0-or-later,
// license_key 'gpl', no connection to Tiny's servers). The toolbar offers only
// what the server keeps: the element list comes from the text area
// (data-allowed = HtmlSanitizer::ALLOWED_ELEMENTS), and everything else is
// removed while editing and pasting already.
//
// Images (R42) are uploaded to /admin/media/upload (also when dropped or
// pasted), placed by a class and chosen again from /admin/media/list; the
// editor shows them with the same rules as the public pages.
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

    // Placement of images inside the editor, as in app.css (.prose).
    const imageStyle = 'img{max-width:100%;height:auto}'
        + 'figure{margin:1rem 0}figcaption{margin-top:.375rem;font-size:.875rem;opacity:.75}'
        + 'img.image--center{display:block;margin-inline:auto}'
        + 'figure:has(>.image--center){width:fit-content;max-width:100%;margin-inline:auto;text-align:center}'
        + 'img.image--full,figure:has(>.image--full){display:block;width:100%}'
        + 'img.image--left,figure:has(>.image--left){float:left;max-width:50%;margin:.25rem 1.25rem .75rem 0}'
        + 'img.image--right,figure:has(>.image--right){float:right;max-width:50%;margin:.25rem 0 .75rem 1.25rem}'
        + 'figure>img.image--left,figure>img.image--right,figure>img.image--center,figure>img'
        + '{display:block;float:none;max-width:100%;margin:0}';

    // Upload of an image chosen, dropped or pasted in the editor.
    const upload = (form) => (blobInfo) => {
        const body = new FormData();
        body.append('file', blobInfo.blob(), blobInfo.filename());
        const token = form ? form.querySelector('input[name="csrf_token"]') : null;
        body.append('csrf_token', token ? token.value : '');

        return fetch('/admin/media/upload', {
            method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' },
        })
            .then((response) => response.json().catch(() => ({})))
            .then((data) => {
                if (typeof data.location === 'string') {
                    return data.location;
                }
                throw { message: typeof data.error === 'string' ? data.error : 'Upload failed', remove: true };
            });
    };

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
            plugins: 'lists link autolink image',
            toolbar: 'undo redo | blocks | bold italic underline | bullist numlist blockquote | link unlink image | removeformat',
            image_caption: true,
            image_description: true,
            image_dimensions: true,
            image_advtab: false,
            image_uploadtab: true,
            image_class_list: JSON.parse(area.dataset.imageClasses || '[]'),
            image_list: '/admin/media/list',
            images_upload_handler: upload(area.form),
            images_file_types: 'jpeg,jpg,png,webp',
            automatic_uploads: true,
            images_reuse_filename: false,
            object_resizing: 'img',
            content_style: imageStyle,
            block_formats: area.dataset.blockFormats,
            valid_elements: area.dataset.allowed,
            valid_styles: {},
            invalid_styles: '*',
            paste_data_images: true,
            link_default_protocol: 'https',
            link_target_list: false,
            link_title: true,
            convert_urls: false,
            setup: (editor) => {
                // The image dialog: its source is filled by the upload or the
                // image list and cannot be typed (R42.4), the server checks it
                // anyway.
                // A tab change builds the fields of the tab anew, enabled –
                // also when the upload switches back to "General" – so the
                // source is locked on opening and after every tab change.
                // The window manager exists only from the start of the editor on.
                editor.on('init', () => {
                    const windowManager = editor.windowManager;
                    const open = windowManager.open.bind(windowManager);
                    windowManager.open = (spec, params) => {
                        if (!spec || !spec.initialData || !('src' in spec.initialData)) {
                            return open(spec, params);
                        }
                        const lock = (api) => {
                            api.setEnabled('src', false);
                            queueMicrotask(() => api.setEnabled('src', false));
                        };
                        const onTabChange = spec.onTabChange;
                        const dialog = open({
                            ...spec,
                            onTabChange: (api, details) => {
                                if (onTabChange) {
                                    onTabChange(api, details);
                                }
                                lock(api);
                            },
                        }, params);
                        lock(dialog);

                        return dialog;
                    };
                });
                // Keep the text area current, so leaving the form or a failed
                // submit never loses what was typed.
                editor.on('change input undo redo', () => editor.save());
            },
        });
    });
})();
