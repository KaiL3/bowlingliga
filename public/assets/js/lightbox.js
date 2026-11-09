/*
 * Shows an original score sheet in an overlay on the same page (R15.6).
 *
 * Links with the class js-lightbox open their image in the dialog rendered
 * by Presentation/Lightbox. Closing: the × button, Escape, a click next to
 * the image and the back button or swipe of the phone – opening adds a
 * history entry, so "back" closes the overlay instead of leaving the page.
 * A click on the image switches between fitting the screen and the original
 * size. Without JavaScript the link opens the file itself.
 */
(function () {
    'use strict';

    var dialog = document.querySelector('.js-lightbox-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') {
        return;
    }
    var image = dialog.querySelector('.lightbox__image');
    var title = dialog.querySelector('.lightbox__title');
    var opener = null;

    document.querySelectorAll('a.js-lightbox').forEach(function (link) {
        link.addEventListener('click', function (event) {
            // New tab, new window or download stay the browser's business.
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
                return;
            }
            event.preventDefault();
            open(link);
        });
    });

    function open(link) {
        opener = link;
        title.textContent = link.dataset.lightboxTitle || '';
        image.classList.remove('lightbox__image--full');
        image.alt = title.textContent;
        image.src = link.href;
        dialog.showModal();
        history.pushState({ lightbox: true }, '');
    }

    /** Closes via the history entry, so "back" is not needed twice afterwards. */
    function close() {
        if (history.state && history.state.lightbox) {
            history.back();
        } else {
            dialog.close();
        }
    }

    window.addEventListener('popstate', function () {
        if (dialog.open) {
            dialog.close();
        }
    });

    dialog.addEventListener('close', function () {
        image.removeAttribute('src');
        if (opener) {
            opener.focus();
        }
    });

    dialog.addEventListener('cancel', function (event) {
        event.preventDefault();
        close();
    });

    dialog.querySelector('.lightbox__close').addEventListener('click', close);

    dialog.addEventListener('click', function (event) {
        if (event.target === dialog || event.target.classList.contains('lightbox__body')) {
            close();
        }
    });

    image.addEventListener('click', function () {
        image.classList.toggle('lightbox__image--full');
    });

    // A file that is no image after all: open it the plain way.
    image.addEventListener('error', function () {
        if (dialog.open && image.getAttribute('src')) {
            var href = image.getAttribute('src');
            dialog.close();
            window.location.href = href;
        }
    });
})();
