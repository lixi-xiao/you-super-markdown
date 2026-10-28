/**
 * v6.0.0：站长后台「友情链接」管理
 * 依赖：api.php 的 links_admin / links_save / links_delete / links_status / links_meta / links_check
 * 安全：所有写操作带 X-CSRF-Token；请求带 X-Fp（环境指纹），满足后台会话环境校验。
 */
(function () {
    'use strict';

    var root = document.getElementById('linksAdmin');
    if (!root) return;

    var csrf = root.getAttribute('data-csrf') || '';
    var rows = [];
    // v6.2.0：动态文案翻译（简体下原样返回）
    var T = window.TK || function (s) { return s; };

    function el(id) { return document.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fp() { return (typeof window.ysmGetFp === 'function') ? window.ysmGetFp() : ''; }

    function api(url, opts) {
        opts = opts || {};
        var headers = opts.headers || {};
        headers['X-CSRF-Token'] = csrf;
        headers['X-Fp'] = fp();
        headers['X-FpS'] = (typeof window.ysmGetStableFp === 'function') ? window.ysmGetStableFp() : '';
        if (opts.body && !headers['Content-Type']) headers['Content-Type'] = 'application/json';
        opts.headers = headers;
        return fetch(url, opts).then(function (r) { return r.json(); });
    }

    function formMsg(text, ok) {
        var m = el('linkFormMsg');
        if (!m) return;
        m.textContent = text || '';
        m.style.color = ok ? '#16a34a' : '#dc2626';
    }

    var STATUS_LABEL = { active: TK('links.001'), pending: TK('links.002'), dead: TK('links.003'), disabled: TK('links.004') };
    var STATUS_COLOR = { active: '#16a34a', pending: '#d97706', dead: '#dc2626', disabled: '#6b7280' };

    function render() {
        var table = el('linksTable');
        if (!table) return;
        var head = '<tr><th>' + TK('links.005') + '</th><th>' + TK('links.006') + '</th><th>' + TK('links.007') + '</th><th>' + TK('links.008') + '</th><th>' + TK('links.009') + '</th></tr>';
        if (!rows.length) {
            table.innerHTML = head + '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px">' + TK('links.010') + '</td></tr>';
            return;
        }
        table.innerHTML = head + rows.map(function (r) {
            var st = r.status || 'active';
            var fav = r.favicon
                ? '<img src="' + esc(r.favicon) + '" alt="" style="width:28px;height:28px;border-radius:6px;object-fit:cover;background:#f1f5f9" onerror="this.style.display=\'none\'">'
                : '<span style="display:inline-flex;width:28px;height:28px;border-radius:6px;background:#eef2ff;color:#4f46e5;align-items:center;justify-content:center;font-weight:700">' + esc((r.site_name || '?').charAt(0).toUpperCase()) + '</span>';
            var checked = r.last_check ? new Date(r.last_check * 1000).toLocaleString() : '—';
            var toggle = st === 'active'
                ? '<button class="btn btn-sm btn-outline" data-act="status" data-id="' + r.id + '" data-status="disabled">' + TK('links.004') + '</button>'
                : '<button class="btn btn-sm btn-outline" data-act="status" data-id="' + r.id + '" data-status="active">' + TK('links.001') + '</button>';
            return '<tr>' +
                '<td><div style="display:flex;gap:10px;align-items:center;min-width:0">' + fav +
                    '<div style="min-width:0"><div style="font-weight:600;word-break:break-all">' + esc(r.site_name) + '</div>' +
                    '<a href="' + esc(r.url) + '" target="_blank" rel="noopener" style="font-size:12px;color:var(--text-muted);word-break:break-all">' + esc(r.url) + '</a>' +
                    (r.desc ? '<div style="font-size:12px;color:var(--text-secondary);word-break:break-word">' + esc(r.desc) + '</div>' : '') +
                    '</div></div></td>' +
                '<td><span style="color:' + (STATUS_COLOR[st] || '#6b7280') + ';font-weight:600">' + esc(STATUS_LABEL[st] || st) + '</span></td>' +
                '<td>' + (parseInt(r.sort, 10) || 0) + '</td>' +
                '<td style="font-size:12px;color:var(--text-muted)">' + esc(checked) + '</td>' +
                '<td style="white-space:nowrap">' +
                    '<button class="btn btn-sm btn-outline" data-act="edit" data-id="' + r.id + '">' + TK('links.011') + '</button> ' +
                    '<button class="btn btn-sm btn-outline" data-act="check" data-id="' + r.id + '">' + TK('links.012') + '</button> ' +
                    toggle + ' ' +
                    '<button class="btn btn-sm btn-outline" data-act="delete" data-id="' + r.id + '" style="color:#dc2626;border-color:#fca5a5">' + TK('links.013') + '</button>' +
                '</td>' +
            '</tr>';
        }).join('');
    }

    function resetForm() {
        el('linkId').value = '';
        el('linkName').value = '';
        el('linkUrl').value = '';
        el('linkDesc').value = '';
        el('linkFavicon').value = '';
        el('linkSort').value = '0';
        el('linkFormTitle').textContent = TK('links.014');
        el('linkCancelBtn').style.display = 'none';
        formMsg('');
    }

    function fillForm(r) {
        el('linkId').value = r.id;
        el('linkName').value = r.site_name || '';
        el('linkUrl').value = r.url || '';
        el('linkDesc').value = r.desc || '';
        el('linkFavicon').value = r.favicon || '';
        el('linkSort').value = parseInt(r.sort, 10) || 0;
        el('linkFormTitle').textContent = T('编辑友链 #' + r.id);
        el('linkCancelBtn').style.display = 'inline-flex';
        formMsg('');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function load() {
        api('../api.php?action=links_admin').then(function (d) {
            if (d && d.success) { rows = d.links || []; render(); }
            else formMsg((d && d.error) || TK('links.015'));
        }).catch(function () { formMsg(TK('links.015')); });
    }

    el('linkSaveBtn').addEventListener('click', function () {
        var body = {
            id: parseInt(el('linkId').value, 10) || 0,
            site_name: el('linkName').value.trim(),
            url: el('linkUrl').value.trim(),
            desc: el('linkDesc').value.trim(),
            favicon: el('linkFavicon').value.trim(),
            sort: parseInt(el('linkSort').value, 10) || 0
        };
        if (!body.site_name || !body.url) { formMsg(TK('links.016')); return; }
        formMsg(TK('links.017'), true);
        api('../api.php?action=links_save', { method: 'POST', body: JSON.stringify(body) }).then(function (d) {
            if (d && d.success) { resetForm(); formMsg(TK('links.018'), true); load(); }
            else formMsg((d && d.error) || TK('links.019'));
        }).catch(function () { formMsg(TK('links.019')); });
    });

    el('linkCancelBtn').addEventListener('click', resetForm);

    el('linkFetchBtn').addEventListener('click', function () {
        var url = el('linkUrl').value.trim();
        if (!url) { formMsg(TK('links.020')); return; }
        formMsg(TK('links.021'), true);
        api('../api.php?action=links_meta&url=' + encodeURIComponent(url)).then(function (d) {
            if (d && d.ok) {
                if (d.name) el('linkName').value = d.name;
                if (d.desc) el('linkDesc').value = d.desc;
                if (d.favicon) el('linkFavicon').value = d.favicon;
                formMsg(TK('links.022'), true);
            } else {
                formMsg((d && d.error) || TK('links.023'));
            }
        }).catch(function () { formMsg(TK('links.023')); });
    });

    var table = el('linksTable');
    if (table) {
        table.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('button[data-act]') : null;
            if (!btn) return;
            var act = btn.getAttribute('data-act');
            var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
            var row = null;
            for (var i = 0; i < rows.length; i++) { if (parseInt(rows[i].id, 10) === id) { row = rows[i]; break; } }
            if (!row) return;

            if (act === 'edit') { fillForm(row); return; }
            if (act === 'status') {
                api('../api.php?action=links_status', { method: 'POST', body: JSON.stringify({ id: id, status: btn.getAttribute('data-status') }) })
                    .then(function (d) { if (d && d.success) load(); });
                return;
            }
            if (act === 'check') {
                btn.disabled = true;
                btn.textContent = TK('links.024');
                api('../api.php?action=links_check&id=' + id).then(function (d) {
                    if (d && d.success) { load(); } else { btn.disabled = false; btn.textContent = TK('links.012'); }
                }).catch(function () { btn.disabled = false; btn.textContent = TK('links.012'); });
                return;
            }
            if (act === 'delete') {
                var tt = window.ysmT || function (s) { return s; };
                if (!confirm(tt('确定删除「') + (row.site_name || '') + tt('」？'))) return;
                api('../api.php?action=links_delete', { method: 'POST', body: JSON.stringify({ id: id }) })
                    .then(function (d) { if (d && d.success) load(); });
            }
        });
    }

    // ================= v6.3.9：申请审核（待审核 / 已拒绝 / 展示设置 / 统计） =================
    function setCount(id, n) { var e = el(id); if (e) e.textContent = n; }

    function itemHtml(r, isPending) {
        var meta = [r.url, r.email, r.created || r.decided_at || ''].filter(Boolean).map(esc).join(' · ');
        return '<div class="link-item" data-id="' + esc(r.id) + '">' +
            '<div class="link-item-main">' +
                '<div class="link-item-title">' + esc(r.site_name) + '</div>' +
                (r.desc ? '<div class="link-item-desc">' + esc(r.desc) + '</div>' : '') +
                '<div class="link-item-meta">' + meta + '</div>' +
                (r.reject_reason ? '<div class="link-item-meta" style="color:#dc2626">' + esc(r.reject_reason) + '</div>' : '') +
            '</div>' +
            '<div class="link-item-acts">' +
                (isPending
                    ? '<button class="btn btn-sm btn-primary" data-aa="approve">' + TK('links.apply_approve') + '</button>' +
                      '<button class="btn btn-sm btn-outline" data-aa="reject">' + TK('links.apply_reject') + '</button>' +
                      '<a class="btn btn-sm btn-outline" href="' + esc(r.url) + '" target="_blank" rel="noopener">' + TK('links.dash_visit') + '</a>'
                    : '<a class="btn btn-sm btn-outline" href="' + esc(r.url) + '" target="_blank" rel="noopener">' + TK('links.dash_visit') + '</a>') +
            '</div>' +
        '</div>';
    }

    function loadApplies() {
        api('../api.php?action=links_applies_list').then(function (d) {
            if (!d || !d.success) return;
            var c = d.counts || {};
            setCount('lsPending', c.pending || 0);
            setCount('lsActive', c.active || 0);
            setCount('lsRejected', c.rejected || 0);
            setCount('linkPendingCount', '(' + (c.pending || 0) + ')');
            setCount('linkRejectedCount', '(' + (c.rejected || 0) + ')');
            var box = el('linkReviewRequired');
            if (box) box.checked = !!d.review_required;
            var pl = el('linkPendingList');
            if (pl) pl.innerHTML = (d.pending && d.pending.length)
                ? d.pending.map(function (r) { return itemHtml(r, true); }).join('')
                : '<div class="link-empty">' + TK('links.dash_empty') + '</div>';
            var rl = el('linkRejectedList');
            if (rl) rl.innerHTML = (d.rejected && d.rejected.length)
                ? d.rejected.map(function (r) { return itemHtml(r, false); }).join('')
                : '<div class="link-empty">' + TK('links.dash_empty') + '</div>';
        }).catch(function () {});
    }

    function decide(id, decision, btn) {
        var body = { id: id, decision: decision, reason: '' };
        if (decision === 'reject') {
            var why = window.prompt(TK('links.dash_reason_prompt'), '');
            if (why === null) return;
            body.reason = String(why).slice(0, 60);
        }
        if (btn) btn.disabled = true;
        api('../api.php?action=links_apply_decide', { method: 'POST', body: JSON.stringify(body) }).then(function (d) {
            if (d && d.success) { loadApplies(); load(); }
            else if (btn) { btn.disabled = false; formMsg((d && d.error) || TK('links.019')); }
        }).catch(function () { if (btn) btn.disabled = false; });
    }

    var pendingBox = el('linkPendingList');
    if (pendingBox) {
        pendingBox.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-aa]');
            if (!btn) return;
            var item = btn.closest('.link-item');
            if (!item) return;
            decide(item.getAttribute('data-id'), btn.getAttribute('data-aa'), btn);
        });
    }

    var setting = el('linkReviewRequired');
    if (setting) {
        setting.addEventListener('change', function () {
            api('../api.php?action=links_setting', { method: 'POST', body: JSON.stringify({ review_required: setting.checked ? 1 : 0 }) })
                .then(function (d) { if (!d || !d.success) setting.checked = !setting.checked; });
        });
    }

    document.querySelectorAll('.link-stat[data-jump]').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = el(b.getAttribute('data-jump'));
            if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    resetForm();
    load();
    loadApplies();
})();
