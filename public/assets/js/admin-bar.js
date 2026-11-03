/*
 * Admin bar and tab bars: on narrow screens they can be swiped sideways. This
 * script scrolls the active entry into view – immediately and again after the
 * page has loaded, because the widths still change once the web font is in.
 * Without JavaScript the bars stay fully swipeable.
 */
(function () {
    'use strict';

    function centreActive() {
        document.querySelectorAll('.admin-bar__list, .tab-bar').forEach(function (list) {
            var active = list.querySelector('[aria-current="page"]');
            if (!active || list.scrollWidth <= list.clientWidth) {
                return;
            }

            list.scrollLeft = active.offsetLeft - (list.clientWidth - active.offsetWidth) / 2;
        });
    }

    centreActive();
    window.addEventListener('load', centreActive);

    // Season selection: apply a change immediately, the button is only needed
    // without JavaScript.
    document.querySelectorAll('.admin-season').forEach(function (form) {
        var select = form.querySelector('select');
        var button = form.querySelector('.admin-season__apply');
        if (!select) {
            return;
        }
        if (button) {
            button.hidden = true;
        }
        select.addEventListener('change', function () {
            form.submit();
        });
    });
})();
