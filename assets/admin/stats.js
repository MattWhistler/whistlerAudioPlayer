(function () {
    'use strict';
    if (typeof window === 'undefined' || !window.aspStats) {
        return;
    }
    var cfg = window.aspStats;

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                if (k === 'class') node.className = attrs[k];
                else if (k === 'html') node.innerHTML = attrs[k];
                else node.setAttribute(k, attrs[k]);
            });
        }
        (children || []).forEach(function (c) {
            if (typeof c === 'string') node.appendChild(document.createTextNode(c));
            else if (c) node.appendChild(c);
        });
        return node;
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function closeModal() {
        var existing = document.querySelector('.asp-modal');
        if (existing) existing.remove();
    }

    function openModal(contents) {
        closeModal();
        var box = el('div', { class: 'asp-modal__box', role: 'dialog', 'aria-modal': 'true' }, [
            el('button', { class: 'asp-modal__close', type: 'button', 'aria-label': cfg.i18n.close }, ['×']),
            contents
        ]);
        var modal = el('div', { class: 'asp-modal' }, [box]);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
        box.querySelector('.asp-modal__close').addEventListener('click', closeModal);
        document.addEventListener('keydown', function escListener(e) {
            if (e.key === 'Escape') {
                closeModal();
                document.removeEventListener('keydown', escListener);
            }
        });
        document.body.appendChild(modal);
    }

    function fmtTime(seconds) {
        seconds = Math.max(0, parseInt(seconds, 10) || 0);
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return m + ':' + (s < 10 ? '0' + s : s);
    }

    function renderFunnel(funnel) {
        var started = Math.max(1, funnel.started || 0);
        var stages = [
            ['started', cfg.labels.started, funnel.started || 0],
            ['reached_25', cfg.labels.reached_25, funnel.reached_25 || 0],
            ['reached_50', cfg.labels.reached_50, funnel.reached_50 || 0],
            ['reached_75', cfg.labels.reached_75, funnel.reached_75 || 0],
            ['completed', cfg.labels.completed, funnel.completed || 0]
        ];
        var ol = el('ol', { class: 'asp-metabox__funnel' });
        stages.forEach(function (s) {
            var pct = Math.round((s[2] / started) * 100);
            var li = document.createElement('li');
            li.className = 'asp-funnel__row';
            li.innerHTML =
                '<span class="asp-funnel__label">' + escapeHtml(s[1]) + '</span>' +
                '<span class="asp-funnel__bar"><span style="width:' + pct + '%"></span></span>' +
                '<span class="asp-funnel__count">' + s[2] + ' (' + pct + '%)</span>';
            ol.appendChild(li);
        });
        return ol;
    }

    function renderEvents(events) {
        var table = document.createElement('table');
        table.innerHTML =
            '<thead><tr>' +
            '<th>' + escapeHtml(cfg.i18n.when) + '</th>' +
            '<th>' + escapeHtml(cfg.i18n.event) + '</th>' +
            '<th>' + escapeHtml(cfg.i18n.pos) + '</th>' +
            '<th>' + escapeHtml(cfg.i18n.extra) + '</th>' +
            '</tr></thead><tbody></tbody>';
        var tbody = table.querySelector('tbody');
        events.forEach(function (ev) {
            var tr = document.createElement('tr');
            var extra = ev.extra_data || '';
            var bot = parseInt(ev.is_bot, 10) === 1 ? ' (' + escapeHtml(cfg.i18n.bot) + ')' : '';
            tr.innerHTML =
                '<td>' + escapeHtml(ev.created_at || '') + '</td>' +
                '<td>' + escapeHtml(ev.event_type || '') + bot + '</td>' +
                '<td>' + fmtTime(ev.position_seconds) + '</td>' +
                '<td><code>' + escapeHtml(extra) + '</code></td>';
            tbody.appendChild(tr);
        });
        return table;
    }

    function fetchDetails(postId, since, bots) {
        var url = cfg.endpoint
            + (cfg.endpoint.indexOf('?') >= 0 ? '&' : '?')
            + 'post_id=' + encodeURIComponent(postId)
            + '&since=' + encodeURIComponent(since || '')
            + '&bots=' + encodeURIComponent(bots ? '1' : '0');

        var box = el('div', null, []);
        box.appendChild(el('p', null, [cfg.i18n.loading]));
        openModal(box);

        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce, 'Accept': 'application/json' }
        })
            .then(function (res) {
                if (!res.ok) throw new Error('http_' + res.status);
                return res.json();
            })
            .then(function (data) {
                box.innerHTML = '';
                box.appendChild(el('h2', null, [data.post_title || ('#' + postId)]));
                box.appendChild(el('h3', null, [cfg.i18n.funnel]));
                box.appendChild(renderFunnel(data.funnel || {}));
                box.appendChild(el('h3', null, [cfg.i18n.recent]));
                box.appendChild(renderEvents(data.events || []));
            })
            .catch(function () {
                box.innerHTML = '';
                box.appendChild(el('p', null, [cfg.i18n.error]));
            });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.asp-details-btn');
        if (!btn) return;
        e.preventDefault();
        var postId = parseInt(btn.getAttribute('data-post-id'), 10);
        var since = btn.getAttribute('data-since') || '';
        var bots = btn.getAttribute('data-bots') === '1';
        if (postId > 0) {
            fetchDetails(postId, since, bots);
        }
    });
})();
