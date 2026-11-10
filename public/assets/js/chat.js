/* ============================================================================
   Chat client - one script for the member chat and the admin conversation.

   Configured entirely by data-* attributes on #chat-app (see the views):
     data-mode            member | admin
     data-me              user | operator
     data-conversation    conversation id (0 = none yet)
     data-latest-id       newest message id already rendered
     data-oldest-id       oldest message id already rendered
     data-has-more        1 when older messages exist
     data-read-through    how far the other side has read (ticks)
     data-csrf            CSRF token
     data-endpoint-*      the URLs this page may call
     data-upload-limit    bytes

   The server renders the first page of the thread; this script owns
   everything that happens afterwards (new messages, quoting, editing,
   deleting, reacting, searching) and never re-renders what PHP already drew.
   ========================================================================== */
(function () {
    'use strict';

    var EMOJI = ['👍', '❤️', '😂', '😮', '😢', '🔥'];
    var PICKER = ('😀 😁 😂 🤣 😊 😍 😘 😗 😚 🙂 🤗 🤩 😎 😏 😉 🙃 😋 🤪 😜 🥰 😇 🤓 😴 🤤 😌 😔 😢 😭 😤 😡 🤬 😱 😳 🤯 😷 🤒 🥳 🤠 👽 ' +
        '👍 👎 👌 ✌️ 🤞 👊 ✊ 🤛 🤜 🙏 👏 💪 🖤 ❤️ 🧡 💛 💚 💙 💜 💖 💗 💕 💞 💘 💝 💋 👅 🔥 ✨ ⭐ 🌟 💫 🎉 🎊 🎂 🎁 💯 🎯 🚀 💎 ' +
        '🌸 🌹 🌺 🍑 🍆 🍒 🍓 🍉 🥂 🍾 ☕ 🌞 🌙 ⚡ ☀️ 🌈 ❄️ 💦 🍀 🐱 🐶 🦄 🦋 🐝 🐣').split(' ');

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /** "Today" / "Yesterday" / "12 November 2026" for a day divider. */
    function dayLabel(date) {
        var now = new Date();
        var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        var diff = Math.round((today - d) / 86400000);
        if (diff === 0) { return 'Today'; }
        if (diff === 1) { return 'Yesterday'; }
        var opts = { day: 'numeric', month: 'long' };
        if (d.getFullYear() !== now.getFullYear()) { opts.year = 'numeric'; }
        return d.toLocaleDateString(undefined, opts);
    }

    function dayKey(date) {
        return date.getFullYear() + '-' + (date.getMonth() + 1) + '-' + date.getDate();
    }

    /** Parse the server's "YYYY-MM-DD HH:MM:SS" as a LOCAL date. */
    function parseDate(s) {
        var m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
        if (!m) { return new Date(); }
        return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0));
    }

    function timeLabel(date) {
        return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    }

    /** Server-rendered labels win: the browser does not know the site zone. */
    function dayKeyOf(msg) { return msg.day || dayKey(parseDate(msg.created_at)); }
    function dayLabelOf(msg) { return msg.day_label || dayLabel(parseDate(msg.created_at)); }
    function timeLabelOf(msg) { return msg.time_label || timeLabel(parseDate(msg.created_at)); }

    function roleName(role, me) {
        if (role === me) {
            return me === 'operator' ? 'Operator' : 'You';
        }
        if (role === 'model') { return 'Amethyst AI'; }
        if (role === 'operator') { return 'Amethyst'; }
        return 'Member';
    }

    function initialFor(name) {
        var s = String(name || '?').trim();
        return s ? s.charAt(0).toUpperCase() : '?';
    }

    function ChatApp(root) {
        this.root = root;
        this.mode = root.getAttribute('data-mode') || 'member';
        this.me = root.getAttribute('data-me') || 'user';
        this.cid = parseInt(root.getAttribute('data-conversation') || '0', 10) || 0;
        this.latestId = parseInt(root.getAttribute('data-latest-id') || '0', 10) || 0;
        this.oldestId = parseInt(root.getAttribute('data-oldest-id') || '0', 10) || 0;
        this.hasMore = root.getAttribute('data-has-more') === '1';
        this.readThrough = parseInt(root.getAttribute('data-read-through') || '0', 10) || 0;
        this.csrf = root.getAttribute('data-csrf') || '';
        this.limit = parseInt(root.getAttribute('data-upload-limit') || '26214400', 10) || 26214400;
        this.endpoints = {
            send: root.getAttribute('data-endpoint-send') || '',
            history: root.getAttribute('data-endpoint-history') || '',
            stream: root.getAttribute('data-endpoint-stream') || '',
            edit: root.getAttribute('data-endpoint-edit') || '',
            del: root.getAttribute('data-endpoint-delete') || '',
            react: root.getAttribute('data-endpoint-react') || '',
            search: root.getAttribute('data-endpoint-search') || '',
            typing: root.getAttribute('data-endpoint-typing') || '',
            reply: root.getAttribute('data-endpoint-reply') || '',
            pin: root.getAttribute('data-endpoint-pin') || '',
            remove: root.getAttribute('data-endpoint-remove') || '',
            poll: root.getAttribute('data-endpoint-poll') || ''
        };
        this.list = qs('.chat-list', root);
        this.form = qs('.chat-inputrow', root);
        this.input = qs('.chat-input', root);
        this.fileInput = qs('.chat-file-input', root);
        this.attachPreview = qs('.chat-attach-preview', root);
        this.progress = qs('.chat-progress', root);
        this.replyBar = qs('.chat-reply-bar', root);
        this.typingEl = qs('.chat-typing', root);
        this.jump = qs('.chat-jump', root);
        this.picker = qs('.chat-emoji', root);
        this.searchBar = qs('.chat-search', root);
        this.searchInput = qs('.chat-search-input', root);
        this.searchStatus = qs('.chat-search-status', root);
        this.lightbox = qs('#chat-lightbox');
        this.menu = null;
        this.replyTo = null;
        this.editId = null;
        this.pending = [];
        this.loadingOlder = false;
        this.unread = 0;
        this.typingTimer = null;
        this.lastTypingPing = 0;
        this.baseTitle = document.title;
        this.draftKey = 'chat-draft:' + this.mode + ':' + this.cid;
        this.init();
    }

    ChatApp.prototype.init = function () {
        var self = this;

        // Existing bubbles get their interactive bits (menu, reaction
        // buttons, image zoom) without being re-rendered.
        qsa('.chat-row', this.list).forEach(function (row) { self.wireRow(row); });
        this.markTicks();

        if (this.input) {
            this.input.addEventListener('input', function () { self.onInput(); });
            this.input.addEventListener('keydown', function (e) { self.onKey(e); });
            this.autosize();
            var draft = '';
            try { draft = localStorage.getItem(this.draftKey) || ''; } catch (e) { draft = ''; }
            if (draft && !this.input.value) { this.input.value = draft; this.autosize(); }
        }

        if (this.form) {
            this.form.addEventListener('submit', function (e) {
                e.preventDefault();
                // The admin layout puts a full-page "Saving…" overlay over any
                // form submit and expects a navigation to clear it. This form
                // never navigates, so the overlay must not be raised; the
                // composer's own progress bar reports the upload instead.
                if (window.AdminProgress && typeof window.AdminProgress.hide === 'function') {
                    window.AdminProgress.hide();
                }
                self.send();
            });
        }

        if (this.fileInput) {
            this.fileInput.addEventListener('change', function () { self.onFile(); });
        }

        qsa('[data-chat-attach]', this.root).forEach(function (btn) {
            btn.addEventListener('click', function () { self.fileInput && self.fileInput.click(); });
        });
        qsa('[data-chat-emoji-toggle]', this.root).forEach(function (btn) {
            btn.addEventListener('click', function () { self.togglePicker(btn); });
        });
        qsa('[data-chat-search-toggle]', this.root).forEach(function (btn) {
            btn.addEventListener('click', function () { self.toggleSearch(btn); });
        });
        qsa('[data-chat-cancel-reply]', this.root).forEach(function (btn) {
            btn.addEventListener('click', function () { self.setReply(null); });
        });

        this.buildPicker();
        this.buildMenu();
        this.bindScroll();
        this.bindDrop();
        this.bindLightbox();

        if (this.searchInput) {
            var searchTimer = null;
            this.searchInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () { self.search(); }, 260);
            });
            this.searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { self.toggleSearch(null); }
            });
        }

        // Typing: ping the other side (throttled), and show their state.
        this.typingEl && this.typingEl.classList.remove('is-visible');

        if (this.mode === 'member') {
            // The event stream is the fast path on a box that flushes it; the
            // poll is the safety net where a proxy buffers the whole response
            // (mod_proxy_fcgi holds it until the script ends). Both deliver
            // the same messages and the client dedupes by id, so keeping the
            // poll is cheap insurance rather than a second code path.
            this.connectStream();
            this.startPoll(4000);
        }
        if (this.mode === 'admin') { this.startPoll(5000); }

        // Start at the newest message.
        var self2 = this;
        requestAnimationFrame(function () { self2.scrollToBottom(false); });
    };

    /* ---------------------------------------------------------------- menu */

    ChatApp.prototype.buildMenu = function () {
        var menu = document.createElement('div');
        menu.className = 'chat-menu';
        menu.setAttribute('role', 'menu');
        menu.innerHTML = '<div class="chat-menu-emoji" role="group" aria-label="React"></div>' +
            '<button type="button" data-act="reply" role="menuitem">Reply</button>' +
            '<button type="button" data-act="edit" role="menuitem">Edit</button>' +
            '<button type="button" data-act="copy" role="menuitem">Copy text</button>' +
            '<button type="button" data-act="pin" role="menuitem">Pin</button>' +
            '<button type="button" data-act="delete" class="is-danger" role="menuitem">Delete</button>';
        this.root.appendChild(menu);
        this.menu = menu;

        var self = this;
        var emojiRow = qs('.chat-menu-emoji', menu);
        EMOJI.forEach(function (emoji) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = emoji;
            b.setAttribute('aria-label', 'React ' + emoji);
            b.addEventListener('click', function () { self.react(self.menu.dataset.id, emoji); self.closeMenu(); });
            emojiRow.appendChild(b);
        });

        menu.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-act]');
            if (!btn) { return; }
            var id = parseInt(menu.dataset.id || '0', 10);
            var act = btn.getAttribute('data-act');
            if (act === 'reply') { self.startReply(id); }
            if (act === 'edit') { self.startEdit(id); }
            if (act === 'copy') { self.copyText(id); }
            if (act === 'pin') { self.postPin(id); }
            if (act === 'delete') { self.removeMessage(id); }
            self.closeMenu();
        });

        document.addEventListener('click', function (e) {
            if (self.menu && self.menu.classList.contains('is-open') && !self.menu.contains(e.target) && !e.target.closest('[data-chat-menu]')) {
                self.closeMenu();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { self.closeMenu(); }
        });
    };

    ChatApp.prototype.openMenu = function (id, anchor) {
        if (!this.menu) { return; }
        var row = this.row(id);
        if (!row) { return; }
        var role = row.getAttribute('data-role');
        var mine = role === this.me;
        var canModerate = this.mode === 'admin';
        qs('[data-act="edit"]', this.menu).style.display = (mine && role === 'user' && this.mode === 'member') ? '' : 'none';
        qs('[data-act="reply"]', this.menu).style.display = '';
        qs('[data-act="copy"]', this.menu).style.display = '';
        qs('[data-act="pin"]', this.menu).style.display = canModerate ? '' : 'none';
        qs('[data-act="delete"]', this.menu).style.display = (mine || canModerate) ? '' : 'none';
        qs('[data-act="pin"]', this.menu).textContent = row.getAttribute('data-pinned') === '1' ? 'Unpin' : 'Pin';

        this.menu.dataset.id = String(id);
        this.menu.classList.add('is-open');
        var rect = anchor.getBoundingClientRect();
        var rootRect = this.root.getBoundingClientRect();
        var top = rect.bottom - rootRect.top + 4;
        var left = Math.min(rect.left - rootRect.left, rootRect.width - 170);
        this.menu.style.top = Math.max(4, top) + 'px';
        this.menu.style.left = Math.max(4, left) + 'px';
    };

    ChatApp.prototype.closeMenu = function () {
        if (this.menu) { this.menu.classList.remove('is-open'); }
    };

    /* --------------------------------------------------------------- rows */

    ChatApp.prototype.row = function (id) {
        return qs('.chat-row[data-id="' + String(id) + '"]', this.list);
    };

    ChatApp.prototype.wireRow = function (row) {
        var self = this;
        // The id is read WHEN the control is used, not when it is wired: an
        // optimistic row is rendered before the server has given it one, and
        // a captured 0 would make every action on it fail.
        var currentId = function () { return parseInt(row.getAttribute('data-id') || '0', 10) || 0; };

        qsa('[data-chat-menu]', row).forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var id = currentId();
                if (id > 0) { self.openMenu(id, btn); }
            });
        });
        qsa('.chat-media img', row).forEach(function (img) {
            img.addEventListener('click', function (e) { e.preventDefault(); self.openLightbox(img.getAttribute('src'), img.getAttribute('alt')); });
        });
        qsa('.chat-reaction', row).forEach(function (chip) {
            chip.addEventListener('click', function () {
                var id = currentId();
                if (id > 0) { self.react(id, chip.getAttribute('data-emoji')); }
            });
        });
        var bubble = qs('.chat-bubble', row);
        if (bubble) {
            bubble.addEventListener('dblclick', function () {
                var id = currentId();
                if (id > 0) { self.startReply(id); }
            });
        }
    };

    ChatApp.prototype.markTicks = function () {
        var self = this;
        qsa('.chat-row[data-role="' + this.me + '"]', this.list).forEach(function (row) {
            var id = parseInt(row.getAttribute('data-id') || '0', 10);
            var ticks = qs('.chat-ticks', row);
            if (!ticks) { return; }
            if (self.readThrough >= id && id > 0) {
                ticks.textContent = '✓✓';
                ticks.classList.add('is-seen');
                ticks.setAttribute('aria-label', 'Seen');
            } else {
                ticks.textContent = '✓';
                ticks.classList.remove('is-seen');
                ticks.setAttribute('aria-label', 'Sent');
            }
        });
    };

    ChatApp.prototype.setReadThrough = function (value) {
        var v = parseInt(value || '0', 10) || 0;
        if (v === this.readThrough) { return; }
        this.readThrough = v;
        this.markTicks();
    };

    /* ------------------------------------------------------------ render */

    ChatApp.prototype.quoteHtml = function (quote) {
        if (!quote) { return ''; }
        var label = quote.role === this.me ? (this.me === 'operator' ? 'You' : 'You') : roleName(quote.role, this.me);
        var text = quote.deleted ? 'Message deleted' : quote.excerpt;
        return '<blockquote class="chat-quote"><b>' + esc(label) + '</b>' + esc(text) + '</blockquote>';
    };

    ChatApp.prototype.mediaHtml = function (msg) {
        if (!msg.attachment_name) { return ''; }
        if (msg.media_expired || !msg.attachment_url) {
            return '<span class="chat-file chat-expired">🔒 Media expired</span>';
        }
        if (msg.attachment_thumb_url) {
            return '<a class="chat-media" href="' + esc(msg.attachment_url) + '" target="_blank" rel="noopener">' +
                '<img loading="lazy" src="' + esc(msg.attachment_thumb_url) + '" alt="' + esc(msg.attachment_name) + '" draggable="false" data-nocontext></a>';
        }
        return '<a class="chat-file" href="' + esc(msg.attachment_url) + '" target="_blank" rel="noopener">📎 ' + esc(msg.attachment_name) + '</a>';
    };

    ChatApp.prototype.reactionsHtml = function (msg) {
        if (!msg.reactions || !msg.reactions.length) { return ''; }
        var me = this.me;
        var html = '<div class="chat-reactions">';
        msg.reactions.forEach(function (r) {
            var mine = (r.actors || []).indexOf(me) >= 0;
            html += '<button type="button" class="chat-reaction' + (mine ? ' is-mine' : '') + '" data-emoji="' + esc(r.emoji) + '" aria-pressed="' + (mine ? 'true' : 'false') + '">' +
                esc(r.emoji) + ' <span>' + (r.actors || []).length + '</span></button>';
        });
        return html + '</div>';
    };

    ChatApp.prototype.rowHtml = function (msg, opts) {
        opts = opts || {};
        var role = msg.sender_role || msg.role || 'user';
        var mine = role === this.me;
        var deleted = !!msg.deleted;
        var model = role === 'model';
        var classes = ['chat-row', mine ? 'chat-row--out' : 'chat-row--in'];
        if (opts.first) { classes.push('chat-row--start'); }
        if (opts.cont) { classes.push('chat-row--cont'); }

        var author = (!mine && opts.first) ? '<p class="chat-author">' + esc(roleName(role, this.me)) + '</p>' : '';
        var bubbleClass = 'chat-bubble' + (mine ? '' : ' chat-bubble--model');
        if (deleted) { bubbleClass += ' chat-bubble--deleted'; }

        var body = '';
        if (deleted) {
            body = '<span class="chat-text">This message was deleted</span>';
        } else {
            body = this.quoteHtml(msg.reply_to) + '<span class="chat-text">' + esc(msg.message || '') + '</span>' + this.mediaHtml(msg);
        }

        var ticks = '';
        if (mine && !deleted) {
            ticks = '<span class="chat-ticks' + (this.readThrough >= (msg.id || 0) ? ' is-seen' : '') + '">' +
                (this.readThrough >= (msg.id || 0) && msg.id ? '✓✓' : '✓') + '</span>';
        }

        var meta = '<div class="chat-meta"><span>' + esc(timeLabelOf(msg)) + '</span>' + ticks +
            (msg.edited && !deleted ? '<span class="chat-edited">edited</span>' : '') +
            '<button type="button" class="chat-btn" data-chat-menu style="width:22px;height:22px;font-size:.85rem" aria-label="Message actions">⋯</button></div>';

        return '<div class="' + classes.join(' ') + '" data-id="' + (msg.id || 0) + '" data-role="' + esc(role) + '" data-created="' + esc(msg.created_at || '') + '" data-day="' + esc(dayKeyOf(msg)) + '" data-pinned="' + (msg.pinned ? '1' : '0') + '">' +
            '<span class="chat-avatar chat-avatar--sm" aria-hidden="true">' + esc(initialFor(roleName(role, this.me))) + '</span>' +
            '<div class="chat-stack">' + author +
            '<div class="' + bubbleClass + '">' + body + '</div>' +
            this.reactionsHtml(msg) + meta +
            '</div></div>';
    };

    /** Should the next message start a new group? (sender changed or 5 min) */
    ChatApp.prototype.groupBreak = function (prev, next) {
        if (!prev) { return true; }
        var a = prev.getAttribute('data-role');
        var b = (next.sender_role || next.role || 'user');
        if (a !== b) { return true; }
        var t1 = parseDate(prev.getAttribute('data-created')).getTime();
        var t2 = parseDate(next.created_at).getTime();
        return Math.abs(t2 - t1) > 5 * 60 * 1000;
    };

    ChatApp.prototype.append = function (msg, opts) {
        opts = opts || {};
        var empty = qs('#chat-empty', this.list);
        if (empty) { empty.remove(); }
        var rows = qsa('.chat-row', this.list);
        var prev = rows.length ? rows[rows.length - 1] : null;
        var first = opts.first !== undefined ? opts.first : this.groupBreak(prev, msg);
        var cont = !first;

        var lastDay = qsa('.chat-day', this.list).pop();
        var lastDayKey = lastDay ? lastDay.getAttribute('data-day') : '';
        if (lastDayKey !== dayKeyOf(msg)) {
            var day = document.createElement('div');
            day.className = 'chat-day';
            day.setAttribute('data-day', dayKeyOf(msg));
            day.textContent = dayLabelOf(msg);
            this.list.appendChild(day);
        }

        var wrap = document.createElement('div');
        wrap.innerHTML = this.rowHtml(msg, { first: first, cont: cont });
        var row = wrap.firstChild;
        this.list.appendChild(row);
        this.wireRow(row);
        this.linkRefs(row, msg.content_refs || []);
        this.latestId = Math.max(this.latestId, parseInt(msg.id || 0, 10) || 0);
        return row;
    };

    ChatApp.prototype.linkRefs = function (el, refs) {
        var bubble = qs('.chat-bubble', el);
        if (!bubble || !refs || !refs.length) { return; }
        var html = bubble.innerHTML;
        refs.slice().sort(function (a, b) { return String(b.title || '').length - String(a.title || '').length; }).forEach(function (r) {
            if (!r.title || !r.url) { return; }
            var re = new RegExp(esc(r.title).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g');
            html = html.replace(re, '<a class="chat-ref" href="' + esc(r.url) + '" target="_blank" rel="noopener">$&</a>');
        });
        bubble.innerHTML = html;
    };

    /* ------------------------------------------------------------- scroll */

    ChatApp.prototype.atBottom = function () {
        return (this.list.scrollHeight - this.list.scrollTop - this.list.clientHeight) < 90;
    };

    ChatApp.prototype.scrollToBottom = function (smooth) {
        this.list.scrollTo({ top: this.list.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
        this.unread = 0;
        this.updateJump();
        this.restoreTitle();
    };

    ChatApp.prototype.updateJump = function () {
        if (!this.jump) { return; }
        var show = !this.atBottom();
        this.jump.classList.toggle('is-visible', show);
        var count = qs('b', this.jump);
        if (count) { count.textContent = this.unread > 0 ? String(this.unread) : '↓'; }
    };

    ChatApp.prototype.bindScroll = function () {
        var self = this;
        var ticking = false;
        this.list.addEventListener('scroll', function () {
            if (ticking) { return; }
            ticking = true;
            requestAnimationFrame(function () {
                ticking = false;
                self.updateJump();
                if (self.atBottom()) { self.unread = 0; self.restoreTitle(); self.updateJump(); }
                if (self.list.scrollTop < 60) { self.loadOlder(); }
            });
        });
        if (this.jump) {
            this.jump.addEventListener('click', function () { self.scrollToBottom(true); });
        }
    };

    ChatApp.prototype.bumpTitle = function () {
        if (!this.atBottom()) {
            this.unread += 1;
            document.title = '(' + this.unread + ') ' + this.baseTitle;
        }
    };

    ChatApp.prototype.restoreTitle = function () {
        if (document.title !== this.baseTitle) { document.title = this.baseTitle; }
    };

    ChatApp.prototype.loadOlder = function () {
        var self = this;
        if (this.loadingOlder || !this.hasMore || this.oldestId <= 0 || !this.endpoints.history) { return; }
        this.loadingOlder = true;
        var url = this.endpoints.history + (this.endpoints.history.indexOf('?') >= 0 ? '&' : '?') +
            'before=' + this.oldestId + '&limit=50';
        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.messages && res.messages.length) {
                    var prevHeight = self.list.scrollHeight;
                    res.messages.forEach(function (m, i) {
                        self.prepend(m, i === 0);
                    });
                    self.oldestId = parseInt(res.messages[0].id, 10) || self.oldestId;
                    self.list.scrollTop += self.list.scrollHeight - prevHeight;
                }
                self.hasMore = !!(res && res.has_more);
            })
            .catch(function () { /* transient */ })
            .finally(function () { self.loadingOlder = false; });
    };

    ChatApp.prototype.prepend = function (msg, first) {
        var firstRow = qs('.chat-row', this.list);
        var wrap = document.createElement('div');
        wrap.innerHTML = this.rowHtml(msg, { first: first, cont: !first });
        var row = wrap.firstChild;
        this.list.insertBefore(row, this.list.firstChild);

        // The divider that was above the old first row may belong to a
        // DIFFERENT day than the message just prepended. Give the older
        // message its own divider instead of stretching the newer one.
        var next = row.nextElementSibling;
        if (next && !next.classList.contains('chat-day')) {
            if (next.getAttribute('data-day') !== dayKeyOf(msg)) {
                var day = document.createElement('div');
                day.className = 'chat-day';
                day.setAttribute('data-day', dayKeyOf(msg));
                day.textContent = dayLabelOf(msg);
                this.list.insertBefore(day, next);
            }
        }

        this.wireRow(row);
        return row;
    };

    /* ------------------------------------------------------------ sending */

    ChatApp.prototype.autosize = function () {
        if (!this.input) { return; }
        this.input.style.height = 'auto';
        this.input.style.height = Math.max(42, Math.min(this.input.scrollHeight, 152)) + 'px';
    };

    ChatApp.prototype.onInput = function () {
        this.autosize();
        var count = qs('.chat-count', this.root);
        if (count) { count.textContent = (this.input.value || '').length + '/2000'; }
        try { localStorage.setItem(this.draftKey, this.input.value); } catch (e) { /* private mode */ }
        this.pingTyping();
    };

    ChatApp.prototype.pingTyping = function () {
        var self = this;
        if (!this.endpoints.typing) { return; }
        var now = Date.now();
        if (now - this.lastTypingPing < 3000) { return; }
        this.lastTypingPing = now;
        var body = new FormData();
        body.append('_token', this.token());
        fetch(this.endpoints.typing, { method: 'POST', body: body, credentials: 'same-origin' }).catch(function () {});
    };

    ChatApp.prototype.onKey = function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
            e.preventDefault();
            this.send();
        }
        if (e.key === 'Escape') {
            if (this.editId) { this.cancelEdit(); }
            else if (this.replyTo) { this.setReply(null); }
        }
    };

    ChatApp.prototype.onFile = function () {
        var f = this.fileInput && this.fileInput.files && this.fileInput.files[0];
        if (!this.attachPreview) { return; }
        if (!f) {
            this.attachPreview.classList.remove('is-visible');
            this.attachPreview.innerHTML = '';
            return;
        }
        if (f.size > this.limit) {
            this.toast('That file is too large (max ' + Math.round(this.limit / 1048576) + ' MB).');
            this.fileInput.value = '';
            return;
        }
        this.attachPreview.innerHTML = '';
        if (/^image\//.test(f.type)) {
            var img = document.createElement('img');
            img.alt = '';
            img.src = URL.createObjectURL(f);
            this.attachPreview.appendChild(img);
        } else {
            var ico = document.createElement('span');
            ico.textContent = '📎';
            this.attachPreview.appendChild(ico);
        }
        var name = document.createElement('span');
        name.className = 'chat-attach-name';
        name.textContent = f.name + ' (' + Math.max(1, Math.round(f.size / 1024)) + ' KB)';
        this.attachPreview.appendChild(name);
        var clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'chat-btn';
        clear.setAttribute('aria-label', 'Remove attachment');
        clear.textContent = '✕';
        var self = this;
        clear.addEventListener('click', function () {
            self.fileInput.value = '';
            self.onFile();
        });
        this.attachPreview.appendChild(clear);
        this.attachPreview.classList.add('is-visible');
    };

    ChatApp.prototype.send = function () {
        var self = this;
        var text = this.input ? this.input.value.trim() : '';
        var file = this.fileInput && this.fileInput.files && this.fileInput.files[0] ? this.fileInput.files[0] : null;
        if (!text && !file) { return; }
        if (file && file.size > this.limit) {
            this.toast('That file is too large (max ' + Math.round(this.limit / 1048576) + ' MB).');
            return;
        }

        // Edit mode: replace the text instead of sending a new message.
        if (this.editId) {
            this.saveEdit(text);
            return;
        }

        var replyTo = this.replyTo;
        var quote = replyTo ? this.quoteData(replyTo) : null;

        this.input.value = '';
        this.autosize();
        try { localStorage.removeItem(this.draftKey); } catch (e) { /* ignore */ }
        if (this.fileInput) { this.fileInput.value = ''; }
        this.onFile();
        this.setReply(null);

        var optimistic = { id: 0, sender_role: this.me, message: text, created_at: this.nowSql(), time_label: new Date().toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }), day: null, day_label: 'Today', reply_to: quote, reactions: [] };
        var row = this.append(optimistic, { first: true });
        row.setAttribute('data-tmp', '1');
        var ticks = qs('.chat-ticks', row);
        if (ticks) { ticks.textContent = '◷'; ticks.removeAttribute('aria-label'); }
        var bubble = qs('.chat-bubble', row);
        if (file && bubble) {
            if (/^image\//.test(file.type)) {
                // Show the picture the operator just picked rather than a
                // paperclip: the server's thumbnail replaces it on reload.
                var preview = document.createElement('a');
                preview.className = 'chat-media';
                preview.href = URL.createObjectURL(file);
                preview.target = '_blank';
                preview.rel = 'noopener';
                var img = document.createElement('img');
                img.src = preview.href;
                img.alt = file.name;
                img.loading = 'lazy';
                preview.appendChild(img);
                bubble.appendChild(preview);
            } else {
                var tag = document.createElement('div');
                tag.className = 'chat-file';
                tag.textContent = '📎 ' + file.name;
                bubble.appendChild(tag);
            }
        }
        this.scrollToBottom(false);

        var body = new FormData();
        body.append('_token', this.token());
        body.append('message', text);
        if (replyTo) { body.append('reply_to', String(replyTo)); }
        if (file) { body.append('attachment', file); }
        // The admin composer also carries the media-expiry choices.
        ['expires_in', 'max_views'].forEach(function (name) {
            var field = qs('[name="' + name + '"]', self.root);
            if (field && field.value) { body.append(name, field.value); }
        });

        this.setProgress(0);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', this.mode === 'member' ? this.endpoints.send : this.endpoints.reply, true);
        xhr.withCredentials = true;
        // Ask for JSON: the admin reply endpoint answers a plain form post
        // with a redirect, and the client needs the id of what it sent.
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable) { self.setProgress(Math.round((e.loaded / e.total) * 100)); }
        });
        xhr.addEventListener('load', function () {
            self.setProgress(-1);
            var ok = false;
            var data = {};
            try { data = JSON.parse(xhr.responseText || '{}'); ok = !!data.ok; } catch (err) { ok = xhr.status >= 200 && xhr.status < 400; }
            if (!ok) { self.failRow(row, text); return; }
            row.removeAttribute('data-tmp');
            if (data.user_message_id || data.message_id) {
                row.setAttribute('data-id', String(data.user_message_id || data.message_id));
            }
            if (ticks) { ticks.textContent = '✓'; }
            self.latestId = Math.max(self.latestId, parseInt(row.getAttribute('data-id') || '0', 10) || 0);
            if (data.ai_reply && self.mode === 'member') {
                self.receive([{ id: data.ai_reply_id || 0, sender_role: 'model', message: data.ai_reply, created_at: self.nowSql(), content_refs: data.ai_content_refs || [], reactions: [] }]);
            }
        });
        xhr.addEventListener('error', function () { self.setProgress(-1); self.failRow(row, text); });
        xhr.send(body);
    };

    ChatApp.prototype.failRow = function (row, text) {
        var self = this;
        var bubble = qs('.chat-bubble', row);
        if (bubble) { bubble.style.borderColor = 'var(--btn-danger-border, #f43f5e)'; }
        var meta = qs('.chat-meta', row);
        if (meta) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'chat-btn';
            btn.style.cssText = 'width:auto;height:20px;font-size:.72rem;color:var(--btn-danger-color,#fecdd3)';
            btn.textContent = 'Failed — retry';
            btn.addEventListener('click', function () {
                if (row.parentNode) { row.parentNode.removeChild(row); }
                self.input.value = text;
                self.autosize();
                self.send();
            });
            meta.appendChild(btn);
        }
        this.toast('Message not sent.');
    };

    ChatApp.prototype.setProgress = function (pct) {
        if (!this.progress) { return; }
        if (pct < 0) { this.progress.classList.remove('is-visible'); this.progress.firstChild.style.width = '0'; return; }
        this.progress.classList.add('is-visible');
        this.progress.firstChild.style.width = Math.max(8, pct) + '%';
    };

    ChatApp.prototype.nowSql = function () {
        var d = new Date();
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' +
            p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    };

    ChatApp.prototype.quoteData = function (id) {
        var row = this.row(id);
        if (!row) { return null; }
        var bubble = qs('.chat-bubble', row);
        return {
            id: id,
            role: row.getAttribute('data-role'),
            excerpt: bubble ? bubble.textContent.trim().slice(0, 140) : '',
            deleted: bubble ? bubble.classList.contains('chat-bubble--deleted') : false
        };
    };

    /* -------------------------------------------------------- reply/edit */

    ChatApp.prototype.setReply = function (id) {
        this.replyTo = id;
        if (!this.replyBar) { return; }
        if (!id) {
            this.replyBar.classList.remove('is-visible');
            return;
        }
        var data = this.quoteData(id);
        var quoteEl = qs('.chat-reply-quote', this.replyBar);
        if (quoteEl) { quoteEl.textContent = data ? data.excerpt : ''; }
        var labelEl = qs('.chat-reply-label', this.replyBar);
        if (labelEl) { labelEl.textContent = data ? 'Replying to ' + roleName(data.role, this.me) : 'Replying'; }
        this.replyBar.classList.add('is-visible');
        this.input && this.input.focus();
    };

    ChatApp.prototype.startReply = function (id) { this.setReply(id); };

    ChatApp.prototype.startEdit = function (id) {
        var row = this.row(id);
        if (!row) { return; }
        var bubble = qs('.chat-bubble', row);
        if (!bubble) { return; }
        this.editId = id;
        var textEl = qs('.chat-text', bubble);
        this.input.value = (textEl ? textEl.textContent : bubble.textContent).trim();
        this.autosize();
        this.setReply(null);
        this.input.focus();
        this.toast('Editing — Enter saves, Esc cancels.');
    };

    ChatApp.prototype.cancelEdit = function () {
        this.editId = null;
        this.input.value = '';
        this.autosize();
    };

    ChatApp.prototype.saveEdit = function (text) {
        var self = this;
        var id = this.editId;
        if (!text) { this.toast('Write something to save.'); return; }
        var body = new FormData();
        body.append('_token', this.csrf);
        body.append('id', String(id));
        body.append('message', text);
        fetch(this.endpoints.edit, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok) { throw new Error((res && res.error) || 'Could not save.'); }
                var row = self.row(id);
                if (row) {
                    var bubble = qs('.chat-bubble', row);
                    var textEl = bubble ? qs('.chat-text', bubble) : null;
                    if (textEl) { textEl.textContent = res.message; } else if (bubble) { bubble.textContent = res.message; }
                    var meta = qs('.chat-meta', row);
                    if (meta && !qs('.chat-edited', meta)) {
                        var tag = document.createElement('span');
                        tag.className = 'chat-edited';
                        tag.textContent = 'edited';
                        // Keep the visual order the server uses: time, ticks, edited, menu.
                        var menuBtn = qs('[data-chat-menu]', meta);
                        meta.insertBefore(tag, menuBtn || null);
                    }
                }
                self.cancelEdit();
                try { localStorage.removeItem(self.draftKey); } catch (e) { /* ignore */ }
            })
            .catch(function (err) { self.toast(err.message || 'Could not save.'); });
    };

    /* ---------------------------------------------------- delete / react */

    ChatApp.prototype.removeMessage = function (id) {
        var self = this;
        var row = this.row(id);
        var isAdmin = this.mode === 'admin';
        if (!window.confirm(isAdmin ? 'Delete this message for both sides?' : 'Delete this message?')) { return; }
        var body = new FormData();
        if (this.mode === 'member') {
            body.append('_token', this.csrf);
            body.append('id', String(id));
        } else {
            body.append('_token', this.token());
        }
        fetch(isAdmin ? this.endpoints.remove.replace('__MID__', String(id)) : this.endpoints.del, {
            method: 'POST', body: body, credentials: 'same-origin', headers: this._headers()
        }).then(function (r) { return r.json().catch(function () { return { ok: r.ok }; }); })
            .then(function (res) {
                if (!res || !res.ok) { throw new Error((res && res.error) || 'Could not delete.'); }
                if (row) {
                    row.setAttribute('data-role', row.getAttribute('data-role'));
                    var bubble = qs('.chat-bubble', row);
                    if (bubble) {
                        bubble.className = (bubble.className.replace('chat-bubble--model', '').trim()) + ' chat-bubble--deleted';
                        bubble.textContent = 'This message was deleted';
                    }
                    var reactions = qs('.chat-reactions', row);
                    if (reactions) { reactions.remove(); }
                    qsa('.chat-media, .chat-file, .chat-quote', row).forEach(function (n) { n.remove(); });
                }
            })
            .catch(function (err) { self.toast(err.message || 'Could not delete.'); });
    };

    ChatApp.prototype.postPin = function (id) {
        var self = this;
        var row = this.row(id);
        var pinBody = new FormData();
        pinBody.append('_token', this.token());
        fetch(this.endpoints.pin.replace('__MID__', String(id)), {
            method: 'POST', body: pinBody, credentials: 'same-origin', headers: this._headers()
        }).then(function (r) { return r.json().catch(function () { return { ok: r.ok }; }); })
            .then(function (res) {
                if (!res || !res.ok) { throw new Error((res && res.error) || 'Could not pin.'); }
                if (row) { row.setAttribute('data-pinned', res.pinned ? '1' : '0'); }
                self.toast(res.pinned ? 'Pinned.' : 'Unpinned.');
            })
            .catch(function (err) { self.toast(err.message || 'Could not pin.'); });
    };

    /** The CSRF token this page posts with (the admin form carries one). */
    ChatApp.prototype.token = function () {
        var field = qs('input[name="_token"]', this.root);
        return field ? field.value : this.csrf;
    };

    ChatApp.prototype._headers = function () {
        return { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    };

    ChatApp.prototype.react = function (id, emoji) {
        var self = this;
        if (!id) { return; }
        var body = new FormData();
        body.append('_token', this.token());
        body.append('message_id', String(id));
        body.append('emoji', emoji);
        var reactUrl = this.endpoints.react.indexOf('__MID__') >= 0
            ? this.endpoints.react.replace('__MID__', String(id))
            : this.endpoints.react;
        fetch(reactUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: this._headers() })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok) { throw new Error((res && res.error) || 'Could not react.'); }
                var row = self.row(id);
                if (!row) { return; }
                var html = self.reactionsHtml({ reactions: res.reactions || [] });
                var old = qs('.chat-reactions', row);
                if (old) { old.remove(); }
                if (html) {
                    var holder = document.createElement('div');
                    holder.innerHTML = html;
                    var fresh = holder.firstChild;
                    var stack = qs('.chat-stack', row);
                    stack.insertBefore(fresh, qs('.chat-meta', stack));
                    qsa('.chat-reaction', fresh).forEach(function (chip) {
                        chip.addEventListener('click', function () { self.react(id, chip.getAttribute('data-emoji')); });
                    });
                }
            })
            .catch(function (err) { self.toast(err.message || 'Could not react.'); });
    };

    ChatApp.prototype.copyText = function (id) {
        var row = this.row(id);
        var bubble = row ? qs('.chat-bubble', row) : null;
        if (!bubble) { return; }
        var text = bubble.textContent.trim();
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(this.toast.bind(this, 'Copied.'), this.toast.bind(this, 'Could not copy.'));
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); this.toast('Copied.'); } catch (e) { this.toast('Could not copy.'); }
            document.body.removeChild(ta);
        }
    };

    /* -------------------------------------------------------- typing/SSE */

    ChatApp.prototype.showTyping = function (on) {
        if (!this.typingEl) { return; }
        this.typingEl.classList.toggle('is-visible', !!on);
        if (on) { this.typingEl.setAttribute('aria-label', (this.mode === 'member' ? 'Amethyst is typing' : 'The member is typing')); }
    };

    ChatApp.prototype.connectStream = function () {
        var self = this;
        if (!this.endpoints.stream) { return; }
        var url = this.endpoints.stream + (this.endpoints.stream.indexOf('?') >= 0 ? '&' : '?') + 'since=' + this.latestId;
        var es = new EventSource(url, { withCredentials: true });
        es.onmessage = function (e) {
            var data;
            try { data = JSON.parse(e.data); } catch (err) { return; }
            if (!data || !data.ok) { return; }
            if (data.messages && data.messages.length) { self.receive(data.messages); }
            if (typeof data.typing !== 'undefined') { self.showTyping(data.typing); }
            if (typeof data.read_through !== 'undefined') { self.setReadThrough(data.read_through); }
            if (data.latestId) { self.latestId = Math.max(self.latestId, parseInt(data.latestId, 10) || 0); }
            if (data.mode) { self.root.setAttribute('data-ai-mode', data.mode); }
        };
        es.onerror = function () { /* EventSource reconnects on its own */ };
        this.stream = es;
    };

    /** Poll for new messages (admin mode, and the member fallback). */
    ChatApp.prototype.startPoll = function (intervalMs) {
        var self = this;
        if (!this.endpoints.poll) {
            if (this.mode !== 'member') { return; }
            // The member page names its endpoints explicitly; the poll lives
            // beside the stream on the same base.
            this.endpoints.poll = this.endpoints.stream.replace(/\/stream(\?.*)?$/, '/poll');
        }
        if (!this.endpoints.poll) { return; }
        var inFlight = false;
        this.pollTimer = setInterval(function () {
            if (inFlight || document.hidden) { return; }
            inFlight = true;
            var url = self.endpoints.poll + (self.endpoints.poll.indexOf('?') >= 0 ? '&' : '?') +
                (self.mode === 'admin' ? 'conversation=' + self.cid + '&' : '') + 'since=' + self.latestId;
            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok) { return; }
                    if (res.messages && res.messages.length) { self.receive(res.messages); }
                    if (typeof res.typing !== 'undefined') { self.showTyping(res.typing); }
                    if (typeof res.read_through !== 'undefined') { self.setReadThrough(res.read_through); }
                })
                .catch(function () { /* transient */ })
                .finally(function () { inFlight = false; });
        }, intervalMs || 5000);
    };


    ChatApp.prototype.receive = function (messages) {
        var self = this;
        messages.forEach(function (m) {
            var id = parseInt(m.id || 0, 10);
            if (id > 0 && self.row(id)) { return; } // already rendered (echo)
            if (id > 0 && id <= self.oldestId && self.oldestId > 0) { return; }
            self.append(m);
            self.bumpTitle();
        });
        if (this.atBottom()) { this.scrollToBottom(true); }
    };

    /* -------------------------------------------------------------- search */

    ChatApp.prototype.toggleSearch = function (btn) {
        if (!this.searchBar) { return; }
        var open = !this.searchBar.classList.contains('is-open');
        this.searchBar.classList.toggle('is-open', open);
        if (btn) { btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        if (open) { this.searchInput.focus(); }
        else if (this.searchInput) { this.searchInput.value = ''; this.searchStatus && (this.searchStatus.textContent = ''); }
    };

    ChatApp.prototype.search = function () {
        var self = this;
        var q = this.searchInput ? this.searchInput.value.trim() : '';
        if (!this.endpoints.search) { return; }
        if (!q) { if (this.searchStatus) { this.searchStatus.textContent = ''; } return; }
        if (this.searchStatus) { this.searchStatus.textContent = 'Searching…'; }
        fetch(this.endpoints.search + (this.endpoints.search.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json' }, credentials: 'same-origin'
        }).then(function (r) { return r.json(); })
            .then(function (res) {
                var results = (res && res.results) || [];
                if (self.searchStatus) { self.searchStatus.textContent = results.length ? results.length + ' found' : 'No matches'; }
                if (results.length) { self.highlight(results[0].id); }
            })
            .catch(function () { if (self.searchStatus) { self.searchStatus.textContent = 'Search failed'; } });
    };

    ChatApp.prototype.highlight = function (id) {
        var row = this.row(id);
        if (!row) { return; }
        row.scrollIntoView({ block: 'center', behavior: 'smooth' });
        row.classList.add('chat-hit');
        setTimeout(function () { row.classList.remove('chat-hit'); }, 1800);
    };

    /* ------------------------------------------------------------ uploads */

    ChatApp.prototype.bindDrop = function () {
        var self = this;
        var zone = qs('.chat-drop', this.root);
        if (!zone) { return; }
        var depth = 0;
        ['dragenter', 'dragover'].forEach(function (ev) {
            self.root.addEventListener(ev, function (e) {
                if (!e.dataTransfer || !e.dataTransfer.types || e.dataTransfer.types.indexOf('Files') < 0) { return; }
                e.preventDefault();
                depth++;
                zone.classList.add('is-active');
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            self.root.addEventListener(ev, function (e) {
                e.preventDefault();
                depth = Math.max(0, depth - 1);
                if (depth === 0) { zone.classList.remove('is-active'); }
                if (ev === 'drop' && e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length && self.fileInput) {
                    self.fileInput.files = e.dataTransfer.files;
                    self.onFile();
                }
            });
        });
    };

    ChatApp.prototype.bindLightbox = function () {
        if (!this.lightbox) { return; }
        var self = this;
        this.lightbox.addEventListener('click', function (e) {
            if (e.target === self.lightbox || e.target.closest('.chat-lightbox-close')) { self.closeLightbox(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { self.closeLightbox(); }
        });
    };

    ChatApp.prototype.openLightbox = function (src, alt) {
        if (!this.lightbox || !src) { return; }
        var img = qs('img', this.lightbox);
        if (img) { img.src = src; img.alt = alt || 'Image'; }
        this.lightbox.classList.add('is-open');
        qs('.chat-lightbox-close', this.lightbox).focus();
    };

    ChatApp.prototype.closeLightbox = function () {
        if (this.lightbox) { this.lightbox.classList.remove('is-open'); }
    };

    /* --------------------------------------------------------------- misc */

    ChatApp.prototype.buildPicker = function () {
        var self = this;
        if (!this.picker) { return; }
        PICKER.forEach(function (emoji) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = emoji;
            b.setAttribute('aria-label', 'Insert ' + emoji);
            b.addEventListener('click', function () {
                if (!self.input) { return; }
                self.input.value += emoji;
                self.onInput();
                self.input.focus();
            });
            self.picker.appendChild(b);
        });
    };

    ChatApp.prototype.togglePicker = function (btn) {
        if (!this.picker) { return; }
        var open = !this.picker.classList.contains('is-open');
        this.picker.classList.toggle('is-open', open);
        if (btn) { btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    };

    ChatApp.prototype.toast = function (text) {
        var el = qs('#chat-toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'chat-toast';
            el.className = 'chat-toast';
            el.setAttribute('role', 'status');
            this.root.appendChild(el);
        }
        el.textContent = text;
        el.classList.add('is-visible');
        clearTimeout(this._toastTimer);
        var self = this;
        this._toastTimer = setTimeout(function () { el.classList.remove('is-visible'); }, 3200);
    };

    document.addEventListener('DOMContentLoaded', function () {
        qsa('#chat-app').forEach(function (root) { new ChatApp(root); });
    });
})();
