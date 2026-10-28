/**
 * v6.3.4-beta.2：后台「AI 译本」抽屉（写作者 / 站长 / 超管共用）
 *
 * 用法：文章行渲染 <span class="tr-entry" data-tr-slug="文件名.md"></span>
 * 交互：点按钮打开抽屉 → 列出各语种状态（未生成 / 已生成 / 已过期）→ 生成 / 重新生成 / 查看译文
 *      - 生成过程有进度条与「取消」，长文不再像卡死
 *      - 支持「翻译全部未生成」，串行执行
 *      - 错误就地显示，不再用 alert 打断
 * 安全：请求带 X-Fp / X-FpS（本页不加载 main.js），额度与 Key 由服务端按登录账号判定
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
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var drawer = null, mask = null, state = { slug: '', langs: {}, source: '', title: '' };
    var controller = null, cancelled = false, running = false;

    function build() {
        if (drawer) return;
        mask = document.createElement('div');
        mask.className = 'tr-drawer-mask';
        drawer = document.createElement('div');
        drawer.className = 'tr-drawer';
        drawer.setAttribute('role', 'dialog');
        drawer.setAttribute('aria-modal', 'true');
        drawer.innerHTML =
            '<div class="tr-drawer-head">' +
            '  <div class="tr-drawer-headtext">' +
            '    <div class="tr-drawer-title">' + esc(T('ai.tr_btn')) + '</div>' +
            '    <div class="tr-drawer-sub"></div>' +
            '  </div>' +
            '  <button type="button" class="tr-drawer-close" aria-label="' + esc(T('ai.tr_close')) + '">&times;</button>' +
            '</div>' +
            '<div class="tr-drawer-toolbar">' +
            '  <button type="button" class="tr-btn tr-btn-primary" data-tr-all>' + esc(T('ai.tr_all')) + '</button>' +
            '  <span class="tr-toolbar-note">' + esc(T('ai.tr_note')) + '</span>' +
            '</div>' +
            '<div class="tr-drawer-body"></div>' +
            '<div class="tr-drawer-foot"></div>';
        document.body.appendChild(mask);
        document.body.appendChild(drawer);
        mask.addEventListener('click', close);
        drawer.querySelector('.tr-drawer-close').addEventListener('click', close);
        drawer.querySelector('[data-tr-all]').addEventListener('click', runAll);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    }

    function open(slug, title) {
        build();
        state.slug = slug;
        state.title = title || slug;
        state.langs = {};
        state.source = '';
        cancelled = false;
        drawer.querySelector('.tr-drawer-sub').textContent = state.title;
        drawer.querySelector('.tr-drawer-body').innerHTML = '<div class="tr-skeleton">' + esc(T('ai.tr_loading')) + '</div>';
        drawer.querySelector('.tr-drawer-foot').textContent = '';
        drawer.querySelector('[data-tr-all]').style.display = 'none';
        mask.classList.add('open');
        drawer.classList.add('open');
        document.body.style.overflow = 'hidden';
        loadStatus();
    }

    function close() {
        if (controller) { try { controller.abort(); } catch (e) {} }
        if (!drawer) return;
        mask.classList.remove('open');
        drawer.classList.remove('open');
        document.body.style.overflow = '';
    }

    function loadStatus() {
        return fetch('api.php?action=ai_translation_status&slug=' + encodeURIComponent(state.slug), {
            credentials: 'same-origin', headers: fpHeaders()
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j || !j.success) throw new Error((j && j.error) || T('ai.tr_fail'));
            state.langs = j.langs || {};
            state.source = j.source || '';
            if (j.title) {
                state.title = j.title;
                drawer.querySelector('.tr-drawer-sub').textContent = state.title + (j.source_name ? '  ·  ' + T('ai.tr_source') + ' ' + j.source_name : '');
            }
            render();
        }).catch(function (err) {
            drawer.querySelector('.tr-drawer-body').innerHTML = '<div class="tr-error-box">' + esc(err.message || T('ai.tr_fail')) + '</div>';
        });
    }

    function stateLabel(st) {
        if (st === 'done') return T('ai.tr_done');
        if (st === 'stale') return T('ai.tr_stale');
        return T('ai.tr_none');
    }

    function render() {
        var body = drawer.querySelector('.tr-drawer-body');
        var codes = Object.keys(state.langs);
        if (!codes.length) {
            body.innerHTML = '<div class="tr-skeleton">' + esc(T('ai.tr_no_lang')) + '</div>';
            drawer.querySelector('[data-tr-all]').style.display = 'none';
            return;
        }
        var html = '';
        codes.forEach(function (lc) {
            var o = state.langs[lc] || {};
            var st = o.status || 'none';
            html += '<div class="tr-lang" data-lang="' + lc + '">' +
                '<div class="tr-lang-main">' +
                '  <div class="tr-lang-name">' + esc(langName(lc)) + '</div>' +
                '  <div class="tr-lang-meta">' +
                '    <span class="tr-badge tr-badge-' + st + '">' + esc(stateLabel(st)) + '</span>' +
                (o.updated ? '<span class="tr-lang-time">' + esc(o.updated) + '</span>' : '') +
                (o.chars ? '<span class="tr-lang-time">' + o.chars + ' ' + esc(T('ai.tr_chars')) + '</span>' : '') +
                '  </div>' +
                '</div>' +
                '<div class="tr-lang-actions">' +
                (st !== 'none' ? '<a class="tr-btn tr-btn-ghost" target="_blank" rel="noopener" href="index.php?file=' + encodeURIComponent(state.slug) + '&lang=' + encodeURIComponent(lc) + '">' + esc(T('ai.tr_view')) + '</a>' : '') +
                '  <button type="button" class="tr-btn tr-btn-ghost tr-cancel" data-cancel="' + lc + '" style="display:none">' + esc(T('ai.tr_cancel')) + '</button>' +
                '  <button type="button" class="tr-btn tr-btn-primary" data-run="' + lc + '">' + esc(st === 'none' ? T('ai.tr_generate') : T('ai.tr_regen')) + '</button>' +
                '</div>' +
                '<div class="tr-progress" data-progress="' + lc + '"><span></span></div>' +
                '</div>';
        });
        body.innerHTML = html;
        // 需要补翻的语种数决定「翻译全部」是否显示
        var missing = codes.filter(function (lc) { return (state.langs[lc] || {}).status !== 'done'; }).length;
        var allBtn = drawer.querySelector('[data-tr-all]');
        allBtn.style.display = missing ? '' : 'none';
        allBtn.textContent = T('ai.tr_all') + (missing ? '（' + missing + '）' : '');
        Array.prototype.forEach.call(body.querySelectorAll('[data-run]'), function (b) {
            b.addEventListener('click', function () { runOne(b.getAttribute('data-run')); });
        });
        Array.prototype.forEach.call(body.querySelectorAll('[data-cancel]'), function (b) {
            b.addEventListener('click', function () {
                if (controller) { try { controller.abort(); } catch (e) {} }
            });
        });
    }

    function setRowRunning(lc, on) {
        var row = drawer.querySelector('.tr-lang[data-lang="' + lc + '"]');
        if (!row) return;
        var btn = row.querySelector('[data-run]');
        var cancelBtn = row.querySelector('[data-cancel]');
        var prog = row.querySelector('[data-progress]');
        if (on) {
            if (btn) { btn.disabled = true; btn.style.display = 'none'; }
            if (cancelBtn) { cancelBtn.style.display = ''; }
            if (prog) prog.classList.add('active');
        } else {
            if (btn) { btn.disabled = false; btn.style.display = ''; btn.textContent = T('ai.tr_regen'); }
            if (cancelBtn) { cancelBtn.style.display = 'none'; }
            if (prog) prog.classList.remove('active');
        }
    }

    function footMsg(text, isErr) {
        var f = drawer.querySelector('.tr-drawer-foot');
        f.textContent = text || '';
        f.className = 'tr-drawer-foot' + (isErr ? ' is-error' : '');
    }

    function runOne(lc, silent) {
        if (running) return Promise.resolve(false);
        var cur = (state.langs[lc] || {}).status || 'none';
        if (cur !== 'none' && !silent) {
            if (!confirm(T('ai.tr_regen_confirm', { lang: langName(lc) }))) return Promise.resolve(false);
        } else if (!silent) {
            if (!confirm(T('ai.tr_confirm', { lang: langName(lc) }))) return Promise.resolve(false);
        }
        running = true;
        cancelled = false;
        controller = new AbortController();
        setRowRunning(lc, true);
        footMsg(T('ai.tr_running') + ' ' + langName(lc) + ' …');
        var payload = JSON.stringify({ slug: state.slug, lang: lc });
        return fetch('api.php?action=ai_translate', {
            method: 'POST',
            credentials: 'same-origin',
            signal: controller.signal,
            headers: Object.assign({ 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, fpHeaders()),
            body: payload
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res && res.success) {
                state.langs[lc] = { status: 'done', updated: '', chars: 0 };
                footMsg(T('ai.tr_ok') + ' · ' + langName(lc));
                return true;
            }
            footMsg((res && res.error) || T('ai.tr_fail'), true);
            return false;
        }).catch(function (err) {
            if (err && err.name === 'AbortError') { footMsg(T('ai.tr_cancelled')); return false; }
            footMsg(T('ai.tr_fail'), true);
            return false;
        }).then(function (ok) {
            running = false;
            controller = null;
            setRowRunning(lc, false);
            // 重新拉一次状态：拿到 updated / chars 等真实数据
            return loadStatus().then(function () { return ok; });
        });
    }

    function runAll() {
        var codes = Object.keys(state.langs).filter(function (lc) {
            return (state.langs[lc] || {}).status !== 'done';
        });
        if (!codes.length) return;
        if (!confirm(T('ai.tr_all_confirm', { n: codes.length }))) return;
        var i = 0;
        function next() {
            if (i >= codes.length) { footMsg(''); return; }
            var lc = codes[i++];
            runOne(lc, true).then(function () { setTimeout(next, 300); });
        }
        next();
    }

    function bind(entry) {
        var slug = entry.getAttribute('data-tr-slug');
        if (!slug) return;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tr-entry-btn';
        btn.textContent = T('ai.tr_btn');
        entry.appendChild(btn);
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            // 文章标题优先取同一行第一个单元格（列表里就是文章名）
            var tr = entry.closest ? entry.closest('tr') : null;
            var title = tr && tr.cells && tr.cells[0] ? tr.cells[0].textContent.trim() : slug;
            open(slug, title);
        });
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('.tr-entry[data-tr-slug]'), bind);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

    // 供其它脚本（如「文档管理」动态刷新列表后）重新绑定
    window.ysmTranslateAdminInit = init;
})();
