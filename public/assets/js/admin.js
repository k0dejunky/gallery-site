/* Admin behaviour layer.
 *
 * Everything the admin panel does that is not page-specific lives here. It
 * used to be scattered: the user-list bulk bar and the creator-profile link
 * rows called handlers that were defined in the MEMBER layout
 * (views/layout.php), which the admin never loads - so "Delete" on the bulk
 * bar submitted instantly with no confirmation, and "Add another link" did
 * nothing at all.
 *
 * The admin delegation (data-confirm / data-action / data-copy / ...) lives
 * in views/admin/layout.php; this file provides the handlers it calls, plus
 * the drawer focus trap.
 */
(function () {
    'use strict';

    /* --- bulk actions on the user list ----------------------------------
       The role picker only matters for the "role" action, and a bulk delete
       has to say what it is about to delete. */
    window.toggleBulkRole = function (sel) {
        var el = document.getElementById('bulk-role');
        if (!el) return;
        var isRole = sel && sel.value === 'role';
        el.style.display = isRole ? '' : 'none';
        el.disabled = !isRole;
    };

    window.bulkApply = function (btn) {
        var form = btn && btn.form;
        if (!form) return;
        var checked = document.querySelectorAll('.user-check:checked');
        if (!checked.length) {
            window.alert('No users selected.');
            return;
        }
        var action = form.querySelector('select[name="action"]');
        var what = action ? action.value : 'action';
        var noun = what === 'delete' ? 'delete' : (what === 'suspend' ? 'suspend' : 'change the role of');
        var message = 'About to ' + noun + ' ' + checked.length + ' user' + (checked.length === 1 ? '' : 's') + '. Continue?';
        if (!window.confirm(message)) return;
        form.submit();
    };

    /* --- creator profile: repeatable link rows -------------------------- */
    window.addLinkRow = function () {
        var rows = document.getElementById('link-rows');
        if (!rows || !rows.children[0]) return;
        var clone = rows.children[0].cloneNode(true);
        clone.querySelectorAll('input').forEach(function (input) { input.value = ''; });
        rows.appendChild(clone);
    };

    /* --- the mobile drawer -----------------------------------------------
       The drawer is a real overlay: it traps focus while open, gives the
       focus back to the button that opened it, and is removed from the tab
       order while it is closed (it used to stay focusable, so a keyboard
       user tabbed through 33 invisible links on a phone).

       Only a drawer at 960px and below: above that the nav IS the sidebar
       and must stay visible, focusable and announced. */
    function initDrawer() {
        var toggle = document.getElementById('nav-toggle');
        var drawer = document.getElementById('admin-nav');
        var backdrop = document.getElementById('nav-backdrop');
        if (!toggle || !drawer) return;

        var mq = window.matchMedia('(max-width: 960px)');

        function isDrawerMode() { return mq.matches; }
        function isOpen() { return document.body.classList.contains('nav-open'); }
        function focusables() {
            return Array.prototype.slice.call(
                drawer.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])')
            ).filter(function (el) { return el.offsetParent !== null; });
        }
        function setOpen(open) {
            if (!isDrawerMode()) return;
            document.body.classList.toggle('nav-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
            // `inert` takes the closed drawer out of the tab order and out of
            // the accessibility tree; the stylesheet's visibility rule is the
            // fallback for browsers without it.
            if ('inert' in HTMLElement.prototype) { drawer.inert = !open; }
            if (open) {
                var first = focusables()[0];
                if (first) first.focus();
            } else if (document.activeElement && drawer.contains(document.activeElement)) {
                toggle.focus();
            }
            document.body.style.overflow = open ? 'hidden' : '';
        }

        // Leaving drawer mode (window widened): the nav is the sidebar again,
        // so it must not stay hidden from assistive tech.
        function syncMode() {
            if (isDrawerMode()) {
                drawer.setAttribute('aria-hidden', isOpen() ? 'false' : 'true');
                if ('inert' in HTMLElement.prototype) { drawer.inert = !isOpen(); }
            } else {
                drawer.removeAttribute('aria-hidden');
                if ('inert' in HTMLElement.prototype) { drawer.inert = false; }
                document.body.classList.remove('nav-open');
                document.body.style.overflow = '';
                toggle.setAttribute('aria-expanded', 'false');
            }
        }
        if (mq.addEventListener) { mq.addEventListener('change', syncMode); }
        else if (mq.addListener) { mq.addListener(syncMode); }
        syncMode();

        toggle.addEventListener('click', function () { if (isDrawerMode()) setOpen(!isOpen()); });
        if (backdrop) backdrop.addEventListener('click', function () { setOpen(false); });
        document.addEventListener('keydown', function (e) {
            if (!isDrawerMode() || !isOpen()) return;
            if (e.key === 'Escape') { setOpen(false); return; }
            if (e.key !== 'Tab') return;
            var items = focusables();
            if (!items.length) return;
            var first = items[0];
            var last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        });
    }

    if (document.readyState !== 'loading') { initDrawer(); }
    else { document.addEventListener('DOMContentLoaded', initDrawer); }
})();
