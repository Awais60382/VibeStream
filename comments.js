/* YouTube-style comments: like / dislike / reply / delete / sort / post.
   Works on every <section class="vs-cm" data-endpoint data-video-id> on the page. */
(function () {
    'use strict';

    function notify(msg) {
        if (typeof window.toast === 'function') { window.toast(msg); return; }
        var t = document.getElementById('toast');
        if (t) {
            t.textContent = msg;
            t.style.display = 'block';
            setTimeout(function () { t.style.display = 'none'; }, 3000);
        } else {
            alert(msg);
        }
    }

    function post(root, action, extra) {
        var body = new URLSearchParams(Object.assign({ action: action, video_id: root.dataset.videoId }, extra || {}));
        return fetch(root.dataset.endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (r) { return r.text(); }).then(function (t) {
            try { return JSON.parse(t); } catch (e) { return { success: false, error: 'Server returned an unexpected response' }; }
        }).catch(function () { return { success: false, error: 'Server error' }; });
    }

    function setCount(root, n) {
        Array.prototype.forEach.call(root.querySelectorAll('[data-cm-count]'), function (el) { el.textContent = n; });
        root.dispatchEvent(new CustomEvent('vs-comments:count', { bubbles: true, detail: { count: n } }));
    }

    function closeMenus(except) {
        Array.prototype.forEach.call(document.querySelectorAll('.vs-cm-menu'), function (m) {
            if (m !== except) m.hidden = true;
        });
    }

    function applyVote(item, d) {
        var like = item.querySelector('.vs-cm-like');
        var dislike = item.querySelector('.vs-cm-dislike');
        item.dataset.likes = d.likes;
        item.querySelector('.vs-cm-likes').textContent = d.likes > 0 ? d.likes : '';
        like.classList.toggle('on', d.my_vote === 1);
        dislike.classList.toggle('on', d.my_vote === -1);
        like.setAttribute('aria-pressed', d.my_vote === 1 ? 'true' : 'false');
        dislike.setAttribute('aria-pressed', d.my_vote === -1 ? 'true' : 'false');
    }

    function sortList(root, mode) {
        var list = root.querySelector('.vs-cm-list');
        var items = Array.prototype.slice.call(list.querySelectorAll('.vs-cm-item'));
        items.sort(function (a, b) {
            var ta = parseInt(a.dataset.ts || '0', 10), tb = parseInt(b.dataset.ts || '0', 10);
            var ia = parseInt(a.dataset.id || '0', 10), ib = parseInt(b.dataset.id || '0', 10);
            if (mode === 'top') {
                var d = parseInt(b.dataset.likes || '0', 10) - parseInt(a.dataset.likes || '0', 10);
                if (d) return d;
            }
            return (tb - ta) || (ib - ia);
        });
        items.forEach(function (el) { list.appendChild(el); });
        Array.prototype.forEach.call(root.querySelectorAll('.vs-cm-sortmenu button'), function (b) {
            b.classList.toggle('on', b.dataset.cm === (mode === 'top' ? 'sort-top' : 'sort-new'));
        });
    }

    function showEmptyIfNeeded(root) {
        var list = root.querySelector('.vs-cm-list');
        if (!list.querySelector('.vs-cm-item') && !list.querySelector('.vs-cm-empty')) {
            var d = document.createElement('div');
            d.className = 'vs-cm-empty';
            d.textContent = 'No comments yet. Be the first one.';
            list.appendChild(d);
        }
    }

    function submit(root) {
        var input = root.querySelector('.vs-cm-input');
        var compose = root.querySelector('.vs-cm-compose');
        var text = input.value.trim();
        if (!text || input.disabled || root.dataset.busy) return;
        root.dataset.busy = '1';
        post(root, 'comment', { comment_text: text }).then(function (d) {
            delete root.dataset.busy;
            if (!d.success) { notify(d.error || 'Comment failed'); return; }
            var list = root.querySelector('.vs-cm-list');
            var empty = list.querySelector('.vs-cm-empty');
            if (empty) empty.remove();
            if (d.html) {
                list.insertAdjacentHTML('afterbegin', d.html);
                list.scrollTop = 0;
            }
            input.value = '';
            compose.classList.remove('has-text');
            setCount(root, d.comment_count);
        });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-cm]');
        if (!btn) { closeMenus(); return; }
        var root = btn.closest('.vs-cm');
        if (!root) return;
        var action = btn.dataset.cm;
        var item = btn.closest('.vs-cm-item');

        if (action === 'menu' || action === 'sort') {
            var menu = btn.parentNode.querySelector('.vs-cm-menu');
            var willOpen = menu.hidden;
            closeMenus(menu);
            menu.hidden = !willOpen;
            return;
        }
        closeMenus();

        if (action === 'sort-top' || action === 'sort-new') {
            sortList(root, action === 'sort-top' ? 'top' : 'new');
        } else if (action === 'post') {
            submit(root);
        } else if (action === 'like' || action === 'dislike') {
            if (root.dataset.loggedIn !== '1') { notify('Sign in to like or dislike comments.'); return; }
            post(root, 'comment_vote', { comment_id: item.dataset.id, vote: action === 'like' ? 1 : -1 }).then(function (d) {
                if (!d.success) { notify(d.error || 'Could not save your vote'); return; }
                applyVote(item, d);
            });
        } else if (action === 'reply') {
            var input = root.querySelector('.vs-cm-input');
            if (!input || input.disabled) return;
            input.value = '@' + item.dataset.user + ' ';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        } else if (action === 'heart') {
            post(root, 'comment_heart', { comment_id: item.dataset.id }).then(function (d) {
                if (!d.success) { notify(d.error || 'Could not heart the comment'); return; }
                btn.classList.toggle('on', !!d.hearted);
                btn.setAttribute('aria-pressed', d.hearted ? 'true' : 'false');
                var badge = item.querySelector('.vs-cm-hearted');
                if (badge) badge.hidden = !d.hearted;
            });
        } else if (action === 'copy') {
            var txt = item.querySelector('.vs-cm-text').textContent;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(txt).then(function () { notify('Comment copied'); });
            }
        } else if (action === 'delete') {
            if (!confirm('Delete this comment?')) return;
            post(root, 'comment_delete', { comment_id: item.dataset.id }).then(function (d) {
                if (!d.success) { notify(d.error || 'Could not delete the comment'); return; }
                item.remove();
                setCount(root, d.comment_count);
                showEmptyIfNeeded(root);
            });
        }
    });

    // opened from a notification (watch.php?id=...#comment-12): scroll to that comment and flash it
    function focusCommentFromHash() {
        var m = /^#comment-(\d+)$/.exec(window.location.hash || '');
        if (!m) return;
        var el = document.getElementById('comment-' + m[1]);
        if (!el) return;
        el.scrollIntoView({ block: 'center' });
        el.classList.add('vs-cm-flash');
        setTimeout(function () { el.classList.remove('vs-cm-flash'); }, 2800);
    }
    setTimeout(focusCommentFromHash, 350);

    document.addEventListener('input', function (e) {
        if (!e.target.classList || !e.target.classList.contains('vs-cm-input')) return;
        var compose = e.target.closest('.vs-cm-compose');
        if (compose) compose.classList.toggle('has-text', e.target.value.trim() !== '');
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || !e.target.classList || !e.target.classList.contains('vs-cm-input')) return;
        var root = e.target.closest('.vs-cm');
        if (root) { e.preventDefault(); submit(root); }
    });
})();
