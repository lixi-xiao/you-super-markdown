/* You Super Markdown — Markdown 正文预览（v6.3.2）
 * 用途：编辑器（sc.php）把 Markdown 源码渲染成正文样式预览，避免只能看代码。
 * 依赖：vendor/marked.min.js、vendor/highlight.min.js（按需注入）；```mermaid 时加载 mermaid，含公式时加载 MathJax。
 * 安全：只渲染当前用户在编辑器里输入/选择的本地内容；输出统一清洗（去 script/style、on* 属性、javascript: 链接）。
 */
(function () {
    'use strict';

    function T(k, v) {
        if (typeof window.TK === 'function') return window.TK(k, v || {});
        var d = (window.I18N && window.I18N.dict) || {};
        return d[k] || k;
    }

    var loading = {};
    function loadScript(src) {
        if (loading[src]) return loading[src];
        loading[src] = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = src;
            s.onload = function () { resolve(); };
            s.onerror = function () { reject(new Error('load ' + src)); };
            document.head.appendChild(s);
        });
        return loading[src];
    }
    function ensureMarked() {
        if (typeof window.marked !== 'undefined') return Promise.resolve();
        return loadScript('vendor/marked.min.js');
    }
    function ensureHljs() {
        if (typeof window.hljs !== 'undefined') return Promise.resolve();
        return loadScript('vendor/highlight.min.js');
    }
    function ensureMermaid() {
        if (window.mermaid) return Promise.resolve();
        return loadScript('vendor/mermaid.min.js');
    }
    function ensureMathJax() {
        return loadScript('vendor/mathjax/es5/tex-chtml.js');
    }

    /** 输出清洗：去掉可执行节点/属性，保留排版与媒体 */
    function sanitize(root) {
        var bad = root.querySelectorAll('script,style,link,meta,base,object,embed');
        Array.prototype.forEach.call(bad, function (n) {
            if (n.parentNode) n.parentNode.removeChild(n);
        });
        var all = root.querySelectorAll('*');
        Array.prototype.forEach.call(all, function (el) {
            Array.prototype.slice.call(el.attributes || []).forEach(function (a) {
                var name = a.name.toLowerCase();
                var val = (a.value || '').replace(/\s+/g, '').toLowerCase();
                if (name.indexOf('on') === 0) el.removeAttribute(a.name);
                else if ((name === 'href' || name === 'src' || name === 'xlink:href') && val.indexOf('javascript:') === 0) {
                    el.removeAttribute(a.name);
                }
            });
        });
    }

    function hasMath(md) {
        return /\$\$[\s\S]+?\$\$/.test(md) || /(^|[^\\$])\$[^\s$][^\n$]*\$/.test(md) || /\\\(|\\\[/.test(md);
    }

    function renderMermaid(box) {
        var codes = box.querySelectorAll('pre > code.language-mermaid');
        if (!codes.length) return Promise.resolve();
        Array.prototype.forEach.call(codes, function (c) {
            var div = document.createElement('div');
            div.className = 'mermaid';
            div.textContent = c.textContent;
            c.parentNode.parentNode.replaceChild(div, c.parentNode);
        });
        return ensureMermaid().then(function () {
            if (!window.mermaid || typeof window.mermaid.run !== 'function') return;
            try {
                window.mermaid.initialize({
                    startOnLoad: false,
                    theme: (document.documentElement.getAttribute('data-theme') === 'light' ? 'default' : 'dark'),
                    securityLevel: 'strict'
                });
            } catch (e) { /* 已初始化则忽略 */ }
            return window.mermaid.run({ nodes: box.querySelectorAll('.mermaid') }).catch(function () {});
        });
    }

    function renderMath(box, md) {
        if (!hasMath(md)) return Promise.resolve();
        return ensureMathJax().then(function () {
            if (!window.MathJax) return;
            if (window.MathJax.typesetPromise) return window.MathJax.typesetPromise([box]);
            if (window.MathJax.Hub && window.MathJax.Hub.Queue) window.MathJax.Hub.Queue(['Typeset', window.MathJax.Hub, box]);
        }).catch(function () {});
    }

    var modal = null;
    function buildModal() {
        if (modal) return modal;
        modal = document.createElement('div');
        modal.className = 'md-preview-mask';
        modal.innerHTML =
            '<div class="md-preview-panel" role="dialog" aria-modal="true">' +
            '  <div class="md-preview-head">' +
            '    <span class="md-preview-title"></span>' +
            '    <button type="button" class="md-preview-close" aria-label="">&times;</button>' +
            '  </div>' +
            '  <div class="md-preview-body"></div>' +
            '</div>';
        document.body.appendChild(modal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal || e.target.closest('.md-preview-close')) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
        return modal;
    }

    function close() {
        if (!modal) return;
        modal.classList.remove('open');
        modal.querySelector('.md-preview-panel').classList.remove('open');
        document.body.style.overflow = '';
    }

    function open(title) {
        var m = buildModal();
        m.querySelector('.md-preview-title').textContent = title || T('editor.076');
        m.querySelector('.md-preview-close').setAttribute('aria-label', T('editor.077'));
        m.classList.add('open');
        m.querySelector('.md-preview-panel').classList.add('open');
        document.body.style.overflow = 'hidden';
        m.querySelector('.md-preview-body').scrollTop = 0;
    }

    /** 对外入口：渲染并展示一段 Markdown */
    window.ysmPreviewMarkdown = function (md, title) {
        md = String(md == null ? '' : md);
        open(title);
        var body = modal.querySelector('.md-preview-body');
        if (md.trim() === '') {
            body.innerHTML = '<div class="md-preview-empty">' + T('editor.081') + '</div>';
            return;
        }
        body.innerHTML = '<div class="md-preview-empty">' + T('editor.080') + '</div>';
        ensureMarked().then(function () {
            var html = (typeof window.marked !== 'undefined') ? window.marked.parse(md) : '<pre>' + md + '</pre>';
            body.innerHTML = '<div class="md-preview-content markdown-body"></div>';
            var box = body.querySelector('.md-preview-content');
            box.innerHTML = html;
            sanitize(box);
            return ensureHljs().then(function () {
                Array.prototype.forEach.call(box.querySelectorAll('pre code'), function (c) {
                    try { window.hljs.highlightElement(c); } catch (e) { /* 忽略单块失败 */ }
                });
            });
        }).then(function () {
            var box = body.querySelector('.md-preview-content');
            if (!box) return;
            return renderMermaid(box).then(function () { return renderMath(box, md); });
        }).catch(function () {
            body.innerHTML = '<div class="md-preview-empty">' + T('editor.079') + '</div>';
        });
    };

    function bind() {
        // 1) 文本框预览：<button data-md-preview="contentArea">
        Array.prototype.forEach.call(document.querySelectorAll('[data-md-preview]'), function (btn) {
            // 阻止按钮抢焦点——否则 textarea 失焦会清掉选区，回到编辑器后光标/选中都丢了
            btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
            btn.addEventListener('click', function () {
                var el = document.getElementById(btn.getAttribute('data-md-preview'));
                if (!el) return;
                window.ysmPreviewMarkdown(el.value, btn.getAttribute('data-md-preview-title') || '');
            });
        });
        // 2) 上传文件预览：<button data-md-preview-file="fileInput">
        Array.prototype.forEach.call(document.querySelectorAll('[data-md-preview-file]'), function (btn) {
            var input = document.getElementById(btn.getAttribute('data-md-preview-file'));
            if (!input) return;
            var sync = function () {
                var f = input.files && input.files[0];
                var ok = !!f && /\.(md|markdown|txt)$/i.test(f.name);
                btn.style.display = ok ? '' : 'none';
            };
            input.addEventListener('change', sync);
            sync();
            btn.addEventListener('click', function () {
                var f = input.files && input.files[0];
                if (!f) return;
                if (!/\.(md|markdown|txt)$/i.test(f.name)) {
                    open(f.name);
                    modal.querySelector('.md-preview-body').innerHTML = '<div class="md-preview-empty">' + T('editor.078') + '</div>';
                    return;
                }
                var reader = new FileReader();
                reader.onload = function () { window.ysmPreviewMarkdown(String(reader.result || ''), f.name); };
                reader.onerror = function () {
                    open(f.name);
                    modal.querySelector('.md-preview-body').innerHTML = '<div class="md-preview-empty">' + T('editor.079') + '</div>';
                };
                reader.readAsText(f, 'utf-8');
            });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
    else bind();
})();
