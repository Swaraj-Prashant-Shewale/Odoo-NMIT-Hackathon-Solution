/* ==========================================================================
   Dayflow HRMS - client behaviour

   The Content-Security-Policy forbids inline script, so nothing in this
   application uses an onclick attribute or a <script> block inside a page.
   Every behaviour is wired here from data attributes instead, which is both
   the safer arrangement and the tidier one.
   ========================================================================== */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        liveClock();
        elapsedTimers();
        confirmActions();
        submitOnChange();
        passwordToggles();
        passwordStrength();
        autoDismissAlerts();
        dateRangeGuards();
        preventDoubleSubmit();
        characterCounters();
        filterableTables();
    });

    /* ---------------------------------------------------------------------
       A ticking clock on the attendance card, so the punch screen feels live.
       --------------------------------------------------------------------- */
    function liveClock() {
        var nodes = document.querySelectorAll('[data-live-clock]');
        if (!nodes.length) { return; }

        function paint() {
            var now = new Date();
            var hours = now.getHours();
            var suffix = hours >= 12 ? 'pm' : 'am';
            var display = ((hours % 12) || 12) + ':' +
                String(now.getMinutes()).padStart(2, '0') + ':' +
                String(now.getSeconds()).padStart(2, '0');

            nodes.forEach(function (node) {
                node.textContent = display + ' ' + suffix;
            });
        }

        paint();
        setInterval(paint, 1000);
    }

    /* ---------------------------------------------------------------------
       Counts up from a check-in time. The start value is rendered by the
       server as an epoch second, so the browser clock being wrong only skews
       the display rather than the recorded attendance.
       --------------------------------------------------------------------- */
    function elapsedTimers() {
        var nodes = document.querySelectorAll('[data-elapsed-since]');
        if (!nodes.length) { return; }

        function paint() {
            var now = Math.floor(Date.now() / 1000);

            nodes.forEach(function (node) {
                var since = parseInt(node.getAttribute('data-elapsed-since'), 10);
                if (!since) { return; }

                var seconds = Math.max(0, now - since);
                var h = Math.floor(seconds / 3600);
                var m = Math.floor((seconds % 3600) / 60);
                var s = seconds % 60;

                node.textContent = h + 'h ' + String(m).padStart(2, '0') + 'm ' + String(s).padStart(2, '0') + 's';
            });
        }

        paint();
        setInterval(paint, 1000);
    }

    /* ---------------------------------------------------------------------
       A confirmation step before anything destructive or irreversible.
       --------------------------------------------------------------------- */
    function confirmActions() {
        document.querySelectorAll('[data-confirm]').forEach(function (element) {
            element.addEventListener('click', function (event) {
                if (!window.confirm(element.getAttribute('data-confirm'))) {
                    event.preventDefault();
                    event.stopPropagation();
                }
            });
        });
    }

    /* ---------------------------------------------------------------------
       Filter dropdowns that apply as soon as they change.
       --------------------------------------------------------------------- */
    function submitOnChange() {
        document.querySelectorAll('[data-submit-on-change]').forEach(function (element) {
            element.addEventListener('change', function () {
                var form = element.closest('form');
                if (form) { form.submit(); }
            });
        });
    }

    /* ---------------------------------------------------------------------
       Show and hide a password field.
       --------------------------------------------------------------------- */
    function passwordToggles() {
        document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.getAttribute('data-toggle-password'));
                if (!input) { return; }

                var hidden = input.type === 'password';
                input.type = hidden ? 'text' : 'password';
                button.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');

                var icon = button.querySelector('i');
                if (icon) {
                    icon.className = hidden ? 'fa fa-eye-slash' : 'fa fa-eye';
                }
            });
        });
    }

    /* ---------------------------------------------------------------------
       Password strength meter.

       This is guidance for the person choosing a password, nothing more. The
       real policy is enforced by the identity service, which never sees this
       calculation and does not trust it.
       --------------------------------------------------------------------- */
    function passwordStrength() {
        document.querySelectorAll('[data-strength-for]').forEach(function (meter) {
            var input = document.getElementById(meter.getAttribute('data-strength-for'));
            if (!input) { return; }

            var label = meter.parentElement ? meter.parentElement.querySelector('[data-strength-label]') : null;
            var names = ['Too weak', 'Weak', 'Fair', 'Good', 'Strong'];

            input.addEventListener('input', function () {
                var value = input.value;
                var score = 0;

                if (value.length >= 10) { score++; }
                if (value.length >= 14) { score++; }
                if (/[A-Z]/.test(value) && /[a-z]/.test(value)) { score++; }
                if (/[0-9]/.test(value) && /[^A-Za-z0-9]/.test(value)) { score++; }

                if (value.length === 0) { score = -1; }

                meter.querySelectorAll('span').forEach(function (bar, index) {
                    bar.className = index <= score ? 'on-' + score : '';
                });

                if (label) {
                    label.textContent = score < 0 ? '' : names[score];
                }
            });
        });
    }

    /* ---------------------------------------------------------------------
       Success messages fade after a while; warnings and errors stay until
       they are dismissed, because they usually need an action.
       --------------------------------------------------------------------- */
    function autoDismissAlerts() {
        document.querySelectorAll('.alert-success').forEach(function (alert) {
            setTimeout(function () {
                alert.classList.remove('show');
                setTimeout(function () { alert.remove(); }, 200);
            }, 6000);
        });
    }

    /* ---------------------------------------------------------------------
       Keeps a date range coherent while it is being filled in. The service
       validates the range again on submission.
       --------------------------------------------------------------------- */
    function dateRangeGuards() {
        document.querySelectorAll('[data-range-start]').forEach(function (start) {
            var end = document.getElementById(start.getAttribute('data-range-start'));
            if (!end) { return; }

            function sync() {
                if (start.value) {
                    end.min = start.value;
                    if (end.value && end.value < start.value) {
                        end.value = start.value;
                    }
                }
                announceDays(start, end);
            }

            start.addEventListener('change', sync);
            end.addEventListener('change', function () { announceDays(start, end); });
            sync();
        });
    }

    function announceDays(start, end) {
        var target = document.querySelector('[data-range-days]');
        if (!target || !start.value || !end.value) { return; }

        var from = new Date(start.value);
        var to = new Date(end.value);
        var days = Math.round((to - from) / 86400000) + 1;

        target.textContent = days > 0
            ? days + (days === 1 ? ' calendar day' : ' calendar days')
            : '';
    }

    /* ---------------------------------------------------------------------
       Stops a double click from submitting a form twice. Approvals and
       payroll runs are not safe to repeat, and the server rejects the second
       attempt, but a confusing error is worth avoiding.
       --------------------------------------------------------------------- */
    function preventDoubleSubmit() {
        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function () {
                var buttons = form.querySelectorAll('button[type="submit"]');

                buttons.forEach(function (button) {
                    if (button.hasAttribute('data-allow-repeat')) { return; }

                    setTimeout(function () {
                        button.disabled = true;
                        var busy = button.getAttribute('data-busy-label');
                        if (busy) { button.textContent = busy; }
                    }, 0);
                });
            });
        });
    }

    /* ---------------------------------------------------------------------
       Remaining-character counters on free-text fields.
       --------------------------------------------------------------------- */
    function characterCounters() {
        document.querySelectorAll('[data-counter-for]').forEach(function (counter) {
            var input = document.getElementById(counter.getAttribute('data-counter-for'));
            if (!input) { return; }

            var max = parseInt(input.getAttribute('maxlength'), 10);
            if (!max) { return; }

            function paint() {
                var remaining = max - input.value.length;
                counter.textContent = remaining + ' characters remaining';
                counter.classList.toggle('text-danger', remaining < 20);
            }

            input.addEventListener('input', paint);
            paint();
        });
    }

    /* ---------------------------------------------------------------------
       Instant client-side filtering of a rendered table.

       Only ever hides rows that are already on the page, so it cannot reveal
       anything the server did not send.
       --------------------------------------------------------------------- */
    function filterableTables() {
        document.querySelectorAll('[data-filter-table]').forEach(function (input) {
            var table = document.getElementById(input.getAttribute('data-filter-table'));
            if (!table) { return; }

            input.addEventListener('input', function () {
                var needle = input.value.trim().toLowerCase();
                var shown = 0;

                table.querySelectorAll('tbody tr').forEach(function (row) {
                    var match = needle === '' || row.textContent.toLowerCase().indexOf(needle) !== -1;
                    row.style.display = match ? '' : 'none';
                    if (match) { shown++; }
                });

                var empty = document.querySelector('[data-filter-empty]');
                if (empty) { empty.style.display = shown === 0 ? '' : 'none'; }
            });
        });
    }
})();
