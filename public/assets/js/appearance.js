/*
 * Switch for the style, colour mode and language of the website.
 *
 * Without JavaScript the switch sends a form to /appearance; the server sets
 * the cookies and redirects back. With JavaScript style and mode take effect
 * immediately: the script sets data-style/data-mode on the <html> element,
 * stores the choice in the same cookies (bl_style, bl_mode; appearance values
 * only) and hides the apply button. A new language sends the form at once,
 * because the texts are rendered by the server (cookie bl_lang).
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-appearance]');
    if (!form) {
        return;
    }

    var root = document.documentElement;
    var oneYear = 365 * 24 * 60 * 60;

    function store(name, value) {
        document.cookie = name + '=' + encodeURIComponent(value) + '; max-age=' + oneYear + '; path=/; samesite=lax';
    }

    var submit = form.querySelector('.appearance__submit');
    if (submit) {
        submit.hidden = true;
    }

    form.addEventListener('change', function (event) {
        var field = event.target;
        if (field.name === 'style') {
            root.dataset.style = field.value;
            store('bl_style', field.value);
        } else if (field.name === 'mode') {
            root.dataset.mode = field.value;
            store('bl_mode', field.value);
        } else if (field.name === 'language') {
            // The texts come from the server: send the form, it stores the
            // choice and reloads the page.
            form.submit();
        }
    });
})();
