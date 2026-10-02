/* You Super Markdown — AI 写作 · 个人 Key 管理（v5.4.0-beta）
 * 站长 / 写作者后台共用：为每个账号配置各自的服务商 Key（绑定站内账号）。
 * 安全口径：前端只提交 key/model/provider；服务端连通性测试通过才保存；接口只回传"已配置(****末四位)/未配置"。
 */
(function () {
    'use strict';
    var box = document.getElementById('aiKeyManager');
    if (!box) return;
    var csrf = box.getAttribute('data-csrf') || '';
    var providers = [];
    var state = {}; // provider -> {configured, hint, model, is_default}

    function api(action, method, body) {
        return fetch('../api.php?action=' + action, {
            method: method || 'GET',
            headers: method === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : {},
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) {
            return r.json().catch(function () { return { success: false, error: 'HTTP ' + r.status }; });
        });
    }
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s === null || s === undefined) ? '' : String(s);
        return d.innerHTML;
    }
    function notice(msg, ok) {
        var el = document.getElementById('aiKeyNotice');
        if (!el) return;
        el.textContent = msg || '';
        el.className = 'msg ' + (ok ? 'msg-success' : 'msg-error');
        el.style.display = msg ? 'flex' : 'none';
    }

    function render() {
        var html = '';
        providers.forEach(function (p) {
            var st = state[p.id] || { configured: false, hint: '未配置', model: '', is_default: false };
            html += '<div class="card" style="margin-bottom:14px">';
            html += '<div class="card-title" style="display:flex;justify-content:space-between;align-items:center">'
                + '<span>' + esc(p.label) + '</span>'
                + '<span style="font-size:0.82em;font-weight:600;' + (st.configured ? 'color:var(--accent)' : 'color:var(--text-muted)') + '">'
                + esc(st.hint) + (st.is_default ? ' · 默认' : '') + '</span></div>';
            html += '<div class="form-row">';
            html += '<div class="form-group"><label class="form-label">模型名（自行填写）</label>'
                + '<input class="form-input ai-model" data-provider="' + esc(p.id) + '" value="' + esc(st.model) + '" placeholder="如 deepseek-chat / qwen-plus"></div>';
            html += '<div class="form-group"><label class="form-label">API Key' + (st.configured ? '（留空=不修改）' : '') + '</label>'
                + '<input class="form-input ai-key" type="password" data-provider="' + esc(p.id) + '" autocomplete="off" placeholder="粘贴你的 Key（不会回显）"></div>';
            html += '</div>';
            html += '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">';
            html += '<label style="display:flex;align-items:center;gap:6px;font-size:0.9em;color:var(--text-secondary)">'
                + '<input type="checkbox" class="ai-default" data-provider="' + esc(p.id) + '" ' + (st.is_default ? 'checked' : '') + ' style="accent-color:var(--accent)"> 设为默认</label>';
            html += '<button type="button" class="btn btn-sm btn-outline ai-test" data-provider="' + esc(p.id) + '">测试连接</button>';
            html += '<button type="button" class="btn btn-sm btn-primary ai-save" data-provider="' + esc(p.id) + '">保存</button>';
            if (st.configured) html += '<button type="button" class="btn btn-sm btn-danger ai-del" data-provider="' + esc(p.id) + '">删除</button>';
            html += '<span class="form-hint ai-msg" data-provider="' + esc(p.id) + '"></span>';
            html += '</div>';
            html += '</div>';
        });
        box.innerHTML = html;
    }

    function rowMsg(pid, text, ok) {
        var el = box.querySelector('.ai-msg[data-provider="' + pid + '"]');
        if (!el) return;
        el.textContent = text || '';
        el.style.color = ok ? 'var(--accent)' : 'var(--danger,#dc2626)';
    }
    function readRow(pid) {
        var m = box.querySelector('.ai-model[data-provider="' + pid + '"]');
        var k = box.querySelector('.ai-key[data-provider="' + pid + '"]');
        var d = box.querySelector('.ai-default[data-provider="' + pid + '"]');
        return { model: m ? m.value.trim() : '', key: k ? k.value.trim() : '', is_default: d ? d.checked : false };
    }

    box.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('button') : null;
        if (!t) return;
        var pid = t.getAttribute('data-provider');
        if (!pid) return;
        var row = readRow(pid);
        if (t.classList.contains('ai-test')) {
            if (!row.key) { rowMsg(pid, '请先填写 Key', false); return; }
            t.disabled = true; rowMsg(pid, '测试中…', true);
            api('ai_key_test', 'POST', { provider: pid, model: row.model, key: row.key }).then(function (d) {
                t.disabled = false;
                rowMsg(pid, d.message || (d.success ? '连接成功' : '测试失败'), !!d.success);
            }).catch(function () { t.disabled = false; rowMsg(pid, '网络错误', false); });
        } else if (t.classList.contains('ai-save')) {
            if (!row.key) { rowMsg(pid, '请填写 Key（如需保留原 Key 请直接保存模型）', false); return; }
            t.disabled = true; rowMsg(pid, '校验并保存中…', true);
            api('ai_key_save', 'POST', { provider: pid, model: row.model, key: row.key, is_default: row.is_default }).then(function (d) {
                t.disabled = false;
                if (d.success) { applyKeys(d.keys); notice('已保存（' + pid + '）', true); }
                else rowMsg(pid, d.error || '保存失败', false);
            }).catch(function () { t.disabled = false; rowMsg(pid, '网络错误', false); });
        } else if (t.classList.contains('ai-del')) {
            if (!confirm('确定删除该服务商的 Key？')) return;
            t.disabled = true;
            api('ai_key_delete', 'POST', { provider: pid }).then(function (d) {
                t.disabled = false;
                if (d.success) { applyKeys(d.keys); notice('已删除（' + pid + '）', true); }
                else rowMsg(pid, d.error || '删除失败', false);
            }).catch(function () { t.disabled = false; rowMsg(pid, '网络错误', false); });
        }
    });

    function applyKeys(keys) {
        if (!keys) return;
        keys.forEach(function (k) { state[k.provider] = k; });
        render();
    }

    api('ai_keys', 'GET').then(function (d) {
        if (!d.success) { notice(d.error || '无法加载（AI 可能未开放）', false); return; }
        providers = d.providers || [];
        (d.keys || []).forEach(function (k) { state[k.provider] = k; });
        render();
    }).catch(function () { notice('网络错误', false); });
})();
