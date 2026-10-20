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
 * in views/admin/layout.php; this file provides the handlers it calls, the
 * confirmation modal, the undo toast, and the drawer focus trap.
 */
(function () {
    'use strict';

    /* --- the confirmation modal -------------------------------------------
       The native window.confirm() is what every destructive admin action
       used, and it is the ugliest thing in the panel: a browser dialog with
       no styling, no context and no way to say WHAT will happen. This is a
       styled one, with a focus trap, Escape to cancel and the focus handed
       back to the button that opened it.

       ask() returns a promise, so the caller can wait for the answer. When
       this file has not loaded, the delegation falls back to the native
       confirm - a destructive action must never lose its confirmation. */
    var confirmHost = null;

    function buildModal() {
        if (confirmHost) { return confirmHost; }

        var backdrop = document.createElement('div');
        backdrop.className = 'adm-modal-backdrop';
        backdrop.setAttribute('hidden', 'hidden');

        var dialog = document.createElement('div');
        dialog.className = 'adm-modal';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'adm-modal-title');
        dialog.setAttribute('aria-describedby', 'adm-modal-text');

        var title = document.createElement('h2');
        title.className = 'adm-modal-title';
        title.id = 'adm-modal-title';
        title.textContent = 'Are you sure?';

        var text = document.createElement('p');
        text.className = 'adm-modal-text';
        text.id = 'adm-modal-text';

        var actions = document.createElement('div');
        actions.className = 'adm-modal-actions';

        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn adm-modal-cancel';
        cancel.textContent = 'Cancel';

        var ok = document.createElement('button');
        ok.type = 'button';
        ok.className = 'btn adm-modal-ok';
        ok.textContent = 'Yes, continue';

        actions.appendChild(cancel);
        actions.appendChild(ok);
        dialog.appendChild(title);
        dialog.appendChild(text);
        dialog.appendChild(actions);
        backdrop.appendChild(dialog);
        document.body.appendChild(backdrop);

        confirmHost = { backdrop: backdrop, dialog: dialog, title: title, text: text, cancel: cancel, ok: ok };
        return confirmHost;
    }

    function focusablesIn(root) {
        return Array.prototype.slice.call(
            root.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])')
        ).filter(function (el) { return el.offsetParent !== null; });
    }

    /**
     * Ask a question. Resolves true for "yes".
     */
    function ask(message, options) {
        options = options || {};
        var ui = buildModal();

        return new Promise(function (resolve) {
            var previous = document.activeElement;

            ui.title.textContent = options.title || 'Are you sure?';
            ui.text.textContent = message || '';
            ui.ok.textContent = options.confirmLabel || 'Yes, continue';
            ui.ok.className = 'btn adm-modal-ok' + (options.danger ? ' btn-danger' : '');
            ui.cancel.textContent = options.cancelLabel || 'Cancel';
            ui.backdrop.hidden = false;
            document.body.classList.add('adm-modal-open');

            var done = false;
            function close(answer) {
                if (done) { return; }
                done = true;
                ui.backdrop.hidden = true;
                document.body.classList.remove('adm-modal-open');
                document.removeEventListener('keydown', onKey, true);
                if (previous && previous.focus) { previous.focus(); }
                resolve(answer);
            }
            function onKey(e) {
                if (ui.backdrop.hidden) { return; }
                if (e.key === 'Escape') { e.preventDefault(); close(false); return; }
                if (e.key !== 'Tab') { return; }
                var items = focusablesIn(ui.dialog);
                if (!items.length) { return; }
                var first = items[0];
                var last = items[items.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }

            ui.cancel.addEventListener('click', function () { close(false); });
            ui.ok.addEventListener('click', function () { close(true); });
            ui.backdrop.addEventListener('click', function (e) {
                if (e.target === ui.backdrop) { close(false); }
            });
            document.addEventListener('keydown', onKey, true);

            // The answer button gets the focus, so the keyboard path is
            // Enter = yes, Escape = no, without touching the mouse.
            ui.ok.focus();
        });
    }

    window.AdminConfirm = {
        ask: ask,
        /** The native confirm, wrapped so callers always get a promise. */
        askSync: function (message) {
            return Promise.resolve(window.confirm(message));
        }
    };

    /** Ask, falling back to the native confirm if this file did not load. */
    window.adminAsk = function (message, options) {
        if (window.AdminConfirm && window.AdminConfirm.ask) {
            return window.AdminConfirm.ask(message, options);
        }
        return Promise.resolve(window.confirm(message));
    };

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
            window.adminAsk('No users selected. Tick the boxes next to the users you want to act on.');
            return;
        }
        var action = form.querySelector('select[name="action"]');
        var what = action ? action.value : 'action';
        var noun = what === 'delete' ? 'delete' : (what === 'suspend' ? 'suspend' : 'change the role of');
        var message = 'About to ' + noun + ' ' + checked.length + ' user' + (checked.length === 1 ? '' : 's') + '. Continue?';
        window.adminAsk(message, { danger: what === 'delete', confirmLabel: what === 'delete' ? 'Delete' : 'Apply' }).then(function (ok) {
            if (ok) { form.submit(); }
        });
    };

    /* --- the undo toast ---------------------------------------------------
       A toast that can offer to undo the thing it is reporting. The
       "undo" is a URL the caller supplies (usually a POST endpoint that
       puts the row back), so the toast does not have to know what it is
       undoing. */
    var toastHost = null;
    var toastTimer = null;

    function toastEl() {
        if (toastHost) { return toastHost; }
        toastHost = document.createElement('div');
        toastHost.className = 'adm-toast';
        toastHost.setAttribute('role', 'status');
        toastHost.setAttribute('aria-live', 'polite');
        toastHost.hidden = true;
        document.body.appendChild(toastHost);
        return toastHost;
    }

    window.adminToast = function (message, options) {
        options = options || {};
        var el = toastEl();
        el.innerHTML = '';

        var text = document.createElement('span');
        text.className = 'adm-toast-text';
        text.textContent = message;
        el.appendChild(text);

        if (options.actionLabel && options.actionUrl) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'adm-toast-action';
            btn.textContent = options.actionLabel;
            btn.addEventListener('click', function () {
                var form = document.createElement('form');
                form.method = 'post';
                form.action = options.actionUrl;
                var token = options.actionToken || csrfToken();
                if (token) {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = '_token';
                    hidden.value = token;
                    form.appendChild(hidden);
                }
                document.body.appendChild(form);
                form.submit();
            });
            el.appendChild(btn);
        }

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'adm-toast-close';
        close.setAttribute('aria-label', 'Dismiss');
        close.textContent = '×';
        close.addEventListener('click', function () { hide(); });
        el.appendChild(close);

        el.hidden = false;
        el.classList.add('adm-toast-on');

        if (toastTimer) { clearTimeout(toastTimer); }
        toastTimer = setTimeout(hide, options.duration || 8000);

        function hide() {
            el.classList.remove('adm-toast-on');
            el.hidden = true;
        }
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

    /* --- undoable row deletes ---------------------------------------------
       A gallery delete is reversible (the row is only soft-deleted), so
       asking "are you sure?" and then reloading the page is the worst of
       both: the admin still has to go and find the gallery to restore it.

       Instead the row is removed in place and a toast offers "Undo", which
       posts to the restore endpoint. Without JavaScript the form posts
       normally, so nothing is lost if this file does not load. */
    function csrfToken(scope) {
        // The form that is being submitted always carries the session's
        // token in a hidden input; that is the token that is guaranteed to
        // be the right one. The meta tag is the fallback for posts that
        // are not attached to a form.
        var input = scope && scope.querySelector ? scope.querySelector('input[name="_token"]') : null;
        if (input && input.value) { return input.value; }

        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function postForm(url, extra, token) {
        var body = new URLSearchParams();
        body.set('_token', token || csrfToken());
        Object.keys(extra || {}).forEach(function (k) { body.set(k, extra[k]); });
        return fetch(url, {
            method: 'POST',
            body: body,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            credentials: 'same-origin'
        });
    }

    function bumpListCount() {
        // "Showing 1,200 of 3,400" must not keep a number that is now
        // wrong - and it must keep its thousands separator.
        var line = document.querySelector('.adm-list-count');
        if (!line) { return; }
        var numbers = line.querySelectorAll('strong');
        for (var i = 0; i < numbers.length && i < 2; i++) {
            var n = parseInt(numbers[i].textContent.replace(/[^0-9]/g, ''), 10);
            if (!isNaN(n) && n > 0) { numbers[i].textContent = (n - 1).toLocaleString(); }
        }
    }

    function initUndoableDeletes() {
        document.querySelectorAll('form[data-undo-url]').forEach(function (form) {
            if (form.__admBound) { return; }
            form.__admBound = '1';

            form.addEventListener('submit', function (e) {
                e.preventDefault();

                var label = form.getAttribute('data-undo-label') || 'this row';
                var undoUrl = form.getAttribute('data-undo-url');

                // The confirmation is the layout delegation's job: it asks
                // (with the styled modal) before this event is allowed
                // through, so asking again here would show two dialogs.

                postForm(form.action, {}, csrfToken(form)).then(function (r) {
                    if (window.AdminProgress) { window.AdminProgress.hide(); }
                    if (!r.ok) {
                        window.adminToast('The delete did not go through (error ' + r.status + '). The page has been reloaded.', {});
                        window.location.reload();
                        return;
                    }
                    var row = form.closest('tr');
                    if (row) { row.remove(); }
                    bumpListCount();
                    window.adminToast('Deleted ' + label + '.', {
                        actionLabel: 'Undo',
                        actionUrl: undoUrl,
                        actionToken: csrfToken(form)
                    });
                }).catch(function () {
                    if (window.AdminProgress) { window.AdminProgress.hide(); }
                    window.adminToast('The delete could not be sent. The page has been reloaded.', {});
                    window.location.reload();
                });
            });
        });
    }

    /* --- inline row editing -----------------------------------------------
       A role is a permission, and a select that saves itself on every
       change turns one stray arrow key into a changed role. The inline
       editor keeps the old value until the admin says so: the row shows
       what would change, with a Save and a Cancel next to it, and Escape
       puts the value back. */
    function initInlineEdit() {
        document.querySelectorAll('[data-autosubmit="manual"]').forEach(function (el) {
            if (el.__admBound) { return; }
            el.__admBound = '1';

            var form = el.form;
            if (!form) { return; }

            var original = el.value;
            var controls = null;

            function cell() {
                return el.closest('td') || form;
            }

            // A change that has not been saved is a change the admin
            // believes they made. Warn before the page goes away, and put
            // the value back if it does.
            function guardUnload(on) {
                if (on) {
                    window.addEventListener('beforeunload', warnOnLeave);
                    window.addEventListener('pagehide', revertPending);
                } else {
                    window.removeEventListener('beforeunload', warnOnLeave);
                    window.removeEventListener('pagehide', revertPending);
                }
            }
            function warnOnLeave(e) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
            function revertPending() {
                el.value = original;
            }

            function showControls(on) {
                if (on) {
                    if (!controls) {
                        controls = document.createElement('span');
                        controls.className = 'adm-inline-controls';

                        var save = document.createElement('button');
                        save.type = 'button';
                        save.className = 'btn btn-sm adm-inline-save';
                        save.textContent = 'Save';
                        save.addEventListener('click', function () {
                            if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
                        });

                        var cancel = document.createElement('button');
                        cancel.type = 'button';
                        cancel.className = 'btn btn-sm adm-inline-cancel';
                        cancel.textContent = 'Cancel';
                        cancel.addEventListener('click', reset);

                        controls.appendChild(save);
                        controls.appendChild(cancel);
                        form.appendChild(controls);
                    }
                    controls.hidden = false;
                } else if (controls) {
                    controls.hidden = true;
                }
                cell().classList.toggle('adm-inline-pending', on);
                guardUnload(on);
            }

            function reset() {
                el.value = original;
                showControls(false);
            }

            el.addEventListener('change', function () {
                if (el.value === original) { showControls(false); return; }
                showControls(true);
            });

            el.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && el.value !== original) {
                    e.preventDefault();
                    reset();
                }
            });
        });
    }

    if (document.readyState !== 'loading') { initDrawer(); initUndoableDeletes(); initInlineEdit(); }
    else {
        document.addEventListener('DOMContentLoaded', function () {
            initDrawer();
            initUndoableDeletes();
            initInlineEdit();
        });
    }
})();
