/**
 * v6.0.0：i18n 运行时文案接入（首页全量 UI 文案翻译）
 *
 * 设计：
 *  - 以「简体中文原文」为键（phrase table），避免为每处模板手写 key；
 *  - 支持 `{n}` 占位模式的动态文案；
 *  - 翻译文本节点 + placeholder/title/aria-label/alt/value 属性；
 *  - MutationObserver 处理异步渲染出来的 UI（文章/评论等用户内容不翻译）；
 *  - 简体中文界面直接退出（零开销）。
 */
(function () {
    'use strict';

    var CONF = window.I18N || {};
    var LANG = CONF.lang || 'zh-CN';

    // v6.1.0：无论是否非中文都提供 window.ysmT——模板里统一用 ysmT('中文') 包住动态文案，
    // 简体界面下原样返回，避免 undefined 报错。
    if (typeof window.ysmT !== 'function') {
        window.ysmT = function (s) { return String(s == null ? '' : s); };
    }
    try {
        if (window.I18N && typeof window.I18N.s !== 'function') window.I18N.s = window.ysmT;
    } catch (e) {}

    if (!LANG || LANG === 'zh-CN') return;

    var PHRASES = CONF.phrases || {};
    if (!PHRASES || typeof PHRASES !== 'object') return;

    // 用户内容/代码区域不翻译（避免把文章正文、标题、评论内容当 UI 文案）
    var SKIP_SELECTOR = [
        'script', 'style', 'noscript', 'code', 'pre', 'textarea', 'kbd', 'samp',
        '.markdown-body', '#readingView .markdown-body',
        '#cardsGrid', '#archiveView', '#searchResults', '#sidebarFileList',
        '#sidebarTocList', '#tocFileList', '.home-popular-list', '.home-links-list', '.announcement-section',
        '.cmt-list', '.comment-list', '.cmt-content', '.comment-content',
        '#userDropdownName', '.user-dropdown-avatar'
    ].join(',');

    var CJK = /[\u4e00-\u9fa5]/;

    // ---- 精确 + 模式匹配 ----
    var exact = {};
    var patterns = [];
    Object.keys(PHRASES).forEach(function (k) {
        if (k.indexOf('{') >= 0) {
            var segs = k.split(/\{[a-zA-Z0-9_]+\}/);
            var rx = '^' + segs.map(function (s) {
                return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }).join('(.+?)') + '$';
            patterns.push({ rx: new RegExp(rx), to: String(PHRASES[k]) });
        } else {
            exact[k] = String(PHRASES[k]);
        }
    });

    function applyPattern(p, s) {
        var m = s.match(p.rx);
        if (!m) return null;
        var idx = 0;
        return p.to.replace(/\{[a-zA-Z0-9_]+\}/g, function () {
            idx += 1;
            return m[idx] != null ? m[idx] : '';
        });
    }

    function translateCore(s) {
        if (!s || !CJK.test(s)) return null;
        if (Object.prototype.hasOwnProperty.call(exact, s)) return exact[s];
        for (var i = 0; i < patterns.length; i++) {
            var r = applyPattern(patterns[i], s);
            if (r !== null) return r;
        }
        return null;
    }

    // 对外暴露：供 JS 动态拼接文案时主动翻译
    window.ysmT = function (s) {
        var v = translateCore(String(s == null ? '' : s));
        return v === null ? String(s == null ? '' : s) : v;
    };
    // 兼容：I18N.s('中文')
    try {
        if (window.I18N && typeof window.I18N.s !== 'function') window.I18N.s = window.ysmT;
    } catch (e) {}

    // ---- 文本节点翻译 ----
    var done = typeof WeakSet !== 'undefined' ? new WeakSet() : null;

    function translated(raw) {
        var lead = raw.match(/^\s*/)[0];
        var trail = raw.match(/\s*$/)[0];
        var core = raw.trim();
        if (!core) return null;
        var v = translateCore(core);
        return v === null ? null : lead + v + trail;
    }

    function translateTextNode(n) {
        if (!n || !n.nodeValue) return;
        if (done && done.has(n)) return;
        var p = n.parentNode;
        if (!p || p.nodeType !== 1) return;
        if (p.closest && p.closest(SKIP_SELECTOR)) return;
        var v = translated(n.nodeValue);
        if (v !== null && v !== n.nodeValue) {
            if (done) done.add(n);
            n.nodeValue = v;
        }
    }

    var ATTRS = ['placeholder', 'title', 'aria-label', 'alt'];
    function translateAttrs(el) {
        if (!el || el.nodeType !== 1) return;
        if (el.closest && el.closest(SKIP_SELECTOR)) return;
        ATTRS.forEach(function (a) {
            var raw = el.getAttribute(a);
            if (!raw || !CJK.test(raw)) return;
            var v = translateCore(raw.trim());
            if (v !== null) {
                var nv = raw.replace(raw.trim(), v);
                if (el.getAttribute(a) !== nv) el.setAttribute(a, nv);
            }
        });
        if (el.tagName === 'INPUT' && (el.type === 'button' || el.type === 'submit') && el.value) {
            var vv = translateCore(String(el.value).trim());
            if (vv !== null && vv !== el.value) el.value = vv;
        }
    }

    function walk(root) {
        if (!root) return;
        if (root.nodeType === 3) { translateTextNode(root); return; }
        if (root.nodeType !== 1 && root.nodeType !== 9) return;

        if (root.nodeType === 1) translateAttrs(root);

        var all = root.querySelectorAll ? root.querySelectorAll('*') : [];
        for (var i = 0; i < all.length; i++) translateAttrs(all[i]);

        if (!document.createTreeWalker) return;
        var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: function (n) {
                if (!n.nodeValue || !CJK.test(n.nodeValue)) return NodeFilter.FILTER_REJECT;
                var p = n.parentNode;
                if (!p || (p.closest && p.closest(SKIP_SELECTOR))) return NodeFilter.FILTER_REJECT;
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        var batch = [];
        while (w.nextNode()) batch.push(w.currentNode);
        batch.forEach(translateTextNode);
    }

    function run() {
        walk(document.body || document.documentElement);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }

    // ---- 异步 UI（弹窗/列表/提示）持续翻译 ----
    if (typeof MutationObserver !== 'undefined' && document.body) {
        var timer = null;
        var obs = new MutationObserver(function (muts) {
            var need = false;
            for (var i = 0; i < muts.length; i++) {
                var m = muts[i];
                if (m.type === 'childList' && m.addedNodes && m.addedNodes.length) { need = true; break; }
                if (m.type === 'characterData') { need = true; break; }
                if (m.type === 'attributes') { need = true; break; }
            }
            if (!need) return;
            if (timer) return;
            timer = setTimeout(function () {
                timer = null;
                walk(document.body);
            }, 60);
        });
        obs.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true,
            attributeFilter: ATTRS
        });
    }
})();
