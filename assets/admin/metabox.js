(function () {
    'use strict';
    if (typeof window === 'undefined' || !window.aspMetabox) {
        return;
    }

    var cfg = window.aspMetabox;
    var root = document.getElementById('asp_stats_metabox');
    if (!root) {
        return;
    }
    var box = root.querySelector('.asp-metabox');
    if (!box) {
        return;
    }
    var postId = parseInt(box.getAttribute('data-post-id'), 10);
    if (!postId) {
        return;
    }

    var loading = box.querySelector('.asp-metabox__loading');
    var content = box.querySelector('.asp-metabox__content');
    var errorEl = box.querySelector('.asp-metabox__error');

    function showError() {
        if (loading) loading.hidden = true;
        if (errorEl) {
            errorEl.textContent = cfg.i18n.error;
            errorEl.hidden = false;
        }
    }

    function setText(name, value) {
        var el = box.querySelector('[data-asp="' + name + '"]');
        if (el) el.textContent = value;
    }

    function renderFunnel(funnel) {
        var ol = box.querySelector('[data-asp="funnel"]');
        if (!ol) return;
        var started = funnel.started || 0;
        var stages = [
            ['started', cfg.labels.started, funnel.started],
            ['reached_25', cfg.labels.reached_25, funnel.reached_25],
            ['reached_50', cfg.labels.reached_50, funnel.reached_50],
            ['reached_75', cfg.labels.reached_75, funnel.reached_75],
            ['completed', cfg.labels.completed, funnel.completed]
        ];
        ol.innerHTML = '';
        stages.forEach(function (stage) {
            var key = stage[0], label = stage[1], count = stage[2] || 0;
            var pct = started > 0 ? Math.round((count / started) * 100) : 0;
            var li = document.createElement('li');
            li.className = 'asp-funnel__row asp-funnel__row--' + key;
            li.innerHTML =
                '<span class="asp-funnel__label">' + label + '</span>' +
                '<span class="asp-funnel__bar"><span style="width:' + pct + '%"></span></span>' +
                '<span class="asp-funnel__count">' + count + ' (' + pct + '%)</span>';
            ol.appendChild(li);
        });
    }

    var url = cfg.endpoint + (cfg.endpoint.indexOf('?') >= 0 ? '&' : '?') + 'post_id=' + encodeURIComponent(postId);
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
            if (loading) loading.hidden = true;
            if (content) content.hidden = false;
            setText('plays', String(data.plays || 0));
            setText('completion', (data.completion_rate || 0) + '%');
            setText('avg-listen', data.avg_listen_human || '0:00');
            renderFunnel(data.funnel || {});
        })
        .catch(showError);
})();
