/**
 * v6.3.1：后台「AI 译本」控件（写作者/站长/超管共用）
 *
 * 用法：在文章行渲染 <span class="tr-entry" data-tr-slug="文件名.md"></span>
 * - 触发账号自己的 AI Key / 日配额（服务端按登录态判定，不跨账号共享）
 * - 语种列表排除「内容原语种」；已有译本显示 已生成/已过期，可重新生成
 */
(function () {
    'use strict';
    function T(k, v) {
        if (typeof window.TK === 'function') return window.TK(k, v || {});
        var d = (window.I18N && window.I18N.dict) || {};
        return d[k] || k;
    }
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }
    // v6.3.3：后台 AI 接口要求环境指纹头（requireSessionEnv 比对 X-Fp / 稳定指纹 X-FpS）。
    // 本页不加载 main.js，其 fetch 包装不会自动注入——缺头会被判成「登录环境已变化」。
    function fpHeaders() {
        var h = {};
        if (typeof window.ysmGetFp === 'function') h['X-Fp'] = window.ysmGetFp();
        if (typeof window.ysmGetStableFp === 'function') h['X-FpS'] = window.ysmGetStableFp();
        return h;
    }
    function langName(code) {
        var ls = (window.I18N && window.I18N.langs) || {};
        return (ls[code] && ls[code].name) || code;
    }
    function menuHtml(langs) {
        var h = '<div class="tr-menu" role="menu">';
        Object.keys(langs).forEach(function (lc) {
            var st = langs[lc];
            var label = st === 'done' ? T('ai.tr_done') : (st === 'stale' ? T('ai.tr_stale') : T('ai.tr_none'));
            h += '<button type="button" class="tr-menu-item" data-lang="' + lc + '">' +
                 '<span class="tr-menu-name">' + langName(lc) + '</span>' +
                 '<span class="tr-state tr-state-' + st + '">' + label + '</span></button>';
        });
        return h + '</div>';
    }
    function bind(entry) {
        var slug = entry.getAttribute('data-tr-slug');
        if (!slug) return;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tr-entry-btn';
        btn.textContent = T('ai.tr_title');
        entry.appendChild(btn);
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var old = entry.querySelector('.tr-menu');
            if (old) { old.remove(); return; }
            btn.disabled = true;
            fetch('api.php?action=ai_translation_status&slug=' + encodeURIComponent(slug), { credentials: 'same-origin', headers: fpHeaders() })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j || !j.success) throw new Error((j && j.error) || T('ai.tr_fail'));
                    var wrap = document.createElement('div');
                    wrap.innerHTML = menuHtml(j.langs || {});
                    var menu = wrap.firstChild;
                    entry.appendChild(menu);
                    menu.addEventListener('click', function (ev) {
                        var it = ev.target.closest ? ev.target.closest('.tr-menu-item') : null;
                        if (!it) return;
                        var lc = it.getAttribute('data-lang');
                        if (!confirm(T('ai.tr_confirm', { lang: langName(lc) }))) return;
                        it.disabled = true;
                        it.querySelector('.tr-state').textContent = T('ai.tr_running');
                        fetch('api.php?action=ai_translate', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: Object.assign({ 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, fpHeaders()),
                            body: JSON.stringify({ slug: slug, lang: lc })
                        }).then(function (r) { return r.json(); }).then(function (res) {
                            if (res && res.success) {
                                it.querySelector('.tr-state').textContent = T('ai.tr_done');
                                it.querySelector('.tr-state').className = 'tr-state tr-state-done';
                            } else {
                                alert((res && res.error) || T('ai.tr_fail'));
                                it.disabled = false;
                                it.querySelector('.tr-state').textContent = T('ai.tr_none');
                            }
                        }).catch(function () {
                            alert(T('ai.tr_fail'));
                            it.disabled = false;
                        });
                    });
                })
                .catch(function (err) { alert(err.message || T('ai.tr_fail')); })
                .then(function () { btn.disabled = false; });
        });
    }
    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('.tr-entry[data-tr-slug]'), bind);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
