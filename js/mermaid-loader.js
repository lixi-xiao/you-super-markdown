/* You Super Markdown — Mermaid 统一加载器（v6.3.6）
 * 目标：前台文章 / 公告弹窗 / 后台正文预览共用一套加载与渲染逻辑。
 * 机制：
 *   1) 预加载：需要出图时立即 <link rel=preload>，尽早起请求；
 *   2) 本地缓存：渲染成功的 SVG 存入 localStorage（键 = 主题+版本+源码），命中缓存直接注入，**不加载 mermaid 库**；
 *   3) 可见反馈：加载中占位「图表加载中…」，失败/超时显示「点击重试」；
 *   4) 缓存上限 2MB，超出按写入时间淘汰最旧条目，配额异常自动降级为不缓存（不影响渲染）。
 * 安全：SVG 由 mermaid 自身生成（securityLevel=strict）；缓存注入前统一做 id 前缀化，避免多图 id 冲突。
 */
(function () {
    'use strict';

    var SCRIPT = 'vendor/mermaid.min.js';
    var VER = '2';   // v6.3.8：缓存结构修复（样式选择器前缀化）→ 旧缓存自动失效重渲染
    var LS_PREFIX = 'ysmMmd:';
    var LS_INDEX = 'ysmMmdIdx';
    var MAX_BYTES = 2 * 1024 * 1024;
    var ensurePromise = null;

    function T(key, def) {
        try {
            if (typeof window.TK === 'function') {
                var v = window.TK(key);
                if (v) return v;
            }
        } catch (e) {}
        return def || key;
    }
    function themeName() {
        return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'default';
    }
    function fnv(s) {
        var h = 2166136261;
        s = String(s);
        for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); }
        return ('00000000' + (h >>> 0).toString(16)).slice(-8);
    }
    function cacheKey(src) {
        return LS_PREFIX + fnv(themeName() + '|' + VER + '|' + src);
    }

    // ---- 存储（带 LRU 上限；异常降级为不缓存）----
    function lsGet(k) {
        try { return window.localStorage.getItem(k); } catch (e) { return null; }
    }
    function lsIndex() {
        try {
            var raw = window.localStorage.getItem(LS_INDEX);
            var arr = raw ? JSON.parse(raw) : [];
            return Array.isArray(arr) ? arr : [];
        } catch (e) { return []; }
    }
    function lsSet(k, v) {
        if (!v || v.length < 40) return;
        try {
            var idx = lsIndex();
            var total = 0, i;
            for (i = 0; i < idx.length; i++) total += (idx[i].n || 0);
            while (idx.length && total + v.length > MAX_BYTES) {
                var old = idx.shift();
                total -= (old.n || 0);
                try { window.localStorage.removeItem(old.k); } catch (e2) {}
            }
            window.localStorage.setItem(k, v);
            var found = false;
            for (i = 0; i < idx.length; i++) {
                if (idx[i].k === k) { idx[i].n = v.length; idx[i].t = Date.now(); found = true; break; }
            }
            if (!found) idx.push({ k: k, n: v.length, t: Date.now() });
            window.localStorage.setItem(LS_INDEX, JSON.stringify(idx));
        } catch (e) {
            // 配额满/隐私模式：清空索引，本次不缓存（不影响渲染）
            try { window.localStorage.removeItem(LS_INDEX); } catch (e3) {}
        }
    }

    // ---- id 前缀化：同一页面注入多份缓存 SVG 时避免 id 冲突 ----
    // v6.3.8：必须同步改写 <style> 里的 #id 选择器——否则样式整段失效（节点变纯黑块、文字不可见）
    function prefixIds(svg, token) {
        var ids = {};
        svg = svg.replace(/\bid="([^"]+)"/g, function (m, id) {
            ids[id] = 1;
            return 'id="' + token + id + '"';
        });
        svg = svg.replace(/<style([^>]*)>([\s\S]*?)<\/style>/g, function (m, attrs, css) {
            css = css.replace(/#([A-Za-z0-9_-]+)/g, function (mm, id) {
                return ids[id] ? '#' + token + id : mm;
            });
            return '<style' + attrs + '>' + css + '</style>';
        });
        svg = svg.replace(/url\(#([^)]+)\)/g, function (m, id) {
            return ids[id] ? 'url(#' + token + id + ')' : m;
        });
        svg = svg.replace(/\b(xlink:href|href)="#([^"]+)"/g, function (m, attr, id) {
            return ids[id] ? attr + '="#' + token + id + '"' : m;
        });
        svg = svg.replace(/\b(aria-(?:labelledby|describedby|owns|controls|activedescendant|details))="([^"]+)"/g, function (m, attr, v) {
            return attr + '="' + v.split(/\s+/).map(function (x) { return ids[x] ? token + x : x; }).join(' ') + '"';
        });
        svg = svg.replace(/style="([^"]*)"/g, function (m, css) {
            return 'style="' + css.replace(/#([A-Za-z0-9_-]+)/g, function (mm, id) {
                return ids[id] ? '#' + token + id : mm;
            }) + '"';
        });
        return svg;
    }

    // ---- 占位 / 失败 UI ----
    function removeTip(el) {
        var tip = el && el.__ysmTip;
        if (tip && tip.parentNode) tip.parentNode.removeChild(tip);
        if (el) el.__ysmTip = null;
    }
    function placeholder(el) {
        if (!el || el.__ysmTip) return;
        var tip = document.createElement('div');
        tip.className = 'ysm-mmd-tip';
        tip.innerHTML = '<span class="ysm-mmd-dot"></span>' + T('js.165');
        el.parentNode.insertBefore(tip, el);
        el.__ysmTip = tip;
    }
    function failTip(el, src) {
        if (!el) return;
        removeTip(el);
        var tip = document.createElement('div');
        tip.className = 'ysm-mmd-tip ysm-mmd-tip-err';
        var span = document.createElement('span');
        span.textContent = T('js.166');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ysm-mmd-retry';
        btn.textContent = T('js.149');
        btn.addEventListener('click', function () {
            removeTip(el);
            el.removeAttribute('data-processed');
            el.textContent = src;
            placeholder(el);
            ensure().then(function (ok) {
                if (!ok) { failTip(el, src); return; }
                initMermaid();
                runOne(el);
            });
        });
        tip.appendChild(span);
        tip.appendChild(btn);
        el.parentNode.insertBefore(tip, el);
        el.__ysmTip = tip;
    }

    // ---- 加载 mermaid 库（预加载 + 30s 超时）----
    function inject(src) {
        return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = src;
            s.async = true;
            s.onload = function () { resolve(); };
            s.onerror = function () {
                if (s.parentNode) s.parentNode.removeChild(s);
                reject(new Error('load ' + src));
            };
            document.head.appendChild(s);
        });
    }
    function preload(src) {
        try {
            var l = document.createElement('link');
            l.rel = 'preload';
            l.as = 'script';
            l.href = src;
            document.head.appendChild(l);
        } catch (e) {}
    }
    function ensure() {
        if (window.mermaid) return Promise.resolve(true);
        if (ensurePromise) return ensurePromise;
        preload(SCRIPT);
        ensurePromise = new Promise(function (resolve) {
            var done = false;
            var timer = setTimeout(function () {
                if (done) return;
                done = true;
                resolve(!!window.mermaid);
            }, 30000);
            inject(SCRIPT).then(function () {
                if (window.mermaid) { done = true; clearTimeout(timer); resolve(true); }
                else { throw new Error('empty'); }
            }).catch(function () {
                if (done) return;
                done = true;
                clearTimeout(timer);
                ensurePromise = null;
                resolve(false);
            });
        });
        return ensurePromise;
    }

    function initMermaid() {
        if (!window.mermaid) return;
        var th = themeName();
        if (window.__ysmMermaidInited && window.__ysmMermaidTheme === th) return;
        try {
            window.mermaid.initialize({
                startOnLoad: false,
                useMaxWidth: true,
                theme: th,
                fontFamily: '"ChineseFont","Inter",-apple-system,"PingFang SC","Microsoft YaHei",sans-serif',
                securityLevel: 'strict',
                flowchart: { useMaxWidth: true },
                sequence: { useMaxWidth: true },
                gantt: { useMaxWidth: true }
            });
            window.__ysmMermaidInited = true;
            window.__ysmMermaidTheme = th;
        } catch (e) {}
    }

    function waitVisible(el) {
        return new Promise(function (resolve) {
            var n = 0;
            (function tick() {
                if (!el.isConnected || el.offsetWidth > 0) return resolve();
                if (++n > 120) return resolve();
                requestAnimationFrame(tick);
            })();
        });
    }
    function waitFonts() {
        if (!document.fonts || !document.fonts.ready) return Promise.resolve();
        return Promise.race([
            document.fonts.ready,
            new Promise(function (r) { setTimeout(r, 1500); })
        ]);
    }
    function svgOk(svg) {
        if (!svg) return false;
        var vb = svg.getAttribute('viewBox');
        if (!vb) return false;
        var n = vb.split(/[\s,]+/).map(Number);
        if (n.length !== 4 || !(n[2] > 0) || !(n[3] > 0)) return false;
        return svg.getBoundingClientRect().width > 0;
    }

    function applyCached(el, svgText, src) {
        el.innerHTML = prefixIds(svgText, 'c' + fnv(src + String(Date.now())));
        el.setAttribute('data-processed', 'true');
        el.__ysmSrc = src;
        el.classList.add('ysm-mmd-cached');
        removeTip(el);
    }

    function runOne(el) {
        var src = el.__ysmSrc != null ? el.__ysmSrc : el.textContent;
        el.__ysmSrc = src;
        return waitVisible(el).then(function () {
            return window.mermaid.run({ nodes: [el] });
        }).then(function () {
            var svg = el.querySelector('svg');
            var bad = !svg || (svg.textContent || '').indexOf('Syntax error') >= 0;
            if (!bad && svgOk(svg)) {
                el.classList.remove('ysm-mmd-cached');
                removeTip(el);
                lsSet(cacheKey(src), svg.outerHTML);
                return;
            }
            // 容器宽度为 0 / 首帧测量失败 → 复位重渲染一次
            el.removeAttribute('data-processed');
            el.textContent = src;
            return new Promise(function (r) { requestAnimationFrame(function () { r(); }); }).then(function () {
                return window.mermaid.run({ nodes: [el] });
            }).then(function () {
                var svg2 = el.querySelector('svg');
                if (!svg2 || (svg2.textContent || '').indexOf('Syntax error') >= 0) {
                    el.textContent = src;
                    failTip(el, src);
                    return;
                }
                el.classList.remove('ysm-mmd-cached');
                removeTip(el);
                lsSet(cacheKey(src), svg2.outerHTML);
            });
        }).catch(function () {
            el.textContent = src;
            failTip(el, src);
        });
    }

    /**
     * 渲染容器内所有 mermaid 代码块（自动转换 + 缓存 + 占位 + 重试）。
     * @param {Element} root 文章正文/预览容器
     * @param {Function} [after] 全部处理完成回调（无论成功失败都会调用）
     */
    function renderAll(root, after) {
        after = typeof after === 'function' ? after : function () {};
        if (!root) { after(); return Promise.resolve(); }
        var nodes = [];
        try {
            var codes = root.querySelectorAll('pre code.language-mermaid');
            Array.prototype.forEach.call(codes, function (c) {
                var pre = c.parentElement;
                if (!pre) return;
                var d = document.createElement('div');
                d.className = 'mermaid';
                d.textContent = c.textContent;
                pre.replaceWith(d);
                nodes.push(d);
            });
        } catch (e) {}
        if (!nodes.length) {
            nodes = Array.prototype.slice.call(root.querySelectorAll('.mermaid:not([data-processed])'));
        }
        if (!nodes.length) { after(); return Promise.resolve(); }

        var miss = [];
        nodes.forEach(function (el) {
            var src = el.__ysmSrc != null ? el.__ysmSrc : el.textContent;
            el.__ysmSrc = src;
            var cached = lsGet(cacheKey(src));
            if (cached) { applyCached(el, cached, src); }
            else { miss.push(el); placeholder(el); }
        });
        if (!miss.length) { after(); return Promise.resolve(); }

        return ensure().then(function (ok) {
            if (!ok) {
                miss.forEach(function (el) { el.textContent = el.__ysmSrc; failTip(el, el.__ysmSrc); });
                after();
                return;
            }
            initMermaid();
            return waitFonts().then(function () {
                return new Promise(function (resolve) {
                    var i = 0;
                    (function next() {
                        if (i >= miss.length) { resolve(); return; }
                        var el = miss[i++];
                        runOne(el).then(next, next);
                    })();
                });
            });
        }).then(function () { after(); }).catch(function () { after(); });
    }

    window.ysmMermaidEnsure = ensure;
    window.ysmMermaidRenderAll = renderAll;
    window.ysmMermaidInit = initMermaid;
    window.ysmMermaidTheme = themeName;
})();
