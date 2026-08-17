(function() {
    // v4.5.0：环境指纹——hash(lang|时区|分辨率|canvas 像素|UA)，登录/评论/后台等登录态接口经 X-Fp 头携带；
    //         服务端与会话绑定指纹比对，换浏览器/设备/隐私模式即判定环境变化。访客浏览不受影响。
    //         注意：指纹必须在同一会话内稳定（同步生成，不引入异步哈希，避免首请求与后续请求指纹不一致）。
    var ymFpCache = '';
    function ymFnvHash(str) {
        var h = 0x811c9dc5;
        for (var i = 0; i < str.length; i++) { h ^= str.charCodeAt(i); h = Math.imul(h, 0x01000193); }
        return ('00000000' + (h >>> 0).toString(16)).slice(-8);
    }
    function ymCanvasHash() {
        try {
            var c = document.createElement('canvas');
            c.width = 200; c.height = 40;
            var ctx = c.getContext('2d');
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.fillStyle = '#f60';
            ctx.fillRect(0, 0, 200, 40);
            ctx.fillStyle = '#069';
            ctx.fillText('YouSuperMarkdown\u2620' + navigator.userAgent.length, 5, 12);
            var d = c.toDataURL();
            return d.length + ':' + d.slice(-64);
        } catch (e) { return ''; }
    }
    function ymGetFp() {
        if (ymFpCache) return ymFpCache;
        try {
            var parts = [
                navigator.language || '',
                new Date().getTimezoneOffset(),
                (screen.width || 0) + 'x' + (screen.height || 0),
                ymCanvasHash(),
                navigator.userAgent
            ];
            ymFpCache = ymFnvHash(parts.join('|')) + ymFnvHash(parts.join('~')) + ymFnvHash(navigator.userAgent);
            return ymFpCache;
        } catch (e) {
            ymFpCache = ymFnvHash('no-fp');
            return ymFpCache;
        }
    }
    // 暴露给后台/OTP 入口页面使用（后台原生表单无自定义头，用上报校验模式）
    window.ymGetFp = ymGetFp;
    // v4.5.0：所有 api.php 请求统一携带 X-Fp（登录态接口服务端校验环境）
    (function ymPatchFetch() {
        var origFetch = window.fetch;
        if (!origFetch) return;
        window.fetch = function(url, opts) {
            opts = opts || {};
            var u = String(url);
            if (u.indexOf('api.php') !== -1) {
                opts.headers = Object.assign({}, opts.headers || {}, { 'X-Fp': ymGetFp() });
            }
            return origFetch.call(this, url, opts);
        };
    })();
    (function applyBg() {
        var body = document.body;
        var bgType = body.dataset.bgType || 'none';
        var bgImage = body.dataset.bgImage || '';
        var bgApiUrl = body.dataset.bgApiUrl || '';
        var bgBlur = body.dataset.bgBlur === '1';
        var bgBlurLevel = parseInt(body.dataset.bgBlurLevel) || 0;
        var bgCardOpacity = body.dataset.bgCardOpacity !== undefined ? parseInt(body.dataset.bgCardOpacity) : 100;
        body.style.setProperty('--bg-card-opacity', (bgCardOpacity / 100));
        if (bgBlur && bgBlurLevel > 0) {
            body.classList.add('bg-blur');
            body.style.setProperty('--bg-blur-level', bgBlurLevel + 'px');
        }
        // v4.6.2：背景图预加载——加载成功才启用背景（bg-active），失败则不加并输出控制台警告，
        //          避免「背景图 404/加载失败却仍加模糊类」导致视觉无变化且无任何提示
        // v4.7.2：对比色自适应——canvas 读取背景图平均亮度（同源上传图可读；跨域 API 图无 CORS 读失败降级不设属性）
        function computeBgLuma(url, cb) {
            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function() {
                try {
                    var w = Math.max(1, Math.min(img.naturalWidth || 100, 64));
                    var h = Math.max(1, Math.min(img.naturalHeight || 100, 64));
                    var c = document.createElement('canvas');
                    c.width = w; c.height = h;
                    var x = c.getContext('2d');
                    x.drawImage(img, 0, 0, w, h);
                    var d = x.getImageData(0, 0, w, h).data;
                    var sum = 0, n = 0;
                    for (var i = 0; i < d.length; i += 4) { sum += 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]; n++; }
                    cb(n > 0 ? sum / n : -1);
                } catch (e) { cb(-1); }
            };
            img.onerror = function() { cb(-1); };
            img.src = url;
        }
        function applyBgImage(url) {
            var probe = new Image();
            probe.onload = function() {
                body.classList.add('bg-active');
                body.style.setProperty('--bg-url', 'url(' + url + ')');
                // 背景暗（≤140）→ data-text-contrast=light 整页暗色白字；背景亮 → dark 黑色系；读取失败降级不设属性（保持浅色强制黑）
                computeBgLuma(url, function(luma) {
                    if (luma < 0) { body.removeAttribute('data-text-contrast'); return; }
                    body.setAttribute('data-text-contrast', luma <= 140 ? 'light' : 'dark');
                });
            };
            probe.onerror = function() {
                console.warn('[applyBg] 背景图加载失败（已停用背景显示）:', url);
            };
            probe.src = url;
        }
        if (bgType === 'image' && bgImage) {
            // v3.3.3：背景图相对路径补前导斜杠——CSS 变量里相对路径按 CSS 文件(css/)解析，
            // 导致 /css/data/bg/.. 404；统一转成根相对路径 /data/bg/..
            var bgUrl = (bgImage.indexOf('/') === 0 || /^https?:/i.test(bgImage)) ? bgImage : '/' + bgImage;
            applyBgImage(bgUrl);
        } else if (bgType === 'api' && bgApiUrl) {
            var apiUrl = (bgApiUrl.indexOf('/') === 0 || /^https?:/i.test(bgApiUrl)) ? bgApiUrl : '/' + bgApiUrl;
            applyBgImage(apiUrl);
        }
        if (window.console && console.info) console.info('[applyBg] type=' + bgType + ' blur=' + bgBlurLevel + 'px cardOpacity=' + bgCardOpacity + '%');
    })();
    // v4.2.2：mermaid 按需加载——mermaid.min.js 达 3.3MB，页面默认不再放 <head> 阻塞首屏；
    // 仅当公告/文章正文出现 ```mermaid 代码块时才动态注入，加载完成后渲染。
    // v4.6.1：源由 jsdelivr CDN 改为本地 vendor/mermaid.min.js（大陆网络 CDN 不可达时流程图彻底失效）。
    // v4.7.14 重写：单次 mermaid.run() 批量渲染所有节点 + _ymInited 防重复初始化 + 错误回退逻辑
    // 安全：mermaid SVG 由库自身做 XSS 过滤；fallback 文本经 escapeHTML 转义
    // 约束：不耦合 hljs（hljs 在 loadFile / 公告中独立同步调用，两者互不依赖）
    var _ymInited = false;
    function ensureMermaid(cb) {
        if (window.mermaid) { cb(); return; }
        var s = document.createElement('script');
        s.src = 'vendor/mermaid.min.js';
        s.onload = cb;
        s.onerror = function() {};
        document.head.appendChild(s);
    }
    function renderMermaidBlocks(root, cb) {
        cb = cb || function() {};
        try {
            var blocks = root.querySelectorAll('pre code.language-mermaid');
            if (!blocks.length) { cb(); return; }
            ensureMermaid(function() {
                (async function() {
                    try {
                        // 1. pre code.language-mermaid → div.mermaid（保留纯文本）
                        blocks.forEach(function(code) {
                            var pre = code.parentElement;
                            if (!pre) return;
                            var div = document.createElement('div');
                            div.className = 'mermaid';
                            div.textContent = code.textContent;
                            pre.replaceWith(div);
                        });
                        // 2. 初始化 mermaid（_ymInited 防重复初始化）
                        if (!_ymInited) {
                            mermaid.initialize({ startOnLoad: false });
                            _ymInited = true;
                        }
                        // 3. 串行逐个渲染（避免并发 run() 导致 mermaid 内部状态混乱）
                        var els = root.querySelectorAll('.mermaid');
                        for (var i = 0; i < els.length; i++) {
                            var el = els[i];
                            try {
                                await mermaid.run({ nodes: [el] });
                                var svg = el.querySelector('svg');
                                if (svg && svg.textContent.indexOf('Syntax error') >= 0) {
                                    el.innerHTML = '<pre style="overflow:auto">' + escapeHTML(el.textContent) + '</pre>';
                                }
                            } catch (e) {
                                el.innerHTML = '<pre style="overflow:auto">' + escapeHTML(el.textContent) + '</pre>';
                            }
                        }
                    } catch (e) {}
                })().then(cb).catch(function() { cb(); });
            });
        } catch (e) { cb(); }
    }
    const topBar = document.getElementById('topBar');
    const btnSearch = document.getElementById('btnSearch');
    const btnToc = document.getElementById('btnToc');
    const btnFont = document.getElementById('btnFont');
    const btnThemeToggle = document.getElementById('btnThemeToggle');
    // v4.6.2：主题跟随系统状态——手动切换后停止跟随（否则系统深色模式下 matchMedia 监听会把刚切走的主题立刻改回）
    let mdThemeMedia = null, mdThemeManual = false, mdThemeHandler = null;
    const btnColor = document.getElementById('btnColor');
    const searchPanel = document.getElementById('searchPanel');
    const tocPanel = document.getElementById('tocPanel');
    const fontPanel = document.getElementById('fontPanel');
    const colorPanel = document.getElementById('colorPanel');
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    const tocFileList = document.getElementById('tocFileList');
    const homeView = document.getElementById('homeView');
    const cardsGrid = document.getElementById('cardsGrid');
    const archiveView = document.getElementById('archiveView');
    const emptyHome = document.getElementById('emptyHome');
    const readingView = document.getElementById('readingView');
    const markdownBody = document.getElementById('markdownBody');
    const floatingButtons = document.getElementById('floatingButtons');
    const floatTocBtn = document.getElementById('floatTocBtn');
    const scrollToTopBtn = document.getElementById('scrollToTopBtn');
    const floatHomeBtn = document.getElementById('floatHomeBtn');
    const tocPopup = document.getElementById('tocPopup');
    const tocPopupList = document.getElementById('tocPopupList');
    const floatMusicBtn = document.getElementById('floatMusicBtn');
    const musicPopup = document.getElementById('musicPopup');
    const musicList = document.getElementById('musicList');
    const musicLoading = document.getElementById('musicLoading');
    const musicCover = document.getElementById('musicCover');
    const musicName = document.getElementById('musicName');
    const musicArtist = document.getElementById('musicArtist');
    const musicPlay = document.getElementById('musicPlay');
    const musicPlayIcon = document.getElementById('musicPlayIcon');
    const musicPrev = document.getElementById('musicPrev');
    const musicNext = document.getElementById('musicNext');
    const musicLyrToggle = document.getElementById('musicLyrToggle');
    const musicLyrPanel = document.getElementById('musicLyrPanel');
    const musicLyrScroll = document.getElementById('musicLyrScroll');
    const musicPlayerMain = document.getElementById('musicPlayerMain');
    const musicProgressBar = document.getElementById('musicProgressBar');
    const musicProgressFill = document.getElementById('musicProgressFill');
    const musicProgressDot = document.getElementById('musicProgressDot');
    const musicCurTime = document.getElementById('musicCurTime');
    const musicTotalTime = document.getElementById('musicTotalTime');
    const musicPopupCount = document.getElementById('musicPopupCount');
    const musicAudio = document.getElementById('musicAudio');
    const musicListToggle = document.getElementById('musicListToggle');
    const musicModeBtn = document.getElementById('musicModeBtn');
    const discRing = document.querySelector('.disc-ring');
    const discNotes = document.querySelectorAll('.disc-note');
    const hueSlider = document.getElementById('hueSlider');
    const fontTypeButtons = document.querySelectorAll('.font-type-btn');
    const fontSizeSlider = document.getElementById('fontSizeSlider');
    const fontSizeValue = document.getElementById('fontSizeValue');
    const tocPanelHeader = document.getElementById('tocPanelHeader');
    const shareModalOverlay = document.getElementById('shareModalOverlay');
    const shareQrcode = document.getElementById('shareQrcode');
    const shareModalClose = document.getElementById('shareModalClose');
    const readingProgress = document.getElementById('readingProgress');
    const readingProgressText = document.getElementById('readingProgressText');
    const toast = document.getElementById('toast');
    const imgLightbox = document.getElementById('imgLightbox');
    const lightboxImg = document.getElementById('lightboxImg');
    const sidebar = document.getElementById('sidebar');
    const sidebarFileList = document.getElementById('sidebarFileList');
    const sidebarTocList = document.getElementById('sidebarTocList');
    const sidebarTocHeader = document.getElementById('sidebarTocHeader');
    const sidebarSearchInput = document.getElementById('sidebarSearchInput');
    const sidebarCount = document.getElementById('sidebarCount');
    const sidebarBackBtn = document.getElementById('sidebarBackBtn');
    const sidebarArticleTitle = document.getElementById('sidebarArticleTitle');
    const prevNextNav = document.getElementById('prevNextNav');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const prevTitle = document.getElementById('prevTitle');
    const nextTitle = document.getElementById('nextTitle');
    let allFiles = [];
    let currentCategory = '';
    let currentTag = '';          // v4.0.0：标签聚合过滤
    let archiveMode = false;      // v4.0.0：归档视图开关
    let isReadingView = false;
    let currentHeadings = [];
    let activeHeadingIndex = -1;
    let lastScrollY = 0;
    let currentFileIndex = -1;
    let currentFileName = '';
    function escapeHTML(str) { const div = document.createElement('div'); div.textContent = str; return div.innerHTML; }
    // v4.4.1：标题锚点 slug 归一化——去除中文顿号/括号/全角标点与英文标点、空白转连字符、小写，
    //         使渲染标题 id 与正文手写锚点（[目录](#一项目概述)）一致；模糊匹配时也用它比对。
    // v4.6.1：v4.5.0 误删本函数定义（调用处保留），导致 marked 渲染/锚点处理全部 ReferenceError，
    //         所有文章点击后报「文档不存在」——补回 v4.4.1 原始定义。
    function ymSlug(s) {
        return String(s || '')
            .toLowerCase()
            .trim()
            .replace(/<[^>]*>/g, '')
            .replace(/[\u3000-\u303f\u2000-\u206f\u2e00-\u2e7f\\'"!@#$%^&*()+,./:;<=>?[\]{}`~|·「」『』〈〉《》【】（）]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
    }
    let CSRF_TOKEN = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    // v2.11.1：动态获取 CSRF token（修复「会话失败」根因——登录/注册等匿名 POST 前调用，
    // 同一请求链内 cookie 与 token 必然同 session；浏览器未携带 PHPSESSID/session 过期也能自愈）
    function ensureFreshCsrf() {
        return fetch('api.php?action=csrf').then(r => r.json()).then(d => {
            if (d.success && d.csrf_token) CSRF_TOKEN = d.csrf_token;
            return d.success ? d.csrf_token : CSRF_TOKEN;
        }).catch(() => CSRF_TOKEN);
    }
    (function() {
        const origFetch = window.fetch.bind(window);
        window.fetch = function(url, options) {
            options = options || {};
            if (typeof url === 'string' && (url.indexOf('api.php') !== -1 || url.indexOf('index.php') !== -1) && String(options.method || 'GET').toUpperCase() === 'POST') {
                const headers = options.headers || {};
                if (headers instanceof Headers) {
                    headers.set('X-CSRF-Token', CSRF_TOKEN);
                } else if (Array.isArray(headers)) {
                    headers.push(['X-CSRF-Token', CSRF_TOKEN]);
                } else {
                    options.headers = Object.assign({}, headers, { 'X-CSRF-Token': CSRF_TOKEN });
                }
            }
            return origFetch(url, options);
        };
    })();
    function setAccentHue(hue) {
        document.documentElement.style.setProperty('--accent-hue', hue);
        localStorage.setItem('md-reader-hue', hue);
    }
    const savedHue = localStorage.getItem('md-reader-hue') || 220;
    setAccentHue(savedHue);
    hueSlider.value = savedHue;
    hueSlider.addEventListener('input', () => setAccentHue(hueSlider.value));
    const colorResetBtn = document.getElementById('colorResetBtn');
    if (colorResetBtn) colorResetBtn.addEventListener('click', () => { setAccentHue(220); hueSlider.value = 220; });
    function applyFontType(type) {
        if (type === 'default') {
            document.body.style.fontFamily = '-apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif';
        } else {
            document.body.style.fontFamily = "'ChineseFont', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif";
        }
        localStorage.setItem('md-font-type', type);
    }
    function applyFontSize(size) {
        document.documentElement.style.setProperty('--base-font-size', size + 'px');
        fontSizeValue.textContent = size + 'px';
        localStorage.setItem('md-font-size', size);
    }
    const savedFontType = localStorage.getItem('md-font-type') || 'default';
    const savedFontSize = localStorage.getItem('md-font-size') || 14;
    applyFontType(savedFontType);
    applyFontSize(savedFontSize);
    fontSizeSlider.value = savedFontSize;
    fontTypeButtons.forEach(btn => btn.classList.toggle('active', btn.dataset.font === savedFontType));
    fontTypeButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            fontTypeButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            applyFontType(btn.dataset.font);
        });
    });
    fontSizeSlider.addEventListener('input', () => applyFontSize(fontSizeSlider.value));
    function openPanel(panel) { closeAllPanels(); panel.classList.add('active'); }
    function closePanel(panel) { panel.classList.remove('active'); }
    function closeAllPanels() {
        [searchPanel, tocPanel, fontPanel, colorPanel].forEach(p => p.classList.remove('active'));
    }
    btnSearch.addEventListener('click', (e) => {
        e.stopPropagation();
        if (searchPanel.classList.contains('active')) closePanel(searchPanel);
        else openPanel(searchPanel);
    });
    btnToc.addEventListener('click', (e) => {
        e.stopPropagation();
        if (tocPanel.classList.contains('active')) closePanel(tocPanel);
        else {
            if (isReadingView) { renderDocumentOutline(); tocPanelHeader.style.display = 'block'; }
            else { renderTocList(); tocPanelHeader.style.display = 'none'; }
            openPanel(tocPanel);
        }
    });
    btnFont.addEventListener('click', (e) => {
        e.stopPropagation();
        if (fontPanel.classList.contains('active')) closePanel(fontPanel);
        else openPanel(fontPanel);
    });
    btnColor.addEventListener('click', (e) => {
        e.stopPropagation();
        if (colorPanel.classList.contains('active')) closePanel(colorPanel);
        else openPanel(colorPanel);
    });
    document.addEventListener('click', (e) => {
        if (!searchPanel.contains(e.target) && e.target !== btnSearch) closePanel(searchPanel);
        if (!tocPanel.contains(e.target) && e.target !== btnToc) closePanel(tocPanel);
        if (!fontPanel.contains(e.target) && e.target !== btnFont) closePanel(fontPanel);
        if (!colorPanel.contains(e.target) && e.target !== btnColor) closePanel(colorPanel);
    });
    floatTocBtn.addEventListener('click', (e) => { e.stopPropagation(); tocPopup.classList.toggle('active'); musicPopup.classList.remove('active'); });
    document.addEventListener('click', (e) => { if (!tocPopup.contains(e.target) && !floatTocBtn.contains(e.target)) tocPopup.classList.remove('active'); });
    shareModalClose.addEventListener('click', () => { shareModalOverlay.classList.remove('active'); shareQrcode.innerHTML = ''; });
    shareModalOverlay.addEventListener('click', (e) => { if (e.target === shareModalOverlay) { shareModalOverlay.classList.remove('active'); shareQrcode.innerHTML = ''; } });
    // v4.7.7：移动端浮动按钮组滑动隐藏——滚动时向右滑出，停止 5 秒后显示
    var _floatHideTimer = null;
    function _floatShow() {
        if (window.innerWidth <= 768) floatingButtons.classList.remove('buttons-hidden');
    }
    window.addEventListener('scroll', () => {
        const scrollY = window.pageYOffset || document.documentElement.scrollTop;
        scrollToTopBtn.style.opacity = scrollY > 300 ? '1' : '0';
        scrollToTopBtn.style.pointerEvents = scrollY > 300 ? 'auto' : 'none';
        // v4.7.7：移动端滑动时隐藏浮动按钮组，停止 5 秒后恢复
        if (window.innerWidth <= 768) {
            floatingButtons.classList.add('buttons-hidden');
            if (_floatHideTimer) clearTimeout(_floatHideTimer);
            _floatHideTimer = setTimeout(_floatShow, 5000);
        }
        if (isReadingView) {
            if (scrollY <= 0) topBar.classList.remove('hidden');
            else if (scrollY > lastScrollY && scrollY > 80) topBar.classList.add('hidden');
            else if (scrollY < lastScrollY) topBar.classList.remove('hidden');
            updateActiveHeading();
            const docH = document.documentElement.scrollHeight - window.innerHeight;
            const pct = docH > 0 ? Math.min(100, (scrollY / docH) * 100) : 0;
            const isDesktop = window.innerWidth >= 1025;
            if (isDesktop) {
                const barW = window.innerWidth - 280;
                readingProgress.style.width = (barW * pct / 100) + 'px';
            } else {
                readingProgress.style.width = pct + '%';
            }
            readingProgress.classList.add('active');
            // v4.1.4：进度条只显示进度条本身，不再显示百分比文字
        } else {
            topBar.classList.remove('hidden');
            readingProgress.classList.remove('active');
            if (readingProgressText) readingProgressText.classList.remove('active');
            readingProgress.style.width = '0%';
        }
        lastScrollY = scrollY;
    });
    scrollToTopBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    floatHomeBtn.addEventListener('click', showHome);
    // v4.7.11：主题监听兼容辅助函数（Safari <14 / 旧 Android WebView 只支持 addListener/removeListener）
    function mdThemeAddListener(mq, handler) {
        if (!mq || !handler) return;
        if (typeof mq.addEventListener === 'function') { mq.addEventListener('change', handler); }
        else if (typeof mq.addListener === 'function') { mq.addListener(handler); }
    }
    function mdThemeRemoveListener(mq, handler) {
        if (!mq || !handler) return;
        if (typeof mq.removeEventListener === 'function') { mq.removeEventListener('change', handler); }
        else if (typeof mq.removeListener === 'function') { mq.removeListener(handler); }
    }
    btnThemeToggle.addEventListener('click', () => {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const nextTheme = isDark ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', nextTheme);
        // v4.7.11：手动切换双持久化——localStorage 记住用户选择，sessionStorage 标记"手动切换过"
        //          隐私模式下 localStorage 写入失败时，sessionStorage 仍可保证当前会话不跟随系统
        try { localStorage.setItem('md-theme', nextTheme); } catch (e) {}
        try { sessionStorage.setItem('md-theme-manual', '1'); } catch (e) {}
        // v4.6.2：手动切换后停止跟随系统——移除 change 监听，系统深色模式不再把刚切走的主题立刻改回
        mdThemeManual = true;
        mdThemeRemoveListener(mdThemeMedia, mdThemeHandler);
        btnThemeToggle.innerHTML = isDark
            ? '<svg viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>'
            : '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
    });
    // v4.0.0：深色模式跟随系统——首次访问未手动设置时，按系统偏好（prefers-color-scheme）决定；
    //         手动切换后写入 localStorage 记住用户选择（v4.6.2：并停止跟随系统，见点击 handler）
    // v4.7.11：初始化时读取 sessionStorage 的 md-theme-manual 标记（隐私模式下 localStorage 可能写入失败）；
    //          兼容 addListener/removeListener；加 mdThemeManual 双重保险判断
    (function() {
        let saved = '';
        try { saved = localStorage.getItem('md-theme') || ''; } catch (e) {}
        try { if (sessionStorage.getItem('md-theme-manual')) mdThemeManual = true; } catch (e) {}
        mdThemeMedia = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
        const systemDark = mdThemeMedia ? mdThemeMedia.matches : false;
        const theme = saved || (systemDark ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
        if (theme === 'dark') {
            btnThemeToggle.innerHTML = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
        }
        // 用户从未手动设置过时，跟随系统主题变化实时切换（手动切换后移除监听）
        // v4.7.11：saved 存在 OR mdThemeManual 标记存在 → 不跟随系统
        if (!saved && mdThemeMedia && !mdThemeManual) {
            mdThemeHandler = function(e) {
                if (mdThemeManual) return;
                document.documentElement.setAttribute('data-theme', e.matches ? 'dark' : 'light');
                btnThemeToggle.innerHTML = e.matches
                    ? '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>'
                    : '<svg viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
            };
            mdThemeAddListener(mdThemeMedia, mdThemeHandler);
        }
    })();
    async function loadFileList() {
        try {
            const resp = await fetch('?action=list');
            const data = await resp.json();
            if (data.success) { allFiles = data.files; renderCategoryBar(); renderAnnouncements(); renderHomeContent(); renderSidebarList(allFiles); }
        } catch (err) { cardsGrid.innerHTML = '<div class="empty-state">⚠️ 加载失败</div>'; }
    }
    // v3.1.6：首页公告卡片（服务端 window.YM_ANNOUNCEMENTS 数据；整卡可点跳详情）
    // v3.1.8：无关联文章的公告（纯文字/更新公告）点击弹出完整内容弹窗，手机端可看全文
    // v3.3.4：取消公告筛选条——公告数量少、筛公告意义不大，标签改为纯展示（可点跳详情保留）
    function renderAnnouncements() {
        var sec = document.getElementById('announcementSection');
        if (!sec) return;
        var anns = window.YM_ANNOUNCEMENTS || [];
        if (!anns.length) { sec.innerHTML = ''; sec.style.display = 'none'; return; }
        sec.style.display = '';
        var listHTML = anns.map(function(a) {
            // 有关联文章 → 点击跳文章详情；无 → 点击弹出完整公告弹窗
            var clickAttr = a.article
                ? ' data-file="' + escapeHTML(a.article) + '"'
                : ' data-ann-id="' + escapeHTML(a.id) + '"';
            var coverHTML = a.cover
                ? '<div class="ann-card-media"><img class="ann-card-cover" src="' + escapeHTML(a.cover) + '" alt="" loading="lazy" onerror="this.parentNode.style.display=\'none\'"><div class="ann-card-cover-ink"></div></div>'
                : '';
            var typeHTML = a.type === 'update'
                ? '<span class="ann-card-type update"><svg viewBox="0 0 24 24" width="12" height="12"><path d="M20 6L9 17l-5-5"/></svg>更新</span>'
                : '<span class="ann-card-type manual">公告</span>';
            var tagHTML = (a.tags || []).map(function(t) { return '<span class="ann-card-tag">#' + escapeHTML(t) + '</span>'; }).join('');
            return '<div class="ann-card' + (a.cover ? ' has-media' : '') + '"' + clickAttr + '>' +
                '<div class="ann-card-left">' +
                    // v3.3.16：类型徽章与标题同一行（移动到标题旁边），不再是标题上方独立一行
                    '<div class="ann-card-title-row">' + typeHTML + '<h3 class="ann-card-title">' + escapeHTML(a.title) + '</h3></div>' +
                    '<div class="ann-card-meta">' +
                        '<span class="ann-meta-item"><span class="ann-meta-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>' + escapeHTML(a.date) + '</span>' +
                        (a.words ? '<span class="ann-meta-item"><span class="ann-meta-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>' + (a.words / 1000).toFixed(1) + 'k字</span>' : '') +
                    '</div>' +
                    (a.summary ? '<p class="ann-card-summary">' + escapeHTML(a.summary) + '</p>' : '') +
                    (tagHTML ? '<div class="ann-card-tags">' + tagHTML + '</div>' : '') +
                    '<span class="ann-card-more">' + (a.article ? '查看文章 →' : '查看详情 →') + '</span>' +
                '</div>' +
                coverHTML +
            '</div>';
        }).join('');
        sec.innerHTML = '<div class="ann-header"><span class="ann-header-icon"><svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></span>公告</div>' +
            '<div class="ann-list">' + (listHTML || '<div class="ann-empty">暂无公告</div>') + '</div>';
        // 事件：整卡点击跳文章详情
        sec.querySelectorAll('.ann-card[data-file]').forEach(function(c) {
            c.addEventListener('click', function() { loadFile(c.getAttribute('data-file')); });
        });
        // v3.1.8：无关联文章公告点击 → 弹出完整内容
        sec.querySelectorAll('.ann-card[data-ann-id]').forEach(function(c) {
            c.addEventListener('click', function() {
                var id = c.getAttribute('data-ann-id');
                var ann = null;
                (window.YM_ANNOUNCEMENTS || []).forEach(function(a) { if (a.id === id) ann = a; });
                if (ann) openAnnounceDetail(ann);
            });
        });
    }
    // v3.1.8：公告详情弹窗（完整内容；有关联文章时提供跳转按钮）
    // v3.2.3：body 为 markdown 原文 → 用 marked 渲染富文本，提升公告可读性（小白也能看懂排版）
    function openAnnounceDetail(a) {
        var m = document.getElementById('announceModal');
        if (!m) return;
        m.querySelector('.ann-modal-type').textContent = a.type === 'update' ? '更新公告' : '公告';
        m.querySelector('.ann-modal-title').textContent = a.title;
        m.querySelector('.ann-modal-date').textContent = a.date || '';
        // v3.3.15：公告弹窗展示字数（首页公告卡片有字数、点进纯文字公告弹窗却无——补齐）
        var annWordsEl = m.querySelector('.ann-modal-words');
        if (annWordsEl) {
            if (a.words && a.words > 0) {
                annWordsEl.style.display = 'inline-flex';
                annWordsEl.innerHTML = '<span class="ann-modal-words-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>' + a.words + ' 字';
            } else {
                annWordsEl.style.display = 'none';
            }
        }
        var content = m.querySelector('.ann-modal-content');
        if (a.body && window.marked) {
            content.classList.add('markdown-body');
            // v3.3.0：公告正文视频语法提取（与文章阅读一致）
            var annVideoSlots = [];
            var annBody = a.body.replace(/!video\[([^\]]*)\]\(([^)\s]+)\)/g, function(m, t, s) {
                annVideoSlots.push({ title: t, src: s.trim() });
                return '@@YM_VIDEO_' + (annVideoSlots.length - 1) + '@@';
            });
            content.innerHTML = marked.parse(annBody);
            // v3.3.0：公告视频渲染（仅站内 data/videos/；外链按纯文本保留）
            renderYmVideos(content, annVideoSlots);
            // v3.3.2：公告正文图片懒加载
            content.querySelectorAll('img').forEach(function(im) {
                if (!im.getAttribute('loading')) im.setAttribute('loading', 'lazy');
                im.setAttribute('decoding', 'async');
            });
            // v3.2.5：公告正文 mermaid 流程图渲染（与文章阅读一致）；v4.2.2 起按需加载
            // v4.7.14：mermaid 渲染完成后执行 hljs，避免异步加载时序问题
            renderMermaidBlocks(content, function() {
                if (typeof hljs !== 'undefined') {
                    content.querySelectorAll('pre code').forEach(function(b) { hljs.highlightElement(b); });
                }
            });
            // 外链安全：禁止外链在当前页直接跳转（防 tabnabbing/钓鱼），与文章渲染一致
            content.querySelectorAll('a[href]').forEach(function(a) {
                var href = a.getAttribute('href') || '';
                if (/^(https?:)?\/\//i.test(href) && href.indexOf(window.location.host) === -1) {
                    a.setAttribute('target', '_blank');
                    a.setAttribute('rel', 'noopener noreferrer');
                }
            });
        } else {
            content.classList.remove('markdown-body');
            content.textContent = a.summary || '（暂无内容）';
        }
        var link = m.querySelector('.ann-modal-link');
        if (a.article) {
            link.style.display = 'inline-flex';
            link.dataset.file = a.article;
        } else {
            link.style.display = 'none';
        }
        m.classList.add('active');
    }
    // v3.3.0：!video[标题](站内相对路径) → <video> 播放器
    // 安全策略：src 仅允许站内相对路径 data/videos/xxx.mp4（防外链跳转/防盗链/防 IP 泄露给第三方）；
    // 外链/绝对 URL 一律按原始语法纯文本展示（不渲染播放器、不发网络请求）
    function renderYmVideos(root, slots) {
        if (!root || !slots || !slots.length) return;
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        var textNodes = [];
        while (walker.nextNode()) textNodes.push(walker.currentNode);
        textNodes.forEach(function(node) {
            var val = node.nodeValue;
            if (val.indexOf('@@YM_VIDEO_') === -1) return;
            var frag = document.createDocumentFragment();
            var re = /@@YM_VIDEO_(\d+)@@/g;
            var last = 0, m;
            while ((m = re.exec(val)) !== null) {
                if (m.index > last) frag.appendChild(document.createTextNode(val.slice(last, m.index)));
                var slot = slots[parseInt(m[1], 10)];
                if (slot) {
                    var src = slot.src;
                    // 仅站内 data/videos/ 且为安全文件名（字母数字下划线连字符点）才渲染播放器
                    if (/^data\/videos\/[A-Za-z0-9_.\-]+\.[A-Za-z0-9]+$/.test(src)) {
                        var vid = document.createElement('video');
                        vid.controls = true;
                        vid.preload = 'metadata';
                        vid.playsInline = true;
                        vid.setAttribute('controlslist', 'nodownload');
                        if (slot.title) vid.title = slot.title;
                        // v3.3.2：懒加载——视频进入视口才发起请求（首屏不拉大文件；配合 nginx mp4/Range 更流畅）
                        if ('IntersectionObserver' in window) {
                            vid.dataset.ymSrc = src;
                            var io = new IntersectionObserver(function(entries, obs) {
                                entries.forEach(function(en) {
                                    if (en.isIntersecting && !vid.src) {
                                        vid.src = vid.dataset.ymSrc;
                                        obs.unobserve(vid);
                                    }
                                });
                            }, { rootMargin: '200px' });
                            io.observe(vid);
                        } else {
                            vid.src = src;
                        }
                        frag.appendChild(vid);
                        if (slot.title) {
                            var cap = document.createElement('p');
                            cap.className = 'ym-video-cap';
                            cap.textContent = '▶ ' + slot.title;
                            frag.appendChild(cap);
                        }
                    } else {
                        // 不合法（外链/绝对地址/路径穿越）→ 按原始语法文本展示，不发任何网络请求
                        frag.appendChild(document.createTextNode('!video[' + slot.title + '](' + slot.src + ')'));
                    }
                }
                last = m.index + m[0].length;
            }
            if (last < val.length) frag.appendChild(document.createTextNode(val.slice(last)));
            node.parentNode.replaceChild(frag, node);
        });
    }
    // v3.1.8：公告弹窗交互（关闭/遮罩点击/跳转文章）
    (function() {
        var m = document.getElementById('announceModal');
        if (!m) return;
        var close = function() { m.classList.remove('active'); };
        document.getElementById('announceModalClose').addEventListener('click', close);
        m.addEventListener('click', function(e) { if (e.target === m) close(); });
        document.getElementById('announceModalLink').addEventListener('click', function() {
            var f = this.dataset.file;
            if (f) { close(); loadFile(f); }
        });
    })();
    function renderCards() {
        if (archiveMode && archiveView) { cardsGrid.style.display = 'none'; if (archiveView) archiveView.style.display = 'block'; }
        else if (archiveView) archiveView.style.display = 'none';
        if (allFiles.length === 0) { emptyHome.style.display = 'block'; cardsGrid.innerHTML = ''; return; }
        emptyHome.style.display = 'none';
        cardsGrid.style.display = '';
        // v3.3.12：首页卡片封面走缩略图（大图只在文章阅读页加载，首页不再下载 MB 级原图）
        function cardCover(src) {
            if (!src) return src;
            if (/^\/?data\/images\//.test(src) && /\.(jpe?g|png|webp|gif)$/i.test(src)) {
                return 'img.php?src=' + encodeURIComponent(src) + '&w=640';
            }
            return src;
        }
        var displayFiles = filteredFiles();
        cardsGrid.innerHTML = displayFiles.map((file, i) => `
            <div class="doc-card" data-filename="${escapeHTML(file.name)}" style="animation-delay:${i*0.05}s">
                ${file.cover ? `<div class="doc-cover"><img src="${escapeHTML(cardCover(file.cover))}" alt="" loading="lazy" onerror="this.parentNode.style.display='none'"></div>` : ''}
                <div class="card-title">${file.pinned ? '<span class="card-pin-icon" title="置顶"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2z" fill="currentColor" stroke="none"/></svg></span>' : ''}${escapeHTML(file.displayName)}</div>
                <div class="card-meta">
                    <span><span class="meta-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>${file.modified}</span>
                    <span><span class="meta-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>${file.wordCount}字</span>
                    ${file.category ? `<span><span class="meta-icon"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></span>${escapeHTML(file.category)}</span>` : ''}
                    ${(file.views || 0) > 0 ? `<span><span class="meta-icon"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span>${file.views}</span>` : ''}
                </div>
                <div class="card-excerpt">${escapeHTML(file.excerpt || '')}</div>
                <div class="card-tags">${file.tags.map(t => `<span class="tag" data-tag="${escapeHTML(t)}">#${escapeHTML(t)}</span>`).join('')}</div>
            </div>`).join('');
        document.querySelectorAll('.doc-card').forEach(card => card.addEventListener('click', () => loadFile(card.dataset.filename)));
        // v4.0.0：卡片标签点击 → 标签聚合过滤（阻止冒泡，避免误触进入文章）
        document.querySelectorAll('.doc-card .card-tags .tag').forEach(tag => {
            tag.addEventListener('click', function(e) {
                e.stopPropagation();
                currentTag = currentTag === this.dataset.tag ? '' : this.dataset.tag;
                currentCategory = '';
                renderCategoryBar();
                renderHomeContent();
            });
        });
        // v4.7.14：卡片标签横向滚动（鼠标拖动 + 滚轮）
        document.querySelectorAll('.doc-card .card-tags').forEach(function(el) { enableHScroll(el); });
    }
    function renderCategoryBar() {
        var bar = document.getElementById('categoryBar');
        if (!bar) return;
        var map = {};
        var tagMap = {};
        allFiles.forEach(f => {
            var c = f.category || '';
            if (c) map[c] = (map[c] || 0) + 1;
            (f.tags || []).forEach(t => { if (t && t !== '#') tagMap[t] = (tagMap[t] || 0) + 1; });
        });
        var cats = Object.keys(map);
        var tags = Object.keys(tagMap);
        if (cats.length === 0 && tags.length === 0) { bar.classList.remove('has-categories'); bar.innerHTML = ''; return; }
        bar.classList.add('has-categories');
        var total = allFiles.length;
        // v4.0.0：归档视图切换按钮 + 标签聚合条
        var archiveBtn = archiveMode
            ? `<div class="category-bar-item active" data-view="archive">归档</div>`
            : `<div class="category-bar-item" data-view="archive">归档</div>`;
        var leftHTML = `<div class="category-bar-left">${archiveBtn}<div class="category-bar-item${currentCategory === '' && !currentTag ? ' active' : ''}" data-category="">全部 <span class="category-bar-count">${total}</span></div></div>`;
        var divider = '<div class="category-bar-divider"></div>';
        var rightItems = '';
        if (currentTag) {
            rightItems += `<div class="category-bar-item active tag-filter" data-tag="${escapeHTML(currentTag)}">#${escapeHTML(currentTag)} <span class="category-bar-count">${tagMap[currentTag] || 0}</span></div>`;
        }
        cats.forEach(c => {
            rightItems += `<div class="category-bar-item${currentCategory === c && !currentTag ? ' active' : ''}" data-category="${escapeHTML(c)}">${escapeHTML(c)} <span class="category-bar-count">${map[c]}</span></div>`;
        });
        // v4.1.1：标签云独立一行（不再与分类按钮同排挤压导致重叠/挤出）
        var tagCloudHTML = '';
        if (currentCategory === '' && tags.length > 0) {
            tagCloudHTML = `<div class="tag-cloud-row"><div class="tag-cloud">${tags.slice(0, 20).map(t => `<span class="tag-cloud-item${currentTag === t ? ' active' : ''}" data-tag="${escapeHTML(t)}">#${escapeHTML(t)}</span>`).join('')}</div></div>`;
        }
        var rightHTML = `<div class="category-bar-right">${rightItems}</div>`;
        bar.innerHTML = leftHTML + divider + rightHTML + tagCloudHTML;
        bar.querySelectorAll('.category-bar-item').forEach(item => {
            item.addEventListener('click', function() {
                if (this.dataset.view === 'archive') { archiveMode = !archiveMode; renderCategoryBar(); renderHomeContent(); return; }
                if (this.dataset.tag !== undefined) { currentTag = currentTag === this.dataset.tag ? '' : this.dataset.tag; }
                else {
                    // v4.0.0：点「全部」（data-category=""）同时清除标签筛选
                    currentCategory = this.dataset.category;
                    currentTag = '';
                }
                renderCategoryBar();
                renderHomeContent();
            });
        });
        bar.querySelectorAll('.tag-cloud-item').forEach(item => {
            item.addEventListener('click', function() {
                currentTag = currentTag === this.dataset.tag ? '' : this.dataset.tag;
                renderCategoryBar();
                renderHomeContent();
            });
        });
        // v4.1.4：分类栏/标签云启用横向滚动（滚轮+拖拽），超出部分电脑端可查看
        enableHScroll(bar.querySelector('.category-bar-right'));
        enableHScroll(bar.querySelector('.tag-cloud'));
    }
    // v4.1.4：分类/标签栏横向滚动增强——桌面端鼠标滚轮垂直滚动转横向，并支持按住拖动
    function enableHScroll(el) {
        if (!el || el.__ymHScroll) return;
        el.__ymHScroll = true;
        // 滚轮：垂直滚动（deltaY）转为横向滚动（deltaX），Shift 不依赖
        el.addEventListener('wheel', function(e) {
            if (Math.abs(e.deltaY) > Math.abs(e.deltaX) && el.scrollWidth > el.clientWidth + 2) {
                e.preventDefault();
                el.scrollLeft += e.deltaY;
            }
        }, { passive: false });
        // 按住拖动（桌面鼠标；移动端原生触摸滚动不受影响）
        var down = false, startX = 0, startLeft = 0, moved = false;
        el.addEventListener('mousedown', function(e) {
            if (e.button !== 0) return;
            if (el.scrollWidth <= el.clientWidth + 2) return; // 无溢出不启用拖拽，避免干扰点击
            down = true; moved = false;
            startX = e.clientX; startLeft = el.scrollLeft;
            el.style.cursor = 'grabbing';
        });
        document.addEventListener('mousemove', function(e) {
            if (!down) return;
            var dx = e.clientX - startX;
            if (Math.abs(dx) > 4) moved = true;
            el.scrollLeft = startLeft - dx;
        });
        document.addEventListener('mouseup', function() {
            if (!down) return;
            down = false;
            el.style.cursor = '';
        });
        // 子元素点击：若刚拖动过则忽略
        el.addEventListener('click', function(e) {
            if (moved) { e.stopPropagation(); e.preventDefault(); moved = false; }
        }, true);
    }
    // v4.0.0：根据 currentCategory/currentTag 过滤 + 归档视图渲染（按年月分组）
    function filteredFiles() {
        return allFiles.filter(f =>
            (!currentCategory || f.category === currentCategory) &&
            (!currentTag || (f.tags || []).includes(currentTag))
        );
    }
    function renderHomeContent() {
        if (archiveMode) { renderArchive(); return; }
        renderCards();
    }
    function renderArchive() {
        if (!archiveView) return;
        emptyHome.style.display = 'none';
        cardsGrid.style.display = 'none';
        archiveView.style.display = 'block';
        var files = filteredFiles();
        if (!files.length) { archiveView.innerHTML = '<div class="archive-empty">暂无归档内容</div>'; return; }
        // 按 modified 年月分组（倒序）
        var groups = {};
        files.forEach(f => {
            var key = (f.modified || '').slice(0, 7);
            if (!key) key = '未分类';
            (groups[key] = groups[key] || []).push(f);
        });
        var years = Object.keys(groups).sort((a, b) => b.localeCompare(a));
        var html = years.map(y => {
            var items = groups[y].map(f => `
                <a class="archive-item" data-filename="${escapeHTML(f.name)}">
                    <span class="archive-item-title">${escapeHTML(f.displayName)}</span>
                    <span class="archive-item-meta">${escapeHTML(f.modified)}${(f.views || 0) > 0 ? ' · ' + f.views + ' 浏览' : ''}</span>
                </a>`).join('');
            return `<div class="archive-group"><div class="archive-year">${escapeHTML(y)} <span class="archive-year-count">${groups[y].length} 篇</span></div><div class="archive-items">${items}</div></div>`;
        }).join('');
        archiveView.innerHTML = html;
        archiveView.querySelectorAll('.archive-item').forEach(item => item.addEventListener('click', () => loadFile(item.dataset.filename)));
    }
    function renderSidebarList(files) {
        if (!sidebarFileList) return;
        sidebarCount.textContent = files.length;
        sidebarFileList.innerHTML = files.map(file => `
            <div class="sidebar-item" data-filename="${escapeHTML(file.name)}">
                <div class="sidebar-item-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
                <div style="flex:1;min-width:0;overflow:hidden;">
                    <div class="sidebar-item-text">${file.pinned ? '<span class="sidebar-pin-icon" title="置顶"><svg viewBox="0 0 24 24" width="14" height="14"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2z" fill="currentColor" stroke="none"/></svg></span>' : ''}${escapeHTML(file.displayName)}</div>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${file.modified}</div>
                </div>
            </div>`).join('');
        sidebarFileList.querySelectorAll('.sidebar-item').forEach(item => {
            item.addEventListener('click', () => loadFile(item.dataset.filename));
        });
    }
    function highlightSidebarItem(filename) {
        if (!sidebarFileList) return;
        sidebarFileList.querySelectorAll('.sidebar-item').forEach(item => {
            item.classList.toggle('active', item.dataset.filename === filename);
        });
    }
    function showSidebarToc() {
        if (!sidebarFileList || !sidebarTocList || !sidebarTocHeader) return;
        sidebarFileList.style.display = 'none';
        sidebarTocHeader.style.display = 'block';
        sidebarTocList.style.display = 'block';
        if (sidebarSearchInput) sidebarSearchInput.parentElement.style.display = 'none';
        if (sidebarCount) sidebarCount.style.display = 'none';
        if (sidebarBackBtn) sidebarBackBtn.style.display = 'flex';
        if (sidebarArticleTitle) sidebarArticleTitle.style.display = 'block';
    }
    function showSidebarFileList() {
        if (!sidebarFileList || !sidebarTocList || !sidebarTocHeader) return;
        sidebarFileList.style.display = 'block';
        sidebarTocHeader.style.display = 'none';
        sidebarTocList.style.display = 'none';
        if (sidebarSearchInput) sidebarSearchInput.parentElement.style.display = 'block';
        if (sidebarCount) sidebarCount.style.display = 'inline';
        if (sidebarBackBtn) sidebarBackBtn.style.display = 'none';
        if (sidebarArticleTitle) sidebarArticleTitle.style.display = 'none';
    }
    function renderSidebarToc(headings) {
        if (!sidebarTocList) return;
        if (!headings.length) { sidebarTocList.innerHTML = '<div style="padding:16px;color:var(--text-muted);text-align:center;font-size:13px;">暂无标题</div>'; return; }
        renderTocItems(sidebarTocList, headings);
    }
    if (sidebarSearchInput) {
        // v4.1.0：侧边栏搜索同步升级为后端全文搜索（与顶部搜索一致，防抖 250ms）
        sidebarSearchInput.addEventListener('input', function() {
            const q = this.value.trim();
            if (!q) { renderSidebarList(allFiles); return; }
            clearTimeout(sidebarSearchInput._timer);
            sidebarSearchInput._timer = setTimeout(async function() {
                try {
                    const resp = await fetch('?action=search&q=' + encodeURIComponent(q));
                    const data = await resp.json();
                    if (data.success) renderSidebarList(data.files || []);
                } catch (err) { /* 失败保持原列表 */ }
            }, 250);
        });
    }
    if (sidebarBackBtn) {
        sidebarBackBtn.addEventListener('click', showHome);
    }
    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        if (!query) { searchResults.innerHTML = ''; return; }
        // v4.0.0：全文搜索升级——防抖后请求后端 ?action=search（标题/标签/摘要/正文匹配）
        clearTimeout(searchInput._timer);
        searchInput._timer = setTimeout(async function() {
            searchResults.innerHTML = '<div style="padding:14px;color:var(--text-muted);text-align:center;font-size:13px">搜索中…</div>';
            try {
                const resp = await fetch('?action=search&q=' + encodeURIComponent(query));
                const data = await resp.json();
                if (!data.success) { searchResults.innerHTML = '<div style="padding:14px;color:var(--text-muted);text-align:center;font-size:13px">搜索失败</div>'; return; }
                const files = data.files || [];
                if (!files.length) { searchResults.innerHTML = '<div style="padding:14px;color:var(--text-muted);text-align:center;font-size:13px">未找到匹配「' + escapeHTML(query) + '」的文章</div>'; return; }
                searchResults.innerHTML = files.map(file => `
                    <div class="dropdown-item" data-filename="${escapeHTML(file.name)}">
                        <div class="file-icon"><svg width="16" height="16" viewBox="0 0 24 24" stroke="currentColor" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
                        <div class="file-info"><div class="file-name">${escapeHTML(file.displayName)}</div><div style="font-size:11px;color:var(--text-muted);margin-top:2px">${escapeHTML((file.excerpt || '').slice(0, 60))}</div></div>
                    </div>`).join('');
                document.querySelectorAll('#searchResults .dropdown-item').forEach(item => {
                    item.addEventListener('click', () => {
                        loadFile(item.dataset.filename);
                        closePanel(searchPanel); searchInput.value = ''; searchResults.innerHTML = '';
                    });
                });
            } catch (err) {
                searchResults.innerHTML = '<div style="padding:14px;color:var(--text-muted);text-align:center;font-size:13px">搜索失败</div>';
            }
        }, 250);
    });
    function renderTocList() {
        tocFileList.innerHTML = allFiles.map(file => `
            <div class="dropdown-item" data-filename="${escapeHTML(file.name)}">
                <div class="file-icon"><svg width="16" height="16" viewBox="0 0 24 24" stroke="currentColor" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
                <div class="file-info"><div class="file-name">${escapeHTML(file.displayName)}</div></div>
            </div>`).join('');
        document.querySelectorAll('#tocFileList .dropdown-item').forEach(item => item.addEventListener('click', () => { loadFile(item.dataset.filename); closePanel(tocPanel); }));
    }
    function removeOrdinal(text) { return text.replace(/^[\d一二三四五六七八九十]+[\.\、\s]+/, ''); }
    function renderTocItems(container, headings) {
        container.innerHTML = headings.map((h, idx) => {
            const level = h.level;
            const leftIcon = level === 1
                ? `<div class="toc-block"><svg width="14" height="14" viewBox="0 0 24 24" stroke="currentColor" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>`
                : `<div class="toc-dot-circle"></div>`;
            return `<div class="toc-item" data-anchor="${h.el.id}" data-level="${level}" style="padding-left:${(level-1)*16}px">${leftIcon}<div class="toc-text">${escapeHTML(removeOrdinal(h.text))}</div></div>`;
        }).join('');
        container.querySelectorAll('.toc-item').forEach(item => item.addEventListener('click', function(e) {
            e.preventDefault();
            const anchor = this.dataset.anchor;
            if (anchor) { const target = document.getElementById(anchor); if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            closePanel(tocPanel); tocPopup.classList.remove('active');
        }));
    }
    function renderDocumentOutline() {
        if (!currentHeadings.length) { tocFileList.innerHTML = '<div style="padding:16px;color:var(--text-muted);text-align:center;">暂无标题</div>'; return; }
        renderTocItems(tocFileList, currentHeadings);
        updateActiveHeading(true);
    }
    function renderTocPopup() {
        if (!currentHeadings.length) { tocPopupList.innerHTML = '<div style="padding:16px;color:var(--text-muted);text-align:center;">暂无标题</div>'; return; }
        renderTocItems(tocPopupList, currentHeadings);
        updateActiveHeading(true);
    }
    function extractHeadings() {
        currentHeadings = [];
        markdownBody.querySelectorAll('h1, h2, h3, h4, h5, h6').forEach((el, i) => {
            if (!el.id) {
                el.id = 'heading-' + i;
            }
            const id = el.id;
            currentHeadings.push({ text: el.textContent, level: parseInt(el.tagName.charAt(1)), el });
            const anchor = document.createElement('a');
            anchor.className = 'heading-anchor';
            anchor.href = '#' + id;
            anchor.textContent = '#';
            anchor.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                history.replaceState(null, '', '#' + id);
            });
            el.appendChild(anchor);
        });
    }
    function updateActiveHeading(force = false) {
        if (!currentHeadings.length) return;
        let activeIdx = -1;
        const scrollPos = window.scrollY + 80;
        for (let i = currentHeadings.length - 1; i >= 0; i--) { if (currentHeadings[i].el.offsetTop <= scrollPos) { activeIdx = i; break; } }
        if (activeIdx !== activeHeadingIndex || force) {
            activeHeadingIndex = activeIdx;
            const applyActive = (items) => items.forEach((item, i) => item.classList.toggle('active', i === activeIdx));
            applyActive(tocFileList.querySelectorAll('.toc-item'));
            applyActive(tocPopupList.querySelectorAll('.toc-item'));
            applyActive(sidebarTocList.querySelectorAll('.toc-item'));
        }
    }
    function addCopyButtons() {
        document.querySelectorAll('.markdown-body pre').forEach(pre => {
            if (pre.querySelector('.copy-btn')) return;
            const code = pre.querySelector('code');
            if (!code) return;
            const html = code.innerHTML;
            const rawLines = html.replace(/^\n/, '').replace(/\n$/, '').split('\n');
            while (rawLines.length > 0 && rawLines[rawLines.length - 1].trim() === '') rawLines.pop();
            code.innerHTML = rawLines.map(line => `<span class="line">${line || '\u00a0'}</span>`).join('\n');
            const btn = document.createElement('div');
            btn.className = 'copy-btn';
            btn.innerHTML = '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const codeEl = pre.querySelector('code');
                const text = codeEl ? codeEl.textContent : pre.textContent;
                function showCopied() {
                    btn.classList.add('copied');
                    btn.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>';
                    showToast('已复制到剪贴板');
                    setTimeout(() => { btn.classList.remove('copied'); btn.innerHTML = '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>'; }, 2000);
                }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(showCopied).catch(function() {
                        var ta = document.createElement('textarea');
                        ta.value = text; ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0;';
                        document.body.appendChild(ta); ta.select();
                        try { document.execCommand('copy'); showCopied(); } catch(ex) {}
                        document.body.removeChild(ta);
                    });
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text; ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0;';
                    document.body.appendChild(ta); ta.select();
                    try { document.execCommand('copy'); showCopied(); } catch(ex) {}
                    document.body.removeChild(ta);
                }
            });
            pre.appendChild(btn);
            const lineCount = rawLines.length;
            if (lineCount > 15) {
                pre.classList.add('collapsible');
                const collapseBtn = document.createElement('div');
                collapseBtn.className = 'collapse-btn';
                collapseBtn.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>';
                collapseBtn.title = '折叠/展开代码块';
                collapseBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    pre.classList.toggle('collapsed');
                });
                pre.appendChild(collapseBtn);
            }
        });
    }
    function updatePrevNext() {
        if (!isReadingView || allFiles.length === 0 || currentFileIndex === -1) { prevNextNav.style.display = 'none'; return; }
        prevNextNav.style.display = 'flex';
        const prevIdx = currentFileIndex - 1;
        const nextIdx = currentFileIndex + 1;
        if (prevIdx >= 0) { prevBtn.classList.remove('disabled'); prevTitle.textContent = allFiles[prevIdx].displayName; }
        else { prevBtn.classList.add('disabled'); prevTitle.textContent = '没有了'; }
        if (nextIdx < allFiles.length) { nextBtn.classList.remove('disabled'); nextTitle.textContent = allFiles[nextIdx].displayName; }
        else { nextBtn.classList.add('disabled'); nextTitle.textContent = '没有了'; }
    }
    prevBtn.addEventListener('click', () => { if (currentFileIndex > 0) loadFile(allFiles[currentFileIndex - 1].name); });
    nextBtn.addEventListener('click', () => { if (currentFileIndex < allFiles.length - 1) loadFile(allFiles[currentFileIndex + 1].name); });
    function getUrlParam(name) { return new URL(window.location.href).searchParams.get(name); }
    function updateUrl(filename) {
        const url = new URL(window.location.href);
        if (filename) url.searchParams.set('file', filename);
        else url.searchParams.delete('file');
        window.history.pushState({}, '', url.toString());
    }
    function getShortUrl(filename) {
        const url = new URL(window.location.origin + window.location.pathname);
        url.searchParams.set('file', filename);
        return url.toString();
    }
    function showHome(pushState = true) {
        if (isReadingView) {
            sessionStorage.setItem('md-read-scroll-' + currentFileName, window.scrollY);
        } else {
            sessionStorage.setItem('md-list-scroll', window.scrollY);
        }
        homeView.style.display = 'block'; readingView.classList.remove('active');
        isReadingView = false; prevNextNav.style.display = 'none';
        floatingButtons.classList.remove('reading'); // v4.7.4：回首页仅保留 BGM 按钮（组常驻）
        tocPopup.classList.remove('active'); musicPopup.classList.remove('active'); shareModalOverlay.classList.remove('active'); shareQrcode.innerHTML = '';
        // v4.1.3：返回首页显式隐藏阅读进度条并清空文本——此前依赖 scrollTo 触发 scroll 事件，
        // 页面已在顶部时不触发，导致残留 "0%" 进度文本显示在主页右上角
        readingProgress.classList.remove('active');
        readingProgress.style.width = '0%';
        if (readingProgressText) {
            readingProgressText.classList.remove('active');
            readingProgressText.textContent = '';
        }
        // v4.0.0：返回首页时按当前视图模式（卡片/归档）恢复显示
        if (archiveMode) { cardsGrid.style.display = 'none'; if (archiveView) archiveView.style.display = 'block'; }
        else if (archiveView) archiveView.style.display = 'none';
        showSidebarFileList(); highlightSidebarItem('');
        document.title = (window.YM_SITE_TITLE || 'You Markdown');
        cmtOnArticleHide();
        const savedScroll = sessionStorage.getItem('md-list-scroll');
        if (savedScroll) { requestAnimationFrame(() => window.scrollTo(0, parseInt(savedScroll))); } else { window.scrollTo(0, 0); }
        if (pushState) updateUrl(null);
    }
    function showReading() {
        homeView.style.display = 'none'; readingView.classList.add('active');
        isReadingView = true;
        floatingButtons.classList.add('reading'); // v4.7.4：阅读视图显示 目录/返回主页/回到顶部（BGM 常驻组内）
        showSidebarToc();
        window.scrollTo(0, 0);
    }
    function buildDocHeader(file) {
        const wordCount = file.wordCount;
        const readTime = Math.max(1, Math.round(wordCount / 300));
        let html = '<div class="doc-header">';
        html += '<div class="doc-stats">';
        html += '<span class="stat-item"><span class="meta-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>' + wordCount + ' 字</span>';
        html += '<span class="stat-item"><span class="meta-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>' + readTime + ' 分钟</span>';
        html += '</div>';
        html += '<div class="doc-title">' + escapeHTML(file.displayName) + '</div>';
        html += '<div class="doc-info">';
        html += '<span class="info-item"><span class="meta-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span> ' + file.modified + '</span>';
        if (file.category) html += '<span class="info-item"><span class="meta-icon"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></span> ' + escapeHTML(file.category) + '</span>';
        if (file.tags && file.tags.length) {
            html += '<span class="tag-list"><span class="meta-icon"><svg viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></span><span class="tag-text">' + file.tags.slice(0,2).map(t => escapeHTML(t)).join('/') + '</span></span>';
        }
        html += '</div></div>';
        return html;
    }
    function buildBottomCards(file) {
        const shortUrl = getShortUrl(file.name);
        const licenseUrl = file.licenseUrl || '';
        const licenseText = file.license || 'CC BY-NC-SA 4.0';
        const licenseHtml = licenseUrl
            ? `<a href="${escapeHTML(licenseUrl)}" target="_blank" rel="noopener">${escapeHTML(licenseText)}</a>`
            : escapeHTML(licenseText);
        const authorHtml = file.author ? `<span class="info-card-author">${escapeHTML(file.author)}</span>` : '';
        return `
        <div class="article-bottom-cards">
            <div class="share-card">
                <div class="share-card-top">
                    <div class="share-card-icon">
                        <svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                    </div>
                    <div>
                        <div class="share-card-title">分享</div>
                        <div class="share-card-desc">如果这篇文章对你有帮助，欢迎分享给更多人！</div>
                    </div>
                </div>
                <button class="share-card-btn" id="shareCardBtnInline">分享</button>
            </div>
            <div class="article-info-card">
                <div class="info-card-title">${escapeHTML(file.displayName)}</div>
                <div class="info-card-url">${escapeHTML(shortUrl)}</div>
                <div class="info-card-meta">
                    ${authorHtml ? `<div class="info-card-meta-item">
                        <span class="info-card-meta-label">作者</span>
                        <span>${authorHtml}</span>
                    </div>` : ''}
                    <div class="info-card-meta-item">
                        <span class="info-card-meta-label">发布于</span>
                        <span>${file.modified}</span>
                    </div>
                    <div class="info-card-meta-item">
                        <span class="info-card-meta-label">许可证书</span>
                        <span class="info-card-license">${licenseHtml}</span>
                    </div>
                </div>
            </div>
        </div>`;
    }
    function showNotFound(filename) {
        showReading();
        markdownBody.innerHTML = '<div style="text-align:center;padding:60px 20px;">' +
            '<div style="font-size:4em;font-weight:800;color:var(--accent);line-height:1;margin-bottom:12px;">404</div>' +
            '<div style="font-size:1.2em;font-weight:600;margin-bottom:8px;">文档不存在</div>' +
            '<div style="color:var(--text-secondary);margin-bottom:24px;">' + (filename ? '文件 ' + escapeHTML(filename) + ' 未找到，可能已被删除。' : '你访问的页面不存在。') + '</div>' +
            '<a href="./" style="display:inline-flex;align-items:center;gap:8px;padding:10px 24px;border-radius:10px;background:var(--accent);color:#fff;text-decoration:none;font-weight:600;">返回首页</a>' +
            '</div>';
        document.title = '404 - 文档不存在 | ' + (window.YM_SITE_TITLE || 'You Markdown');
    }
    // v4.6.1：加载失败（网络错误/响应非 JSON）——与「文件不存在」区分，避免误报"文档不存在"
    function showLoadError(filename) {
        showReading();
        markdownBody.innerHTML = '<div style="text-align:center;padding:60px 20px;">' +
            '<div style="font-size:4em;font-weight:800;color:var(--accent);line-height:1;margin-bottom:12px;">⚠</div>' +
            '<div style="font-size:1.2em;font-weight:600;margin-bottom:8px;">加载失败</div>' +
            '<div style="color:var(--text-secondary);margin-bottom:24px;">' + (filename ? '文件 ' + escapeHTML(filename) + ' 暂时无法加载，请检查网络后重试。' : '网络异常，请稍后重试。') + '</div>' +
            '<a href="./" style="display:inline-flex;align-items:center;gap:8px;padding:10px 24px;border-radius:10px;background:var(--accent);color:#fff;text-decoration:none;font-weight:600;">返回首页</a>' +
            '</div>';
        document.title = '加载失败 | ' + (window.YM_SITE_TITLE || 'You Markdown');
    }
    // v4.6.1：内容已获取但渲染过程出错（marked/highlight 等）——独立提示并输出控制台错误，便于定位
    function showRenderError(filename) {
        showReading();
        markdownBody.innerHTML = '<div style="text-align:center;padding:60px 20px;">' +
            '<div style="font-size:4em;font-weight:800;color:var(--accent);line-height:1;margin-bottom:12px;">!</div>' +
            '<div style="font-size:1.2em;font-weight:600;margin-bottom:8px;">内容渲染失败</div>' +
            '<div style="color:var(--text-secondary);margin-bottom:24px;">文件内容已获取，但页面渲染时发生错误。请查看浏览器控制台或联系管理员。</div>' +
            '<a href="./" style="display:inline-flex;align-items:center;gap:8px;padding:10px 24px;border-radius:10px;background:var(--accent);color:#fff;text-decoration:none;font-weight:600;">返回首页</a>' +
            '</div>';
        document.title = '渲染失败 | ' + (window.YM_SITE_TITLE || 'You Markdown');
    }
    async function loadFile(filename, pushState = true) {
        showReading();
        markdownBody.innerHTML = '<p style="text-align:center;color:var(--text-muted);padding:40px;">⏳ 加载中...</p>';
        currentFileIndex = allFiles.findIndex(f => f.name === filename);
        updatePrevNext();
        if (pushState) updateUrl(filename);
        let data = null;
        // v4.6.1：网络/解析阶段独立 try——失败提示「加载失败」，不再误报「文档不存在」
        try {
            const resp = await fetch(`?action=read&file=${encodeURIComponent(filename)}`);
            const text = await resp.text();
            try { data = JSON.parse(text); } catch (e) { data = null; }
        } catch (err) {
            console.error('[loadFile network error]', err);
            showLoadError(filename);
            cmtOnArticleHide();
            return;
        }
        if (!data || !data.success) {
            showNotFound(filename);
            cmtOnArticleHide();
            return;
        }
        // v4.6.1：渲染阶段独立 try——渲染错误提示「内容渲染失败」并输出控制台，便于定位
        try {
            // v3.3.15：公告关联文章（如「更新历史」META hidden）不在 allFiles 列表，
                //          findIndex 为 -1 时旧逻辑回退 wordCount:0 → 文章详情显示"0 字"。
                //          改用 read 接口返回的字数（服务端统一算法）修正。
                const listMeta = allFiles[currentFileIndex];
                const fileMeta = Object.assign(
                    { displayName: filename.replace(/\.md$/i,''), wordCount: 0, modified: '', tags: [], category: '', author: '', license: 'CC BY-NC-SA 4.0', licenseUrl: '', pinned: false },
                    listMeta || {},
                    { displayName: data.displayName || (listMeta && listMeta.displayName) || filename.replace(/\.md$/i,''),
                      wordCount: (data.wordCount > 0) ? data.wordCount : ((listMeta && listMeta.wordCount) || 0),
                      modified: data.modified || (listMeta && listMeta.modified) || '' }
                );
                document.title = fileMeta.displayName + ' - ' + (window.YM_SITE_TITLE || 'You Markdown');
                currentFileName = filename;
                let mdContent = data.content;
                // v3.3.0：提取 !video[标题](站内相对路径) 语法为占位符，marked 渲染后转 <video> 播放器（防跳转）
                var videoSlots = [];
                mdContent = mdContent.replace(/!video\[([^\]]*)\]\(([^)\s]+)\)/g, function(m, t, s) {
                    videoSlots.push({ title: t, src: s.trim() });
                    return '@@YM_VIDEO_' + (videoSlots.length - 1) + '@@';
                });
                mdContent = mdContent.replace(/^(<!--.*?-->)?\s*#\s+.*\r?\n?/, '');
                const parsedHtml = typeof marked !== 'undefined' ? marked.parse(mdContent) : '<pre>' + escapeHTML(mdContent) + '</pre>';
                markdownBody.innerHTML = buildDocHeader(fileMeta) + parsedHtml + buildBottomCards(fileMeta);
                markdownBody.querySelectorAll('table').forEach(table => {
                    if (!table.parentElement.classList.contains('table-wrapper')) {
                        const wrapper = document.createElement('div'); wrapper.className = 'table-wrapper';
                        table.parentNode.insertBefore(wrapper, table); wrapper.appendChild(table);
                    }
                });
                // 外链安全：禁止外链在当前页直接跳转离开本站；统一新窗口 + noopener noreferrer（防 tabnabbing）
                markdownBody.querySelectorAll('a[href]').forEach(function(a) {
                    var href = a.getAttribute('href') || '';
                    if (/^(https?:)?\/\//i.test(href) && href.indexOf(window.location.host) === -1) {
                        a.setAttribute('target', '_blank');
                        a.setAttribute('rel', 'noopener noreferrer');
                    }
                });
                // v3.2.5：mermaid 流程图渲染（```mermaid 代码块 → 实际图表；优先于 hljs 高亮处理）；v4.2.2 起按需加载
                // v4.7.14：mermaid 渲染完成后执行 hljs 和 addCopyButtons，避免异步加载时序问题
                renderMermaidBlocks(markdownBody, function() {
                    if (typeof hljs !== 'undefined') {
                        markdownBody.querySelectorAll('pre code').forEach(block => hljs.highlightElement(block));
                    }
                    addCopyButtons();
                });
                // v3.3.0：视频语法 → 播放器（仅站内 data/videos/ 相对路径；外链按纯文本保留）
                renderYmVideos(markdownBody, videoSlots);
                // v3.3.2：正文图片懒加载（首屏外的大图不预先下载，滚动到才加载）
                markdownBody.querySelectorAll('img').forEach(function(im) {
                    if (!im.getAttribute('loading')) im.setAttribute('loading', 'lazy');
                    im.setAttribute('decoding', 'async');
                });
                extractHeadings();
                markdownBody.querySelectorAll('p').forEach(p => {
                    const links = p.querySelectorAll('a');
                    if (links.length === 1 && p.textContent.trim() === links[0].textContent.trim() && links[0].getAttribute('href') && links[0].getAttribute('href').startsWith('#')) {
                        p.classList.add('toc-link');
                    }
                });
                markdownBody.querySelectorAll('a[href^="#"]').forEach(anchor => {
                    anchor.addEventListener('click', function(e) {
                        e.preventDefault();
                        const href = this.getAttribute('href');
                        const id = decodeURIComponent(href.substring(1));
                        let target = document.getElementById(id);
                        // v4.4.1：精确 id 未命中时按 ymSlug 归一化模糊匹配标题——
                        //         兼容手写锚点（#一项目概述）与 marked slug 生成 id 的标点差异
                        if (!target) {
                            const want = ymSlug(id);
                            if (want) {
                                markdownBody.querySelectorAll('h1, h2, h3, h4, h5, h6').forEach(h => {
                                    if (!target && h.id && ymSlug(h.id) === want) target = h;
                                });
                            }
                        }
                        if (target) {
                            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            history.replaceState(null, '', '#' + id);
                        }
                    });
                });
                renderTocPopup();
                renderSidebarToc(currentHeadings);
                bindImageLightbox();
                highlightSidebarItem(filename);
                if (sidebarArticleTitle) sidebarArticleTitle.textContent = fileMeta.displayName;
                if (tocPanel.classList.contains('active') && isReadingView) { renderDocumentOutline(); tocPanelHeader.style.display = 'block'; }
                updateActiveHeading(true);
                updatePrevNext();
                const inlineShareBtn = document.getElementById('shareCardBtnInline');
                if (inlineShareBtn) {
                    inlineShareBtn.addEventListener('click', () => {
                        shareModalOverlay.classList.add('active');
                        shareQrcode.innerHTML = '';
                        new QRCode(shareQrcode, { text: window.location.href, width: 180, height: 180 });
                    });
                }
                updatePrevNext();
                if (!localStorage.getItem('md-keyboard-hint-shown')) {
                    setTimeout(() => showToast('← → 切换文章 | ESC 返回首页 | T 回到顶部', 3000), 500);
                    localStorage.setItem('md-keyboard-hint-shown', '1');
                }
                const savedReadScroll = sessionStorage.getItem('md-read-scroll-' + filename);
                if (savedReadScroll) { requestAnimationFrame(() => window.scrollTo(0, parseInt(savedReadScroll))); }
                // v3.3.11：公告为单向通知——公告关联文章（如「更新历史」）不显示评论区
                var _annFileMap = {};
                (window.YM_ANNOUNCEMENTS || []).forEach(function(_a) { if (_a.article) _annFileMap[_a.article] = 1; });
                if (_annFileMap[filename]) { cmtOnArticleHide(); } else { cmtOnArticleLoad(); }
        } catch (err) {
            console.error('[loadFile render error]', err);
            showRenderError(filename);
            cmtOnArticleHide();
        }
    }
    window.addEventListener('popstate', () => {
        const fileParam = getUrlParam('file');
        if (fileParam && allFiles.some(f => f.name === fileParam)) {
            loadFile(fileParam, false);
        } else {
            showHome(false);
        }
    });
    if (typeof marked !== 'undefined') {
        const renderer = new marked.Renderer();
        renderer.html = function(token) {
            const raw = (token && typeof token === 'object' && token.text !== undefined) ? token.text : token;
            return escapeHTML(String(raw));
        };
        renderer.list = (body) => body;
        renderer.listitem = (text) => `<p>${text}</p>`;
        renderer.hr = () => '';
        // v4.4.1：标题 id 生成改用 ymSlug——去除中文顿号/括号/全角标点等，与文章正文手写锚点
        //         （如 [目录](#一项目概述)）保持一致；否则 marked 默认 slugger 保留「、」导致
        //         id=“一、项目概述”≠链接“#一项目概述”，点击目录无法跳转。
        //         slugger 参数仅用于同级重复标题去重（同 slug 追加 -2/-3…）。
        renderer.heading = function(text, level, raw, slugger) {
            const base = ymSlug(raw);
            const id = slugger ? slugger.slug(base) : base;
            return `<h${level} id="${id}">${text}</h${level}>`;
        };
        marked.setOptions({ gfm: true, breaks: false, smartLists: true, renderer });
    }
    async function init() {
        await loadFileList();
        const fileParam = getUrlParam('file');
        if (fileParam) loadFile(fileParam, false);
        else {
            showHome(false);
            // v4.7.3：首页场景也检查登录态——设备验证进行中（切后台看邮箱后页面被移动端重载）时恢复验证弹窗
            // v4.7.4：?admin_login=1 首页场景同样自动弹出登录弹窗
            cmtCheckAuth().then(cmtHandleAdminLoginHint);
        }
    }
    let toastTimer = null;
    function showToast(msg, duration = 2000) {
        toast.textContent = msg;
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), duration);
    }
    let lightboxImages = [];
    let lightboxIndex = 0;
    function openLightbox(src, alt, index) {
        lightboxImages = Array.from(markdownBody.querySelectorAll('img')).map(i => ({src: i.src, alt: i.alt}));
        lightboxIndex = typeof index === 'number' ? index : lightboxImages.findIndex(i => i.src === src);
        if (lightboxIndex < 0) lightboxIndex = 0;
        lightboxImg.src = src;
        lightboxImg.alt = alt || '';
        imgLightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
        imgLightbox.querySelectorAll('.img-lightbox-close,.img-lightbox-prev,.img-lightbox-next').forEach(b => b.remove());
        const closeBtn = document.createElement('button');
        closeBtn.className = 'img-lightbox-close';
        closeBtn.innerHTML = '<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        closeBtn.addEventListener('click', closeLightbox);
        imgLightbox.appendChild(closeBtn);
        if (lightboxImages.length > 1) {
            const prevBtn = document.createElement('button');
            prevBtn.className = 'img-lightbox-prev';
            prevBtn.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>';
            prevBtn.addEventListener('click', (e) => { e.stopPropagation(); navigateLightbox(-1); });
            imgLightbox.appendChild(prevBtn);
            const nextBtn = document.createElement('button');
            nextBtn.className = 'img-lightbox-next';
            nextBtn.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>';
            nextBtn.addEventListener('click', (e) => { e.stopPropagation(); navigateLightbox(1); });
            imgLightbox.appendChild(nextBtn);
        }
    }
    function navigateLightbox(dir) {
        lightboxIndex = (lightboxIndex + dir + lightboxImages.length) % lightboxImages.length;
        lightboxImg.src = lightboxImages[lightboxIndex].src;
        lightboxImg.alt = lightboxImages[lightboxIndex].alt || '';
    }
    function closeLightbox() {
        imgLightbox.classList.remove('active');
        document.body.style.overflow = '';
    }
    imgLightbox.addEventListener('click', (e) => { if (e.target === imgLightbox) closeLightbox(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && imgLightbox.classList.contains('active')) closeLightbox(); });
    function bindImageLightbox() {
        markdownBody.querySelectorAll('img').forEach((img, idx) => {
            img.style.cursor = 'zoom-in';
            img.addEventListener('click', () => openLightbox(img.src, img.alt, idx));
        });
    }
    document.addEventListener('keydown', (e) => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;
        if (e.key === 'Escape' && imgLightbox.classList.contains('active')) { closeLightbox(); return; }
        if (e.key === 'ArrowLeft' && imgLightbox.classList.contains('active')) { navigateLightbox(-1); return; }
        if (e.key === 'ArrowRight' && imgLightbox.classList.contains('active')) { navigateLightbox(1); return; }
        if (isReadingView) {
            if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (currentFileIndex > 0) loadFile(allFiles[currentFileIndex - 1].name);
            } else if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                e.preventDefault();
                if (currentFileIndex < allFiles.length - 1) loadFile(allFiles[currentFileIndex + 1].name);
            } else if (e.key === 't' || e.key === 'T') {
                e.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else if (e.key === 'Escape') {
                e.preventDefault();
                showHome();
            }
        } else {
            if (e.key === 'Escape') {
                closeAllPanels();
            }
        }
    });
    window.toggleKbdHelp = function toggleKbdHelp() {
        let overlay = document.querySelector('.kbd-help-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'kbd-help-overlay';
            overlay.innerHTML = `
        <div class="kbd-help-box">
            <div class="kbd-help-title">
                <svg viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><line x1="6" y1="10" x2="6" y2="10"/><line x1="10" y1="10" x2="10" y2="10"/><line x1="14" y1="10" x2="14" y2="10"/><line x1="18" y1="10" x2="18" y2="10"/><line x1="8" y1="14" x2="16" y2="14"/></svg>
                快捷键
            </div>
            <div class="kbd-help-section">
                <h4>导航</h4>
                <div class="kbd-row"><span class="kbd-label">搜索文档（全文）</span><span class="kbd-keys"><kbd>/</kbd></span></div>
                <div class="kbd-row"><span class="kbd-label">归档视图切换</span><span class="kbd-keys"><kbd>A</kbd></span></div>
                <div class="kbd-row"><span class="kbd-label">打开 RSS 订阅</span><span class="kbd-keys"><kbd>R</kbd></span></div>
                <div class="kbd-row"><span class="kbd-label">上一篇文章</span><span class="kbd-keys"><kbd>←</kbd></span></div>
                <div class="kbd-row"><span class="kbd-label">下一篇文章</span><span class="kbd-keys"><kbd>→</kbd></span></div>
                <div class="kbd-row"><span class="kbd-label">返回首页</span><span class="kbd-keys"><kbd>Esc</kbd></span></div>
                <div class="kbd-row"><span class="kbd-label">回到顶部</span><span class="kbd-keys"><kbd>T</kbd></span></div>
            </div>
            <div class="kbd-help-section">
                <h4>阅读</h4>
                <div class="kbd-row"><span class="kbd-label">打印文章</span><span class="kbd-keys"><kbd>P</kbd></span></div>
                <div class="kbd-row"><span class="kbd-label">折叠/展开侧边栏</span><span class="kbd-keys"><kbd>S</kbd></span></div>
                <div class="kbd-row"><span class="kbd-label">关闭弹窗</span><span class="kbd-keys"><kbd>Esc</kbd></span></div>
            </div>
            <div class="kbd-help-section">
                <h4>灯箱</h4>
                <div class="kbd-row"><span class="kbd-label">上一张图片</span><span class="kbd-keys"><kbd>←</kbd></span></div>
                <div class="kbd-row"><span class="kbd-label">下一张图片</span><span class="kbd-keys"><kbd>→</kbd></span></div>
                <div class="kbd-row"><span class="kbd-label">关闭</span><span class="kbd-keys"><kbd>Esc</kbd></span></div>
            </div>
        </div>`;
            overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.classList.remove('show'); });
            document.body.appendChild(overlay);
        }
        overlay.classList.toggle('show');
    }
    function toggleSidebar() {
        const sb = document.getElementById('sidebar');
        if (!sb) return;
        const isCollapsed = sb.style.display === 'none';
        sb.style.display = isCollapsed ? 'flex' : 'none';
        document.body.style.paddingLeft = isCollapsed ? '280px' : '0';
        // v2.6.5：折叠按钮在 sidebar 内，收起后自身也消失；由外部 restore 按钮提供恢复入口
        const restoreBtn = document.getElementById('sidebarRestoreBtn');
        if (restoreBtn) restoreBtn.style.display = isCollapsed ? 'none' : 'flex';
        localStorage.setItem('md-sidebar-hidden', isCollapsed ? '0' : '1');
    }
    window.toggleSidebar = toggleSidebar;
    if (localStorage.getItem('md-sidebar-hidden') === '1') {
        const sb = document.getElementById('sidebar');
        if (sb) { sb.style.display = 'none'; document.body.style.paddingLeft = '0'; }
        const restoreBtn = document.getElementById('sidebarRestoreBtn');
        if (restoreBtn) restoreBtn.style.display = 'flex';
    }
    (function() {
        const isTyping = () => {
            const el = document.activeElement;
            return el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.isContentEditable);
        };
        document.addEventListener('keydown', (e) => {
            if (isTyping() || imgLightbox.classList.contains('active')) return;
            if ((e.key === '/' || (e.ctrlKey && e.key === 'k')) && !isReadingView) {
                e.preventDefault();
                sidebarSearchInput ? sidebarSearchInput.focus() : searchInput.focus();
                return;
            }
            if (e.key === '?' && !e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                toggleKbdHelp();
                return;
            }
            if (e.key === 's' && !e.ctrlKey && !e.metaKey && !isReadingView) {
                e.preventDefault();
                toggleSidebar();
                return;
            }
            // v4.1.0：新增功能快捷键——A 归档视图切换、R 打开 RSS 订阅
            if (e.key === 'a' && !e.ctrlKey && !e.metaKey && !isReadingView) {
                e.preventDefault();
                archiveMode = !archiveMode;
                renderCategoryBar();
                renderHomeContent();
                return;
            }
            if (e.key === 'r' && !e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                window.open('/index.php?action=rss_guide', '_blank', 'noopener');
                return;
            }
            if (e.key === 'p' && !e.ctrlKey && !e.metaKey && isReadingView) {
                e.preventDefault();
                window.print();
                return;
            }
        });
    })();
    const cmtSection = document.getElementById('commentSection');
    const cmtArea = document.getElementById('commentArea');
    const cmtListSection = document.getElementById('commentListSection');
    const cmtCapsuleBar = document.getElementById('cmtCapsuleBar');
    const cmtCapsuleBtn = document.getElementById('cmtCapsuleBtn');
    const cmtUserBar = document.getElementById('cmtUserBar');
    const cmtUserInner = document.getElementById('cmtUserInner');
    const cmtUserAvatar = document.getElementById('cmtUserAvatar');
    const cmtUserGreeting = document.getElementById('cmtUserGreeting');
    const cmtLogoutBtn = document.getElementById('cmtLogoutBtn');
    const cmtTextarea = document.getElementById('cmtTextarea');
    const cmtSendBtn = document.getElementById('cmtSendBtn');
    const cmtList = document.getElementById('cmtList');
    const cmtAuthModal = document.getElementById('cmtAuthModal');
    const cmtAuthTitle = document.getElementById('cmtAuthTitle');
    const cmtAuthSlide = document.getElementById('cmtAuthSlide');
    const cmtLoginForm = document.getElementById('cmtLoginForm');
    const cmtRegForm = document.getElementById('cmtRegForm');
    const cmtLoginQQ = document.getElementById('cmtLoginQQ');
    const cmtLoginPw = document.getElementById('cmtLoginPw');
    const cmtLoginErr = document.getElementById('cmtLoginErr');
    const cmtLoginBtn = document.getElementById('cmtLoginBtn');
    const cmtRegQQ = document.getElementById('cmtRegQQ');
    const cmtRegNick = document.getElementById('cmtRegNick');
    const cmtRegPw = document.getElementById('cmtRegPw');
    const cmtRegErr = document.getElementById('cmtRegErr');
    const cmtRegBtn = document.getElementById('cmtRegBtn');
    // v3.3.16：密码显示切换（登录/注册）——线稿眼睛两态图标 + 输入框 type 切换
    // 此前按钮仅有 emoji 👁 且无绑定事件（死按钮），本次补齐逻辑并统一为 SVG 线稿
    (function bindPwToggles() {
        const EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>';
        const EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/><line x1="3" y1="3" x2="21" y2="21"/></svg>';
        ['cmtLoginPwToggle', 'cmtRegPwToggle', 'cmtResetPwToggle'].forEach(id => {
            const btn = document.getElementById(id);
            const input = btn ? btn.closest('.cmt-pw-row').querySelector('input') : null;
            if (!btn || !input) return;
            btn.innerHTML = EYE;
            btn.addEventListener('click', () => {
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.innerHTML = show ? EYE_OFF : EYE;
                btn.setAttribute('aria-pressed', show ? 'true' : 'false');
                btn.title = show ? '隐藏密码' : '显示/隐藏密码';
            });
        });
    })();
    // v2.9.0 注册验证控件
    const cmtRegVerifyBox = document.getElementById('cmtRegVerifyBox');
    const cmtRegEmail = document.getElementById('cmtRegEmail');
    const cmtRegSendCode = document.getElementById('cmtRegSendCode');
    const cmtRegCode = document.getElementById('cmtRegCode');
    const regVerifyOn = (document.body.getAttribute('data-reg-verify') === '1');
    // v4.4.0：注册算术人机验证弹窗 + 注册蜜罐字段
    const cmtArithModal = document.getElementById('cmtArithModal');
    const cmtArithQuestion = document.getElementById('cmtArithQuestion');
    const cmtArithAnswer = document.getElementById('cmtArithAnswer');
    const cmtArithErr = document.getElementById('cmtArithErr');
    const cmtArithOk = document.getElementById('cmtArithOk');
    const cmtArithCancel = document.getElementById('cmtArithCancel');
    const cmtRegHoneypot = document.getElementById('cmtRegHoneypot');
    // v2.11.0：滑块人机验证已彻底移除（cmtRegCaptchaBox / regCaptchaOn 删除）
    // v2.10.0：邮箱验证开关（控制前台个人设置里的邮箱绑定/更换入口）与 CSRF token
    const emailChangeOn = (document.body.getAttribute('data-email-change') === '1');
    const csrfToken = document.body.getAttribute('data-csrf') || '';
    const cmtSwitchText = document.getElementById('cmtSwitchText');
    const cmtSwitchBtn = document.getElementById('cmtSwitchBtn');
    // v4.7.0：陌生设备登录邮件二次验证 + 找回密码
    const cmtDevForm = document.getElementById('cmtDevForm');
    const cmtDevTip = document.getElementById('cmtDevTip');
    const cmtDevCode = document.getElementById('cmtDevCode');
    const cmtDevErr = document.getElementById('cmtDevErr');
    const cmtDevBtn = document.getElementById('cmtDevBtn');
    const cmtDevBack = document.getElementById('cmtDevBack');
    const cmtResetForm = document.getElementById('cmtResetForm');
    const cmtResetQQ = document.getElementById('cmtResetQQ');
    const cmtResetEmail = document.getElementById('cmtResetEmail');
    const cmtResetSendCode = document.getElementById('cmtResetSendCode');
    const cmtResetCode = document.getElementById('cmtResetCode');
    const cmtResetPw = document.getElementById('cmtResetPw');
    const cmtResetErr = document.getElementById('cmtResetErr');
    const cmtResetBtn = document.getElementById('cmtResetBtn');
    const cmtResetBack = document.getElementById('cmtResetBack');
    const cmtForgotLink = document.getElementById('cmtForgotLink');
    const cmtProfileModal = document.getElementById('cmtProfileModal');
    const cmtEditNick = document.getElementById('cmtEditNick');
    const cmtEditSign = document.getElementById('cmtEditSign');
    const cmtProfileErr = document.getElementById('cmtProfileErr');
    const cmtProfileSave = document.getElementById('cmtProfileSave');
    const cmtAdminModal = document.getElementById('cmtAdminModal');
    const cmtAdminQQ = document.getElementById('cmtAdminQQ');
    const cmtAdminNick = document.getElementById('cmtAdminNick');
    const cmtAdminPw = document.getElementById('cmtAdminPw');
    const cmtAdminPw2 = document.getElementById('cmtAdminPw2');
    const cmtAdminErr = document.getElementById('cmtAdminErr');
    const cmtAdminSave = document.getElementById('cmtAdminSave');
    const cmtConfirmOverlay = document.getElementById('cmtConfirmOverlay');
    const cmtConfirmOk = document.getElementById('cmtConfirmOk');
    const cmtConfirmCancel = document.getElementById('cmtConfirmCancel');
    const guestCommentsEnabled = document.body.dataset.guestComments === '1';
    let cmtUser = null;
    let cmtAuthMode = 'login';
    let cmtLongPressTimer = null;
    let cmtAdminFirstLogin = false;
    let cmtConfirmCb = null;
    let cmtExpandedItems = new Set();
    function cmtEscape(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    function cmtGreeting() {
        const h = new Date().getHours();
        if (h < 12) return '上午好'; if (h < 14) return '中午好'; return '下午好';
    }
    function cmtFormatTime(t) {
        if (!t) return '';
        const d = new Date(t.replace(/-/g, '/'));
        const now = Date.now();
        const diff = (now - d.getTime()) / 1000;
        if (diff < 60) return '刚刚';
        if (diff < 3600) return Math.floor(diff / 60) + '分钟前';
        if (diff < 86400) return Math.floor(diff / 3600) + '小时前';
        const Y = d.getFullYear(), M = d.getMonth() + 1, D = d.getDate();
        const hh = d.getHours().toString().padStart(2, '0');
        const mm = d.getMinutes().toString().padStart(2, '0');
        return Y + '-' + M + '-' + D + ' ' + hh + ':' + mm;
    }
    function cmtAvatarHtml(url, name, qq) {
        const initial = cmtEscape((name||'?').charAt(0));
        // v2.10.0：优先使用自定义头像（data/avatars/...），未上传时回退 QQ 头像
        const src = url ? url : (qq ? 'api.php?action=avatar&qq=' + encodeURIComponent(qq) : '');
        if (src) return '<img src="' + cmtEscape(src) + '" alt="" class="cmt-avatar-img" onerror="this.style.display=\'none\';this.parentNode.querySelector(\'.cmt-avatar-text\').style.display=\'flex\'"/><span class="cmt-avatar-text" style="display:none">' + initial + '</span>';
        return '<span class="cmt-avatar-text">' + initial + '</span>';
    }
    function cmtOpenModal(id) { id.classList.add('show'); }
    function cmtCloseModal(id) { id.classList.remove('show'); }
    [cmtAuthModal, cmtProfileModal, cmtAdminModal].forEach(m => {
        if (m) m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); });
    });
    if (cmtCapsuleBtn) cmtCapsuleBtn.addEventListener('click', () => {
        cmtAuthMode = 'login'; cmtUpdateAuthUI();
        if (cmtAuthSlide) { cmtAuthSlide.classList.remove('slide-out'); cmtAuthSlide.classList.remove('slide-in'); }
        cmtOpenModal(cmtAuthModal);
    });
    // v2.11.3：打开编辑资料弹窗（主页用户下拉「编辑资料」与评论区用户栏共用）
    function cmtOpenProfileModal() {
        // v3.0.1：主页停留时 cmtUser 可能为 null（cmtCheckAuth 仅在文章加载时执行），而顶部下拉
        // 「编辑资料」按 user-status 显示已登录——点击时若状态缺失则实时恢复，避免静默无反应
        const doOpen = () => {
            cmtEditNick.value = cmtUser.nickname || '';
            cmtEditSign.value = cmtUser.signature || '';
            // v2.10.0：打开编辑资料弹窗时填充头像预览 + 邮箱区
            const avBox = document.getElementById('cmtProfileAvatar');
            if (avBox) {
                const avUrl = cmtUser.avatar || '';
                avBox.innerHTML = avUrl ? '<img src="' + cmtEscape(avUrl) + '" alt="" onerror="this.style.display=\'none\'">' : '';
            }
            const emailWrap = document.getElementById('cmtProfileEmailWrap');
            if (emailWrap) emailWrap.style.display = emailChangeOn ? 'block' : 'none';
            const editEmail = document.getElementById('cmtEditEmail');
            if (editEmail) {
                editEmail.value = '';
                editEmail.placeholder = cmtUser.email ? ('当前绑定：' + cmtUser.email + '，输入新邮箱更换') : '输入邮箱进行绑定';
            }
            const emailCode = document.getElementById('cmtEditEmailCode');
            if (emailCode) emailCode.value = '';
            const emailErr = document.getElementById('cmtEmailErr');
            if (emailErr) emailErr.textContent = '';
            const sendBtn = document.getElementById('cmtEmailSendCode');
            if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = '获取验证码'; }
            cmtOpenModal(cmtProfileModal);
        };
        if (cmtUser) { doOpen(); return; }
        fetch('api.php?action=check').then(r => r.json()).then(d => {
            if (d.success && d.loggedIn) {
                cmtUser = d.user;
                if (d.isAdminFirstLogin) cmtAdminFirstLogin = true;
                cmtUpdateUI();
                doOpen();
            } else {
                cmtUser = null;
                cmtUpdateUI();
                if (cmtAuthModal) { cmtAuthMode = 'login'; cmtUpdateAuthUI(); cmtOpenModal(cmtAuthModal); }
            }
        }).catch(() => {});
    }
    if (cmtUserInner) cmtUserInner.addEventListener('click', () => { cmtOpenProfileModal(); });
    if (cmtLogoutBtn) cmtLogoutBtn.addEventListener('click', e => {
        e.stopPropagation();
        fetch('api.php?action=logout', { method: 'POST' }).catch(() => {});
        cmtUser = null;
        cmtUpdateUI();
    });
    // v2.11.4：切换防抖——动画期间忽略重复点击（此前快速双击会排队两个 setTimeout，
    // 状态被来回切两次，表现为「要点两下才切过去」）
    let cmtSwitchBusy = false;
    if (cmtSwitchBtn) cmtSwitchBtn.addEventListener('click', () => {
        if (cmtSwitchBusy) return;
        cmtSwitchBusy = true;
        cmtAuthSlide.classList.remove('slide-in');
        cmtAuthSlide.classList.add('slide-out');
        setTimeout(() => {
            cmtAuthMode = cmtAuthMode === 'login' ? 'register' : 'login';
            cmtUpdateAuthUI();
            cmtAuthSlide.classList.remove('slide-out');
            cmtAuthSlide.classList.add('slide-in');
            setTimeout(() => {
                cmtAuthSlide.classList.remove('slide-in');
                cmtSwitchBusy = false;
            }, 300);
        }, 200);
    });
    function cmtUpdateAuthUI() {
        const isLogin = cmtAuthMode === 'login';
        if (cmtAuthTitle) cmtAuthTitle.textContent = isLogin ? '登录' : '注册';
        if (cmtLoginForm) cmtLoginForm.style.display = isLogin ? 'flex' : 'none';
        if (cmtRegForm) cmtRegForm.style.display = isLogin ? 'none' : 'flex';
        if (cmtSwitchText) cmtSwitchText.textContent = isLogin ? '还没有账号？' : '已有账号？';
        if (cmtSwitchBtn) cmtSwitchBtn.textContent = isLogin ? '立即注册' : '去登录';
        if (cmtLoginErr) cmtLoginErr.textContent = '';
        if (cmtRegErr) cmtRegErr.textContent = '';
        // v4.7.0：登录/注册切换时隐藏设备验证与找回视图
        if (cmtDevForm) cmtDevForm.style.display = 'none';
        if (cmtResetForm) cmtResetForm.style.display = 'none';
    }
    if (cmtLoginBtn) cmtLoginBtn.addEventListener('click', async () => {
        const qq = cmtLoginQQ.value.trim(), pw = cmtLoginPw.value;
        cmtLoginErr.textContent = '';
        if (!qq || !pw) { cmtLoginErr.textContent = '请填写QQ号和密码'; return; }
        // v2.11.1：提交前动态刷新 token（与当前 session 绑定，杜绝「会话失败」）
        await ensureFreshCsrf();
        cmtLoginBtn.disabled = true; cmtLoginBtn.textContent = '登录中...';
        fetch('api.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ qq, password: pw }) })
            .then(r => r.json()).then(d => {
                // v4.7.0：管理角色陌生设备 → 切到设备验证视图（邮件验证码确认后完成登录）
                if (d.need_device_verify) {
                    cmtShowDevVerify(d.masked_email || '');
                    return;
                }
                if (d.success) { cmtUser = d.user; if (d.isAdminFirstLogin) cmtAdminFirstLogin = true; cmtCloseModal(cmtAuthModal); cmtUpdateUI(); cmtLoad(); cmtMaybeGoAdmin(); }
                else {
                    // v2.10.2：CSRF 校验失败多为会话 cookie 未生效（浏览器 cookie 策略/瞬态），自动刷新页面一次重取 token+cookie
                    if (d.error === 'CSRF 校验失败' && !window.__csrfRetried) {
                        window.__csrfRetried = true;
                        cmtLoginErr.textContent = '会话校验失败，正在刷新页面，请重新登录...';
                        setTimeout(function() { location.reload(); }, 600);
                        return;
                    }
                    // v2.11.0：锁定（429）时显示剩余秒数倒计时
                    if (d.locked_seconds > 0) {
                        let left = d.locked_seconds;
                        const fmt = () => '登录失败次数过多，请 ' + Math.floor(left / 60) + ' 分 ' + (left % 60) + ' 秒后重试';
                        cmtLoginErr.textContent = fmt();
                        const t = setInterval(() => {
                            left--;
                            if (left <= 0) { clearInterval(t); cmtLoginErr.textContent = ''; }
                            else cmtLoginErr.textContent = fmt();
                        }, 1000);
                    } else {
                        cmtLoginErr.textContent = d.error || '登录失败';
                    }
                }
            }).catch(() => { cmtLoginErr.textContent = '网络错误'; })
            .finally(() => { cmtLoginBtn.disabled = false; cmtLoginBtn.textContent = '登录'; });
    });
    // ---- v4.7.0：陌生设备登录邮件二次验证 + 找回密码 ----
    // v4.7.3：切换到设备验证视图（登录返回 need_device_verify 或刷新后 check 报告 pending 时共用，
    //         解决「切后台看邮箱返回后弹窗丢失」——页面被移动端浏览器重载时通过 check 恢复弹窗）
    function cmtShowDevVerify(maskedEmail) {
        if (cmtDevTip) cmtDevTip.textContent = maskedEmail ? ('验证码已发送至 ' + maskedEmail + '，请查收邮箱') : '验证码已发送，请查收邮箱';
        if (cmtDevErr) cmtDevErr.textContent = '';
        if (cmtDevCode) cmtDevCode.value = '';
        if (cmtLoginForm) cmtLoginForm.style.display = 'none';
        if (cmtRegForm) cmtRegForm.style.display = 'none';
        if (cmtResetForm) cmtResetForm.style.display = 'none';
        if (cmtDevForm) cmtDevForm.style.display = 'flex';
        if (cmtAuthTitle) cmtAuthTitle.textContent = '设备验证';
        setTimeout(() => { if (cmtDevCode) cmtDevCode.focus(); }, 60);
    }
    function cmtBackToLogin() {
        cmtLoginForm.style.display = 'flex';
        if (cmtDevForm) cmtDevForm.style.display = 'none';
        if (cmtResetForm) cmtResetForm.style.display = 'none';
        if (cmtRegForm) cmtRegForm.style.display = 'none';
        cmtAuthTitle.textContent = '登录';
        if (cmtSwitchText) cmtSwitchText.textContent = '还没有账号？';
        if (cmtSwitchBtn) cmtSwitchBtn.textContent = '立即注册';
    }
    // 设备验证码提交
    if (cmtDevBtn) cmtDevBtn.addEventListener('click', async () => {
        const code = cmtDevCode.value.trim();
        cmtDevErr.textContent = '';
        if (!/^\d{6}$/.test(code)) { cmtDevErr.textContent = '请输入6位数字验证码'; return; }
        await ensureFreshCsrf();
        cmtDevBtn.disabled = true; cmtDevBtn.textContent = '验证中...';
        fetch('api.php?action=device_verify', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code }) })
            .then(r => r.json()).then(d => {
                if (d.success) {
                    cmtUser = d.user;
                    if (d.isAdminFirstLogin) cmtAdminFirstLogin = true;
                    cmtCloseModal(cmtAuthModal); cmtUpdateUI(); cmtLoad();
                } else {
                    cmtDevErr.textContent = d.error || '验证失败';
                    // 连续输错过多 → 自动返回登录（后端已清 pending）
                    if (d.fails >= 5) setTimeout(() => { cmtBackToLogin(); cmtDevErr.textContent = '验证次数过多，请重新登录'; }, 600);
                }
            }).catch(() => { cmtDevErr.textContent = '网络错误'; })
            .finally(() => { cmtDevBtn.disabled = false; cmtDevBtn.textContent = '确认设备'; });
    });
    if (cmtDevBack) cmtDevBack.addEventListener('click', () => {
        cmtBackToLogin();
        cmtLoginErr.textContent = '';
    });
    // 忘记密码 → 找回视图
    if (cmtForgotLink) cmtForgotLink.addEventListener('click', () => {
        cmtLoginForm.style.display = 'none';
        if (cmtDevForm) cmtDevForm.style.display = 'none';
        if (cmtRegForm) cmtRegForm.style.display = 'none';
        cmtResetForm.style.display = 'flex';
        cmtAuthTitle.textContent = '找回密码';
        cmtResetErr.textContent = '';
        setTimeout(() => { if (cmtResetQQ) cmtResetQQ.focus(); }, 60);
    });
    if (cmtResetBack) cmtResetBack.addEventListener('click', () => {
        cmtBackToLogin();
        cmtLoginErr.textContent = '';
    });
    // 发送找回验证码
    if (cmtResetSendCode) cmtResetSendCode.addEventListener('click', async () => {
        const qq = cmtResetQQ.value.trim(), email = cmtResetEmail.value.trim();
        cmtResetErr.textContent = '';
        if (!qq || !email) { cmtResetErr.textContent = '请填写账号与绑定邮箱'; return; }
        await ensureFreshCsrf();
        cmtResetSendCode.disabled = true; cmtResetSendCode.textContent = '发送中...';
        fetch('api.php?action=password_reset', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ mode: 'send_code', qq, email }) })
            .then(r => r.json()).then(d => {
                if (d.success) {
                    cmtResetErr.textContent = '验证码已发送至 ' + (d.masked_email || '绑定邮箱') + '，请在5分钟内完成重置';
                    cmtResetErr.style.color = '';
                } else {
                    cmtResetErr.textContent = d.error || '发送失败';
                    cmtResetErr.style.color = '#e5484d';
                }
            }).catch(() => { cmtResetErr.textContent = '网络错误'; cmtResetErr.style.color = '#e5484d'; })
            .finally(() => { cmtResetSendCode.disabled = false; cmtResetSendCode.textContent = '发送验证码'; });
    });
    // 重置密码
    if (cmtResetBtn) cmtResetBtn.addEventListener('click', async () => {
        const qq = cmtResetQQ.value.trim(), code = cmtResetCode.value.trim(), pw = cmtResetPw.value;
        cmtResetErr.textContent = '';
        cmtResetErr.style.color = '';
        if (!qq || !code || !pw) { cmtResetErr.textContent = '请完整填写账号、验证码与新密码'; return; }
        await ensureFreshCsrf();
        cmtResetBtn.disabled = true; cmtResetBtn.textContent = '重置中...';
        fetch('api.php?action=password_reset', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ mode: 'do_reset', qq, code, new_password: pw }) })
            .then(r => r.json()).then(d => {
                if (d.success) {
                    cmtResetErr.textContent = '密码已重置，请使用新密码登录';
                    cmtResetPw.value = ''; cmtResetCode.value = '';
                    setTimeout(() => { cmtBackToLogin(); }, 1200);
                } else {
                    cmtResetErr.textContent = d.error || '重置失败';
                }
            }).catch(() => { cmtResetErr.textContent = '网络错误'; })
            .finally(() => { cmtResetBtn.disabled = false; cmtResetBtn.textContent = '重置密码'; });
    });
    // ---- v2.11.0：滑块人机验证组件已彻底移除 ----
    function cmtRegShowVerify() {
        if (cmtRegVerifyBox) cmtRegVerifyBox.style.display = 'flex';
    }
    // 切到注册时显示验证区块
    if (cmtSwitchBtn) {
        const sw = cmtSwitchBtn;
        sw.addEventListener('click', () => {
            setTimeout(() => {
                if (cmtRegForm && cmtRegForm.style.display !== 'none') {
                    if (regVerifyOn) cmtRegShowVerify(); else if (cmtRegVerifyBox) cmtRegVerifyBox.style.display = 'none';
                } else if (cmtRegVerifyBox) cmtRegVerifyBox.style.display = 'none';
            }, 250);
        });
    }

    // 发码按钮：v4.4.0 先弹算术人机验证，答对后才真正发码（60s 倒计时；后端同样限制）
    if (cmtRegSendCode) cmtRegSendCode.addEventListener('click', () => {
        const email = cmtRegEmail.value.trim();
        const re = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        if (!re.test(email)) { cmtRegErr.textContent = '请输入正确的邮箱'; return; }
        cmtRegErr.textContent = '';
        // 获取随机算术题并弹窗
        fetch('api.php?action=arith_challenge')
            .then(r => r.json())
            .then(d => {
                if (!d.success) { cmtRegErr.textContent = '人机验证获取失败，请重试'; return; }
                cmtArithQuestion.textContent = d.expression;
                cmtArithAnswer.value = '';
                cmtArithErr.textContent = '';
                cmtArithOk.disabled = false;
                cmtOpenModal(cmtArithModal);
                setTimeout(() => cmtArithAnswer.focus(), 50);
            })
            .catch(() => { cmtRegErr.textContent = '网络错误'; });
    });
    // 算术题确认：答对才置 pending，随后才真正发码
    if (cmtArithOk) cmtArithOk.addEventListener('click', () => {
        const ans = cmtArithAnswer.value.trim();
        if (!ans) { cmtArithErr.textContent = '请输入计算结果'; return; }
        cmtArithOk.disabled = true;
        cmtArithErr.textContent = '验证中...';
        const email = cmtRegEmail.value.trim();
        fetch('api.php?action=send_register_code', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, arith_answer: ans })
        }).then(r => r.json()).then(d => {
            if (d.success) {
                cmtCloseModal(cmtArithModal);
                cmtRegErr.textContent = '';
                let left = 60;
                cmtRegSendCode.textContent = left + 's 后重发';
                const t = setInterval(() => {
                    left--;
                    if (left <= 0) { clearInterval(t); cmtRegSendCode.textContent = '获取验证码'; cmtRegSendCode.disabled = false; }
                    else cmtRegSendCode.textContent = left + 's 后重发';
                }, 1000);
            } else {
                cmtArithErr.textContent = d.error || '验证失败';
                // 答错/过期：自动换一题重试
                fetch('api.php?action=arith_challenge').then(r => r.json()).then(d2 => {
                    if (d2.success) {
                        cmtArithQuestion.textContent = d2.expression;
                        cmtArithAnswer.value = '';
                    }
                }).catch(() => {});
                cmtArithOk.disabled = false;
            }
        }).catch(() => { cmtArithErr.textContent = '网络错误'; cmtArithOk.disabled = false; });
    });
    if (cmtArithCancel) cmtArithCancel.addEventListener('click', () => {
        cmtCloseModal(cmtArithModal);
        cmtArithOk.disabled = false;
    });

    if (cmtRegBtn) cmtRegBtn.addEventListener('click', async () => {
        const qq = cmtRegQQ.value.trim(), nick = cmtRegNick.value.trim(), pw = cmtRegPw.value;
        const email = regVerifyOn ? cmtRegEmail.value.trim() : '';
        const code = regVerifyOn ? cmtRegCode.value.trim() : '';
        cmtRegErr.textContent = '';
        if (!qq || !pw) { cmtRegErr.textContent = '请填写QQ号和密码'; return; }
        if (pw.length < 8 || !/[a-z]/.test(pw) || !/[A-Z]/.test(pw) || !/[0-9]/.test(pw)) { cmtRegErr.textContent = '密码至少8位，且需包含大写字母、小写字母与数字'; return; }
        if (regVerifyOn) {
            if (!email) { cmtRegErr.textContent = '请填写邮箱'; return; }
            if (!code) { cmtRegErr.textContent = '请填写邮箱验证码'; return; }
        }
        // v2.11.1：提交前动态刷新 token（与当前 session 绑定）
        await ensureFreshCsrf();
        cmtRegBtn.disabled = true; cmtRegBtn.textContent = '注册中...';
        // v4.4.0：注册请求携带蜜罐字段原值（机器人自动填充会被后端静默拒绝）
        const hpVal = cmtRegHoneypot ? cmtRegHoneypot.value.trim() : '';
        fetch('api.php?action=register', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ qq, nickname: nick, password: pw, email, code, website: hpVal }) })
            .then(r => r.json()).then(d => {
                if (d.success) { if (d.user) { cmtUser = d.user; } cmtCloseModal(cmtAuthModal); cmtUpdateUI(); cmtLoad(); }
                else { cmtRegErr.textContent = d.error || '注册失败'; }
            }).catch(() => { cmtRegErr.textContent = '网络错误'; })
            .finally(() => { cmtRegBtn.disabled = false; cmtRegBtn.textContent = '注册'; });
    });
    if (cmtProfileSave) cmtProfileSave.addEventListener('click', () => {
        const nick = cmtEditNick.value.trim(), sign = cmtEditSign.value.trim();
        cmtProfileErr.textContent = '';
        if (!nick) { cmtProfileErr.textContent = '昵称不能为空'; return; }
        fetch('api.php?action=update_profile', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ nickname: nick, signature: sign }) })
            .then(r => r.json()).then(d => {
                if (d.success) { cmtUser.nickname = nick; cmtUser.signature = sign; cmtCloseModal(cmtProfileModal); cmtUpdateUI(); }
                else { cmtProfileErr.textContent = d.error || '保存失败'; }
            }).catch(() => { cmtProfileErr.textContent = '网络错误'; });
    });
    // ===== v2.10.0：头像上传（选择文件后立即上传；登录用户，含 CSRF） =====
    const cmtAvatarFile = document.getElementById('cmtAvatarFile');
    const cmtEmailSendCode = document.getElementById('cmtEmailSendCode');
    const cmtEmailSave = document.getElementById('cmtEmailSave');
    if (cmtAvatarFile) cmtAvatarFile.addEventListener('change', () => {
        const f = cmtAvatarFile.files && cmtAvatarFile.files[0];
        if (!f) return;
        if (!/\.(jpg|jpeg|png|webp)$/i.test(f.name)) { cmtProfileErr.textContent = '仅支持 JPG / PNG / WEBP 格式'; cmtAvatarFile.value = ''; return; }
        if (f.size > 2 * 1024 * 1024) { cmtProfileErr.textContent = '图片不能超过 2MB'; cmtAvatarFile.value = ''; return; }
        cmtProfileErr.textContent = '头像上传中...';
        const fd = new FormData();
        fd.append('avatar', f);
        fetch('api.php?action=avatar_upload', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: fd
        }).then(r => r.json()).then(d => {
            if (d.success) {
                cmtUser.avatar = d.avatar;
                const avBox = document.getElementById('cmtProfileAvatar');
                if (avBox) avBox.innerHTML = '<img src="' + cmtEscape(d.avatar) + '" alt="" onerror="this.style.display=\'none\'">';
                cmtProfileErr.textContent = '';
                showToast('头像已更新');
                cmtUpdateUI();
            } else {
                cmtProfileErr.textContent = d.error || '上传失败';
            }
            cmtAvatarFile.value = '';
        }).catch(() => { cmtProfileErr.textContent = '网络错误'; cmtAvatarFile.value = ''; });
    });
    // 邮箱更换：发送验证码（60s 倒计时，复用后端冷却）
    if (cmtEmailSendCode) cmtEmailSendCode.addEventListener('click', () => {
        const email = cmtEditEmail.value.trim();
        const re = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        const emailErr = document.getElementById('cmtEmailErr');
        if (!re.test(email)) { if (emailErr) emailErr.textContent = '请输入正确的邮箱'; return; }
        if (emailErr) emailErr.textContent = '';
        cmtEmailSendCode.disabled = true;
        fetch('api.php?action=send_email_change_code', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ email })
        }).then(r => r.json()).then(d => {
            if (d.success) {
                if (emailErr) emailErr.textContent = '';
                let left = 60;
                cmtEmailSendCode.textContent = left + 's 后重发';
                const t = setInterval(() => {
                    left--;
                    if (left <= 0) { clearInterval(t); cmtEmailSendCode.textContent = '获取验证码'; cmtEmailSendCode.disabled = false; }
                    else cmtEmailSendCode.textContent = left + 's 后重发';
                }, 1000);
            } else {
                if (emailErr) emailErr.textContent = d.error || '发送失败';
                cmtEmailSendCode.disabled = false;
            }
        }).catch(() => { if (emailErr) emailErr.textContent = '网络错误'; cmtEmailSendCode.disabled = false; });
    });
    // 邮箱更换：验证码确认
    if (cmtEmailSave) cmtEmailSave.addEventListener('click', () => {
        const email = cmtEditEmail.value.trim();
        const code = cmtEditEmailCode.value.trim();
        const emailErr = document.getElementById('cmtEmailErr');
        if (!email) { if (emailErr) emailErr.textContent = '请先填写新邮箱'; return; }
        if (!code) { if (emailErr) emailErr.textContent = '请填写邮箱验证码'; return; }
        if (emailErr) emailErr.textContent = '';
        cmtEmailSave.disabled = true;
        fetch('api.php?action=update_email', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ email, code })
        }).then(r => r.json()).then(d => {
            if (d.success) {
                cmtUser.email = d.email;
                cmtEditEmail.placeholder = '当前绑定：' + d.email + '，输入新邮箱更换';
                cmtEditEmail.value = '';
                cmtEditEmailCode.value = '';
                if (emailErr) emailErr.textContent = '';
                showToast('邮箱绑定成功');
            } else {
                if (emailErr) emailErr.textContent = d.error || '绑定失败';
            }
            cmtEmailSave.disabled = false;
        }).catch(() => { if (emailErr) emailErr.textContent = '网络错误'; cmtEmailSave.disabled = false; });
    });
    if (cmtAdminSave) cmtAdminSave.addEventListener('click', () => {
        const qq = cmtAdminQQ.value.trim(), nick = cmtAdminNick.value.trim(), pw = cmtAdminPw.value, pw2 = cmtAdminPw2.value;
        cmtAdminErr.textContent = '';
        if (!qq) { cmtAdminErr.textContent = '请填写QQ号'; return; }
        if (!nick) { cmtAdminErr.textContent = '请填写昵称'; return; }
        if (pw && (pw.length < 8 || !/[a-z]/.test(pw) || !/[A-Z]/.test(pw) || !/[0-9]/.test(pw))) { cmtAdminErr.textContent = '密码至少8位，且需包含大写字母、小写字母与数字'; return; }
        if (pw !== pw2) { cmtAdminErr.textContent = '两次密码不一致'; return; }
        fetch('api.php?action=admin_setup', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ qq, nickname: nick, password: pw }) })
            .then(r => r.json()).then(d => {
                if (d.success) { if (cmtUser) { cmtUser.nickname = nick; cmtUser.avatar = d.user.avatar || cmtUser.avatar; } cmtCloseModal(cmtAdminModal); cmtUpdateUI(); }
                else { cmtAdminErr.textContent = d.error || '保存失败'; }
            }).catch(() => { cmtAdminErr.textContent = '网络错误'; });
    });
    function cmtUpdateUI() {
        const loggedIn = !!cmtUser;
        const canComment = loggedIn || guestCommentsEnabled;
        if (cmtCapsuleBar) cmtCapsuleBar.style.display = loggedIn ? 'none' : 'block';
        if (cmtUserBar) cmtUserBar.style.display = loggedIn ? 'block' : 'none';
        if (cmtTextarea) cmtTextarea.disabled = !canComment;
        if (loggedIn) {
            const name = cmtUser.nickname || '用户';
            const avatarUrl = cmtUser.avatar || '';
            if (cmtUserAvatar) {
                const avatarSrc = cmtUser.avatar || '';
                if (avatarSrc) {
                    cmtUserAvatar.innerHTML = '<img src="' + cmtEscape(avatarSrc) + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%" onerror="this.style.display=\'none\'">';
                } else {
                    cmtUserAvatar.textContent = name.charAt(0);
                }
            }
            if (cmtUserGreeting) cmtUserGreeting.textContent = cmtGreeting() + '，' + name;
        } else {
            if (cmtUserAvatar) cmtUserAvatar.textContent = '';
        }
        if (loggedIn && cmtUser.role === 'admin' && cmtAdminFirstLogin) {
            cmtAdminFirstLogin = false;
            setTimeout(() => cmtOpenModal(cmtAdminModal), 300);
        }
    }
    if (cmtTextarea) cmtTextarea.addEventListener('input', () => {
        if (cmtSendBtn) cmtSendBtn.classList.toggle('has-content', cmtTextarea.value.trim().length > 0);
    });
    if (cmtSendBtn) cmtSendBtn.addEventListener('click', () => {
        const content = cmtTextarea.value.trim();
        if (!content) return;
        if (!cmtUser && !guestCommentsEnabled) return;
        cmtSendBtn.disabled = true;
        fetch('api.php?action=post', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ article: currentFileName, content }) })
            .then(r => r.json()).then(d => {
                if (d.success) { cmtTextarea.value = ''; cmtSendBtn.classList.remove('has-content'); cmtPage = 1; cmtLoad(); }
                else showToast(d.error || '评论失败');
            }).catch(() => showToast('网络错误')).finally(() => { cmtSendBtn.disabled = false; });
    });
    var cmtPage = 1, cmtPerPage = 20; // v3.3.15：评论区根评论分页（默认每页 20 条，独立实现）
    // v3.3.16：回复展开后默认只显示前 5 条，更多点「查看全部」——大量回复不刷屏
    var REPLY_PREVIEW = 5;
    var cmtLoadedComments = []; // 「查看全部」需要重新读取当前页根评论数据
    function cmtLoad() {
        if (!currentFileName) return;
        cmtExpandedItems.clear();
        cmtList.querySelectorAll('.cmt-item').forEach(item => {
            const expandBtn = item.querySelector('.cmt-reply-expand');
            const replyList = item.querySelector('.cmt-reply-list');
            if (expandBtn && expandBtn.classList.contains('open')) {
                cmtExpandedItems.add(item.dataset.id);
            }
            if (replyList && replyList.classList.contains('show')) {
                cmtExpandedItems.add(item.dataset.id);
            }
        });
        fetch('api.php?action=get&article=' + encodeURIComponent(currentFileName) + '&page=' + cmtPage + '&per_page=' + cmtPerPage)
            .then(r => r.json()).then(d => { if (d.success) { cmtRender(d.comments, d); cmtRestoreExpandState(); } })
            .catch(() => {});
    }
    function cmtRestoreExpandState() {
        cmtExpandedItems.forEach(id => {
            const item = cmtList.querySelector('.cmt-item[data-id="' + id + '"]');
            if (item) {
                const expandBtn = item.querySelector('.cmt-reply-expand');
                const replyList = item.querySelector('.cmt-reply-list');
                if (expandBtn) expandBtn.classList.add('open');
                if (replyList) replyList.classList.add('show');
            }
        });
    }
    function cmtRenderReply(r, level, parentNick) {
        if (level > 10) return '<div class="cmt-reply-item"><div class="cmt-reply-text" style="color:var(--text-muted)">[嵌套过深]</div></div>';
        const isAdmin = cmtUser && cmtUser.role === 'admin';
        const rDel = isAdmin || (cmtUser && cmtUser.id === r.user_id);
        let nestedHtml = '';
        if (r.replies && r.replies.length) {
            nestedHtml = '<div class="cmt-reply-nested">' + r.replies.map(nr => cmtRenderReply(nr, level + 1, r.nickname || '')).join('') + '</div>';
        }
        const replyToHtml = (level >= 2 && parentNick) ? '<span class="cmt-reply-arrow">▶︎</span><span class="cmt-reply-to">' + cmtEscape(parentNick) + '</span>' : '';
        // v2.10.0：评论头像/昵称可点击打开个人详情页（无 user_id 的匿名/游客评论不生成链接）
        const rLink = r.user_id ? '<a class="cmt-user-link" href="user.php?id=' + encodeURIComponent(r.user_id) + '">' : '';
        const rLinkEnd = r.user_id ? '</a>' : '';
        return '<div class="cmt-reply-item" data-id="' + r.id + '" data-del="' + rDel + '">' +
            '<div class="cmt-reply-top"><div class="cmt-reply-avatar">' + rLink + cmtAvatarHtml(r.avatar, r.nickname, r.qq) + rLinkEnd + '</div>' +
            '<div class="cmt-reply-info"><div class="cmt-reply-name-row">' + rLink + '<span class="cmt-reply-name">' + cmtEscape(r.nickname||'') + '</span>' + rLinkEnd + replyToHtml + '<span class="cmt-reply-time">' + cmtFormatTime(r.created_at) + '</span></div>' +
            '</div></div>' +
            '<div class="cmt-reply-text">' + cmtEscape(r.content) + '</div>' +
            '<div class="cmt-reply-actions">' +
            '<button class="cmt-reply-act cmt-like-btn"><svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg><span>' + (r.likes||0) + '</span></button>' +
            '<button class="cmt-reply-act cmt-reply-btn"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>回复</button>' +
            (rDel ? '<button class="cmt-reply-act cmt-del-btn" data-id="' + r.id + '"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>删除</button>' : '') +
            '</div>' + nestedHtml + '</div>';
    }
    function cmtRender(comments, meta) {
        if (!comments || !comments.length) {
            cmtList.innerHTML = '<div class="cmt-empty"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>暂无评论，快来抢沙发吧</div>';
            return;
        }
        const isAdmin = cmtUser && cmtUser.role === 'admin';
        cmtLoadedComments = comments;
        cmtList.innerHTML = comments.map(c => {
            const isOwner = cmtUser && cmtUser.id === c.user_id;
            const canDel = isAdmin || isOwner;
            const sign = c.signature ? '<div class="cmt-sign">' + cmtEscape(c.signature) + '</div>' : '';
            let repliesHtml = '';
            if (c.replies && c.replies.length) {
                // v3.3.16：回复展开后默认只显示前 5 条，更多点「查看全部」——大量回复不刷屏
                const preview = c.replies.slice(0, REPLY_PREVIEW);
                repliesHtml = '<div class="cmt-reply-list">' + preview.map(r => cmtRenderReply(r, 1, c.nickname || '')).join('');
                if (c.replies.length > REPLY_PREVIEW) {
                    repliesHtml += '<button class="cmt-more-replies" data-id="' + c.id + '"><svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>查看全部 ' + c.replies.length + ' 条回复</button>';
                }
                repliesHtml += '</div>';
            }
            const totalReplies = (function countReplies(arr) { return arr.reduce((n, r) => n + 1 + countReplies(r.replies || []), 0); })(c.replies || []);
            const expandBtn = totalReplies ? '<button class="cmt-reply-expand"><span>' + totalReplies + '条回复</span><svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></button>' : '';
            const noSignCls = !c.signature ? ' cmt-no-sign' : '';
            // v2.10.0：评论头像/昵称可点击打开个人详情页（无 user_id 的匿名/游客评论不生成链接）
            const uLink = c.user_id ? '<a class="cmt-user-link" href="user.php?id=' + encodeURIComponent(c.user_id) + '">' : '';
            const uLinkEnd = c.user_id ? '</a>' : '';
            return '<div class="cmt-item" data-id="' + c.id + '" data-del="' + canDel + '">' +
                '<div class="cmt-top' + noSignCls + '"><div class="cmt-avatar">' + uLink + cmtAvatarHtml(c.avatar, c.nickname, c.qq) + uLinkEnd + '</div>' +
                '<div class="cmt-info"><div class="cmt-name-row">' + uLink + '<span class="cmt-name">' + cmtEscape(c.nickname||'') + '</span>' + uLinkEnd + '<span class="cmt-time">' + cmtFormatTime(c.created_at) + '</span></div>' +
                sign + '</div></div>' +
                '<div class="cmt-text">' + cmtEscape(c.content) + '</div>' +
                '<div class="cmt-actions">' +
                '<button class="cmt-act cmt-like-btn"><svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg><span>' + (c.likes||0) + '</span></button>' +
                '<button class="cmt-act cmt-reply-btn"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>回复</button>' +
                (canDel ? '<button class="cmt-act cmt-del-btn" data-id="' + c.id + '"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>删除</button>' : '') +
                expandBtn + '</div>' + repliesHtml +
                '<div class="cmt-reply-input-wrap"><textarea placeholder="写下你的回复..."></textarea><div class="cmt-reply-input-bottom"><button class="cmt-reply-send-btn">发送</button></div></div></div>';
        }).join('') + renderCmtPager(meta || {});
        cmtBindEvents();
        cmtBindPagerEvents();
    }
    // v3.3.15：评论区根评论分页器（独立实现，不复用后台分页组件）
    function renderCmtPager(meta) {
        if (!meta || !meta.total || meta.total <= (meta.per_page || 20)) return '';
        var p = meta.page, tp = meta.total_pages;
        var html = '<div class="cmt-pager">' +
            '<span class="cmt-pager-info">共 ' + meta.total + ' 条评论 · 第 ' + p + '/' + tp + ' 页</span>' +
            '<div class="cmt-pager-btns">' +
            '<button class="cmt-pg-btn" data-pg="' + (p - 1) + '"' + (p <= 1 ? ' disabled' : '') + '>上一页</button>';
        var start = Math.max(1, p - 2), end = Math.min(tp, p + 2);
        if (start > 1) html += '<span class="cmt-pg-ellipsis">…</span>';
        for (var i = start; i <= end; i++) html += '<button class="cmt-pg-btn' + (i === p ? ' active' : '') + '" data-pg="' + i + '">' + i + '</button>';
        if (end < tp) html += '<span class="cmt-pg-ellipsis">…</span>';
        html += '<button class="cmt-pg-btn" data-pg="' + (p + 1) + '"' + (p >= tp ? ' disabled' : '') + '>下一页</button>' +
            '</div></div>';
        return html;
    }
    function cmtBindPagerEvents() {
        cmtList.querySelectorAll('.cmt-pg-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (btn.disabled) return;
                cmtPage = parseInt(btn.getAttribute('data-pg'), 10) || 1;
                cmtLoad();
                var top = cmtListSection.getBoundingClientRect().top + (window.pageYOffset || document.documentElement.scrollTop) - 80;
                window.scrollTo({ top: top, behavior: 'smooth' });
            });
        });
    }
    function cmtSendReply(wrap, target) {
        const ta = wrap.querySelector('textarea');
        const content = ta.value.trim();
        if (!content || !cmtUser) return;
        const parentId = target.dataset.id;
        let ancestor = target.parentElement;
        while (ancestor && ancestor !== cmtList) {
            if (ancestor.classList && ancestor.classList.contains('cmt-item')) {
                cmtExpandedItems.add(ancestor.dataset.id);
            }
            ancestor = ancestor.parentElement;
        }
        fetch('api.php?action=reply', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ article: currentFileName, parent_id: parentId, content }) })
            .then(r => r.json()).then(d => { if (d.success) { ta.value = ''; wrap.classList.remove('show'); cmtLoad(); } else showToast(d.error || '回复失败'); })
            .catch(() => showToast('网络错误'));
    }
    function cmtBindEvents(root) {
        // v3.3.16：支持传入子树 root——「查看全部」插入的回复节点单独绑定交互事件
        root = root || cmtList;
        root.querySelectorAll('.cmt-like-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                btn.classList.toggle('liked');
                const span = btn.querySelector('span');
                if (span) { let n = parseInt(span.textContent); span.textContent = btn.classList.contains('liked') ? n + 1 : Math.max(0, n - 1); }
            });
        });
        root.querySelectorAll('.cmt-reply-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (!cmtUser && !guestCommentsEnabled) { cmtCapsuleBtn && cmtCapsuleBtn.click(); return; }
                const target = btn.closest('.cmt-item, .cmt-reply-item');
                let wrap = target.querySelector(':scope > .cmt-reply-input-wrap');
                if (!wrap) {
                    wrap = document.createElement('div');
                    wrap.className = 'cmt-reply-input-wrap';
                    wrap.innerHTML = '<textarea placeholder="写下你的回复..."></textarea><div class="cmt-reply-input-bottom"><button class="cmt-reply-send-btn">发送</button></div>';
                    target.appendChild(wrap);
                    wrap.querySelector('textarea').addEventListener('input', function() {
                        const sbtn = wrap.querySelector('.cmt-reply-send-btn');
                        if (sbtn) sbtn.classList.toggle('has-content', this.value.trim().length > 0);
                    });
                    wrap.querySelector('.cmt-reply-send-btn').addEventListener('click', function() { cmtSendReply(wrap, target); });
                }
                wrap.classList.toggle('show');
                if (wrap.classList.contains('show')) wrap.querySelector('textarea').focus();
            });
        });
        root.querySelectorAll('.cmt-reply-send-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const wrap = btn.closest('.cmt-reply-input-wrap');
                const target = btn.closest('.cmt-item, .cmt-reply-item');
                cmtSendReply(wrap, target);
            });
        });
        root.querySelectorAll('.cmt-reply-input-wrap textarea').forEach(ta => {
            ta.addEventListener('input', () => {
                const btn = ta.closest('.cmt-reply-input-wrap').querySelector('.cmt-reply-send-btn');
                if (btn) btn.classList.toggle('has-content', ta.value.trim().length > 0);
            });
        });
        root.querySelectorAll('.cmt-del-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const id = btn.dataset.id;
                if (id) {
                    cmtConfirmCb = () => {
                        fetch('api.php?action=delete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, article: currentFileName }) })
                            .then(r => r.json()).then(d => { if (d.success) cmtLoad(); else showToast(d.error || '删除失败'); })
                            .catch(() => showToast('网络错误'));
                    };
                    cmtConfirmOverlay.classList.add('show');
                }
            });
        });
        root.querySelectorAll('.cmt-reply-expand').forEach(btn => {
            btn.addEventListener('click', () => {
                btn.classList.toggle('open');
                const list = btn.closest('.cmt-item').querySelector('.cmt-reply-list');
                if (list) list.classList.toggle('show');
            });
        });
        // v3.3.16：回复「查看全部」——把剩余回复渲染进当前回复列表（数据已保存在 cmtLoadedComments）
        root.querySelectorAll('.cmt-more-replies').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.id;
                const all = cmtLoadedComments.find(x => x.id === id);
                if (!all || !all.replies || all.replies.length <= REPLY_PREVIEW) return;
                const rest = all.replies.slice(REPLY_PREVIEW);
                const nodes = [];
                rest.forEach(r => {
                    const tmp = document.createElement('div');
                    tmp.innerHTML = cmtRenderReply(r, 1, all.nickname || '');
                    const node = tmp.firstElementChild;
                    if (node) { btn.parentNode.insertBefore(node, btn); nodes.push(node); }
                });
                btn.remove();
                // 新插入的回复单独绑定交互（点赞/回复/删除等）
                if (nodes.length) {
                    const wrap = document.createElement('div');
                    nodes.forEach(n => wrap.appendChild(n));
                    cmtBindEvents(wrap);
                }
            });
        });
        root.querySelectorAll('.cmt-reply-item[data-del="true"]').forEach(el => {
            let timer;
            const start = (e) => { e.stopPropagation(); timer = setTimeout(() => cmtShowConfirm(el), 600); };
            const clear = () => clearTimeout(timer);
            el.addEventListener('pointerdown', start);
            el.addEventListener('pointerup', clear);
            el.addEventListener('pointercancel', clear);
            el.addEventListener('pointermove', clear);
        });
        root.querySelectorAll('.cmt-item[data-del="true"]').forEach(el => {
            let timer;
            const start = () => { timer = setTimeout(() => cmtShowConfirm(el), 600); };
            const clear = () => clearTimeout(timer);
            el.addEventListener('pointerdown', start);
            el.addEventListener('pointerup', clear);
            el.addEventListener('pointercancel', clear);
            el.addEventListener('pointermove', clear);
            const textEl = el.querySelector('.cmt-text');
            if (textEl) {
                textEl.style.cursor = 'pointer';
                textEl.addEventListener('pointerdown', (e) => { e.stopPropagation(); timer = setTimeout(() => cmtShowConfirm(el), 600); });
                textEl.addEventListener('pointerup', clear);
                textEl.addEventListener('pointercancel', clear);
            }
        });
    }
    function cmtShowConfirm(el) {
        cmtConfirmCb = () => {
            const id = el.dataset.id;
            fetch('api.php?action=delete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, article: currentFileName }) })
                .then(r => r.json()).then(d => { if (d.success) cmtLoad(); else showToast(d.error || '删除失败'); })
                .catch(() => showToast('网络错误'));
        };
        cmtConfirmOverlay.classList.add('show');
    }
    if (cmtConfirmOk) cmtConfirmOk.addEventListener('click', () => { if (cmtConfirmCb) cmtConfirmCb(); cmtConfirmOverlay.classList.remove('show'); cmtConfirmCb = null; });
    if (cmtConfirmCancel) cmtConfirmCancel.addEventListener('click', () => { cmtConfirmOverlay.classList.remove('show'); cmtConfirmCb = null; });
    // v4.5.0：登录态过期/环境变化时尝试用 refresh token 自动续期（换环境则失败，需重新登录）
    function ymTryRefresh() {
        return fetch('api.php?action=refresh', { method: 'POST', headers: { 'Content-Type': 'application/json' } })
            .then(r => r.json())
            .then(d => { if (d.success && d.user) { cmtUser = d.user; return true; } return false; })
            .catch(() => false);
    }
    // v4.7.3：恢复设备验证弹窗的标记（避免 adminLogin 分支把它覆盖回登录视图）
    var cmtPendingDevShown = false;
    // v4.7.4：URL 带 ?admin_login=1（站长/写作者后台被拦截跳回首页）时——
    //         未登录则自动弹出登录弹窗；已登录则登录完成后自动跳回对应后台。
    function cmtHandleAdminLoginHint() {
        if (getUrlParam('admin_login') !== '1') return;
        if (!cmtUser && !cmtPendingDevShown) {
            cmtAuthMode = 'login';
            cmtUpdateAuthUI();
            cmtOpenModal(cmtAuthModal);
            history.replaceState(null, '', location.pathname);
        }
    }
    function cmtMaybeGoAdmin() {
        if (getUrlParam('admin_login') !== '1') return;
        fetch('api.php?action=user-status').then(r => r.json()).then(d => {
            if (d.success && d.loggedIn && d.canAccessAdmin && d.adminUrl) {
                window.location.href = d.adminUrl;
            } else {
                history.replaceState(null, '', location.pathname);
            }
        }).catch(() => {});
    }
    function cmtCheckAuth() {
        return fetch('api.php?action=check').then(r => r.json()).then(d => {
            if (d.success && d.loggedIn) {
                cmtUser = d.user;
                if (d.isAdminFirstLogin) cmtAdminFirstLogin = true;
            } else if (d.success && d.pending_device_verify) {
                // v4.7.3：陌生设备验证进行中（切后台看邮箱/移动端页面被重载导致弹窗丢失）→ 恢复设备验证弹窗
                cmtUser = null;
                cmtPendingDevShown = true;
                cmtAuthMode = 'login';
                cmtShowDevVerify(d.masked_email || '');
                if (cmtAuthModal) cmtOpenModal(cmtAuthModal);
            } else if (d.success && d.env_invalid) {
                // v4.5.0：环境/会话异常——先尝试 refresh 自动续期，失败则提示重新登录
                return ymTryRefresh().then(ok => {
                    if (ok) {
                        return fetch('api.php?action=check').then(r => r.json()).then(d2 => {
                            if (d2.success && d2.loggedIn) { cmtUser = d2.user; cmtUpdateUI(); return; }
                            cmtUpdateUI();
                        });
                    }
                    cmtUser = null;
                    showToast('登录环境已变化，请重新登录');
                    cmtUpdateUI();
                });
            }
            cmtUpdateUI();
        }).catch(() => cmtUpdateUI());
    }
    const _cmtOrigLoad = typeof loadFile === 'function' ? loadFile : null;
    const _cmtOrigHome = typeof showHome === 'function' ? showHome : null;
    function cmtOnArticleLoad() {
        if (cmtSection) cmtSection.style.display = 'block';
        if (cmtArea) cmtArea.style.display = 'block';
        if (cmtListSection) cmtListSection.style.display = 'block';
        cmtCheckAuth().then(() => {
            cmtLoad();
            // v4.7.4：?admin_login=1 未登录 → 自动弹出登录弹窗（替代此前从未被设置的 data-admin-login 死代码）
            cmtHandleAdminLoginHint();
        });
    }
    function cmtOnArticleHide() {
        if (cmtSection) cmtSection.style.display = 'none';
        if (cmtArea) cmtArea.style.display = 'none';
        if (cmtListSection) cmtListSection.style.display = 'none';
    }
    // ============================================================
    // v4.5.0：本地背景音——独立于网易云播放器的第二路音频（互不干扰），单曲循环。
    // 曲目由站长/超管后台上传（<100MB 自动转码），前端只能开/关不能选曲。
    // 首次播放从服务器取并存 Cache Storage，之后一律从浏览器缓存播放，不重复消耗服务器流量。
    // ============================================================
    var bgmToggle = document.getElementById('bgmToggle');
    var bgmAudio = document.getElementById('bgmAudio');
    var bgmRow = document.getElementById('bgmRow');
    var bgmStorageKey = 'ymd-bgm-on';
    var BGM_URL = 'data/bgm/background.mp3';
    var BGM_CACHE = 'ymd-bgm-v1';
    function bgmLoadSource() {
        if ('caches' in window) {
            return caches.open(BGM_CACHE).then(function(c) {
                return c.match(BGM_URL).then(function(hit) {
                    if (hit) return hit.blob(); // 命中浏览器缓存 → 不再请求服务器
                    return fetch(BGM_URL, { cache: 'force-cache' }).then(function(r) {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        var clone = r.clone();
                        c.put(BGM_URL, clone).catch(function() {});
                        return r.blob();
                    });
                });
            });
        }
        return Promise.resolve(null); // 无 Cache API（非 https 环境）→ 直接走浏览器 HTTP 缓存
    }
    function bgmStart() {
        // v4.7.4：背景音乐与播放器音乐互斥——BGM 开播时暂停网易云/QQ/酷狗播放器
        if (musicAudio && typeof musicAudio.pause === 'function') { try { musicAudio.pause(); } catch (e) {} }
        bgmLoadSource().then(function(blob) {
            if (blob) bgmAudio.src = URL.createObjectURL(blob);
            else bgmAudio.src = BGM_URL;
            return bgmAudio.play();
        }).catch(function() {
            // 自动播放策略拦截或加载失败：静默处理，用户点开关重试即可
        });
    }
    function bgmStop() {
        bgmAudio.pause();
        bgmAudio.src = '';
    }
    function bgmSetOn(on) {
        bgmSyncUi(on);
        try { localStorage.setItem(bgmStorageKey, on ? '1' : '0'); } catch (e) {}
        if (on) bgmStart(); else bgmStop();
    }
    // v4.6.2：背景音 UI 同步（弹窗内开关 + 首页浮动按钮双向一致）
    function bgmSyncUi(on) {
        if (bgmToggle) bgmToggle.checked = !!on;
        if (bgmFloatBtn) bgmFloatBtn.classList.toggle('playing', !!on);
    }
    var bgmFloatBtn = document.getElementById('bgmFloatBtn');
    if (document.body.dataset.bgMusic === '1') {
        if (bgmRow) bgmRow.style.display = 'flex';
        if (bgmFloatBtn) {
            bgmFloatBtn.style.display = 'flex';
            bgmFloatBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                bgmSetOn(!(bgmToggle ? bgmToggle.checked : false));
            });
        }
        if (bgmToggle && bgmAudio) {
            var bgmSaved = false;
            try { bgmSaved = localStorage.getItem(bgmStorageKey) === '1'; } catch (e) {}
            bgmToggle.addEventListener('change', function() { bgmSetOn(bgmToggle.checked); });
            // 记忆上次开启状态：进站尝试自动播放（浏览器自动播放策略拦截则静默，点浮动钮重试）
            if (bgmSaved) { bgmSyncUi(true); bgmStart(); }
        }
    }
    // v4.7.4：滑块点击兜底——修复部分移动端浏览器零尺寸 checkbox 点击无响应（滑块点了没反应）。
    // .bgm-switch input 已改为全尺寸透明覆盖，正常点击 e.target 即 input 走原生 change，本兜底自然跳过；
    // 若个别浏览器仍不触发，则手动翻转勾选态并派发 change。
    document.querySelectorAll('.bgm-switch').forEach(function(sw) {
        var inp = sw.querySelector('input[type="checkbox"]');
        if (!inp) return;
        sw.addEventListener('click', function(e) {
            if (e.target === inp) return;
            e.preventDefault();
            inp.checked = !inp.checked;
            inp.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });
    var musicPlaylistId = document.body.dataset.musicPlaylist || '3778678';
    // v4.7.4：多平台音乐——网易云/QQ音乐/酷狗，每平台独立榜单；播放地址播放时懒解析（不预取，减少外呼）
    var musicPlatforms = { netease: '网易云', qq: 'QQ音乐', kugou: '酷狗' };
    var musicCharts = {
        netease: ['热歌榜', '新歌榜', '原创榜', '飙升榜'],
        qq: ['热歌榜', '新歌榜', '飙升榜'],
        kugou: ['热歌榜', '新歌榜', '飙升榜']
    };
    var musicPlatform = 'netease';
    var musicChart = '热歌榜';
    function musicLoadSrcPref() {
        try {
            var raw = localStorage.getItem('ymd-music-src');
            if (raw) {
                var p = JSON.parse(raw);
                if (p && musicPlatforms[p.platform] && (musicCharts[p.platform] || []).indexOf(p.chart) >= 0) {
                    musicPlatform = p.platform; musicChart = p.chart;
                }
            }
        } catch (e) {}
    }
    function musicSaveSrcPref() {
        try { localStorage.setItem('ymd-music-src', JSON.stringify({ platform: musicPlatform, chart: musicChart })); } catch (e) {}
    }
    function musicRenderChartChips() {
        if (!musicChartRow) return;
        var list = musicCharts[musicPlatform] || ['热歌榜'];
        musicChartRow.innerHTML = list.map(function(c) {
            return '<button type="button" class="music-src-chip' + (c === musicChart ? ' active' : '') + '" data-chart="' + escHtml(c) + '">' + escHtml(c) + '</button>';
        }).join('');
    }
    var musicSongs = [];
    var musicIndex = -1;
    var musicPlaying = false;
    var musicLoaded = false;
    var musicListOpen = false;
    var musicStorageKey = 'ymd-music-state';
    var musicLoopMode = 0;
    // v4.4.0：默认自动播放目标——后台「默认播放歌曲」关键词（留空则不自动播放）
    var musicAutoPlayKeyword = (document.body.dataset.musicAutoPlay || '').trim();
    function musicSaveState() {
        try {
            var state = {
                index: musicIndex,
                time: musicAudio.currentTime || 0,
                volume: musicAudio.volume,
                listOpen: musicListOpen,
                loopMode: musicLoopMode
            };
            localStorage.setItem(musicStorageKey, JSON.stringify(state));
        } catch(e) {}
    }
    function musicLoadState() {
        try {
            var raw = localStorage.getItem(musicStorageKey);
            if (raw) return JSON.parse(raw);
        } catch(e) {}
        return null;
    }
    // v4.7.4：平台/榜单切换——初始化 + 事件绑定（列表面板顶部的平台/榜单 chips）
    var musicSrcRow = document.getElementById('musicSrcRow');
    var musicChartRow = document.getElementById('musicChartRow');
    var musicSongList = document.getElementById('musicSongList');
    musicLoadSrcPref();
    musicRenderChartChips();
    if (musicSrcRow) musicSrcRow.addEventListener('click', function(e) {
        var b = e.target.closest('.music-src-chip');
        if (!b) return;
        // v4.7.6：阻止冒泡——切换平台后 musicRenderChartChips() 重建榜单行，被点按钮脱离 DOM，
        // 冒泡到 document 时 musicPopup.contains(target) 返回 false，会被「点击外部关闭」误关弹窗
        e.stopPropagation();
        var p = b.getAttribute('data-platform');
        if (!p || p === musicPlatform) return;
        musicPlatform = p;
        musicChart = (musicCharts[p] || ['热歌榜'])[0];
        musicSaveSrcPref();
        musicSrcRow.querySelectorAll('.music-src-chip').forEach(function(x) { x.classList.toggle('active', x === b); });
        musicRenderChartChips();
        musicLoaded = true;
        loadMusicHotSongs();
    });
    if (musicChartRow) musicChartRow.addEventListener('click', function(e) {
        var b = e.target.closest('.music-src-chip');
        if (!b) return;
        // v4.7.6：同上——切换榜单时本行 innerHTML 重建，必须阻止冒泡防误关弹窗
        e.stopPropagation();
        var c = b.getAttribute('data-chart');
        if (!c || c === musicChart) return;
        musicChart = c;
        musicSaveSrcPref();
        musicRenderChartChips();
        musicLoaded = true;
        loadMusicHotSongs();
    });
    // 音乐按钮仅在后台配置 music_cookies 后渲染，未配置时跳过音乐初始化
    if (floatMusicBtn && musicPopup) {
        floatMusicBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            musicPopup.classList.toggle('active');
            tocPopup.classList.remove('active');
            if (!musicLoaded) { musicLoaded = true; loadMusicHotSongs(); }
        });
        document.addEventListener('click', function(e) {
            if (!musicPopup.contains(e.target) && !floatMusicBtn.contains(e.target)) musicPopup.classList.remove('active');
        });
    }
    if (musicListToggle) musicListToggle.addEventListener('click', function(e) {
        e.stopPropagation();
        musicListOpen = !musicListOpen;
        musicList.classList.toggle('open', musicListOpen);
        musicListToggle.classList.toggle('open', musicListOpen);
        musicSaveState();
    });
    function loadMusicHotSongs() {
        musicLoading.textContent = '加载中...';
        // v4.7.4：按当前平台+榜单拉取（QQ/酷狗榜单经 music.php 服务端聚合，播放地址播放时懒解析）
        var musicApiUrl = 'music.php?platform=' + encodeURIComponent(musicPlatform) + '&sortAll=' + encodeURIComponent(musicChart);
        fetch(musicApiUrl)
            .then(function(res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function(data) {
                if (Array.isArray(data) && data.length > 0) {
                    musicSongs = data.map(function(t) {
                        return {
                            id: t.id,
                            name: t.name || '',
                            artist: t.artistsname || '',
                            cover: t.picurl || '',
                            url: t.url || '',
                            duration: (t.duration || 0) * 1000
                        };
                    });
                    if (musicPopupCount) musicPopupCount.textContent = (musicPlatforms[musicPlatform] || musicPlatform) + ' · ' + musicChart + ' · ' + musicSongs.length + ' 首';
                    renderMusicList();
                    var saved = musicLoadState();
                    if (saved && saved.index >= 0 && saved.index < musicSongs.length) {
                        musicListOpen = saved.listOpen !== false;
                        musicList.classList.toggle('open', musicListOpen);
                        musicListToggle.classList.toggle('open', musicListOpen);
                        musicPlaySong(saved.index, saved.time || 0);
                    } else {
                        musicListOpen = true;
                        musicList.classList.add('open');
                        musicListToggle.classList.add('open');
                        // v4.4.0：首次进入且无历史播放状态时，按后台「默认播放歌曲」关键词模糊匹配
                        //         定位并播放（匹配不到则播放列表第一首；关键词留空则不自动播放）
                        var autoIdx = -1;
                        if (musicAutoPlayKeyword) {
                            for (var _i = 0; _i < musicSongs.length; _i++) {
                                var _n = String(musicSongs[_i].name || '').toLowerCase();
                                if (_n.indexOf(musicAutoPlayKeyword) !== -1) { autoIdx = _i; break; }
                            }
                            if (autoIdx === -1 && musicSongs.length > 0) autoIdx = 0;
                            if (autoIdx >= 0) musicPlaySong(autoIdx, 0);
                        }
                    }
                    if (saved && typeof saved.volume === 'number') {
                        musicAudio.volume = saved.volume;
                    }
                    if (saved && typeof saved.loopMode === 'number') {
                        musicLoopMode = saved.loopMode;
                        musicUpdateModeIcon();
                    }
                } else if (data.error) {
                    musicLoading.innerHTML = escHtml(data.error) + '，<a href="javascript:void(0)" id="musicRetry">点击重试</a>';
                    bindRetry();
                } else {
                    musicLoading.innerHTML = '数据异常，<a href="javascript:void(0)" id="musicRetry">点击重试</a>';
                    bindRetry();
                }
            })
            .catch(function(err) {
                musicLoading.innerHTML = '网络错误（' + escHtml(String(err)) + '），<a href="javascript:void(0)" id="musicRetry">点击重试</a>';
                bindRetry();
            });
    }
    function bindRetry() {
        var btn = document.getElementById('musicRetry');
        if (btn) btn.addEventListener('click', function() { musicLoaded = false; loadMusicHotSongs(); });
    }
    function renderMusicList() {
        var html = '';
        musicSongs.forEach(function(s, i) {
            html += '<div class="music-item" data-index="' + i + '">' +
                '<div class="mi-idx">' + (i + 1) + '</div>' +
                '<div class="mi-cover"><img src="' + (s.cover || '') + '" alt="" loading="lazy" onerror="this.style.display=\'none\'"></div>' +
                '<div class="mi-info"><div class="mi-name">' + escHtml(s.name) + '</div><div class="mi-artist">' + escHtml(s.artist) + '</div></div>' +
                '<div class="mi-dur">' + fmtTime(s.duration / 1000) + '</div>' +
                '</div>';
        });
        // v4.7.4：只渲染歌曲容器（平台/榜单 chips 在 #musicList 里，不再被覆盖）
        if (musicSongList) musicSongList.innerHTML = html;
        musicSongList.querySelectorAll('.music-item').forEach(function(el) {
            el.addEventListener('click', function() { musicPlaySong(parseInt(this.dataset.index)); });
        });
    }
    function musicStartPlay(playUrl, song, seekTime) {
        musicAudio.src = playUrl;
        musicAudio.play().then(function() {
            musicSetPlaying(true);
            musicConsecutiveFails = 0;
            if (seekTime > 0) musicAudio.currentTime = seekTime;
        }).catch(function() {
            musicTryFallbackUrl(song, seekTime);
        });
    }
    function musicPlaySong(idx, seekTime) {
        if (idx < 0 || idx >= musicSongs.length) return;
        musicIndex = idx;
        var s = musicSongs[idx];
        musicName.textContent = s.name;
        musicArtist.textContent = s.artist;
        if (s.cover) musicCover.src = s.cover;
        musicSongList.querySelectorAll('.music-item').forEach(function(el, i) {
            el.classList.toggle('active', i === idx);
        });
        var playUrl = s.url;
        if (playUrl) {
            musicStartPlay(playUrl, s, seekTime);
        } else if (musicPlatform === 'netease') {
            musicStartPlay('https://music.163.com/song/media/outer/url?id=' + s.id, s, seekTime);
        } else {
            // v4.7.4：QQ/酷狗歌单不预取播放地址 → 播放时懒解析直链（拿到新鲜有效的 CDN 地址）
            musicLoading.textContent = '解析播放地址...';
            fetch('music.php?platform=' + encodeURIComponent(musicPlatform) + '&songId=' + encodeURIComponent(s.id))
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d && d.url) { s.url = d.url; musicStartPlay(d.url, s, seekTime); }
                    else { musicLoading.textContent = '获取播放地址失败'; musicHandlePlayFail(); }
                })
                .catch(function() { musicLoading.textContent = '获取播放地址失败'; musicHandlePlayFail(); });
        }
        musicSaveState();
        var activeEl = musicSongList.querySelector('.music-item.active');
        if (activeEl) activeEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    function musicTryFallbackUrl(song, seekTime) {
        // v4.7.4：网易云走 xfyun blob 兜底；QQ/酷狗直链解析失败不再跨平台兜底，直接失败跳下一首
        if (musicPlatform !== 'netease') { musicHandlePlayFail(); return; }
        var fallbackUrl = 'https://api.xfyun.club/musicAll/?songId=' + song.id + '&mp3Url=mp3';
        fetch(fallbackUrl).then(function(res) {
            if (!res.ok) throw new Error('fallback failed');
            return res.blob();
        }).then(function(blob) {
            var blobUrl = URL.createObjectURL(blob);
            musicAudio.src = blobUrl;
            return musicAudio.play();
        }).then(function() {
            musicSetPlaying(true);
            musicConsecutiveFails = 0;
            if (seekTime > 0) musicAudio.currentTime = seekTime;
        }).catch(function() { musicHandlePlayFail(); });
    }
    function musicHandlePlayFail() {
        musicConsecutiveFails++;
        musicSetPlaying(false);
        if (musicConsecutiveFails >= 5) {
            musicConsecutiveFails = 0;
            return;
        }
        if (musicSongs.length > 1) {
            setTimeout(function() { musicPlaySong((musicIndex + 1) % musicSongs.length); }, 500);
        }
    }
    function musicSetPlaying(playing) {
        musicPlaying = playing;
        musicPlayIcon.innerHTML = playing
            ? '<path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>'
            : '<path d="M8 5v14l11-7z"/>';
        if (discRing) discRing.classList.toggle('spinning', playing);
        discNotes.forEach(function(n) { n.classList.toggle('paused', !playing); });
        // v4.6.2：播放中脉冲光环（统一播放状态视觉语言）
        if (musicPlay) musicPlay.classList.toggle('playing', playing);
    }
    musicPlay.addEventListener('click', function() {
        if (musicIndex === -1) { musicPlaySong(0); return; }
        if (musicPlaying) musicAudio.pause(); else musicAudio.play();
    });
    musicPrev.addEventListener('click', function() {
        if (!musicSongs.length) return;
        musicPlaySong((musicIndex - 1 + musicSongs.length) % musicSongs.length);
    });
    musicNext.addEventListener('click', function() {
        if (!musicSongs.length) return;
        musicPlaySong((musicIndex + 1) % musicSongs.length);
    });
    function musicUpdateModeIcon() {
        var icons = [
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/><text x="12" y="15" text-anchor="middle" font-size="8" fill="currentColor" stroke="none">1</text></svg>',
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>'
        ];
        var titles = ['顺序播放', '列表循环', '单曲循环'];
        if (musicModeBtn) {
            musicModeBtn.innerHTML = icons[musicLoopMode] || icons[0];
            musicModeBtn.title = titles[musicLoopMode] || titles[0];
            musicModeBtn.classList.toggle('active', musicLoopMode > 0);
        }
    }
    if (musicModeBtn) musicModeBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        musicLoopMode = (musicLoopMode + 1) % 3;
        musicUpdateModeIcon();
        musicSaveState();
    });
    musicUpdateModeIcon();
    musicAudio.addEventListener('play', function() {
        // v4.7.4：播放器音乐与背景音乐互斥——播放器开播时关闭背景音乐
        if (typeof bgmSetOn === 'function') bgmSetOn(false);
        musicSetPlaying(true); musicStartWordAnim();
    });
    musicAudio.addEventListener('pause', function() { musicSetPlaying(false); musicStopWordAnim(); musicSaveState(); });
    musicAudio.addEventListener('ended', function() {
        if (musicLoopMode === 2) {
            musicPlaySong(musicIndex);
        } else if (musicLoopMode === 1) {
            musicPlaySong((musicIndex + 1) % musicSongs.length);
        } else {
            if (musicIndex < musicSongs.length - 1) musicPlaySong(musicIndex + 1);
            else musicSetPlaying(false);
        }
    });
    var musicLyrMode = false;  
    var musicLyrLines = [];    
    var musicLyrLoadedId = -1; 
    var musicLyrUserScroll = false; 
    var musicLyrScrollTimer = null; 
    var musicLyrWordMode = false; 
    // v4.6.2：歌词显示开关由「词」文字按钮改为滑块（checkbox change；滑过来=显示歌词、滑过去=隐藏）
    if (musicLyrToggle) musicLyrToggle.addEventListener('change', function() {
        musicLyrMode = !!musicLyrToggle.checked;
        if (musicLyrMode) {
            musicPlayerMain.style.display = 'none';
            musicLyrPanel.classList.add('active');
            if (musicIndex >= 0 && musicIndex < musicSongs.length) {
                var s = musicSongs[musicIndex];
                if (musicLyrLoadedId !== s.id) musicLoadLyric(s.id);
            }
        } else {
            musicPlayerMain.style.display = '';
            musicLyrPanel.classList.remove('active');
        }
    });
    function musicLoadLyric(songId) {
        musicLyrScroll.innerHTML = '<div class="music-lyric-hint">加载中...</div>';
        musicLyrLines = [];
        musicLyrLoadedId = songId;
        fetch('music.php?platform=' + musicPlatform + '&lyric=' + songId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    musicLyrScroll.innerHTML = '<div class="music-lyric-hint">暂无歌词</div>';
                    return;
                }
                if (data.yrc) {
                    musicLyrLines = musicParseYrc(data.yrc);
                }
                if (!musicLyrLines.length && data.lrc) {
                    musicLyrLines = musicParseLrc(data.lrc);
                }
                if (musicLyrLines.length) {
                    if (data.tlrc) {
                        var tlrcLines = musicParseLrc(data.tlrc);
                        musicMergeTranslation(musicLyrLines, tlrcLines);
                    }
                    musicRenderLyric();
                    if (musicLyrWordMode && !musicAudio.paused) musicStartWordAnim();
                } else {
                    musicLyrScroll.innerHTML = '<div class="music-lyric-hint">暂无歌词</div>';
                }
            })
            .catch(function() {
                musicLyrScroll.innerHTML = '<div class="music-lyric-hint">歌词加载失败</div>';
            });
    }
    function musicParseYrc(yrc) {
        var lines = yrc.split('\n');
        var result = [];
        var lineRe = /^\[(\d+),(\d+)\](.*)/;
        var wordRe = /\((\d+),(\d+),\d+\)([^\(]*)/g;
        for (var i = 0; i < lines.length; i++) {
            var m = lines[i].match(lineRe);
            if (!m) continue;
            var lineStart = parseInt(m[1]) / 1000;
            var lineDur = parseInt(m[2]) / 1000;
            var raw = m[3];
            if (!raw.trim()) continue;
            var words = [];
            var wm;
            while ((wm = wordRe.exec(raw)) !== null) {
                var wText = wm[3];
                if (wText) words.push({
                    start: parseInt(wm[1]) / 1000,
                    dur: parseInt(wm[2]) / 1000,
                    text: wText
                });
            }
            if (words.length > 0) {
                musicLyrWordMode = true;
                var txt = words.map(function(w) { return w.text; }).join('');
                result.push({ time: lineStart, text: txt, words: words });
            } else {
            }
        }
        result.sort(function(a, b) { return a.time - b.time; });
        return result;
    }
    function musicMergeTranslation(main, trans) {
        var tIdx = 0;
        for (var i = 0; i < main.length && tIdx < trans.length; i++) {
            while (tIdx < trans.length - 1 && Math.abs(trans[tIdx].time - main[i].time) > Math.abs(trans[tIdx + 1].time - main[i].time)) {
                tIdx++;
            }
            if (Math.abs(trans[tIdx].time - main[i].time) < 2 && trans[tIdx].text) {
                main[i].trans = trans[tIdx].text;
                tIdx++;
            }
        }
    }
    function musicParseLrc(lrc) {
        var lines = lrc.split('\n');
        var result = [];
        var re = /\[(\d{2}):(\d{2})\.?(\d{0,3})\](.*)/;
        var wordRe = /<(\d+),(\d+),\d+>([^<]*)/g;
        for (var i = 0; i < lines.length; i++) {
            var m = lines[i].match(re);
            if (m) {
                var ms = parseInt(m[3] || '0');
                if (m[3].length === 2) ms *= 10;
                if (m[3].length === 1) ms *= 100;
                var t = parseInt(m[1]) * 60 + parseInt(m[2]) + ms / 1000;
                var raw = m[4];
                var words = [];
                var wordMatch;
                var hasWordTags = false;
                while ((wordMatch = wordRe.exec(raw)) !== null) {
                    hasWordTags = true;
                    words.push({
                        start: parseInt(wordMatch[1]) / 1000,
                        dur: parseInt(wordMatch[2]) / 1000,
                        text: wordMatch[3]
                    });
                }
                if (hasWordTags && words.length > 0) {
                    musicLyrWordMode = true;
                    var txt = words.map(function(w) { return w.text; }).join('');
                    if (txt) result.push({ time: t, text: txt, words: words });
                } else {
                    var txt = raw.trim();
                    if (txt) result.push({ time: t, text: txt });
                }
            }
        }
        result.sort(function(a, b) { return a.time - b.time; });
        return result;
    }
    function musicRenderLyric() {
        if (!musicLyrLines.length) {
            musicLyrScroll.innerHTML = '<div class="music-lyric-hint">暂无歌词</div>';
            return;
        }
        var html = '';
        for (var i = 0; i < musicLyrLines.length; i++) {
            var line = musicLyrLines[i];
            var transHtml = line.trans ? '<div class="lyr-trans">' + escHtml(line.trans) + '</div>' : '';
            if (line.words && line.words.length > 0) {
                var inner = '';
                for (var j = 0; j < line.words.length; j++) {
                    inner += '<span class="lyr-word" data-start="' + line.words[j].start + '" data-dur="' + line.words[j].dur + '">' + escHtml(line.words[j].text) + '</span>';
                }
                html += '<div class="music-lyric-line" data-idx="' + i + '">' + inner + transHtml + '</div>';
            } else {
                html += '<div class="music-lyric-line" data-idx="' + i + '">' + escHtml(line.text) + transHtml + '</div>';
            }
        }
        musicLyrScroll.innerHTML = html;
        musicLyrScroll.querySelectorAll('.music-lyric-line').forEach(function(el) {
            el.addEventListener('click', function() {
                var idx = parseInt(this.dataset.idx);
                if (idx >= 0 && idx < musicLyrLines.length && musicAudio.duration) {
                    musicAudio.currentTime = musicLyrLines[idx].time;
                    musicLyrUserScroll = false; 
                    if (musicAudio.paused) musicAudio.play();
                }
            });
        });
    }
    function musicSetAllWordsGray(lineEl) {
        var spans = lineEl.querySelectorAll('.lyr-word');
        for (var i = 0; i < spans.length; i++) {
            spans[i].classList.remove('sung', 'filling');
            spans[i].style.removeProperty('--fill-pct');
        }
    }
    var musicWordAnimFrame = null;
    function musicWordAnimLoop() {
        if (!musicLyrMode || !musicLyrWordMode || !musicLyrLines.length || musicAudio.paused) {
            musicWordAnimFrame = null;
            return;
        }
        var ct = musicAudio.currentTime || 0;
        var activeIdx = -1;
        for (var i = musicLyrLines.length - 1; i >= 0; i--) {
            if (ct >= musicLyrLines[i].time) { activeIdx = i; break; }
        }
        var lines = musicLyrScroll.querySelectorAll('.music-lyric-line');
        if (activeIdx >= 0 && activeIdx < lines.length && musicLyrLines[activeIdx].words) {
            var words = musicLyrLines[activeIdx].words;
            var spans = lines[activeIdx].querySelectorAll('.lyr-word');
            for (var i = 0; i < words.length; i++) {
                if (!spans[i]) continue;
                var w = words[i];
                var wordEnd = w.start + w.dur;
                if (ct >= wordEnd) {
                    spans[i].className = 'lyr-word sung';
                } else if (ct >= w.start) {
                    var pct = ((ct - w.start) / w.dur) * 100;
                    spans[i].className = 'lyr-word filling';
                    spans[i].style.setProperty('--fill-pct', pct + '%');
                } else {
                    spans[i].className = 'lyr-word';
                    spans[i].style.removeProperty('--fill-pct');
                }
            }
        }
        musicWordAnimFrame = requestAnimationFrame(musicWordAnimLoop);
    }
    function musicStartWordAnim() {
        if (!musicWordAnimFrame) musicWordAnimFrame = requestAnimationFrame(musicWordAnimLoop);
    }
    function musicStopWordAnim() {
        if (musicWordAnimFrame) { cancelAnimationFrame(musicWordAnimFrame); musicWordAnimFrame = null; }
    }
    if (musicLyrScroll) musicLyrScroll.addEventListener('scroll', function() {
        if (!musicLyrMode) return;
        musicLyrUserScroll = true;
        clearTimeout(musicLyrScrollTimer);
        musicLyrScrollTimer = setTimeout(function() { musicLyrUserScroll = false; }, 4000);
    });
    if (musicLyrScroll) {
        musicLyrScroll.addEventListener('touchstart', function() {
            if (!musicLyrMode) return;
            musicLyrUserScroll = true;
            clearTimeout(musicLyrScrollTimer);
        }, { passive: true });
        musicLyrScroll.addEventListener('touchend', function() {
            if (!musicLyrMode) return;
            clearTimeout(musicLyrScrollTimer);
            musicLyrScrollTimer = setTimeout(function() { musicLyrUserScroll = false; }, 4000);
        });
    }
    function musicUpdateLyricScroll() {
        if (!musicLyrMode || !musicLyrLines.length) return;
        var ct = musicAudio.currentTime || 0;
        var activeIdx = -1;
        for (var i = musicLyrLines.length - 1; i >= 0; i--) {
            if (ct >= musicLyrLines[i].time) { activeIdx = i; break; }
        }
        var lines = musicLyrScroll.querySelectorAll('.music-lyric-line');
        for (var i = 0; i < lines.length; i++) {
            var isActive = i === activeIdx;
            if (isActive !== lines[i].classList.contains('active')) {
                lines[i].classList.toggle('active', isActive);
            }
            if (musicLyrWordMode && musicLyrLines[i].words && i !== activeIdx) {
                musicSetAllWordsGray(lines[i]);
            }
        }
        if (!musicLyrUserScroll && activeIdx >= 0 && lines[activeIdx]) {
            var container = musicLyrScroll;
            var el = lines[activeIdx];
            var top = el.offsetTop - container.offsetHeight / 2 + el.offsetHeight / 2;
            container.scrollTop = Math.max(0, top);
        }
    }
    musicAudio.addEventListener('timeupdate', musicUpdateLyricScroll);
    var _origPlaySong = musicPlaySong;
    musicPlaySong = function(idx, seekTime) {
        musicLyrLoadedId = -1; 
        musicLyrWordMode = false;
        if (musicLyrMode) {
            musicLyrScroll.scrollTop = 0; 
            musicLyrScroll.innerHTML = '<div class="music-lyric-hint">加载中...</div>';
        }
        _origPlaySong(idx, seekTime);
        if (musicLyrMode && idx >= 0 && idx < musicSongs.length) {
            musicLoadLyric(musicSongs[idx].id);
        }
    };
    var musicConsecutiveFails = 0; 
    musicAudio.addEventListener('error', function() {
        if (musicSongs.length > 0 && musicIndex >= 0 && musicIndex < musicSongs.length) {
            var s = musicSongs[musicIndex];
            if (!s._fallbackTried) {
                s._fallbackTried = true;
                musicTryFallbackUrl(s, 0);
            } else {
                s._fallbackTried = false;
                musicConsecutiveFails++;
                if (musicConsecutiveFails >= 5) {
                    musicSetPlaying(false);
                    musicConsecutiveFails = 0;
                    return; 
                }
                if (musicSongs.length > 1) {
                    setTimeout(function() { musicPlaySong((musicIndex + 1) % musicSongs.length); }, 1000);
                }
            }
        }
    });
    musicAudio.addEventListener('timeupdate', function() {
        if (!musicAudio.duration) return;
        var pct = (musicAudio.currentTime / musicAudio.duration) * 100;
        musicProgressFill.style.width = pct + '%';
        if (musicProgressDot) musicProgressDot.style.left = pct + '%';
        musicCurTime.textContent = fmtTime(musicAudio.currentTime);
        musicTotalTime.textContent = fmtTime(musicAudio.duration);
        if (Math.floor(musicAudio.currentTime) % 5 === 0) musicSaveState();
    });
    (function() {
        var dragging = false;
        var wasPlaying = false;
        function seekTo(e) {
            if (!musicAudio.duration) return;
            var rect = musicProgressBar.getBoundingClientRect();
            var clientX = e.touches ? e.touches[0].clientX : e.clientX;
            var pct = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
            musicAudio.currentTime = pct * musicAudio.duration;
            musicProgressFill.style.width = (pct * 100) + '%';
            if (musicProgressDot) musicProgressDot.style.left = (pct * 100) + '%';
            musicCurTime.textContent = fmtTime(musicAudio.currentTime);
        }
        function onStart(e) {
            e.preventDefault();
            dragging = true;
            wasPlaying = !musicAudio.paused;
            if (wasPlaying) musicAudio.pause();
            musicProgressBar.classList.add('dragging');
            seekTo(e);
        }
        function onMove(e) {
            if (!dragging) return;
            e.preventDefault();
            seekTo(e);
        }
        function onEnd(e) {
            if (!dragging) return;
            dragging = false;
            musicProgressBar.classList.remove('dragging');
            if (wasPlaying) musicAudio.play();
        }
        musicProgressBar.addEventListener('mousedown', onStart);
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onEnd);
        musicProgressBar.addEventListener('touchstart', onStart, { passive: false });
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('touchend', onEnd);
        var origTimeUpdate = musicAudio.ontimeupdate;
        musicAudio.addEventListener('timeupdate', function() {
            if (dragging) return; 
        });
    })();
    var savedVol = musicLoadState();
    musicAudio.volume = (savedVol && typeof savedVol.volume === 'number') ? savedVol.volume : 0.8;
    function fmtTime(sec) {
        if (!sec || isNaN(sec)) return '0:00';
        var m = Math.floor(sec / 60);
        var s = Math.floor(sec % 60);
        return m + ':' + (s < 10 ? '0' : '') + s;
    }
    function escHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
    // ---- 用户按钮 + 下拉菜单 ----
    (function() {
        var btnUser = document.getElementById('btnUser');
        var dropdown = document.getElementById('userDropdown');
        var dropdownName = document.getElementById('userDropdownName');
        var dropdownRole = document.getElementById('userDropdownRole');
        var dropdownAvatar = document.getElementById('userDropdownAvatar');
        var dropdownLogin = document.getElementById('userDropdownLogin');
        var dropdownAdmin = document.getElementById('userDropdownAdmin');
        var dropdownDivider = document.getElementById('userDropdownDivider');
        var dropdownLogout = document.getElementById('userDropdownLogout');
        var dropdownProfile = document.getElementById('userDropdownProfile');
        var cmtAuthModal = document.getElementById('cmtAuthModal');
        var cmtAuthTitle = document.getElementById('cmtAuthTitle');
        var cmtLoginForm = document.getElementById('cmtLoginForm');
        var cmtRegForm = document.getElementById('cmtRegForm');
        var cmtAuthSlide = document.getElementById('cmtAuthSlide');
        var cmtSwitchText = document.getElementById('cmtSwitchText');
        var cmtSwitchBtn = document.getElementById('cmtSwitchBtn');
        if (!btnUser || !dropdown) return;
        var roleLabels = {
            'super_admin': '高级管理员',
            'station_admin': '站长',
            'author': '写作者',
            'user': '注册用户',
            'guest': '访客'
        };
        function openLoginModal() {
            if (cmtAuthModal && cmtAuthTitle && cmtLoginForm && cmtRegForm && cmtAuthSlide) {
                cmtAuthTitle.textContent = '登录';
                cmtLoginForm.style.display = 'flex';
                cmtRegForm.style.display = 'none';
                // v4.7.0：打开时隐藏设备验证与找回视图（上次会话残留清理）
                var dv = document.getElementById('cmtDevForm'); if (dv) dv.style.display = 'none';
                var rf = document.getElementById('cmtResetForm'); if (rf) rf.style.display = 'none';
                if (cmtSwitchText) cmtSwitchText.textContent = '还没有账号？';
                if (cmtSwitchBtn) cmtSwitchBtn.textContent = '立即注册';
                // v2.11.4：清理残留的切换动画状态（防止打开弹窗时表单仍处于 slide-out/in 中间态）
                cmtAuthSlide.classList.remove('slide-out');
                cmtAuthSlide.classList.remove('slide-in');
                cmtAuthModal.classList.add('show');
            }
        }
        function updateDropdown(userData) {
            if (userData) {
                var nickname = userData.nickname || '用户';
                var role = userData.role || 'user';
                // v2.6.5：超管在主页显示「超管」身份，但无登录/管理/退出入口（需到超管后台退出）
                var roleLabel = userData.isSuperAdmin ? '超管' : (roleLabels[role] || role);
                if (dropdownName) dropdownName.textContent = nickname;
                if (dropdownRole) dropdownRole.textContent = roleLabel;
                if (dropdownAvatar) {
                    var initial = nickname.charAt(0).toUpperCase();
                    // v2.11.1：API 返回的 qq 已打码，头像一律用 avatar 字段（不再用 qq 拼 URL）
                    if (userData.avatar) {
                        dropdownAvatar.innerHTML = '<img src="' + escHtml(userData.avatar) + '" alt="" onerror="this.style.display=\'none\';this.parentNode.textContent=\'' + escHtml(initial) + '\'">';
                    } else {
                        dropdownAvatar.textContent = initial;
                    }
                }
                if (dropdownLogin) dropdownLogin.style.display = 'none';
                if (userData.isSuperAdmin) {
                    // v2.10.2：超管在主页可退出登录（原先无退出入口，需回后台）；无「快捷进入管理」按钮（超管后台仅 SSH/OTP 入口）
                    if (dropdownDivider) dropdownDivider.style.display = 'block';
                    if (dropdownLogout) dropdownLogout.style.display = 'flex';
                    if (dropdownAdmin) dropdownAdmin.style.display = 'none';
                    // v2.11.3：超管隐身，不提供「编辑资料」入口（超管走 OTP/SSH 安全通道）
                    if (dropdownProfile) dropdownProfile.style.display = 'none';
                } else {
                    if (dropdownDivider) dropdownDivider.style.display = 'block';
                    if (dropdownLogout) dropdownLogout.style.display = 'flex';
                    // v2.11.3：普通用户/站长/写作者显示「编辑资料」入口
                    if (dropdownProfile) dropdownProfile.style.display = 'flex';
                    if (dropdownAdmin) {
                        dropdownAdmin.style.display = userData.canAccessAdmin ? 'flex' : 'none';
                    }
                }
            } else {
                if (dropdownName) dropdownName.textContent = '未登录';
                if (dropdownRole) dropdownRole.textContent = '访客';
                if (dropdownAvatar) dropdownAvatar.textContent = '?';
                if (dropdownLogin) dropdownLogin.style.display = 'flex';
                if (dropdownDivider) dropdownDivider.style.display = 'none';
                if (dropdownLogout) dropdownLogout.style.display = 'none';
                if (dropdownAdmin) dropdownAdmin.style.display = 'none';
                if (dropdownProfile) dropdownProfile.style.display = 'none';
            }
        }
        btnUser.addEventListener('click', function(e) {
            e.stopPropagation();
            if (dropdown.classList.contains('active')) {
                dropdown.classList.remove('active');
                return;
            }
            fetch('api.php?action=user-status')
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success && d.loggedIn) {
                        updateDropdown(d);
                    } else {
                        updateDropdown(null);
                    }
                    dropdown.classList.add('active');
                })
                .catch(function() {
                    updateDropdown(null);
                    dropdown.classList.add('active');
                });
        });
        if (dropdownLogin) {
            dropdownLogin.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdown.classList.remove('active');
                openLoginModal();
            });
        }
        if (dropdownAdmin) {
            dropdownAdmin.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdown.classList.remove('active');
                var adminUrl = this.dataset.url || '/admin/dashboard.php';
                fetch('api.php?action=user-status')
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d.success && d.loggedIn && d.canAccessAdmin && d.adminUrl) {
                            window.location.href = d.adminUrl;
                        } else {
                            showToast('权限不足，无法进入管理界面');
                        }
                    })
                    .catch(function() {
                        showToast('获取权限信息失败');
                    });
            });
        }
        if (dropdownLogout) {
            dropdownLogout.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdown.classList.remove('active');
                fetch('api.php?action=logout', { method: 'POST' }).catch(function() {});
                if (typeof cmtUser !== 'undefined') {
                    cmtUser = null;
                }
                if (typeof cmtUpdateUI === 'function') cmtUpdateUI();
                updateDropdown(null);
                showToast('已退出登录');
            });
        }
        // v2.11.3：用户下拉「编辑资料」→ 打开个人资料弹窗
        if (dropdownProfile) {
            dropdownProfile.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdown.classList.remove('active');
                if (typeof cmtOpenProfileModal === 'function') cmtOpenProfileModal();
            });
        }
        document.addEventListener('click', function() {
            dropdown.classList.remove('active');
        });
        // 点击用户按钮时不关闭
        dropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    })();
    init();
})();
