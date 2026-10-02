/* You Super Markdown — AI 写作 · 个人 Key 管理（v5.4.0-beta.2）
 * 站长 / 写作者后台共用：为每个账号配置各自的服务商 Key（绑定站内账号）。
 * 安全口径：前端只提交 key/model/provider；服务端连通性测试通过才保存；接口只回传"已配置(****末四位)/未配置"。
 * v5.4.0-beta.2：失败时透出后端可读原因（message/error）；env_invalid 时引导重新登录；模型名输入框下方给出推荐模型名。
 */
(function () {
    'use strict';
    var box = document.getElementById('aiKeyManager');
    if (!box) return;
    var csrf = box.getAttribute('data-csrf') || '';
    var providers = [];
    var state = {}; // provider -> {configured, hint, model, is_default}

    // v5.4.0-beta.2：各服务商推荐模型名（仅提示，不强制、不预填；与后端白名单 provider id 对应）
    var MODEL_HINTS = {
        'deepseek':  { rec: 'deepseek-chat',  note: '' },
        'mimo-payg': { rec: 'mimo-v2.6-pro',  note: 'mimo-v2-pro 已下线，请勿再填' },
        'mimo-plan': { rec: 'mimo-v2.6-pro',  note: 'mimo-v2-pro 已下线，请勿再填' },
        'qwen':      { rec: 'qwen-plus',      note: '' }
    };

    function api(action, method, body) {
        // v5.4.0-beta.4：补 X-Fp（本页不加载 main.js，其 fetch 包装不会注入）——取值与 main.js 一致，否则后端校验环境失败返回 401
        var headers = { 'X-Fp': (typeof window.ysmGetFp === 'function' ? window.ysmGetFp() : '') };
        if (method === 'POST') { headers['Content-Type'] = 'application/json'; headers['X-CSRF-Token'] = csrf; }
        return fetch('../api.php?action=' + action, {
            method: method || 'GET',
            headers: headers,
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
    function notice(msg, ok, relogin) {
        var el = document.getElementById('aiKeyNotice');
        if (!el) return;
        if (relogin) el.innerHTML = esc(msg) + ' <a class="ai-relogin" href="../?admin_login=1">前往登录</a>';
        else el.textContent = msg || '';
        el.className = 'msg ' + (ok ? 'msg-success' : 'msg-error');
        el.style.display = msg ? 'flex' : 'none';
    }
    // v5.4.0-beta.2：统一读取后端可读原因——env_invalid 优先（提示重新登录），其次 message / error
    function reasonOf(d, fallback) {
        d = d || {};
        if (d.env_invalid) return '登录环境已变化，请重新登录';
        return d.message || d.error || fallback;
    }
    function modelHint(pid) {
        var h = MODEL_HINTS[pid];
        if (!h) return '';
        var t = '推荐模型：' + h.rec + (h.note ? '（' + h.note + '）' : '');
        return '<div class="ai-hint ai-model-hint">' + esc(t) + '</div>';
    }

    function render() {
        var html = '';
        providers.forEach(function (p) {
            var st = state[p.id] || { configured: false, hint: '未配置', model: '', is_default: false };
            html += '<div class="ai-key-card' + (st.is_default ? ' is-default' : '') + '" data-provider="' + esc(p.id) + '">';
            html += '<div class="ai-key-head">'
                + '<span class="ai-key-name">' + esc(p.label) + '</span>'
                + '<span class="ai-key-state' + (st.configured ? ' is-on' : '') + '">'
                + esc(st.hint) + (st.is_default ? ' · 默认' : '') + '</span></div>';
            html += '<div class="ai-key-grid">';
            html += '<div class="form-group"><label class="form-label">模型名（自行填写）</label>'
                + '<input class="form-input ai-model" data-provider="' + esc(p.id) + '" value="' + esc(st.model) + '" placeholder="如 deepseek-chat / qwen-plus">'
                + modelHint(p.id) + '</div>';
            html += '<div class="form-group"><label class="form-label">API Key' + (st.configured ? '（留空=不修改）' : '') + '</label>'
                + '<input class="form-input ai-key" type="password" data-provider="' + esc(p.id) + '" autocomplete="off" placeholder="粘贴你的 Key（不会回显）"></div>';
            html += '</div>';
            html += '<div class="ai-key-actions">';
            html += '<label class="ai-default-label"><input type="checkbox" class="ai-default" data-provider="' + esc(p.id) + '" ' + (st.is_default ? 'checked' : '') + '> 设为默认</label>';
            html += '<span class="ai-msg" data-provider="' + esc(p.id) + '"></span>';
            html += '<span class="ai-key-btns">';
            html += '<button type="button" class="btn btn-sm btn-outline ai-test" data-provider="' + esc(p.id) + '">测试连接</button>';
            html += '<button type="button" class="btn btn-sm btn-primary ai-save" data-provider="' + esc(p.id) + '">保存</button>';
            if (st.configured) html += '<button type="button" class="btn btn-sm btn-danger ai-del" data-provider="' + esc(p.id) + '">删除</button>';
            html += '</span></div>';
            html += '</div>';
        });
        box.innerHTML = html;
    }

    function rowMsg(pid, text, tone) {
        var el = box.querySelector('.ai-msg[data-provider="' + pid + '"]');
        if (!el) return;
        el.textContent = text || '';
        el.className = 'ai-msg' + (tone ? ' is-' + tone : '');
    }
    // 失败统一出口：透出可读原因；env_invalid 时另行提示重新登录
    function fail(pid, d, fallback) {
        var reason = reasonOf(d, fallback);
        rowMsg(pid, reason, 'err');
        if (d && d.env_invalid) notice(reason, false, true);
        return reason;
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
            if (!row.key) { rowMsg(pid, '请先填写 Key', 'err'); return; }
            t.disabled = true; rowMsg(pid, '测试中…', 'busy');
            api('ai_key_test', 'POST', { provider: pid, model: row.model, key: row.key }).then(function (d) {
                t.disabled = false;
                if (d.success) rowMsg(pid, d.message || '连接成功', 'ok');
                else fail(pid, d, '测试失败');
            }).catch(function () { t.disabled = false; rowMsg(pid, '网络错误', 'err'); });
        } else if (t.classList.contains('ai-save')) {
            if (!row.key) { rowMsg(pid, '请填写 Key（如需保留原 Key 请直接保存模型）', 'err'); return; }
            t.disabled = true; rowMsg(pid, '校验并保存中…', 'busy');
            api('ai_key_save', 'POST', { provider: pid, model: row.model, key: row.key, is_default: row.is_default }).then(function (d) {
                t.disabled = false;
                if (d.success) { applyKeys(d.keys); notice('已保存（' + pid + '）', true); }
                else fail(pid, d, '保存失败');
            }).catch(function () { t.disabled = false; rowMsg(pid, '网络错误', 'err'); });
        } else if (t.classList.contains('ai-del')) {
            if (!confirm('确定删除该服务商的 Key？')) return;
            t.disabled = true;
            api('ai_key_delete', 'POST', { provider: pid }).then(function (d) {
                t.disabled = false;
                if (d.success) { applyKeys(d.keys); notice('已删除（' + pid + '）', true); }
                else fail(pid, d, '删除失败');
            }).catch(function () { t.disabled = false; rowMsg(pid, '网络错误', 'err'); });
        }
    });

    function applyKeys(keys) {
        if (!keys) return;
        keys.forEach(function (k) { state[k.provider] = k; });
        render();
    }

    api('ai_keys', 'GET').then(function (d) {
        if (!d.success) { notice(reasonOf(d, '无法加载（AI 可能未开放）'), false, !!d.env_invalid); return; }
        providers = d.providers || [];
        (d.keys || []).forEach(function (k) { state[k.provider] = k; });
        render();
    }).catch(function () { notice('网络错误', false); });
})();
