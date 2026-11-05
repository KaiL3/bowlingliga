/*
 * Live calculation of the virtual score sheet (task 35.3).
 *
 * Mirrors the calculation of src/Presentation/ScoreSheetCalculator.php:
 * gross = net + handicap, total net/gross per row over the games entered in
 * it, number of results per game (exactly 4: players are substituted between
 * games only), net/handicap/gross sum per game once it has 4 results, game
 * points (default 2/1/0) from comparing the gross of both teams, bonus points
 * by the gross scale and total points, both by the points rules of the season
 * (data-points, data-bonus; R17.11). On the first matchday without an average the
 * handicap follows from the games of the evening. For blind games the sheet
 * value is checked against the reference "average − 10" (R27.6, R27.11).
 *
 * The values are only displayed; the server-side calculation after saving is
 * authoritative. Without JavaScript the page shows the values the server
 * calculated for the last saved state.
 */
(function () {
    'use strict';

    var sheet = document.querySelector('.js-score-sheet');
    if (!sheet) {
        return;
    }

    var formula = sheet.dataset.factor
        ? { factor: parseFloat(sheet.dataset.factor), target: parseInt(sheet.dataset.target, 10), max: parseInt(sheet.dataset.max, 10) }
        : null;

    function integers(text, fallback) {
        var values = String(text || '').split(',').map(function (v) { return parseInt(v, 10); });
        return values.length === fallback.length && values.every(function (v) { return !isNaN(v); }) ? values : fallback;
    }

    var gamePoints = integers(sheet.dataset.points, [2, 1, 0]);
    var scale = integers(sheet.dataset.bonus, [2100, 100, 6]);

    function number(text) {
        var t = String(text).trim();
        if (!/^\d{1,3}$/.test(t)) {
            return null;
        }
        var n = parseInt(t, 10);
        return n <= 300 ? n : null;
    }

    function show(element, value) {
        if (element) {
            element.textContent = value === null ? '' : String(value);
        }
    }

    function sum(list) {
        return list.reduce(function (a, b) { return a + b; }, 0);
    }

    /* Handicap as in HandicapModule::calculateHandicap (R12.8, R12.10, R12.15, R12.16):
       formula on the exact average, only the result is rounded half up. */
    function handicap(average) {
        if (formula === null || average === null) {
            return null;
        }
        var raw = Math.round(parseFloat(((formula.target - average) * formula.factor).toFixed(9)));
        if (raw > 300) {
            return null;
        }
        return Math.min(Math.max(raw, 0), formula.max);
    }

    /* Bonus points as in BonusPointsScale::points (R17.6–R17.8, R17.11). */
    function bonus(gross) {
        var points = gross <= scale[0] ? 1 : 2 + Math.floor((gross - scale[0] - 1) / scale[1]);
        return Math.min(points, scale[2]);
    }

    function calculateTeam(team) {
        var rows = [];
        team.querySelectorAll('.js-row').forEach(function (row) {
            // Blind per game (R27.5).
            var slotBlind = row.dataset.blind === '1';
            var blind = { 1: slotBlind, 2: slotBlind, 3: slotBlind };
            row.querySelectorAll('.js-blind').forEach(function (toggle) {
                blind[toggle.dataset.number] = slotBlind || toggle.checked;
            });
            var avg = row.dataset.avg !== '' ? parseFloat(row.dataset.avg) : null;

            var values = { 1: null, 2: null, 3: null };
            var inputs = {};
            row.querySelectorAll('.js-net').forEach(function (field) {
                values[field.dataset.number] = number(field.value);
                inputs[field.dataset.number] = field;
            });
            row.querySelectorAll('.js-fix').forEach(function (cell) {
                values[cell.dataset.number] = parseInt(cell.dataset.value, 10);
            });

            // First matchday: handicap only from the games actually played (R12.9, R27.13).
            var hdc = row.dataset.hdc !== '' ? parseInt(row.dataset.hdc, 10) : null;
            if (hdc === null && row.dataset.first === '1' && !slotBlind) {
                var played = [1, 2, 3]
                    .filter(function (nr) { return !blind[nr]; })
                    .map(function (nr) { return values[nr]; })
                    .filter(function (v) { return v !== null; });
                hdc = played.length ? handicap(sum(played) / played.length) : null;
            }

            // Blind reference: average − 10 of the row's player, limited to 0–300;
            // only deviations of blind games are flagged (R27.6, R27.11).
            var anyBlind = blind[1] || blind[2] || blind[3];
            var reference = anyBlind && avg !== null ? Math.min(Math.max(Math.round(avg - 10), 0), 300) : null;
            Object.keys(inputs).forEach(function (nr) {
                var deviation = blind[nr] && reference !== null && values[nr] !== null && values[nr] !== reference;
                inputs[nr].classList.toggle('score-sheet__field--check', deviation);
                inputs[nr].classList.toggle('score-sheet__field--blind', blind[nr]);
            });
            var referenceLabel = row.querySelector('.js-reference');
            if (reference !== null) {
                if (!referenceLabel) {
                    referenceLabel = document.createElement('small');
                    referenceLabel.className = 'js-reference';
                    row.querySelector('th').append(' ', referenceLabel);
                }
                referenceLabel.textContent = 'Ref. ' + reference;
            } else if (referenceLabel) {
                referenceLabel.remove();
            }

            var gross = {};
            [1, 2, 3].forEach(function (nr) {
                gross[nr] = values[nr] !== null ? values[nr] + (hdc || 0) : null;
            });
            var entered = [1, 2, 3].filter(function (nr) { return values[nr] !== null; });

            show(row.querySelector('.js-hdc'), hdc);
            show(row.querySelector('.js-net-total'), entered.length ? sum(entered.map(function (nr) { return values[nr]; })) : null);
            show(row.querySelector('.js-gross-total'), entered.length ? sum(entered.map(function (nr) { return gross[nr]; })) : null);

            rows.push({ hdc: hdc, values: values, gross: gross });
        });

        var games = {};
        [1, 2, 3].forEach(function (nr) {
            var playing = rows.filter(function (r) { return r.values[nr] !== null; });
            games[nr] = playing.length === 4
                ? {
                    net: sum(playing.map(function (r) { return r.values[nr]; })),
                    hdc: sum(playing.map(function (r) { return r.hdc || 0; })),
                    gross: sum(playing.map(function (r) { return r.gross[nr]; })),
                    points: null
                }
                : { net: null, hdc: null, gross: null, points: null };
            games[nr].count = playing.length;
        });

        var all = [1, 2, 3].every(function (nr) { return games[nr].gross !== null; });
        return {
            element: team,
            games: games,
            net: all ? sum([1, 2, 3].map(function (nr) { return games[nr].net; })) : null,
            hdc: all ? sum([1, 2, 3].map(function (nr) { return games[nr].hdc; })) : null,
            gross: all ? sum([1, 2, 3].map(function (nr) { return games[nr].gross; })) : null
        };
    }

    function calculate() {
        var teams = Array.prototype.map.call(sheet.querySelectorAll('.js-team'), calculateTeam);

        if (teams.length === 2) {
            [1, 2, 3].forEach(function (nr) {
                var a = teams[0].games[nr].gross;
                var b = teams[1].games[nr].gross;
                if (a === null || b === null) {
                    return;
                }
                teams[0].games[nr].points = a > b ? gamePoints[0] : (a === b ? gamePoints[1] : gamePoints[2]);
                teams[1].games[nr].points = b > a ? gamePoints[0] : (a === b ? gamePoints[1] : gamePoints[2]);
            });
        }

        teams.forEach(function (t) {
            var el = t.element;
            el.querySelectorAll('.js-count').forEach(function (cell) {
                var count = t.games[cell.dataset.number].count;
                cell.textContent = count + '/4';
                cell.classList.toggle('sheet__count--ok', count === 4);
                cell.classList.toggle('sheet__count--over', count > 4);
            });
            ['net', 'hdc', 'gross', 'points'].forEach(function (kind) {
                el.querySelectorAll('.js-sum-' + kind).forEach(function (cell) {
                    show(cell, t.games[cell.dataset.number][kind]);
                });
            });

            var points = [1, 2, 3].map(function (nr) { return t.games[nr].points; });
            var gamePoints = points.every(function (p) { return p !== null; }) ? sum(points) : null;
            var bonusPoints = t.gross !== null ? bonus(t.gross) : null;

            show(el.querySelector('.js-sum-net-total'), t.net);
            show(el.querySelector('.js-sum-hdc-total'), t.hdc);
            show(el.querySelector('.js-sum-gross-total'), t.gross);
            show(el.querySelector('.js-sum-points-total'), gamePoints);
            show(el.querySelector('.js-bonus'), bonusPoints);
            show(el.querySelector('.js-total'), gamePoints !== null && bonusPoints !== null ? gamePoints + bonusPoints : null);
        });
    }

    /* Blind player check when ticking "Blind" (R27.4, R27.15): a player who
       plays themselves that evening may have their remaining games blind
       (injured, without substitute). Otherwise the player must be the roster
       player with the most games played so far (column G) among those who do
       not play and do not already serve as blind player. If allowed, the
       blind value (average − 10, R27.6; for a new player without an average
       the ghost score, R27.18) is entered and stays editable; if not,
       a dialog explains why and the tick is removed. Unticking clears the
       value. The server checks the same rule again when confirming. */
    var dialog = document.querySelector('.js-blind-dialog');

    function showMessage(text) {
        if (dialog && typeof dialog.showModal === 'function') {
            dialog.querySelector('.js-blind-dialog-text').textContent = text;
            dialog.showModal();
        } else {
            window.alert(text);
        }
    }

    function rowState(row) {
        var ownGame = false;
        var blind = false;
        row.querySelectorAll('.js-net').forEach(function (field) {
            var toggle = row.querySelector('.js-blind[data-number="' + field.dataset.number + '"]');
            var isBlind = toggle !== null && toggle.checked;
            blind = blind || isBlind;
            ownGame = ownGame || (!isBlind && field.value.trim() !== '');
        });
        return { ownGame: ownGame, blind: blind };
    }

    function blindPlayerError(team, row) {
        if (rowState(row).ownGame) {
            return null;
        }
        var games = parseInt(row.dataset.games, 10) || 0;
        var better = null;
        team.querySelectorAll('.js-row[data-player]').forEach(function (other) {
            if (other === row) {
                return;
            }
            var state = rowState(other);
            var otherGames = parseInt(other.dataset.games, 10) || 0;
            if (!state.ownGame && !state.blind && otherGames > games && (better === null || otherGames > better.games)) {
                better = { name: other.dataset.name, games: otherGames };
            }
        });
        if (better === null) {
            return null;
        }
        return fill(sheet.dataset.textWrongBlind || '', {
            expected: better.name,
            expected_games: better.games,
            chosen: row.dataset.name,
            chosen_games: games
        });
    }

    // Replaces the {placeholders} of a text from the page (in the visitor's language).
    function fill(template, values) {
        return template.replace(/\{(\w+)\}/g, function (match, name) {
            return values[name] !== undefined ? String(values[name]) : match;
        });
    }

    function updateBadge(row) {
        var cell = row.querySelector('.js-blind-badge');
        if (!cell) {
            return;
        }
        var state = rowState(row);
        cell.textContent = '';
        if (state.blind && !state.ownGame) {
            var badge = document.createElement('span');
            badge.className = 'badge badge--blind';
            badge.title = sheet.dataset.textBlindPlayer || '';
            badge.textContent = sheet.dataset.textBlind || '';
            cell.appendChild(badge);
        }
    }

    sheet.addEventListener('change', function (event) {
        var toggle = event.target;
        if (!toggle.classList || !toggle.classList.contains('js-blind')) {
            return;
        }
        var row = toggle.closest('.js-row');
        var field = row.querySelector('.js-net[data-number="' + toggle.dataset.number + '"]');

        if (!toggle.checked) {
            field.value = '';
        } else {
            var error = blindPlayerError(toggle.closest('.js-team'), row);
            if (error !== null) {
                toggle.checked = false;
                showMessage(error);
            } else if (row.dataset.avg !== '') {
                field.value = String(Math.min(Math.max(Math.round(parseFloat(row.dataset.avg) - 10), 0), 300));
            } else if (!rowState(row).ownGame && sheet.dataset.ghost) {
                field.value = sheet.dataset.ghost;
            }
        }
        updateBadge(row);
    }, true);

    sheet.addEventListener('input', function (event) {
        var row = event.target.closest && event.target.closest('.js-row');
        if (row) {
            updateBadge(row);
        }
    });

    sheet.addEventListener('input', calculate);
    sheet.addEventListener('change', calculate);
    calculate();
})();
