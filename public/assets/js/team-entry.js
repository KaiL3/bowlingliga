/**
 * Result entry by the teams (R37): small helps for the guided entry.
 *
 * Progressive enhancement: without JavaScript every step is a plain form
 * with "Weiter" and "Zurück". With JavaScript
 * - the net field of a player is only shown and sent while he is set to
 *   "spielt" (the server ignores it otherwise),
 * - the confirmation step is fetched again every 15 seconds, so that the
 *   opponent's entry and confirmation show up without a reload – but not
 *   while a reason for sending back is being written.
 * During the entry of a game nothing is reloaded, so no typed value is lost.
 *
 * Local asset without external dependencies (R36.8).
 */
(function () {
    'use strict';

    var REFRESH_MS = 15000;

    var root = document.querySelector('.js-team-entry');
    if (!root) {
        return;
    }

    function fieldFor(select) {
        var player = select.closest('.wizard-player');
        return player ? player.querySelector('.js-net-field') : null;
    }

    function toggle(select) {
        var field = fieldFor(select);
        if (field) {
            field.hidden = select.value !== 'plays';
            field.querySelector('input').disabled = field.hidden;
        }
    }

    root.querySelectorAll('.js-mark').forEach(toggle);

    root.addEventListener('change', function (event) {
        if (event.target.classList && event.target.classList.contains('js-mark')) {
            toggle(event.target);
            if (event.target.value === 'plays') {
                var input = fieldFor(event.target);
                input = input ? input.querySelector('input') : null;
                if (input) {
                    input.focus();
                }
            }
        }
    });

    if (root.getAttribute('data-refresh') !== '1') {
        return;
    }

    var code = root.getAttribute('data-code') || '';
    var step = root.getAttribute('data-step') || '';

    function writing() {
        var note = root.querySelector('textarea');
        return note && (note.value !== '' || document.activeElement === note);
    }

    window.setInterval(function () {
        if (writing()) {
            return;
        }
        fetch('/entry?part=1&step=' + encodeURIComponent(step) + '&code=' + encodeURIComponent(code), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'fetch' }
        }).then(function (response) {
            return response.ok ? response.text() : '';
        }).then(function (html) {
            if (html !== '' && !writing()) {
                root.innerHTML = html;
            }
        }).catch(function () {
            // Next attempt with the next refresh (poor network in the bowling alley).
        });
    }, REFRESH_MS);
}());
