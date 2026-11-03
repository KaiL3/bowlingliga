/**
 * Accessible tabs for the matchday detail view and the rules page.
 *
 * Progressive enhancement: without JavaScript all panels are visible and fully
 * usable (the CSS only hides panels once the component is marked as
 * "enhanced"). With JavaScript exactly one panel is shown and switched via the
 * tabs or the arrow keys (WAI-ARIA tabs pattern).
 *
 * Local asset without external dependencies (R36.8).
 */
(function () {
    'use strict';

    function initTabs(container) {
        var tabs = Array.prototype.slice.call(
            container.querySelectorAll('[role="tab"]')
        );
        var panels = Array.prototype.slice.call(
            container.querySelectorAll('[role="tabpanel"]')
        );

        if (tabs.length === 0 || panels.length === 0) {
            return;
        }

        // Mark the component as enhanced; the CSS then hides the inactive
        // panels.
        container.classList.add('tabs--js');

        function activate(index) {
            tabs.forEach(function (tab, i) {
                var selected = i === index;
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.classList.toggle('tabs__tab--active', selected);
                tab.tabIndex = selected ? 0 : -1;
            });
            panels.forEach(function (panel, i) {
                panel.hidden = i !== index;
            });
        }

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () {
                activate(index);
                tab.focus();
            });

            tab.addEventListener('keydown', function (event) {
                var next = null;
                if (event.key === 'ArrowRight') {
                    next = (index + 1) % tabs.length;
                } else if (event.key === 'ArrowLeft') {
                    next = (index - 1 + tabs.length) % tabs.length;
                } else if (event.key === 'Home') {
                    next = 0;
                } else if (event.key === 'End') {
                    next = tabs.length - 1;
                }

                if (next !== null) {
                    event.preventDefault();
                    activate(next);
                    tabs[next].focus();
                }
            });
        });

        // A link to a panel (e.g. /rules#rules-panel-handicap) opens
        // that tab; otherwise the first one.
        var linked = panels.findIndex(function (panel) {
            return panel.id !== '' && '#' + panel.id === window.location.hash;
        });
        activate(linked >= 0 ? linked : 0);
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.slice
            .call(document.querySelectorAll('[data-tabs]'))
            .forEach(initTabs);
    });
})();
