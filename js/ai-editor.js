// v6.4.0-beta：编辑器 AI 浮层（原 sc.php 第 1248-1601 行内联脚本）
//   选中文本 → 动作 → 流式 SSE → 差异预览 → 插入/替换/撤销；Key 永不触达前端。
//   依赖前序脚本提供：window.YSM_AI_CFG（configUrl）、js/fp.js（X-Fp 指纹）、js/i18n.js（T）。
// ============================================================================
// v5.4.0-beta：AI 写作侧边浮层（选中文本 → 动作 → 流式 SSE → 差异预览 → 插入/替换/撤销）
//   Key 永不触达前端；接口只回传"已配置(****末四位)/未配置"。
// v5.4.2-beta：记住上次选择（动作/服务商/风格/语言，localStorage）、生成可取消 + 失败可重试、
//   显示耗时（上游若回 usage 则一并显示 token）、未配置 Key 时给出「去配置 Key」引导、移动端底部操作条吸底。
// ============================================================================
(function() {
    var T = window.ysmT || function(s) { return s; };
    var $ = function(id) { return document.getElementById(id); };
    var drawer = $('aiDrawer');
    var ta = $('editContent');
    if (!drawer || !ta) return;
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var aiConfigUrl = (window.YSM_AI_CFG || {}).configUrl || 'api.php?action=ai_config';
    var cfg = null, captured = '', result = '', lastSel = { start: 0, end: 0 };
    // v6.3.3：最近一次有效选区快照——点击按钮等操作可能让 textarea 失焦、选区归零，
    //         打开浮层时用它兜底，保证「先选中、再点按钮」这条路始终取到用户刚选的内容。
    var selSnap = { start: 0, end: 0 };
    var undoValue = null, busy = false, finalized = false, curAction = 'polish';
    // v5.4.2-beta：记住上次选择（动作/服务商/风格/语言）——localStorage，纯前端偏好，不含任何 Key
    var PREF_KEY = 'ysm_ai_pref';
    function loadPref() { try { return JSON.parse(localStorage.getItem(PREF_KEY) || '{}') || {}; } catch (e) { return {}; } }
    function savePref() { try { localStorage.setItem(PREF_KEY, JSON.stringify({ action: curAction, provider: $('aiProvider').value, style: $('aiStyle').value, lang: $('aiLang').value })); } catch (e) {} }
    // v5.4.2-beta：生成可取消（AbortController）+ 失败可重试 + 显示耗时（上游若回 usage 则一并显示 token）
    var controller = null, tokenUsage = null;

    function api(action, method, body) {
        // v5.4.0-beta.4：补 X-Fp（本页不加载 main.js，其 fetch 包装不会注入）——取值与 main.js 一致，否则后端校验环境失败返回 401
        var headers = { 'X-Fp': (typeof window.ysmGetFp === 'function' ? window.ysmGetFp() : '') };
        if (method === 'POST') { headers['Content-Type'] = 'application/json'; headers['X-CSRF-Token'] = csrf; }
        return fetch('api.php?action=' + action, {
            method: method || 'GET',
            headers: headers,
            body: body ? JSON.stringify(body) : undefined
        }).then(function(r) { return r.json().catch(function() { return { success: false, error: 'HTTP ' + r.status }; }); });
    }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>]/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
    // v5.4.0-beta.2：状态反馈分色（成功/失败/进行中），失败透出后端可读原因
    function setStatus(t, tone) {
        var el = $('aiStatus');
        if (!el) return;
        el.textContent = t || '';
        el.className = 'ai-status' + (tone ? ' is-' + tone : '');
    }
    // 统一读取后端可读原因：env_invalid 优先（提示重新登录），其次 error / message
    function reasonOf(d, fallback) {
        d = d || {};
        if (d.env_invalid) return T('登录环境已变化，请重新登录');
        return d.message || d.error || fallback;
    }

    function tokenize(s) {
        var out = [], re = /[A-Za-z0-9]+|[\u4e00-\u9fff]|[^\s]|\s+/g, m;
        while ((m = re.exec(s))) out.push(m[0]);
        return out;
    }
    function diffHtml(oldS, newS) {
        var a = tokenize(oldS), b = tokenize(newS);
        if (a.length * b.length > 400000) {
            return { old: esc(oldS), 'new': esc(newS) };
        }
        var n = a.length, m = b.length, i, j, dp = [];
        for (i = 0; i <= n; i++) { dp.push(new Array(m + 1)); for (j = 0; j <= m; j++) dp[i][j] = 0; }
        for (i = n - 1; i >= 0; i--) for (j = m - 1; j >= 0; j--) {
            dp[i][j] = a[i] === b[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
        }
        var oh = '', nh = ''; i = 0; j = 0;
        while (i < n && j < m) {
            if (a[i] === b[j]) { oh += esc(a[i]); nh += esc(b[j]); i++; j++; }
            else if (dp[i + 1][j] >= dp[i][j + 1]) { oh += '<span class="ai-del">' + esc(a[i]) + '</span>'; i++; }
            else { nh += '<span class="ai-add">' + esc(b[j]) + '</span>'; j++; }
        }
        while (i < n) { oh += '<span class="ai-del">' + esc(a[i]) + '</span>'; i++; }
        while (j < m) { nh += '<span class="ai-add">' + esc(b[j]) + '</span>'; j++; }
        return { old: oh, 'new': nh };
    }

    function refreshProviderHint() {
        var pid = $('aiProvider').value, k = null, i;
        for (i = 0; i < (cfg.keys || []).length; i++) if (cfg.keys[i].provider === pid) k = cfg.keys[i];
        var hint = $('aiProviderHint');
        if (k && k.configured) hint.innerHTML = T('已配置 {a} · 模型 {b}').replace('{a}', function() { return esc(k.hint); }).replace('{b}', function() { return esc(k.model || T('未填')); });
        // v5.4.2-beta：未配置 Key 时给出直达「AI 写作」配置页的入口
        else hint.innerHTML = T('未配置：请先配置该服务商的 Key') + ' <a class="ai-hint-link" href="' + esc(aiConfigUrl) + '">' + T('去配置 Key') + '</a>';
    }
    function syncActionFields() {
        $('aiStyleWrap').style.display = (curAction === 'style') ? '' : 'none';
        $('aiLangWrap').style.display = (curAction === 'translate') ? '' : 'none';
    }

    function buildForm() {
        if (cfg.privacy_version && $('aiPrivacyVer')) $('aiPrivacyVer').textContent = cfg.privacy_version;
        // v5.4.2-beta：先套用本地记住的动作（决定 chip 高亮），再构建表单
        var pref = loadPref();
        if (pref.action && cfg.actions && cfg.actions[pref.action] !== undefined) curAction = pref.action;
        var chips = '', i;
        for (var k in cfg.actions) {
            chips += '<button type="button" class="ai-chip' + (k === curAction ? ' active' : '') + '" data-action="' + k + '">' + esc(cfg.actions[k]) + '</button>';
        }
        $('aiActionChips').innerHTML = chips;
        var so = '';
        cfg.styles.forEach(function(s) { so += '<option value="' + esc(s) + '">' + esc(s) + '</option>'; });
        $('aiStyle').innerHTML = so;
        var lo = '';
        cfg.langs.forEach(function(s) { lo += '<option value="' + esc(s) + '">' + esc(s) + '</option>'; });
        $('aiLang').innerHTML = lo;
        var po = '';
        cfg.providers.forEach(function(p) { po += '<option value="' + esc(p.id) + '">' + esc(p.label) + '</option>'; });
        $('aiProvider').innerHTML = po;
        // 套用记住的服务商 / 风格 / 语言（仅当仍在候选集合内）
        if (pref.provider) { for (i = 0; i < cfg.providers.length; i++) if (cfg.providers[i].id === pref.provider) $('aiProvider').value = pref.provider; }
        if (pref.style) { for (i = 0; i < cfg.styles.length; i++) if (cfg.styles[i] === pref.style) $('aiStyle').value = pref.style; }
        if (pref.lang) { for (i = 0; i < cfg.langs.length; i++) if (cfg.langs[i] === pref.lang) $('aiLang').value = pref.lang; }
        if (cfg.default_provider && !pref.provider) $('aiProvider').value = cfg.default_provider;
        refreshProviderHint();
        syncActionFields();
    }

    $('aiActionChips').addEventListener('click', function(e) {
        var t = e.target.closest ? e.target.closest('.ai-chip') : null;
        if (!t) return;
        curAction = t.getAttribute('data-action');
        var all = this.querySelectorAll('.ai-chip');
        for (var i = 0; i < all.length; i++) all[i].classList.remove('active');
        t.classList.add('active');
        syncActionFields();
        savePref();
    });
    $('aiProvider').addEventListener('change', function() { refreshProviderHint(); savePref(); });
    $('aiStyle').addEventListener('change', savePref);
    $('aiLang').addEventListener('change', savePref);

    var pendingOpen = false;

    function showDrawer() {
        drawer.style.display = 'flex';
        hideFab();
    }

    function openDrawer() {
        var s = ta.selectionStart, e = ta.selectionEnd;
        // v6.3.3：选区可能因点击按钮而丢失——回退到最近一次有效选区快照
        if (s === e && selSnap.end > selSnap.start) { s = selSnap.start; e = selSnap.end; }
        var sel = ta.value.slice(s, e);
        if (!sel.trim()) { alert(T('请先在编辑框中选中要处理的文本')); return; }
        captured = sel; lastSel = { start: s, end: e };
        // 把焦点和选区还给编辑框（不滚动页面），避免浮层打开后编辑器里的选中态消失
        try { ta.focus({ preventScroll: true }); ta.setSelectionRange(s, e); } catch (err) {}
        $('aiSrc').textContent = sel;
        $('aiResultWrap').style.display = 'none';
        $('aiUndoBtn').style.display = 'none';
        result = ''; finalized = false; undoValue = null; setStatus('');
        // v6.3.19：隐私未确认时先只弹确认层，确认后自动打开抽屉；
        //          此前两层同时打开，确认按钮被抽屉挡住，只能先关抽屉再确认。
        if (!cfg.privacy_ack) {
            pendingOpen = true;
            $('aiPrivacyMask').style.display = 'flex';
            return;
        }
        showDrawer();
    }
    function closeDrawer() {
        if (controller) { try { controller.abort(); } catch (e) {} }
        drawer.style.display = 'none';
        hideFab();
        // v6.3.3：关闭后把焦点与选区还给编辑框（不滚动页面）
        try {
            if (lastSel.end > lastSel.start) {
                ta.focus({ preventScroll: true });
                ta.setSelectionRange(lastSel.start, lastSel.end);
            }
        } catch (err) {}
    }

    $('aiPrivacyOk').addEventListener('click', function() {
        $('aiPrivacyMask').style.display = 'none';
        api('ai_privacy_ack', 'POST', { version: (cfg && cfg.privacy_version) || '' }).then(function(d) {
            if (d && d.success) {
                cfg.privacy_ack = true;
                if (pendingOpen) { pendingOpen = false; showDrawer(); }
            } else if (d && d.need_reload) { cfg.privacy_ack = false; setStatus(T('隐私提示已更新，请刷新页面后重新确认'), 'err'); }
        });
    });
    $('aiPrivacyCancel').addEventListener('click', function() { pendingOpen = false; $('aiPrivacyMask').style.display = 'none'; });

    // v5.4.2-beta：生成按钮态（运行中显示「取消」；出错显示「重试」）
    function setRunBtns(state) {
        var runB = $('aiRunBtn'), cancelB = $('aiCancelBtn'), retryB = $('aiRetryBtn');
        if (!runB) return;
        runB.disabled = (state === 'busy');
        if (cancelB) cancelB.style.display = (state === 'busy') ? '' : 'none';
        if (retryB) retryB.style.display = (state === 'error') ? '' : 'none';
    }

    function finalize(started) {
        finalized = true;
        var dv = diffHtml(captured, result);
        $('aiDiffOld').innerHTML = dv.old;
        $('aiDiffNew').innerHTML = dv['new'];
        var extra = '';
        if (started) extra += ' · ' + T('用时 {n}s').replace('{n}', ((Date.now() - started) / 1000).toFixed(1));
        if (tokenUsage) {
            var tk = (tokenUsage.total_tokens != null) ? tokenUsage.total_tokens
                : ((tokenUsage.completion_tokens || 0) + (tokenUsage.prompt_tokens || 0));
            if (tk) extra += ' · tokens ' + tk;
        }
        setStatus(T('完成（{a} 字{b}）：可插入 / 替换').replace('{a}', function() { return result.length; }).replace('{b}', function() { return extra; }), 'ok');
    }

    function run() {
        if (busy) return;
        if (!cfg.privacy_ack) { $('aiPrivacyMask').style.display = 'flex'; setStatus(T('请先确认隐私提示'), 'err'); return; }
        var pid = $('aiProvider').value, k = null, i;
        for (i = 0; i < (cfg.keys || []).length; i++) if (cfg.keys[i].provider === pid) k = cfg.keys[i];
        if (!k || !k.configured) {
            setStatus(T('该服务商尚未配置 Key，请先到后台「AI 写作」配置'), 'err');
            refreshProviderHint();
            return;
        }
        // v6.2.0-beta：服务商熔断中 → 前端直接降级提示，不再发起请求
        var br = cfg.breaker && cfg.breaker[pid];
        if (br && br.open) {
            var mins = Math.max(1, Math.ceil((br.remaining || 0) / 60));
            setStatus(T('AI 服务暂时不可用（已熔断，约 {n} 分钟后自动恢复）').replace('{n}', function() { return mins; }), 'err');
            return;
        }
        var style = $('aiStyle').value, lang = $('aiLang').value;
        busy = true; setRunBtns('busy'); setStatus(T('生成中…（可取消）'), 'busy');
        result = ''; finalized = false; tokenUsage = null;
        var started = Date.now();
        controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        $('aiResultWrap').style.display = 'block';
        $('aiDiffOld').innerHTML = esc(captured);
        $('aiDiffNew').innerHTML = '';
        var err = '';
        fetch('api.php?action=ai_run', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Fp': (typeof window.ysmGetFp === 'function' ? window.ysmGetFp() : '') },
            signal: controller ? controller.signal : undefined,
            body: JSON.stringify({ action: curAction, provider: pid, text: captured, style: style, lang: lang, stream: true })
        }).then(function(resp) {
            var ct = resp.headers.get('content-type') || '';
            if (ct.indexOf('application/json') >= 0) {
                return resp.json().then(function(d) { err = reasonOf(d, T('调用失败')); });
            }
            if (!resp.body || !resp.body.getReader) { err = T('当前浏览器不支持流式读取'); return; }
            var reader = resp.body.getReader(), dec = new TextDecoder(), buf = '';
            function handleBlock(block) {
                block.split('\n').forEach(function(line) {
                    if (line.indexOf('data:') !== 0) return;
                    var ev; try { ev = JSON.parse(line.slice(5).trim()); } catch (x) { return; }
                    if (ev.event === 'delta' && ev.text) {
                        result += ev.text;
                        $('aiDiffNew').textContent = result;
                        $('aiDiffNew').scrollTop = $('aiDiffNew').scrollHeight;
                    } else if (ev.event === 'error') { err = ev.message || T('调用失败'); }
                    else if (ev.event === 'usage') { tokenUsage = ev.usage || ev; }
                });
            }
            function pump() {
                return reader.read().then(function(r) {
                    if (r.done || err) return;
                    buf += dec.decode(r.value, { stream: true });
                    var idx;
                    while ((idx = buf.indexOf('\n\n')) >= 0) { handleBlock(buf.slice(0, idx)); buf = buf.slice(idx + 2); }
                    return pump();
                });
            }
            return pump();
        }).then(function() {
            busy = false; controller = null;
            if (err) { setRunBtns('error'); setStatus(err, 'err'); return; }
            if (result) { setRunBtns('idle'); finalize(started); } else { setRunBtns('error'); setStatus(T('未获得结果'), 'err'); }
        }).catch(function(e) {
            busy = false; controller = null;
            if (e && e.name === 'AbortError') { setRunBtns('error'); setStatus(T('已取消'), 'err'); }
            else { setRunBtns('error'); setStatus((e && e.message) || T('网络错误'), 'err'); }
        });
    }

    function applyText(mode) {
        if (!result) return;
        undoValue = ta.value;
        var v = ta.value;
        if (mode === 'replace') {
            ta.value = v.slice(0, lastSel.start) + result + v.slice(lastSel.end);
            lastSel = { start: lastSel.start, end: lastSel.start + result.length };
        } else {
            ta.value = v.slice(0, lastSel.start) + result + v.slice(lastSel.start);
            lastSel = { start: lastSel.start, end: lastSel.start + result.length };
        }
        ta.dispatchEvent(new Event('input', { bubbles: true }));
        ta.focus();
        ta.selectionStart = ta.selectionEnd = lastSel.end;
        $('aiUndoBtn').style.display = '';
        setStatus(mode === 'replace' ? T('已替换') : T('已插入'), 'ok');
    }

    $('aiRunBtn').addEventListener('click', run);
    // v5.4.2-beta：取消（中断进行中的流式请求）与重试（失败后一键重跑）
    $('aiCancelBtn').addEventListener('click', function() { if (controller) { try { controller.abort(); } catch (e) {} } });
    $('aiRetryBtn').addEventListener('click', function() { if (!busy) run(); });
    $('aiInsertBtn').addEventListener('click', function() { applyText('insert'); });
    $('aiReplaceBtn').addEventListener('click', function() { applyText('replace'); });
    $('aiUndoBtn').addEventListener('click', function() {
        if (undoValue === null) return;
        ta.value = undoValue; undoValue = null;
        ta.dispatchEvent(new Event('input', { bubbles: true }));
        $('aiUndoBtn').style.display = 'none';
        setStatus(T('已撤销'), 'ok');
    });
    $('aiDrawerClose').addEventListener('click', closeDrawer);
    var btn = $('btnAiWrite');
    if (btn) {
        // v6.3.3：按钮抢焦点会让 textarea 失焦、选区归零（表现为「选中文本后一点按钮，选中就没了」）。
        // 在 mousedown 阶段阻止默认行为，保持编辑框的焦点与选区，click 仍照常触发。
        btn.addEventListener('mousedown', function(e) { e.preventDefault(); });
        btn.addEventListener('click', openDrawer);
    }

    // 选中文本后在编辑框右上角浮出入口（侧边浮层触发）
    var fab = document.createElement('div');
    fab.className = 'ai-fab';
    fab.textContent = T('AI 写作');
    fab.style.display = 'none';
    document.body.appendChild(fab);
    fab.addEventListener('mousedown', function(e) { e.preventDefault(); openDrawer(); });
    function hideFab() { fab.style.display = 'none'; }
    function maybeFab() {
        // v6.3.3：先记录有效选区快照（浮层打开时也要记，供关闭后恢复）
        if (ta.selectionEnd > ta.selectionStart) selSnap = { start: ta.selectionStart, end: ta.selectionEnd };
        if (drawer.style.display === 'flex') return;
        var sel = ta.value.slice(ta.selectionStart, ta.selectionEnd);
        if (!sel.trim()) { hideFab(); return; }
        var r = ta.getBoundingClientRect();
        var left = Math.min(window.innerWidth - 90, Math.max(8, r.right - 96));
        var top = Math.max(8, r.top + 6);
        fab.style.left = left + 'px';
        fab.style.top = top + 'px';
        fab.style.display = '';
    }
    ta.addEventListener('select', maybeFab);
    ta.addEventListener('mouseup', maybeFab);
    ta.addEventListener('keyup', function(e) { if (e.shiftKey || e.key === 'Shift') maybeFab(); });
    window.addEventListener('scroll', hideFab, true);

    // 载入配置：决定动作/风格/语言/服务商与状态
    api('ai_config', 'GET').then(function(d) {
        if (!d || !d.success || !d.allowed) { if (btn) btn.disabled = true; return; }
        cfg = d; buildForm();
    }).catch(function() {});
})();
