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

    var STATUS_LABEL = { active: '启用', pending: '待确认', dead: '掉链', disabled: '停用' };
    var STATUS_COLOR = { active: '#16a34a', pending: '#d97706', dead: '#dc2626', disabled: '#6b7280' };

    function render() {
        var table = el('linksTable');
        if (!table) return;
        var head = '<tr><th>站点</th><th>状态</th><th>排序</th><th>最近巡检</th><th>操作</th></tr>';
        if (!rows.length) {
            table.innerHTML = head + '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px">暂无友链</td></tr>';
            return;
        }
        table.innerHTML = head + rows.map(function (r) {
            var st = r.status || 'active';
            var fav = r.favicon
                ? '<img src="' + esc(r.favicon) + '" alt="" style="width:28px;height:28px;border-radius:6px;object-fit:cover;background:#f1f5f9" onerror="this.style.display=\'none\'">'
                : '<span style="display:inline-flex;width:28px;height:28px;border-radius:6px;background:#eef2ff;color:#4f46e5;align-items:center;justify-content:center;font-weight:700">' + esc((r.site_name || '?').charAt(0).toUpperCase()) + '</span>';
            var checked = r.last_check ? new Date(r.last_check * 1000).toLocaleString() : '—';
            var toggle = st === 'active'
                ? '<button class="btn btn-sm btn-outline" data-act="status" data-id="' + r.id + '" data-status="disabled">停用</button>'
                : '<button class="btn btn-sm btn-outline" data-act="status" data-id="' + r.id + '" data-status="active">启用</button>';
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
                    '<button class="btn btn-sm btn-outline" data-act="edit" data-id="' + r.id + '">编辑</button> ' +
                    '<button class="btn btn-sm btn-outline" data-act="check" data-id="' + r.id + '">巡检</button> ' +
                    toggle + ' ' +
                    '<button class="btn btn-sm btn-outline" data-act="delete" data-id="' + r.id + '" style="color:#dc2626;border-color:#fca5a5">删除</button>' +
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
        el('linkFormTitle').textContent = '新增友链';
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
        el('linkFormTitle').textContent = '编辑友链 #' + r.id;
        el('linkCancelBtn').style.display = 'inline-flex';
        formMsg('');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function load() {
        api('../api.php?action=links_admin').then(function (d) {
            if (d && d.success) { rows = d.links || []; render(); }
            else formMsg((d && d.error) || '加载失败');
        }).catch(function () { formMsg('加载失败'); });
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
        if (!body.site_name || !body.url) { formMsg('站点名与地址必填'); return; }
        formMsg('保存中…', true);
        api('../api.php?action=links_save', { method: 'POST', body: JSON.stringify(body) }).then(function (d) {
            if (d && d.success) { resetForm(); formMsg('已保存', true); load(); }
            else formMsg((d && d.error) || '保存失败');
        }).catch(function () { formMsg('保存失败'); });
    });

    el('linkCancelBtn').addEventListener('click', resetForm);

    el('linkFetchBtn').addEventListener('click', function () {
        var url = el('linkUrl').value.trim();
        if (!url) { formMsg('请先填写站点地址'); return; }
        formMsg('抓取中…', true);
        api('../api.php?action=links_meta&url=' + encodeURIComponent(url)).then(function (d) {
            if (d && d.ok) {
                if (d.name) el('linkName').value = d.name;
                if (d.desc) el('linkDesc').value = d.desc;
                if (d.favicon) el('linkFavicon').value = d.favicon;
                formMsg('已抓取，可继续修改后保存', true);
            } else {
                formMsg((d && d.error) || '抓取失败');
            }
        }).catch(function () { formMsg('抓取失败'); });
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
                btn.textContent = '巡检中';
                api('../api.php?action=links_check&id=' + id).then(function (d) {
                    if (d && d.success) { load(); } else { btn.disabled = false; btn.textContent = '巡检'; }
                }).catch(function () { btn.disabled = false; btn.textContent = '巡检'; });
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

    resetForm();
    load();
})();
