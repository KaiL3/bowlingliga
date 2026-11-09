/*
 * Crops a score sheet photo to the sheet before the upload (R3.21).
 *
 * After a photo has been chosen it is shown with four corner handles. On
 * submit the quadrilateral is rectified to A4 (landscape or portrait, by the
 * shape of the marked area) and replaces the chosen file as a JPEG; the
 * hidden field "cropped" tells the server that this happened. PDFs are
 * uploaded unchanged. Without JavaScript the server rejects photos, PDFs
 * still work.
 *
 * The browser applies the EXIF orientation when it decodes the image; the
 * server prepares the result once more (scaling, no metadata, R3.20).
 */
(function () {
    'use strict';

    var A4 = Math.SQRT2;
    var OUTPUT_LONG_EDGE = 2000;
    var WORK_LONG_EDGE = 3000;
    var HANDLE_RADIUS = 14;
    var GRAB_RADIUS = 36;
    var LOUPE_SIZE = 120;
    var LOUPE_ZOOM = 3;

    document.querySelectorAll('.js-sheet-crop').forEach(setUp);

    function setUp(form) {
        var input = form.querySelector('input[type="file"]');
        var panel = form.querySelector('.sheet-crop');
        var canvas = panel.querySelector('canvas');
        var marker = form.querySelector('input[name="cropped"]');
        var status = panel.querySelector('.sheet-crop__status');
        var submit = form.querySelector('button[type="submit"]');
        var context = canvas.getContext('2d');

        var work = null;      // canvas with the photo, at most WORK_LONG_EDGE
        var corners = [];     // TL, TR, BR, BL in work coordinates
        var dragged = -1;
        var scale = 1;        // display pixels per work pixel
        var busy = false;

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            marker.value = '';
            work = null;
            panel.hidden = true;
            status.textContent = '';
            if (!file || !/^image\/(jpeg|png)$/.test(file.type)) {
                return;
            }
            load(file);
        });

        function load(file) {
            var url = URL.createObjectURL(file);
            var image = new Image();
            image.onload = function () {
                var factor = Math.min(1, WORK_LONG_EDGE / Math.max(image.naturalWidth, image.naturalHeight));
                work = document.createElement('canvas');
                work.width = Math.round(image.naturalWidth * factor);
                work.height = Math.round(image.naturalHeight * factor);
                work.getContext('2d').drawImage(image, 0, 0, work.width, work.height);
                URL.revokeObjectURL(url);
                reset();
                panel.hidden = false;
                render();
            };
            image.onerror = function () {
                URL.revokeObjectURL(url);
                panel.hidden = false;
                status.textContent = form.dataset.cropUnreadable;
            };
            image.src = url;
        }

        function reset() {
            var x = work.width * 0.06;
            var y = work.height * 0.06;
            corners = [
                { x: x, y: y },
                { x: work.width - x, y: y },
                { x: work.width - x, y: work.height - y },
                { x: x, y: work.height - y }
            ];
        }

        function render() {
            if (!work) {
                return;
            }
            var ratio = window.devicePixelRatio || 1;
            // Full width, but a portrait photo stays within the screen height.
            var width = Math.min(panel.clientWidth, window.innerHeight * 0.75 * work.width / work.height);
            scale = width / work.width;
            canvas.width = Math.round(width * ratio);
            canvas.height = Math.round(work.height * scale * ratio);
            canvas.style.width = width + 'px';
            canvas.style.height = (work.height * scale) + 'px';
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.drawImage(work, 0, 0, work.width * scale, work.height * scale);

            // Darken everything outside the sheet.
            context.save();
            context.beginPath();
            context.rect(0, 0, work.width * scale, work.height * scale);
            path();
            context.fillStyle = 'rgba(0, 0, 0, 0.45)';
            context.fill('evenodd');
            context.restore();

            context.lineWidth = 2;
            context.strokeStyle = '#FF6B2C';
            context.beginPath();
            path();
            context.stroke();

            corners.forEach(function (corner, index) {
                context.beginPath();
                context.arc(corner.x * scale, corner.y * scale, HANDLE_RADIUS, 0, 2 * Math.PI);
                context.fillStyle = index === dragged ? '#FF6B2C' : 'rgba(255, 255, 255, 0.85)';
                context.fill();
                context.stroke();
            });

            if (dragged >= 0) {
                loupe(corners[dragged]);
            }
        }

        function path() {
            context.moveTo(corners[0].x * scale, corners[0].y * scale);
            for (var i = 1; i < 4; i++) {
                context.lineTo(corners[i].x * scale, corners[i].y * scale);
            }
            context.closePath();
        }

        /**
         * A magnified view of the dragged corner, on the opposite side and
         * vertically centred, so it covers neither the finger nor a corner.
         */
        function loupe(corner) {
            var displayWidth = work.width * scale;
            var left = corner.x * scale < displayWidth / 2 ? displayWidth - LOUPE_SIZE - 8 : 8;
            var top = Math.max(8, (work.height * scale - LOUPE_SIZE) / 2);
            var source = LOUPE_SIZE / LOUPE_ZOOM / scale;

            context.save();
            context.beginPath();
            context.rect(left, top, LOUPE_SIZE, LOUPE_SIZE);
            context.clip();
            context.fillStyle = '#FFFFFF';
            context.fillRect(left, top, LOUPE_SIZE, LOUPE_SIZE);
            context.drawImage(work, corner.x - source / 2, corner.y - source / 2, source, source, left, top, LOUPE_SIZE, LOUPE_SIZE);
            context.strokeStyle = '#FF6B2C';
            context.lineWidth = 1;
            context.beginPath();
            context.moveTo(left + LOUPE_SIZE / 2, top);
            context.lineTo(left + LOUPE_SIZE / 2, top + LOUPE_SIZE);
            context.moveTo(left, top + LOUPE_SIZE / 2);
            context.lineTo(left + LOUPE_SIZE, top + LOUPE_SIZE / 2);
            context.stroke();
            context.restore();
            context.strokeStyle = '#FF6B2C';
            context.lineWidth = 2;
            context.strokeRect(left, top, LOUPE_SIZE, LOUPE_SIZE);
        }

        function pointer(event) {
            var box = canvas.getBoundingClientRect();
            return { x: (event.clientX - box.left) / scale, y: (event.clientY - box.top) / scale };
        }

        canvas.addEventListener('pointerdown', function (event) {
            if (!work) {
                return;
            }
            var point = pointer(event);
            var best = -1;
            var bestDistance = GRAB_RADIUS / scale;
            corners.forEach(function (corner, index) {
                var distance = Math.hypot(corner.x - point.x, corner.y - point.y);
                if (distance <= bestDistance) {
                    best = index;
                    bestDistance = distance;
                }
            });
            if (best < 0) {
                return;
            }
            dragged = best;
            try {
                canvas.setPointerCapture(event.pointerId);
            } catch (e) {
                // Without capture the drag still works while the pointer stays on the canvas.
            }
            event.preventDefault();
            render();
        });

        canvas.addEventListener('pointermove', function (event) {
            if (dragged < 0) {
                return;
            }
            var point = pointer(event);
            corners[dragged] = {
                x: Math.min(work.width, Math.max(0, point.x)),
                y: Math.min(work.height, Math.max(0, point.y))
            };
            marker.value = '';
            render();
        });

        function release() {
            if (dragged >= 0) {
                dragged = -1;
                render();
            }
        }
        canvas.addEventListener('pointerup', release);
        canvas.addEventListener('pointercancel', release);

        panel.querySelector('[data-crop-rotate]').addEventListener('click', function () {
            if (!work) {
                return;
            }
            var turned = document.createElement('canvas');
            turned.width = work.height;
            turned.height = work.width;
            var turnedContext = turned.getContext('2d');
            turnedContext.translate(turned.width, 0);
            turnedContext.rotate(Math.PI / 2);
            turnedContext.drawImage(work, 0, 0);
            var height = work.height;
            // Clockwise: (x, y) → (height − y, x); the top left corner moves to the top right.
            var moved = corners.map(function (corner) { return { x: height - corner.y, y: corner.x }; });
            corners = [moved[3], moved[0], moved[1], moved[2]];
            work = turned;
            render();
        });

        panel.querySelector('[data-crop-reset]').addEventListener('click', function () {
            if (work) {
                reset();
                render();
            }
        });

        window.addEventListener('resize', render);

        form.addEventListener('submit', function (event) {
            if (!work || marker.value === '1') {
                return;
            }
            event.preventDefault();
            if (busy) {
                return;
            }
            busy = true;
            submit.disabled = true;
            status.textContent = form.dataset.cropWorking;

            // Let the status appear before the calculation blocks the page.
            window.setTimeout(function () {
                rectify().toBlob(function (blob) {
                    var name = (input.files[0].name || 'score-sheet').replace(/\.[^.]*$/, '') + '.jpg';
                    try {
                        var transfer = new DataTransfer();
                        transfer.items.add(new File([blob], name, { type: 'image/jpeg' }));
                        input.files = transfer.files;
                    } catch (e) {
                        busy = false;
                        submit.disabled = false;
                        status.textContent = form.dataset.cropUnsupported;
                        return;
                    }
                    marker.value = '1';
                    form.submit();
                }, 'image/jpeg', 0.92);
            }, 30);
        });

        /** Maps the marked quadrilateral onto an A4 rectangle. */
        function rectify() {
            var wide = edge(0, 1) + edge(3, 2) >= edge(0, 3) + edge(1, 2);
            var width = wide ? OUTPUT_LONG_EDGE : Math.round(OUTPUT_LONG_EDGE / A4);
            var height = wide ? Math.round(OUTPUT_LONG_EDGE / A4) : OUTPUT_LONG_EDGE;
            var h = homography(
                [{ x: 0, y: 0 }, { x: width, y: 0 }, { x: width, y: height }, { x: 0, y: height }],
                corners
            );

            var source = work.getContext('2d').getImageData(0, 0, work.width, work.height);
            var output = document.createElement('canvas');
            output.width = width;
            output.height = height;
            var target = output.getContext('2d').createImageData(width, height);
            var from = source.data;
            var to = target.data;
            var sw = work.width;
            var sh = work.height;

            for (var v = 0; v < height; v++) {
                for (var u = 0; u < width; u++) {
                    var w = h[6] * u + h[7] * v + 1;
                    var x = (h[0] * u + h[1] * v + h[2]) / w;
                    var y = (h[3] * u + h[4] * v + h[5]) / w;
                    var x0 = Math.max(0, Math.min(sw - 2, Math.floor(x)));
                    var y0 = Math.max(0, Math.min(sh - 2, Math.floor(y)));
                    var fx = Math.max(0, Math.min(1, x - x0));
                    var fy = Math.max(0, Math.min(1, y - y0));
                    var a = (y0 * sw + x0) * 4;
                    var b = a + 4;
                    var c = a + sw * 4;
                    var d = c + 4;
                    var o = (v * width + u) * 4;
                    for (var k = 0; k < 3; k++) {
                        var top = from[a + k] + (from[b + k] - from[a + k]) * fx;
                        var bottom = from[c + k] + (from[d + k] - from[c + k]) * fx;
                        to[o + k] = top + (bottom - top) * fy;
                    }
                    to[o + 3] = 255;
                }
            }
            output.getContext('2d').putImageData(target, 0, 0);

            return output;
        }

        function edge(i, j) {
            return Math.hypot(corners[i].x - corners[j].x, corners[i].y - corners[j].y);
        }
    }

    /**
     * Projective transformation mapping each point of "from" onto the same
     * point of "to": [h0..h7] with x' = (h0 u + h1 v + h2) / (h6 u + h7 v + 1).
     */
    function homography(from, to) {
        var rows = [];
        for (var i = 0; i < 4; i++) {
            var u = from[i].x, v = from[i].y, x = to[i].x, y = to[i].y;
            rows.push([u, v, 1, 0, 0, 0, -u * x, -v * x, x]);
            rows.push([0, 0, 0, u, v, 1, -u * y, -v * y, y]);
        }
        // Gaussian elimination with partial pivoting.
        for (var col = 0; col < 8; col++) {
            var pivot = col;
            for (var r = col + 1; r < 8; r++) {
                if (Math.abs(rows[r][col]) > Math.abs(rows[pivot][col])) {
                    pivot = r;
                }
            }
            var swap = rows[col]; rows[col] = rows[pivot]; rows[pivot] = swap;
            for (var s = 0; s < 8; s++) {
                if (s !== col && rows[col][col] !== 0) {
                    var f = rows[s][col] / rows[col][col];
                    for (var c = col; c < 9; c++) {
                        rows[s][c] -= f * rows[col][c];
                    }
                }
            }
        }

        return rows.map(function (row, index) { return row[8] / row[index]; });
    }
})();
