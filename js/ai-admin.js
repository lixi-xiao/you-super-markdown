/* You Super Markdown — AI 写作 · 个人 Key 管理（v5.4.3-beta.2）
 * 站长 / 写作者后台共用：为每个账号配置各自的服务商 Key（绑定站内账号）。
 * 安全口径：前端只提交 key/model/provider；服务端连通性测试通过才保存；接口只回传"已配置(****末四位)/未配置"。
 * v5.4.3-beta.2：模型名改为「下拉选择」（数据来自服务端名单）；旧配置不在名单时标注「待重选」并保留 Key；
 *   新增账号级「思考模式」开关（默认关闭；服务商不支持时给出提示）。
 */
(function () {
    'use strict';
    var box = document.getElementById('aiKeyManager');
    if (!box) return;
    var csrf = box.getAttribute('data-csrf') || '';
    var providers = [];
    var models = {};          // provider -> [ {id,label,max_input_chars,max_out_tokens} ]
    var thinkingSupport = {}; // provider -> bool（是否支持思考模式开关）
    var thinking = false;     // 账号级思考模式（默认关闭）
    var state = {};           // provider -> {configured, hint, model, is_default, model_available}

    function api(action, method, body) {
        // 本页不加载 main.js，其 fetch 包装不会注入 X-Fp —— 取值与 main.js 一致，否则后端校验环境失败返回 401
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
    // 统一读取后端可读原因——env_invalid 优先（提示重新登录），其次 message / error
    function reasonOf(d, fallback) {
        d = d || {};
        if (d.env_invalid) return '登录环境已变化，请重新登录';
        return d.message || d.error || fallback;
    }
    // 该 provider 的模型是否在名单内
    function modelKnown(pid, id) {
        var list = models[pid] || [];
        for (var i = 0; i < list.length; i++) if (list[i].id === id) return true;
        return false;
    }
    // 模型下拉：显示 label（无 label 时回退 id），提交 id；旧配置不在名单 → 置顶「待重选」项（保留 Key）
    function modelSelect(pid, st) {
        var list = models[pid] || [];
        var pending = st.configured && st.model && !modelKnown(pid, st.model);
        var html = '<select class="form-select ai-model" data-provider="' + esc(pid) + '">';
        if (pending) html += '<option value="' + esc(st.model) + '" selected>（待重选）' + esc(st.model) + '</option>';
        else html += '<option value="" disabled' + (st.model ? '' : ' selected') + '>请选择模型</option>';
        for (var i = 0; i < list.length; i++) {
            var m = list[i];
            var lb = m.label ? (m.label + '（' + m.id + '）') : m.id;
            var sel = (!pending && m.id === st.model) ? ' selected' : '';
            html += '<option value="' + esc(m.id) + '"' + sel + '>' + esc(lb) + '</option>';
        }
        return html + '</select>';
    }
    function modelHint(pid, st) {
        if (st.configured && st.model && !modelKnown(pid, st.model)) {
            return '<div class="ai-hint ai-model-hint ai-model-reselect">当前模型「' + esc(st.model) + '」已不在可用名单，请重新选择（Key 已保留）</div>';
        }
        if (thinkingSupport[pid] === false) {
            return '<div class="ai-hint ai-model-hint">该服务商/模型不支持思考模式开关</div>';
        }
        return '';
    }
    // 账号级思考模式开关卡（默认关闭）
    function thinkCard() {
        var supported = [];
        providers.forEach(function (p) { if (thinkingSupport[p.id] !== false) supported.push(p.label); });
        var note = supported.length
            ? ('支持思考模式的服务商：' + supported.join('、'))
            : '当前所有服务商均不支持思考模式开关';
        return '<div class="ai-think-card">'
            + '<div class="ai-think-row">'
            + '<input type="checkbox" id="aiThinkToggle"' + (thinking ? ' checked' : '') + '>'
            + '<div><label for="aiThinkToggle" class="ai-think-label">思考模式（更会推理但更慢更贵）</label>'
            + '<div class="form-hint" style="margin:2px 0 0">账号级，默认关闭；关闭时按服务商默认行为处理。</div></div>'
            + '</div>'
            + '<div class="ai-think-note">' + esc(note) + '</div>'
            + '</div>';
    }

    function render() {
        var html = thinkCard();
        providers.forEach(function (p) {
            var st = state[p.id] || { configured: false, hint: '未配置', model: '', is_default: false };
            html += '<div class="ai-key-card' + (st.is_default ? ' is-default' : '') + '" data-provider="' + esc(p.id) + '">';
            html += '<div class="ai-key-head">'
                + '<span class="ai-key-name">' + esc(p.label) + '</span>'
                + '<span class="ai-key-state' + (st.configured ? ' is-on' : '') + '">'
                + esc(st.hint) + (st.is_default ? ' · 默认' : '') + '</span></div>';
            html += '<div class="ai-key-grid">';
            html += '<div class="form-group"><label class="form-label">模型（从名单选择）</label>'
                + modelSelect(p.id, st) + modelHint(p.id, st) + '</div>';
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

    // 思考模式开关（账号级；切换即保存）
    box.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || t.id !== 'aiThinkToggle') return;
        var want = !!t.checked;
        t.disabled = true;
        api('ai_thinking', 'POST', { enabled: want }).then(function (d) {
            t.disabled = false;
            if (!d.success) { t.checked = !want; notice(reasonOf(d, '思考模式保存失败'), false, !!d.env_invalid); return; }
            thinking = !!d.thinking;
            if (d.thinking_support) thinkingSupport = d.thinking_support;
            notice('思考模式已' + (thinking ? '开启' : '关闭'), true);
            render();
        }).catch(function () { t.disabled = false; t.checked = !want; notice('网络错误', false); });
    });

    box.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('button') : null;
        if (!t) return;
        var pid = t.getAttribute('data-provider');
        if (!pid) return;
        var row = readRow(pid);
        if (t.classList.contains('ai-test')) {
            if (!row.model) { rowMsg(pid, '请先选择模型', 'err'); return; }
            if (!row.key) { rowMsg(pid, '请先填写 Key', 'err'); return; }
            t.disabled = true; rowMsg(pid, '测试中…', 'busy');
            api('ai_key_test', 'POST', { provider: pid, model: row.model, key: row.key }).then(function (d) {
                t.disabled = false;
                if (d.success) rowMsg(pid, d.message || '连接成功', 'ok');
                else fail(pid, d, '测试失败');
            }).catch(function () { t.disabled = false; rowMsg(pid, '网络错误', 'err'); });
        } else if (t.classList.contains('ai-save')) {
            if (!row.model) { rowMsg(pid, '请选择模型', 'err'); return; }
            if (!row.key) { rowMsg(pid, '请填写 Key（如需保留原 Key 请直接保存模型）', 'err'); return; }
            t.disabled = true; rowMsg(pid, '校验并保存中…', 'busy');
            api('ai_key_save', 'POST', { provider: pid, model: row.model, key: row.key, is_default: row.is_default }).then(function (d) {
                t.disabled = false;
                if (d.success) { applyResponse(d); notice('已保存（' + pid + '）', true); }
                else fail(pid, d, '保存失败');
            }).catch(function () { t.disabled = false; rowMsg(pid, '网络错误', 'err'); });
        } else if (t.classList.contains('ai-del')) {
            if (!confirm('确定删除该服务商的 Key？')) return;
            t.disabled = true;
            api('ai_key_delete', 'POST', { provider: pid }).then(function (d) {
                t.disabled = false;
                if (d.success) { applyResponse(d); notice('已删除（' + pid + '）', true); }
                else fail(pid, d, '删除失败');
            }).catch(function () { t.disabled = false; rowMsg(pid, '网络错误', 'err'); });
        }
    });

    // 应用后端响应（按存在字段增量更新，再整体重绘）
    function applyResponse(d) {
        if (!d) return;
        if (d.providers) providers = d.providers;
        if (d.models) models = d.models;
        if (d.thinking_support) thinkingSupport = d.thinking_support;
        if (typeof d.thinking !== 'undefined') thinking = !!d.thinking;
        (d.keys || []).forEach(function (k) { state[k.provider] = k; });
        render();
    }

    api('ai_keys', 'GET').then(function (d) {
        if (!d.success) { notice(reasonOf(d, '无法加载（AI 可能未开放）'), false, !!d.env_invalid); return; }
        applyResponse(d);
    }).catch(function () { notice('网络错误', false); });
})();
