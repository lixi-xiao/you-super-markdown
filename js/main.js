(function() {
    // v4.5.0：环境指纹——hash(lang|时区|分辨率|canvas 像素|UA)，登录/评论/后台等登录态接口经 X-Fp 头携带；
    //         服务端与会话绑定指纹比对，换浏览器/设备/隐私模式即判定环境变化。访客浏览不受影响。
    //         注意：指纹必须在同一会话内稳定（同步生成，不引入异步哈希，避免首请求与后续请求指纹不一致）。
    var e = "";
    function ysmFnvHash(e) {
        var t = 2166136261;
        for (var n = 0; n < e.length; n++) {
            t ^= e.charCodeAt(n);
            t = Math.imul(t, 16777619);
        }
        return ("00000000" + (t >>> 0).toString(16)).slice(-8);
    }
    function ysmCanvasHash() {
        try {
            var e = document.createElement("canvas");
            e.width = 200;
            e.height = 40;
            var t = e.getContext("2d");
            t.textBaseline = "top";
            t.font = "14px Arial";
            t.fillStyle = "#f60";
            t.fillRect(0, 0, 200, 40);
            t.fillStyle = "#069";
            t.fillText("YouSuperMarkdown☠" + navigator.userAgent.length, 5, 12);
            var n = e.toDataURL();
            return n.length + ":" + n.slice(-64);
        } catch (e) {
            return "";
        }
    }
    function ysmGetFp() {
        if (e) return e;
        try {
            var t = [ navigator.language || "", (new Date).getTimezoneOffset(), (screen.width || 0) + "x" + (screen.height || 0), ysmCanvasHash(), navigator.userAgent ];
            e = ysmFnvHash(t.join("|")) + ysmFnvHash(t.join("~")) + ysmFnvHash(navigator.userAgent);
            return e;
        } catch (t) {
            e = ysmFnvHash("no-fp");
            return e;
        }
    }
    // 暴露给后台/OTP 入口页面使用（后台原生表单无自定义头，用上报校验模式）
        window.ysmGetFp = ysmGetFp;
    // v4.5.0：所有 api.php 请求统一携带 X-Fp（登录态接口服务端校验环境）
        (function ysmPatchFetch() {
        var e = window.fetch;
        if (!e) return;
        window.fetch = function(t, n) {
            n = n || {};
            var i = String(t);
            if (i.indexOf("api.php") !== -1) {
                n.headers = Object.assign({}, n.headers || {}, {
                    "X-Fp": ysmGetFp()
                });
            }
            return e.call(this, t, n);
        };
    })();
    (function applyBg() {
        var e = document.body;
        // v5.4.3：应用序号——晚到的旧 onload/亮度回调不得再改写对比色属性（时序安全 + 幂等）
        var bgSeq = 0;
        var t = e.dataset.bgType || "none";
        var n = e.dataset.bgImage || "";
        var i = e.dataset.bgApiUrl || "";
        var a = e.dataset.bgBlur === "1";
        var s = parseInt(e.dataset.bgBlurLevel) || 0;
        var o = e.dataset.bgCardOpacity !== undefined ? parseInt(e.dataset.bgCardOpacity) : 100;
        e.style.setProperty("--bg-card-opacity", o / 100);
        if (a && s > 0) {
            e.classList.add("bg-blur");
            e.style.setProperty("--bg-blur-level", s + "px");
        }
        // v4.6.2：背景图预加载——加载成功才启用背景（bg-active），失败则不加并输出控制台警告，
        //          避免「背景图 404/加载失败却仍加模糊类」导致视觉无变化且无任何提示
        // v4.7.2：对比色自适应——canvas 读取背景图平均亮度（同源上传图可读；跨域 API 图无 CORS 读失败降级不设属性）
                function computeBgLuma(e, t) {
            var n = new Image;
            n.crossOrigin = "anonymous";
            n.onload = function() {
                try {
                    var e = Math.max(1, Math.min(n.naturalWidth || 100, 64));
                    var i = Math.max(1, Math.min(n.naturalHeight || 100, 64));
                    var a = document.createElement("canvas");
                    a.width = e;
                    a.height = i;
                    var s = a.getContext("2d");
                    s.drawImage(n, 0, 0, e, i);
                    var o = s.getImageData(0, 0, e, i).data;
                    var c = 0, r = 0;
                    for (var l = 0; l < o.length; l += 4) {
                        c += .299 * o[l] + .587 * o[l + 1] + .114 * o[l + 2];
                        r++;
                    }
                    t(r > 0 ? c / r : -1);
                } catch (e) {
                    t(-1);
                }
            };
            n.onerror = function() {
                t(-1);
            };
            n.src = e;
        }
        function applyBgImage(t) {
            var mySeq = ++bgSeq;
            var n = new Image;
            n.onload = function() {
                if (mySeq !== bgSeq) return;
                e.classList.add("bg-active");
                e.style.setProperty("--bg-url", "url(" + t + ")");
                // v5.4.3：每次应用先复位对比色属性，确认亮度后再设——避免旧值残留。
                e.removeAttribute("data-text-contrast");
                // 背景暗（≤140）→ data-text-contrast=light 整页暗色白字；背景亮 → dark 黑色系；读取失败降级不设属性（保持浅色强制黑）
                computeBgLuma(t, function(t) {
                    if (mySeq !== bgSeq) return;
                    // v5.4.3：手动选过主题时，对比色自适应让位于用户选择（不覆盖）
                    if (t < 0 || ysmThemeIsManual()) {
                        e.removeAttribute("data-text-contrast");
                        return;
                    }
                    e.setAttribute("data-text-contrast", t <= 140 ? "light" : "dark");
                });
            };
            n.onerror = function() {
                console.warn("[applyBg] 背景图加载失败（已停用背景显示）:", t);
            };
            n.src = t;
        }
        if (t === "image" && n) {
            // v3.3.3：背景图相对路径补前导斜杠——CSS 变量里相对路径按 CSS 文件(css/)解析，
            // 导致 /css/data/bg/.. 404；统一转成根相对路径 /data/bg/..
            var c = n.indexOf("/") === 0 || /^https?:/i.test(n) ? n : "/" + n;
            applyBgImage(c);
        } else if (t === "api" && i) {
            var r = i.indexOf("/") === 0 || /^https?:/i.test(i) ? i : "/" + i;
            applyBgImage(r);
        }
        if (window.console && console.info) console.info("[applyBg] type=" + t + " blur=" + s + "px cardOpacity=" + o + "%");
    })();
    // v4.2.2：mermaid 按需加载——mermaid.min.js 达 3.3MB，页面默认不再放 <head> 阻塞首屏；
    // 仅当公告/文章正文出现 ```mermaid 代码块时才动态注入，加载完成后渲染。
    // v4.6.1：源由 jsdelivr CDN 改为本地 vendor/mermaid.min.js（大陆网络 CDN 不可达时流程图彻底失效）。
    // v4.7.14 重写：单次 mermaid.run() 批量渲染所有节点 + _ysmInited 防重复初始化 + 错误回退逻辑
    // 安全：mermaid SVG 由库自身做 XSS 过滤；fallback 文本经 escapeHTML 转义
    // 约束：不耦合 hljs（hljs 在 loadFile / 公告中独立同步调用，两者互不依赖）
    function ensureMermaid(e) {
        if (window.mermaid) {
            e();
            return;
        }
        var t = document.createElement("script");
        t.src = "vendor/mermaid.min.js";
        t.onload = e;
        t.onerror = function() {};
        document.head.appendChild(t);
    }
    // v5.2.3：mermaid 渲染稳健化（用户实测「QQ 浏览器里流程图显示歪 / 被拉伸」）
    //   根因（代码证据）：vendor/mermaid.min.js 的 y9() 用 node().getBBox() 实时测量后计算 viewBox，
    //     Ejt()/Og() 再给 <svg> 设 width:100% + style="max-width:Npx"（useMaxWidth=true 时不写 height，
    //     宽高比完全由 viewBox 决定）。若在节点不可见（display:none）或宽度为 0 时 run()，getBBox() 全为 0，
    //     viewBox 退化成"仅剩 diagramPadding 的极小方框"，再被 width:100% 拉伸到容器宽 → 图形变形/拉伸。
    //     本仓库中的典型场景：openAnnounceModal 在 .ann-modal-overlay 加 .active（变可见）之前就调用了本函数，
    //     此时公告弹窗容器宽度为 0；文章路径同样缺少"可见/宽度"前置校验。
    //   修法：① 渲染前等到节点已挂载且 offsetWidth>0（rAF 轮询，约 2s 兜底）；
    //         ② 渲染后做尺寸自检（viewBox 宽/高为 0 或 svg 宽度为 0 → 复位源码重渲染一次）；
    //         ③ initialize 显式配置（useMaxWidth / fontFamily / theme 跟随深浅色）。
    function ysmMermaidTheme() {
        return document.documentElement.getAttribute("data-theme") === "dark" ? "dark" : "default";
    }
    function ysmMermaidInit() {
        var e = ysmMermaidTheme();
        if (window.__ysmMermaidInited && window.__ysmMermaidTheme === e) return;
        mermaid.initialize({
            startOnLoad: false,
            // useMaxWidth 缺省即为 true，这里显式写出，避免库升级改变默认行为
            useMaxWidth: true,
            theme: e,
            // 与站点正文字体栈一致，避免"测量用 A 字体、渲染用 B 字体"导致标签越界
            fontFamily: '"ChineseFont","Inter",-apple-system,"PingFang SC","Microsoft YaHei",sans-serif',
            securityLevel: "strict",
            flowchart: { useMaxWidth: true },
            sequence: { useMaxWidth: true },
            gantt: { useMaxWidth: true }
        });
        window.__ysmMermaidInited = true;
        window.__ysmMermaidTheme = e;
    }
    // 等到节点"已挂载 + 有实际渲染宽度"再回调；最长约 2s 兜底，避免节点始终不可见时死等
    function ysmWhenVisible(e, t) {
        var n = 0;
        var i = function() {
            if (!e.isConnected || e.offsetWidth > 0) return t();
            if (++n > 120) return t();
            requestAnimationFrame(i);
        };
        i();
    }
    // SVG 尺寸自检：viewBox 缺失 / 宽高任一为 0 / svg 实际宽度为 0 → 判为无效渲染
    function ysmMermaidSvgOk(e) {
        if (!e) return false;
        var t = e.getAttribute("viewBox");
        if (!t) return false;
        var n = t.split(/[\s,]+/).map(Number);
        if (n.length !== 4 || !(n[2] > 0) || !(n[3] > 0)) return false;
        return e.getBoundingClientRect().width > 0;
    }
    // 单节点渲染：等可见 → run → 尺寸自检；异常则复位源码重渲染一次，仍异常才回退源码展示
    async function ysmMermaidRender(e) {
        // v5.2.4：记住原始源码（挂在元素属性上）——主题切换需要按新主题重渲染，
        //         而渲染后 textContent 已被替换成 SVG，无法再取回源码。
        var t = e.__ysmSrc != null ? e.__ysmSrc : e.textContent;
        e.__ysmSrc = t;
        await new Promise(function(e2) {
            ysmWhenVisible(e, e2);
        });
        await mermaid.run({
            nodes: [ e ]
        });
        var n = e.querySelector("svg");
        if (!n || n.textContent.indexOf("Syntax error") >= 0) {
            e.innerHTML = '<pre style="overflow:auto">' + escapeHTML(t) + "</pre>";
            return;
        }
        if (!ysmMermaidSvgOk(n)) {
            e.removeAttribute("data-processed");
            e.textContent = t;
            await new Promise(function(e2) {
                requestAnimationFrame(function() {
                    e2();
                });
            });
            await mermaid.run({
                nodes: [ e ]
            });
            n = e.querySelector("svg");
            if (!n || n.textContent.indexOf("Syntax error") >= 0) {
                e.innerHTML = '<pre style="overflow:auto">' + escapeHTML(t) + "</pre>";
            }
        }
    }
    function renderMermaidBlocks(e, n) {
        n = n || function() {};
        try {
            var i = e.querySelectorAll("pre code.language-mermaid");
            if (!i.length) {
                n();
                return;
            }
            ensureMermaid(function() {
                (async function() {
                    try {
                        // 1. pre code.language-mermaid → div.mermaid（保留纯文本）
                        i.forEach(function(e) {
                            var t = e.parentElement;
                            if (!t) return;
                            var n = document.createElement("div");
                            n.className = "mermaid";
                            n.textContent = e.textContent;
                            t.replaceWith(n);
                        });
                        // 2. 初始化 mermaid（v5.2.3：显式配置；深浅色变化时重新初始化）
                        ysmMermaidInit();
                        // 2b. 等字体就绪——mermaid 用实时测量决定标签尺寸，webfont 未就绪会测量偏差
                        if (document.fonts && document.fonts.ready) {
                            await Promise.race([ document.fonts.ready, new Promise(function(e2) {
                                setTimeout(e2, 1500);
                            }) ]);
                        }
                        // 3. 串行逐个渲染（避免并发 run() 导致 mermaid 内部状态混乱）
                        var a = e.querySelectorAll(".mermaid");
                        for (var s = 0; s < a.length; s++) {
                            var o = a[s];
                            var c = o.textContent;
                            try {
                                await ysmMermaidRender(o);
                            } catch (err) {
                                o.innerHTML = '<pre style="overflow:auto">' + escapeHTML(c) + "</pre>";
                            }
                        }
                    } catch (e) {}
                })().then(n).catch(function() {
                    n();
                });
            });
        } catch (e) {
            n();
        }
    }
    // v5.2.4：统一主题变更钩子——主题切换时主动"重应用"不会随 data-theme 自动跟随的部分。
    //   根因（用户实测「切回浅色后部分组件仍是暗色」）：mermaid 只在渲染当刻读取主题生成 SVG，
    //   节点 fill / 连线 stroke / 文字色都写成内联属性；data-theme 变化不会让它自动重渲染 →
    //   流程图停留在"上一次渲染"的配色（浅→暗残留浅色图、暗→浅残留暗色图，双向都会残留）。
    //   修法：主题变化时，用事先保存的源码按当前主题重渲染（复位 data-processed + textContent，
    //   避免新旧 SVG 叠加/泄漏）；连续切换用 Promise 链串行，杜绝并发 mermaid.run 内部状态错乱。
    var __ysmMermaidRerenderSeq = Promise.resolve();
    async function ysmMermaidRerenderOnce() {
        if (!window.mermaid || typeof mermaid.run !== "function") return;
        var list = document.querySelectorAll(".mermaid");
        if (!list.length) return;
        var theme = ysmMermaidTheme();
        ysmMermaidInit(); // 新主题下重新 initialize（内部对"主题未变"有短路）
        for (var i = 0; i < list.length; i++) {
            if (theme !== ysmMermaidTheme()) return; // 渲染期间主题又变 → 交给最新一次接管
            var el = list[i];
            // 不可见（如公告弹窗未打开、容器宽度 0）时跳过：避免在宽度 0 下重渲染导致 viewBox 退化，
            // 守住 5.2.3「流程图不变形」；该节点下次可见时（弹窗重开）会按当前主题重新渲染。
            if (!el.isConnected || el.offsetWidth === 0) continue;
            var src = el.__ysmSrc != null ? el.__ysmSrc : el.textContent;
            if (src == null) continue;
            el.__ysmSrc = src;
            el.removeAttribute("data-processed");
            el.textContent = src; // 复位源码，清掉上一主题的 SVG
            try {
                await ysmMermaidRender(el);
            } catch (err) {
                el.innerHTML = '<pre style="overflow:auto">' + escapeHTML(src) + "</pre>";
            }
        }
    }
    function ysmMermaidRerender() {
        __ysmMermaidRerenderSeq = __ysmMermaidRerenderSeq.then(ysmMermaidRerenderOnce).catch(function() {});
        return __ysmMermaidRerenderSeq;
    }
    // hljs 主题样式表：项目仅内置浅色 vendor 表，深色配色由 style.css 的 [data-theme="dark"] .hljs-* 提供。
    //   深色下停用浅色表（浅色下启用），彻底避免浅色 token 颜色在 dark 下残留——即"链接的正确切换"。
    function ysmApplyHljsTheme(t) {
        var l = document.getElementById("hljsTheme");
        if (!l) return;
        var off = t === "dark";
        if (l.disabled !== off) l.disabled = off;
    }
    // v5.4.3：是否「用户手动选过主题」——localStorage.md-theme 存在 / sessionStorage.md-theme-manual 标记即视为手动
    function ysmThemeIsManual() {
        try {
            if (sessionStorage.getItem("md-theme-manual")) return true;
        } catch (e) {}
        try {
            if (localStorage.getItem("md-theme")) return true;
        } catch (e) {}
        return false;
    }
    // v5.4.3：手动主题优先——把「对比色自适应」与「手动主题」的优先级收口到 html[data-theme-manual]：
    //   用户手动选过主题就不再让背景图亮度改写 body 级变量（浅色时移除 data-text-contrast，彻底避免"切不动/刷新才恢复"）。
    function ysmSyncTextContrast() {
        var de = document.documentElement;
        if (!document.body) return;
        if (ysmThemeIsManual()) {
            de.setAttribute("data-theme-manual", "1");
            if (de.getAttribute("data-theme") !== "dark") document.body.removeAttribute("data-text-contrast");
        } else {
            de.removeAttribute("data-theme-manual");
        }
    }
    // v5.2.4：主题唯一入口——点击切换 / 跟随系统 / 初始加载 全部经此收口，保证双向一致
    // v5.4.3：一并同步「手动主题 / 对比色自适应」优先级，保证切主题后立即一致、刷新前后一致
    function ysmApplyTheme(t) {
        if (document.documentElement.getAttribute("data-theme") !== t) {
            document.documentElement.setAttribute("data-theme", t);
        }
        ysmSyncTextContrast();
        ysmApplyHljsTheme(t);
        ysmMermaidRerender();
    }
    // v4.9.0：数学公式渲染（MathJax 3 本地化 tex-chtml.js，约 1.1MB）——阅读页按需加载，仅排版文章正文容器
    // 语法：$$ 块级 / $ 行内 / \(...\)、\[...\]（与 Typora/GitHub 主流一致），\$ 转义输出字面美元
    // 节流：正文不含公式（$$、\( \[ 或成对 $…$）时完全不加载脚本——无公式文章零额外流量
    // 安全：渲染完全在浏览器端、仅针对正文容器执行；代码块/行内代码由 skipHtmlTags(pre/code) 跳过不会误渲染；
    //       公式语法错误由 MathJax 以原文形式展示，不执行任何脚本，服务端无任何解析面
    // 约束：块级 $$…$$ 段落内勿含空行（marked 会把空行拆为两个 <p>，MathJax 无法跨节点配对）
        function ensureMathJax(e) {
        e = e || function() {};
        if (!window.MathJax) {
            window.MathJax = {
                tex: {
                    inlineMath: [ [ "\\(", "\\)" ], [ "$", "$" ] ],
                    displayMath: [ [ "$$", "$$" ], [ "\\[", "\\]" ] ],
                    processEscapes: true
                },
                options: {
                    skipHtmlTags: [ "script", "noscript", "style", "textarea", "pre", "code", "annotation", "annotation-xml" ]
                },
                startup: {
                    typeset: false
                }
            };
        }
        var onReady = function() {
            (MathJax.startup.promise || Promise.resolve()).then(e).catch(e);
        };
        // v4.9.1-fix：判据必须检测引擎真实就绪（typesetPromise 存在），而非 window.MathJax.startup 键——
        //   配置对象自身也含 startup 键（{typeset:false}），原判据导致首次调用误判"已加载"提前 return，脚本永不追加
                if (window.MathJax && typeof MathJax.typesetPromise === "function") {
            onReady();
            return;
        }
        var t = document.createElement("script");
        t.src = "vendor/mathjax/es5/tex-chtml.js";
        t.onload = onReady;
        t.onerror = function() {
            console.error("[mathjax] 公式引擎加载失败，公式将按原文显示");
        };
        document.head.appendChild(t);
    }
    function renderMathBlocks(e) {
        // v5.2.1：长文分段排版——顶层块 > 40 时按批 typeset 并在批间让出主线程，
        //         避免"整容器一次性 typeset"在公式密集长文中长时间阻塞；公式不跨顶层块，分段等价安全
        if (!(window.MathJax && MathJax.typesetPromise)) return;
        try {
            var t = e.children, n = t.length;
            if (n <= 40) {
                MathJax.typesetPromise([ e ]).catch(function(e) {
                    console.error("[mathjax] 排版出错（已保留原文）", e);
                });
                return;
            }
            var i = [], a = 0;
            for (; a < n; a++) i.push(t[a]);
            var s = 0, o = 40;
            var c = function() {
                if (s >= i.length) return;
                var e = i.slice(s, s + o);
                s += o;
                MathJax.typesetPromise(e).catch(function(e) {
                    console.error("[mathjax] 排版出错（已保留原文）", e);
                }).then(function() {
                    whenIdle(c);
                });
            };
            c();
        } catch (t) {
            console.error("[mathjax] 排版出错（已保留原文）", t);
        }
    }
    // v5.2.1：空闲调度——优先 requestIdleCallback（300ms 超时兜底），无则退化为 setTimeout
        function whenIdle(e) {
        if (typeof window.requestIdleCallback === "function") {
            window.requestIdleCallback(function() {
                e();
            }, {
                timeout: 300
            });
        } else {
            window.setTimeout(e, 1);
        }
    }
    // v5.2.1：代码高亮分片——长文大量代码块不再"首帧一次性同步高亮"；分片 + 空闲调度，全部完成后回调（加复制按钮）
        function highlightCodeBlocks(e, t) {
        t = t || function() {};
        if (typeof hljs === "undefined") {
            t();
            return;
        }
        var n = e.querySelectorAll("pre code");
        if (!n.length) {
            t();
            return;
        }
        var i = [], a = 0;
        for (; a < n.length; a++) i.push(n[a]);
        var s = 0, o = 12;
        var c = function() {
            if (s >= i.length) {
                t();
                return;
            }
            var e = Math.min(s + o, i.length);
            for (; s < e; s++) {
                try {
                    hljs.highlightElement(i[s]);
                } catch (e) {}
            }
            if (s < i.length) whenIdle(c); else t();
        };
        whenIdle(c);
    }
    function hasMathInMd(e) {
        // 保守探测公式定界符：$$ 块、LaTeX 原生 \[ \(、成对行内 $…$
        return /\$\$|\\\[|\\\(|\$[^$\n]*\$/.test(e || "");
    }
    // v4.9.2-fix：保护 LaTeX 原生括号定界符不被 CommonMark 转义剥掉——
    //   CommonMark 把 `\(` `\[` 视为"转义括号"，渲染时会删除反斜杠（\(a+b\) → (a+b)），
    //   导致 MathJax 的 inlineMath/displayMath 永远匹配不到 \(...\) / \[...\]（踩坑 #44 同源问题的另一半）。
    //   做法：marked 渲染前，把正文（跳过围栏代码块）中的 \( \) \[ \] 替换为反斜杠的 HTML 实体 &#92;，
    //   marked 输出实体、浏览器解析文本节点后仍是字面 `\(`，MathJax 即可正常配对。
    //   副作用说明：普通正文里原本想"转义括号"的作者会看到字面 `\(`（本平台定位公式优先，属预期）。
        function protectLatexDelims(e) {
        var t = [], n = false;
        var i = String(e).split("\n");
        for (var a = 0; a < i.length; a++) {
            var s = i[a];
            if (/^\s*```/.test(s)) {
                n = !n;
                t.push(s);
                continue;
            }
            if (!n) {
                s = s.split("\\(").join("&#92;(").split("\\)").join("&#92;)").split("\\[").join("&#92;[").split("\\]").join("&#92;]");
            }
            t.push(s);
        }
        return t.join("\n");
    }
    const n = document.getElementById("topBar");
    const i = document.getElementById("btnSearch");
    const a = document.getElementById("btnToc");
    const s = document.getElementById("btnFont");
    const o = document.getElementById("btnThemeToggle");
    // v4.6.2：主题跟随系统状态——手动切换后停止跟随（否则系统深色模式下 matchMedia 监听会把刚切走的主题立刻改回）
        let c = null, r = false, l = null;
    const d = document.getElementById("btnColor");
    const m = document.getElementById("searchPanel");
    const u = document.getElementById("tocPanel");
    const p = document.getElementById("fontPanel");
    const f = document.getElementById("colorPanel");
    const v = document.getElementById("searchInput");
    const y = document.getElementById("searchResults");
    const g = document.getElementById("tocFileList");
    const h = document.getElementById("homeView");
    const x = document.getElementById("cardsGrid");
    const L = document.getElementById("archiveView");
    const E = document.getElementById("emptyHome");
    const b = document.getElementById("readingView");
    const w = document.getElementById("markdownBody");
    const k = document.getElementById("floatingButtons");
    const S = document.getElementById("floatTocBtn");
    const T = document.getElementById("scrollToTopBtn");
    const C = document.getElementById("floatHomeBtn");
    const I = document.getElementById("tocPopup");
    const B = document.getElementById("tocPopupList");
    const M = document.getElementById("floatMusicBtn");
    const H = document.getElementById("musicPopup");
    const A = document.getElementById("musicList");
    const P = document.getElementById("musicLoading");
    const R = document.getElementById("musicCover");
    const O = document.getElementById("musicName");
    const U = document.getElementById("musicArtist");
    const F = document.getElementById("musicPlay");
    const j = document.getElementById("musicPlayIcon");
    const N = document.getElementById("musicPrev");
    const q = document.getElementById("musicNext");
    const _ = document.getElementById("musicLyrToggle");
    const $ = document.getElementById("musicLyrPanel");
    const z = document.getElementById("musicLyrScroll");
    const D = document.getElementById("musicPlayerMain");
    const V = document.getElementById("musicProgressBar");
    const Y = document.getElementById("musicProgressFill");
    const J = document.getElementById("musicProgressDot");
    const W = document.getElementById("musicCurTime");
    const Q = document.getElementById("musicTotalTime");
    const G = document.getElementById("musicPopupCount");
    const X = document.getElementById("musicAudio");
    const K = document.getElementById("musicListToggle");
    const Z = document.getElementById("musicModeBtn");
    const ee = document.querySelector(".disc-ring");
    const te = document.querySelectorAll(".disc-note");
    const ne = document.getElementById("hueSlider");
    const ie = document.querySelectorAll(".font-type-btn");
    const ae = document.getElementById("fontSizeSlider");
    const se = document.getElementById("fontSizeValue");
    const oe = document.getElementById("tocPanelHeader");
    const ce = document.getElementById("shareModalOverlay");
    const re = document.getElementById("shareQrcode");
    const le = document.getElementById("shareModalClose");
    const de = document.getElementById("readingProgress");
    const me = document.getElementById("readingProgressText");
    const ue = document.getElementById("toast");
    const pe = document.getElementById("imgLightbox");
    const fe = document.getElementById("lightboxImg");
    const ve = document.getElementById("sidebar");
    const ye = document.getElementById("sidebarFileList");
    const ge = document.getElementById("sidebarTocList");
    const he = document.getElementById("sidebarTocHeader");
    const xe = document.getElementById("sidebarSearchInput");
    const Le = document.getElementById("sidebarCount");
    const Ee = document.getElementById("sidebarBackBtn");
    const be = document.getElementById("sidebarArticleTitle");
    const we = document.getElementById("prevNextNav");
    const ke = document.getElementById("prevBtn");
    const Se = document.getElementById("nextBtn");
    const Te = document.getElementById("prevTitle");
    const Ce = document.getElementById("nextTitle");
    let Ie = [];
    let Be = "";
    // v5.2.1：标签改为多选（数组），过滤语义为 AND（同时命中全部已选标签）
        let Me = [];
 // v4.0.0：标签聚合过滤
        let He = false;
 // v4.0.0：归档视图开关
        let Ae = false;
    // v5.2.2：「热度优先排序」开关 —— 默认 false＝保持「最新优先」（与既有 list 顺序一致，排序契约不变）。
    // 如需改为热度优先：只把下面这一行改成 true 即可（卡片按 views30 优先、views 兜底降序展示）。
    const YSM_SORT_BY_HEAT = false;
    let Pe = [];
    let Re = -1;
    let Oe = 0;
    let Ue = -1;
    let Fe = "";
    // v5.4.3：请求序号 + AbortController 守卫——弱网下快速切换文章/搜索时，
    //         迟到的旧响应不得再覆盖新视图（修复「点 A 却显示 B / 前一篇」）。
    let loadSeq = 0;
    let loadCtrl = null;
    let searchSeq = 0;
    let searchCtrl = null;
    let sidebarSearchSeq = 0;
    let sidebarSearchCtrl = null;
    let listSeq = 0;
    let listCtrl = null;
    function escapeHTML(e) {
        const t = document.createElement("div");
        t.textContent = e;
        return t.innerHTML;
    }
    // v4.4.1：标题锚点 slug 归一化——去除中文顿号/括号/全角标点与英文标点、空白转连字符、小写，
    //         使渲染标题 id 与正文手写锚点（[目录](#一项目概述)）一致；模糊匹配时也用它比对。
    // v4.6.1：v4.5.0 误删本函数定义（调用处保留），导致 marked 渲染/锚点处理全部 ReferenceError，
    //         所有文章点击后报「文档不存在」——补回 v4.4.1 原始定义。
        function ysmSlug(e) {
        return String(e || "").toLowerCase().trim().replace(/<[^>]*>/g, "").replace(/[\u3000-\u303f\u2000-\u206f\u2e00-\u2e7f\\'"!@#$%^&*()+,./:;<=>?[\]{}`~|·「」『』〈〉《》【】（）]/g, "").replace(/\s+/g, "-").replace(/-+/g, "-").replace(/^-|-$/g, "");
    }
    let je = (document.querySelector('meta[name="csrf-token"]') || {}).content || "";
    // v2.11.1：动态获取 CSRF token（修复「会话失败」根因——登录/注册等匿名 POST 前调用，
    // 同一请求链内 cookie 与 token 必然同 session；浏览器未携带 PHPSESSID/session 过期也能自愈）
        function ensureFreshCsrf() {
        return fetch("api.php?action=csrf").then(e => e.json()).then(e => {
            if (e.success && e.csrf_token) je = e.csrf_token;
            return e.success ? e.csrf_token : je;
        }).catch(() => je);
    }
    (function() {
        const e = window.fetch.bind(window);
        window.fetch = function(t, n) {
            n = n || {};
            if (typeof t === "string" && (t.indexOf("api.php") !== -1 || t.indexOf("index.php") !== -1) && String(n.method || "GET").toUpperCase() === "POST") {
                const e = n.headers || {};
                if (e instanceof Headers) {
                    e.set("X-CSRF-Token", je);
                } else if (Array.isArray(e)) {
                    e.push([ "X-CSRF-Token", je ]);
                } else {
                    n.headers = Object.assign({}, e, {
                        "X-CSRF-Token": je
                    });
                }
            }
            return e(t, n);
        };
    })();
    function setAccentHue(e) {
        document.documentElement.style.setProperty("--accent-hue", e);
        localStorage.setItem("md-reader-hue", e);
    }
    const Ne = localStorage.getItem("md-reader-hue") || 220;
    setAccentHue(Ne);
    ne.value = Ne;
    ne.addEventListener("input", () => setAccentHue(ne.value));
    const qe = document.getElementById("colorResetBtn");
    if (qe) qe.addEventListener("click", () => {
        setAccentHue(220);
        ne.value = 220;
    });
    function applyFontType(e) {
        if (e === "default") {
            document.body.style.fontFamily = '-apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif';
        } else {
            document.body.style.fontFamily = "'ChineseFont', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif";
        }
        localStorage.setItem("md-font-type", e);
    }
    function applyFontSize(e) {
        document.documentElement.style.setProperty("--base-font-size", e + "px");
        se.textContent = e + "px";
        localStorage.setItem("md-font-size", e);
    }
    const _e = localStorage.getItem("md-font-type") || "default";
    const $e = localStorage.getItem("md-font-size") || 14;
    applyFontType(_e);
    applyFontSize($e);
    ae.value = $e;
    ie.forEach(e => e.classList.toggle("active", e.dataset.font === _e));
    ie.forEach(e => {
        e.addEventListener("click", () => {
            ie.forEach(e => e.classList.remove("active"));
            e.classList.add("active");
            applyFontType(e.dataset.font);
        });
    });
    ae.addEventListener("input", () => applyFontSize(ae.value));
    function openPanel(e) {
        closeAllPanels();
        e.classList.add("active");
    }
    function closePanel(e) {
        e.classList.remove("active");
    }
    function closeAllPanels() {
        [ m, u, p, f ].forEach(e => e.classList.remove("active"));
    }
    i.addEventListener("click", e => {
        e.stopPropagation();
        if (m.classList.contains("active")) closePanel(m); else openPanel(m);
    });
    a.addEventListener("click", e => {
        e.stopPropagation();
        if (u.classList.contains("active")) closePanel(u); else {
            if (Ae) {
                renderDocumentOutline();
                oe.style.display = "block";
            } else {
                renderTocList();
                oe.style.display = "none";
            }
            openPanel(u);
        }
    });
    s.addEventListener("click", e => {
        e.stopPropagation();
        if (p.classList.contains("active")) closePanel(p); else openPanel(p);
    });
    d.addEventListener("click", e => {
        e.stopPropagation();
        if (f.classList.contains("active")) closePanel(f); else openPanel(f);
    });
    document.addEventListener("click", e => {
        if (!m.contains(e.target) && e.target !== i) closePanel(m);
        if (!u.contains(e.target) && e.target !== a) closePanel(u);
        if (!p.contains(e.target) && e.target !== s) closePanel(p);
        if (!f.contains(e.target) && e.target !== d) closePanel(f);
    });
    S.addEventListener("click", e => {
        e.stopPropagation();
        I.classList.toggle("active");
        H.classList.remove("active");
    });
    document.addEventListener("click", e => {
        if (!I.contains(e.target) && !S.contains(e.target)) I.classList.remove("active");
    });
    le.addEventListener("click", () => {
        ce.classList.remove("active");
        re.innerHTML = "";
    });
    ce.addEventListener("click", e => {
        if (e.target === ce) {
            ce.classList.remove("active");
            re.innerHTML = "";
        }
    });
    // v4.7.7：移动端浮动按钮组滑动隐藏——滚动时向右滑出，停止 5 秒后显示
        var ze = null;
    function _floatShow() {
        if (window.innerWidth <= 768) k.classList.remove("buttons-hidden");
    }
    window.addEventListener("scroll", () => {
        const e = window.pageYOffset || document.documentElement.scrollTop;
        T.style.opacity = e > 300 ? "1" : "0";
        T.style.pointerEvents = e > 300 ? "auto" : "none";
        // v4.7.7：移动端滑动时隐藏浮动按钮组，停止 5 秒后恢复
                if (window.innerWidth <= 768) {
            k.classList.add("buttons-hidden");
            if (ze) clearTimeout(ze);
            ze = setTimeout(_floatShow, 5e3);
        }
        if (Ae) {
            if (e <= 0) n.classList.remove("hidden"); else if (e > Oe && e > 80) n.classList.add("hidden"); else if (e < Oe) n.classList.remove("hidden");
            updateActiveHeading();
            const t = document.documentElement.scrollHeight - window.innerHeight;
            const i = t > 0 ? Math.min(100, e / t * 100) : 0;
            const a = window.innerWidth >= 1025;
            if (a) {
                const e = window.innerWidth - 280;
                de.style.width = e * i / 100 + "px";
            } else {
                de.style.width = i + "%";
            }
            de.classList.add("active");
            // v4.1.4：进度条只显示进度条本身，不再显示百分比文字
                } else {
            n.classList.remove("hidden");
            de.classList.remove("active");
            if (me) me.classList.remove("active");
            de.style.width = "0%";
        }
        Oe = e;
    });
    T.addEventListener("click", () => window.scrollTo({
        top: 0,
        behavior: "smooth"
    }));
    C.addEventListener("click", showHome);
    // v4.7.11：主题监听兼容辅助函数（Safari <14 / 旧 Android WebView 只支持 addListener/removeListener）
        function mdThemeAddListener(e, t) {
        if (!e || !t) return;
        if (typeof e.addEventListener === "function") {
            e.addEventListener("change", t);
        } else if (typeof e.addListener === "function") {
            e.addListener(t);
        }
    }
    function mdThemeRemoveListener(e, t) {
        if (!e || !t) return;
        if (typeof e.removeEventListener === "function") {
            e.removeEventListener("change", t);
        } else if (typeof e.removeListener === "function") {
            e.removeListener(t);
        }
    }
    o.addEventListener("click", () => {
        const e = document.documentElement.getAttribute("data-theme") === "dark";
        const t = e ? "light" : "dark";
        // v4.7.11：手动切换双持久化——localStorage 记住用户选择，sessionStorage 标记"手动切换过"
        //          隐私模式下 localStorage 写入失败时，sessionStorage 仍可保证当前会话不跟随系统
        // v5.4.3：先落"手动"标记再应用主题——保证对比色自适应立即让位于用户选择（切浅色即移除暗化属性）
        try {
            localStorage.setItem("md-theme", t);
        } catch (e) {}
        try {
            sessionStorage.setItem("md-theme-manual", "1");
        } catch (e) {}
        r = true;
        // v5.2.4：经统一钩子应用（切换 mermaid/hljs 的主题适配 + 对比色优先级同步），不再只改 data-theme
        ysmApplyTheme(t);
        // v4.6.2：手动切换后停止跟随系统——移除 change 监听，系统深色模式不再把刚切走的主题立刻改回
        mdThemeRemoveListener(c, l);
        o.innerHTML = e ? '<svg viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>' : '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
    });
    // v4.0.0：深色模式跟随系统——首次访问未手动设置时，按系统偏好（prefers-color-scheme）决定；
    //         手动切换后写入 localStorage 记住用户选择（v4.6.2：并停止跟随系统，见点击 handler）
    // v4.7.11：初始化时读取 sessionStorage 的 md-theme-manual 标记（隐私模式下 localStorage 可能写入失败）；
    //          兼容 addListener/removeListener；加 mdThemeManual 双重保险判断
        (function() {
        let e = "";
        try {
            e = localStorage.getItem("md-theme") || "";
        } catch (e) {}
        try {
            if (sessionStorage.getItem("md-theme-manual")) r = true;
        } catch (e) {}
        c = window.matchMedia ? window.matchMedia("(prefers-color-scheme: dark)") : null;
        const t = c ? c.matches : false;
        const n = e || (t ? "dark" : "light");
        ysmApplyTheme(n);
        if (n === "dark") {
            o.innerHTML = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
        }
        // 用户从未手动设置过时，跟随系统主题变化实时切换（手动切换后移除监听）
        // v4.7.11：saved 存在 OR mdThemeManual 标记存在 → 不跟随系统
                if (!e && c && !r) {
            l = function(e) {
                if (r) return;
                ysmApplyTheme(e.matches ? "dark" : "light");
                o.innerHTML = e.matches ? '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>' : '<svg viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
            };
            mdThemeAddListener(c, l);
        }
    })();
    async function loadFileList() {
        const seq = ++listSeq;
        if (listCtrl) listCtrl.abort();
        listCtrl = new AbortController();
        try {
            const e = await fetch("?action=list", { signal: listCtrl.signal });
            const t = await e.json();
            if (seq !== listSeq) return;
            if (t.success) {
                // v5.2.1：兜底——list 缺 files 字段（旧/异常响应）时不致后续 forEach 崩溃
                Ie = t.files || [];
                renderCategoryBar();
                renderAnnouncements();
                renderHomeContent();
                renderHomePopular();
                renderSidebarList(Ie);
            }
        } catch (e) {
            if (seq !== listSeq || (e && e.name === "AbortError")) return;
            x.innerHTML = '<div class="empty-state">⚠️ 加载失败</div>';
        }
    }
    // v3.1.6：首页公告卡片（服务端 window.YSM_ANNOUNCEMENTS 数据；整卡可点跳详情）
    // v3.1.8：无关联文章的公告（纯文字/更新公告）点击弹出完整内容弹窗，手机端可看全文
    // v3.3.4：取消公告筛选条——公告数量少、筛公告意义不大，标签改为纯展示（可点跳详情保留）
        function renderAnnouncements() {
        var e = document.getElementById("announcementSection");
        if (!e) return;
        var t = window.YSM_ANNOUNCEMENTS || [];
        if (!t.length) {
            e.innerHTML = "";
            e.style.display = "none";
            return;
        }
        e.style.display = "";
        var n = t.map(function(e) {
            // 有关联文章 → 点击跳文章详情；无 → 点击弹出完整公告弹窗
            var t = e.article ? ' data-file="' + escapeHTML(e.article) + '"' : ' data-ann-id="' + escapeHTML(e.id) + '"';
            var n = e.cover ? '<div class="ann-card-media"><img class="ann-card-cover" src="' + escapeHTML(e.cover) + '" alt="" loading="lazy" onerror="this.parentNode.style.display=\'none\'"><div class="ann-card-cover-ink"></div></div>' : "";
            var i = e.type === "update" ? '<span class="ann-card-type update"><svg viewBox="0 0 24 24" width="12" height="12"><path d="M20 6L9 17l-5-5"/></svg>更新</span>' : '<span class="ann-card-type manual">公告</span>';
            var a = (e.tags || []).map(function(e) {
                return '<span class="ann-card-tag">#' + escapeHTML(e) + "</span>";
            }).join("");
            return '<div class="ann-card' + (e.cover ? " has-media" : "") + '"' + t + ">" + '<div class="ann-card-left">' + 
            // v3.3.16：类型徽章与标题同一行（移动到标题旁边），不再是标题上方独立一行
            '<div class="ann-card-title-row">' + i + '<h3 class="ann-card-title">' + escapeHTML(e.title) + "</h3></div>" + '<div class="ann-card-meta">' + '<span class="ann-meta-item"><span class="ann-meta-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>' + escapeHTML(e.date) + "</span>" + (e.words ? '<span class="ann-meta-item"><span class="ann-meta-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>' + (e.words / 1e3).toFixed(1) + "k字</span>" : "") + "</div>" + (e.summary ? '<p class="ann-card-summary">' + escapeHTML(e.summary) + "</p>" : "") + (a ? '<div class="ann-card-tags">' + a + "</div>" : "") + '<span class="ann-card-more">' + (e.article ? "查看文章 →" : "查看详情 →") + "</span>" + "</div>" + n + "</div>";
        }).join("");
        e.innerHTML = '<div class="ann-header"><span class="ann-header-icon"><svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></span>公告</div>' + '<div class="ann-list">' + (n || '<div class="ann-empty">暂无公告</div>') + "</div>";
        // 事件：整卡点击跳文章详情
                e.querySelectorAll(".ann-card[data-file]").forEach(function(e) {
            e.addEventListener("click", function() {
                loadFile(e.getAttribute("data-file"));
            });
        });
        // v3.1.8：无关联文章公告点击 → 弹出完整内容
                e.querySelectorAll(".ann-card[data-ann-id]").forEach(function(e) {
            e.addEventListener("click", function() {
                var t = e.getAttribute("data-ann-id");
                var n = null;
                (window.YSM_ANNOUNCEMENTS || []).forEach(function(e) {
                    if (e.id === t) n = e;
                });
                if (n) openAnnounceDetail(n);
            });
        });
        // v5.2.2：手机端公告摘要默认折成一行——点击摘要展开/收起全文（阻止冒泡，避免误触整卡跳转）；
        //         桌面端不做折叠，点击摘要仍走整卡原有行为（不改动既有交互）。
                e.querySelectorAll(".ann-card-summary").forEach(function(s) {
            s.addEventListener("click", function(ev) {
                if (!window.matchMedia || !window.matchMedia("(max-width: 767px)").matches) return;
                ev.stopPropagation();
                s.classList.toggle("is-expanded");
            });
        });
    }
    // v3.1.8：公告详情弹窗（完整内容；有关联文章时提供跳转按钮）
    // v3.2.3：body 为 markdown 原文 → 用 marked 渲染富文本，提升公告可读性（小白也能看懂排版）
        function openAnnounceDetail(e) {
        var t = document.getElementById("announceModal");
        if (!t) return;
        t.querySelector(".ann-modal-type").textContent = e.type === "update" ? "更新公告" : "公告";
        t.querySelector(".ann-modal-title").textContent = e.title;
        t.querySelector(".ann-modal-date").textContent = e.date || "";
        // v3.3.15：公告弹窗展示字数（首页公告卡片有字数、点进纯文字公告弹窗却无——补齐）
                var n = t.querySelector(".ann-modal-words");
        if (n) {
            if (e.words && e.words > 0) {
                n.style.display = "inline-flex";
                n.innerHTML = '<span class="ann-modal-words-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>' + e.words + " 字";
            } else {
                n.style.display = "none";
            }
        }
        var i = t.querySelector(".ann-modal-content");
        if (e.body && window.marked) {
            i.classList.add("markdown-body");
            // v3.3.0：公告正文视频语法提取（与文章阅读一致）
                        var a = [];
            var s = e.body.replace(/!video\[([^\]]*)\]\(([^)\s]+)\)/g, function(e, t, n) {
                a.push({
                    title: t,
                    src: n.trim()
                });
                return "@@YSM_VIDEO_" + (a.length - 1) + "@@";
            });
            i.innerHTML = marked.parse(s);
            // v3.3.0：公告视频渲染（仅站内 data/videos/；外链按纯文本保留）
                        renderYmVideos(i, a);
            // v3.3.2：公告正文图片懒加载
                        i.querySelectorAll("img").forEach(function(e) {
                if (!e.getAttribute("loading")) e.setAttribute("loading", "lazy");
                e.setAttribute("decoding", "async");
            });
            // v3.2.5：公告正文 mermaid 流程图渲染（与文章阅读一致）；v4.2.2 起按需加载
            // v4.7.14：mermaid 渲染完成后执行 hljs，避免异步加载时序问题
                        renderMermaidBlocks(i, function() {
                if (typeof hljs !== "undefined") {
                    i.querySelectorAll("pre code").forEach(function(e) {
                        hljs.highlightElement(e);
                    });
                }
            });
            // 外链安全：禁止外链在当前页直接跳转（防 tabnabbing/钓鱼），与文章渲染一致
                        i.querySelectorAll("a[href]").forEach(function(e) {
                var t = e.getAttribute("href") || "";
                if (/^(https?:)?\/\//i.test(t) && t.indexOf(window.location.host) === -1) {
                    e.setAttribute("target", "_blank");
                    e.setAttribute("rel", "noopener noreferrer");
                }
            });
        } else {
            i.classList.remove("markdown-body");
            i.textContent = e.summary || "（暂无内容）";
        }
        var o = t.querySelector(".ann-modal-link");
        if (e.article) {
            o.style.display = "inline-flex";
            o.dataset.file = e.article;
        } else {
            o.style.display = "none";
        }
        t.classList.add("active");
    }
    // v3.3.0：!video[标题](站内相对路径) → <video> 播放器
    // 安全策略：src 仅允许站内相对路径 data/videos/xxx.mp4（防外链跳转/防盗链/防 IP 泄露给第三方）；
    // 外链/绝对 URL 一律按原始语法纯文本展示（不渲染播放器、不发网络请求）
        function renderYmVideos(e, t) {
        if (!e || !t || !t.length) return;
        var n = document.createTreeWalker(e, NodeFilter.SHOW_TEXT);
        var i = [];
        while (n.nextNode()) i.push(n.currentNode);
        i.forEach(function(e) {
            var n = e.nodeValue;
            if (n.indexOf("@@YSM_VIDEO_") === -1) return;
            var i = document.createDocumentFragment();
            var a = /@@YSM_VIDEO_(\d+)@@/g;
            var s = 0, o;
            while ((o = a.exec(n)) !== null) {
                if (o.index > s) i.appendChild(document.createTextNode(n.slice(s, o.index)));
                var c = t[parseInt(o[1], 10)];
                if (c) {
                    var r = c.src;
                    // 仅站内 data/videos/ 且为安全文件名（字母数字下划线连字符点）才渲染播放器
                                        if (/^data\/videos\/[A-Za-z0-9_.\-]+\.[A-Za-z0-9]+$/.test(r)) {
                        var l = document.createElement("video");
                        l.controls = true;
                        l.preload = "metadata";
                        l.playsInline = true;
                        l.setAttribute("controlslist", "nodownload");
                        if (c.title) l.title = c.title;
                        // v3.3.2：懒加载——视频进入视口才发起请求（首屏不拉大文件；配合 nginx mp4/Range 更流畅）
                                                if ("IntersectionObserver" in window) {
                            l.dataset.ysmSrc = r;
                            var d = new IntersectionObserver(function(e, t) {
                                e.forEach(function(e) {
                                    if (e.isIntersecting && !l.src) {
                                        l.src = l.dataset.ysmSrc;
                                        t.unobserve(l);
                                    }
                                });
                            }, {
                                rootMargin: "200px"
                            });
                            d.observe(l);
                        } else {
                            l.src = r;
                        }
                        i.appendChild(l);
                        if (c.title) {
                            var m = document.createElement("p");
                            m.className = "ysm-video-cap";
                            m.textContent = "▶ " + c.title;
                            i.appendChild(m);
                        }
                    } else {
                        // 不合法（外链/绝对地址/路径穿越）→ 按原始语法文本展示，不发任何网络请求
                        i.appendChild(document.createTextNode("!video[" + c.title + "](" + c.src + ")"));
                    }
                }
                s = o.index + o[0].length;
            }
            if (s < n.length) i.appendChild(document.createTextNode(n.slice(s)));
            e.parentNode.replaceChild(i, e);
        });
    }
    // v3.1.8：公告弹窗交互（关闭/遮罩点击/跳转文章）
        (function() {
        var e = document.getElementById("announceModal");
        if (!e) return;
        var close = function() {
            e.classList.remove("active");
        };
        document.getElementById("announceModalClose").addEventListener("click", close);
        e.addEventListener("click", function(t) {
            if (t.target === e) close();
        });
        document.getElementById("announceModalLink").addEventListener("click", function() {
            var e = this.dataset.file;
            if (e) {
                close();
                loadFile(e);
            }
        });
    })();
    function renderCards() {
        if (He && L) {
            x.style.display = "none";
            if (L) L.style.display = "block";
        } else if (L) L.style.display = "none";
        if (!Ie.length) {
            x.innerHTML = "";
            E.style.display = "block";
            E.textContent = "📭 暂无文档";
            return;
        }
        E.style.display = "none";
        x.style.display = "";
        // v3.3.12：首页卡片封面走缩略图（大图只在文章阅读页加载，首页不再下载 MB 级原图）
                function cardCover(e) {
            if (!e) return e;
            if (/^\/?data\/images\//.test(e) && /\.(jpe?g|png|webp|gif)$/i.test(e)) {
                return "img.php?src=" + encodeURIComponent(e) + "&w=640";
            }
            return e;
        }
        var e = filteredFiles();
        // v5.2.2：可选「热度优先排序」（默认关闭，见顶部 YSM_SORT_BY_HEAT）。
        // 关闭时保持 list 原顺序（最新优先），不改任何排序契约；仅在开启时按热度重排。
        if (YSM_SORT_BY_HEAT) {
            e = e.slice().sort(function(a, b) {
                var d = (b.views30 || 0) - (a.views30 || 0);
                return d !== 0 ? d : (b.views || 0) - (a.views || 0);
            });
        }
        // v5.2.1：多选标签交集为空时给出空态提示（此前只清空网格、无任何反馈，表现为"白屏"）
        if (!e.length) {
            x.innerHTML = "";
            E.style.display = "block";
            E.textContent = Me.length ? "🔍 没有同时包含所选标签的文章" : "🔍 该分类下暂无文章";
            return;
        }
        x.innerHTML = e.map((e, t) => `\n            <div class="doc-card" data-heat="${escapeHTML(e.heat || "normal")}" data-views="${e.views || 0}" data-views30="${e.views30 || 0}" data-filename="${escapeHTML(e.name)}" style="animation-delay:${t * .05}s">\n                ${e.cover ? `<div class="doc-cover"><img src="${escapeHTML(cardCover(e.cover))}" alt="" loading="lazy" onerror="this.parentNode.style.display='none'"></div>` : ""}\n                <div class="card-title">${e.pinned ? '<span class="card-pin-icon" title="置顶"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2z" fill="currentColor" stroke="none"/></svg></span>' : ""}${escapeHTML(e.displayName)}</div>\n                <div class="card-meta">\n                    <span><span class="meta-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>${e.modified}</span>\n                    <span><span class="meta-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>${e.wordCount}字</span>\n                    ${e.category ? `<span><span class="meta-icon"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></span>${escapeHTML(e.category)}</span>` : ""}\n                    ${(e.views || 0) > 0 ? `<span><span class="meta-icon"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span>${e.views}</span>` : ""}\n                </div>\n                <div class="card-excerpt">${escapeHTML(e.excerpt || "")}</div>\n                <div class="card-tags">${e.tags.map(e => `<span class="tag${isTagSelected(e) ? " active" : ""}" data-tag="${escapeHTML(e)}">#${escapeHTML(e)}</span>`).join("")}</div></div>`).join("");
        document.querySelectorAll(".doc-card").forEach(e => e.addEventListener("click", () => loadFile(e.dataset.filename)));
        // v4.0.0：卡片标签点击 → 标签聚合过滤（阻止冒泡，避免误触进入文章）
                document.querySelectorAll(".doc-card .card-tags .tag").forEach(e => {
            e.addEventListener("click", function(e) {
                e.stopPropagation();
                toggleTagFilter(this.dataset.tag);
                Be = "";
                renderCategoryBar();
                renderHomeContent();
            });
        });
        // v4.7.14：卡片标签横向滚动（鼠标拖动 + 滚轮）
                document.querySelectorAll(".doc-card .card-tags").forEach(function(e) {
            enableHScroll(e);
        });
    }
    // v5.2.0：副栏「热门文章」——与 list 接口同一份数据，按热度（近30天为主/总量为辅）取前 5
        function renderHomePopular() {
        var box = document.getElementById("homePopularList");
        if (!box) return;
        // v5.2.1：手机端热门榜默认折叠——点击标题展开/收起（桌面端由 CSS 保持展开，类切换无副作用）
        var popSec = document.getElementById("homePopular");
        if (popSec && !popSec.__ysmToggleBound) {
            popSec.__ysmToggleBound = true;
            var popTitle = popSec.querySelector(".home-popular-title");
            if (popTitle) {
                popTitle.addEventListener("click", function() {
                    popSec.classList.toggle("is-open");
                });
            }
        }
        // v5.2.1：兜底——Ie 为空/字段缺失时不报错
        var list = (Ie || []).filter(function(e) {
            return (e.views30 || 0) > 0 || (e.views || 0) > 0;
        }).sort(function(a, b) {
            var d = (b.views30 || 0) - (a.views30 || 0);
            if (d !== 0) return d;
            return (b.views || 0) - (a.views || 0);
        }).slice(0, 5);
        if (!list.length) {
            box.innerHTML = '<div class="home-popular-empty">暂无数据</div>';
            return;
        }
        box.innerHTML = list.map(function(e, i) {
            return '<div class="home-popular-item" data-filename="' + escapeHTML(e.name) + '">' +
                '<span class="home-popular-rank">' + (i + 1) + "</span>" +
                '<span class="home-popular-name">' + escapeHTML(e.displayName) + "</span>" +
                '<span class="home-popular-views">' + (e.views30 || 0) + "</span></div>";
        }).join("");
        box.querySelectorAll(".home-popular-item").forEach(function(e) {
            e.addEventListener("click", function() {
                loadFile(e.dataset.filename);
            });
        });
    }
    // v5.2.1：标签多选（AND）——已选标签数组；点选叠加、再点取消；支持一键清空
        function toggleTagFilter(e) {
        var t = Me.indexOf(e);
        if (t === -1) Me.push(e); else Me.splice(t, 1);
    }
    function isTagSelected(e) {
        return Me.indexOf(e) !== -1;
    }
    function clearTagFilter() {
        Me = [];
    }
    function renderCategoryBar() {
        var e = document.getElementById("categoryBar");
        if (!e) return;
        var t = {};
        var n = {};
        Ie.forEach(e => {
            var i = e.category || "";
            if (i) t[i] = (t[i] || 0) + 1;
            (e.tags || []).forEach(e => {
                if (e && e !== "#") n[e] = (n[e] || 0) + 1;
            });
        });
        var i = Object.keys(t);
        var a = Object.keys(n);
        if (i.length === 0 && a.length === 0) {
            e.classList.remove("has-categories");
            e.innerHTML = "";
            return;
        }
        e.classList.add("has-categories");
        var s = Ie.length;
        // v4.0.0：归档视图切换按钮 + 标签聚合条
                var o = He ? `<div class="category-bar-item active" data-view="archive">归档</div>` : `<div class="category-bar-item" data-view="archive">归档</div>`;
        var c = `<div class="category-bar-left">${o}<div class="category-bar-item${Be === "" && !Me.length ? " active" : ""}" data-category="">全部 <span class="category-bar-count">${s}</span></div></div>`;
        var r = '<div class="category-bar-divider"></div>';
        var l = "";
        i.forEach(e => {
            l += `<div class="category-bar-item${Be === e && !Me.length ? " active" : ""}" data-category="${escapeHTML(e)}">${escapeHTML(e)} <span class="category-bar-count">${t[e]}</span></div>`;
        });
        // v5.2.2：已选二级标签不再单起一整行——改为筛选区内可直接点掉（×）的 chip，
        //         与一级标签同排渲染（放进 .category-bar-right），不占独立整行。
                var f = "";
        if (Me.length) {
            f = Me.map(function(e) {
                return '<span class="tag-filter-chip" data-tag="' + escapeHTML(e) + '">#' + escapeHTML(e) + '<span class="tag-filter-count">' + (n[e] || 0) + '</span><span class="tag-filter-x" aria-hidden="true">×</span></span>';
            }).join("");
        }
        // v4.1.1：标签云独立一行（不再与分类按钮同排挤压导致重叠/挤出）
                var d = "";
        if (Be === "" && a.length > 0) {
            d = `<div class="tag-cloud-row"><div class="tag-cloud">${a.slice(0, 20).map(e => `<span class="tag-cloud-item${isTagSelected(e) ? " active" : ""}" data-tag="${escapeHTML(e)}">#${escapeHTML(e)}</span>`).join("")}</div></div>`;
        }
        var clr = Me.length ? '<span class="tag-filter-clear" data-clear="1">清空</span>' : "";
        var m = `<div class="category-bar-right">${f}${l}${clr}</div>`;
        e.innerHTML = c + r + m + d;
        e.querySelectorAll(".category-bar-item").forEach(e => {
            e.addEventListener("click", function() {
                if (this.dataset.view === "archive") {
                    He = !He;
                    renderCategoryBar();
                    renderHomeContent();
                    return;
                }
                if (this.dataset.tag !== undefined) {
                    toggleTagFilter(this.dataset.tag);
                } else {
                    // v4.0.0：点「全部」（data-category=""）同时清除标签筛选
                    Be = this.dataset.category;
                    clearTagFilter();
                }
                renderCategoryBar();
                renderHomeContent();
            });
        });
        // v5.2.1：已选标签 chip 点击 = 取消该标签；「清空」= 取消全部
                e.querySelectorAll(".tag-filter-chip").forEach(function(e) {
            e.addEventListener("click", function() {
                toggleTagFilter(this.dataset.tag);
                renderCategoryBar();
                renderHomeContent();
            });
        });
        var clearBtn = e.querySelector(".tag-filter-clear");
        if (clearBtn) {
            clearBtn.addEventListener("click", function() {
                clearTagFilter();
                renderCategoryBar();
                renderHomeContent();
            });
        }
        e.querySelectorAll(".tag-cloud-item").forEach(e => {
            e.addEventListener("click", function() {
                toggleTagFilter(this.dataset.tag);
                renderCategoryBar();
                renderHomeContent();
            });
        });
        // v4.1.4：分类栏/标签云启用横向滚动（滚轮+拖拽），超出部分电脑端可查看
        // v5.2.2：已选标签 chip 现内联在 .category-bar-right 内，随其横向滚动，无需单独处理
        enableHScroll(e.querySelector(".category-bar-left"));
        enableHScroll(e.querySelector(".category-bar-right"));
        enableHScroll(e.querySelector(".tag-cloud"));
    }
    // v4.1.4：分类/标签栏横向滚动增强——桌面端鼠标滚轮垂直滚动转横向，并支持按住拖动
    // v5.2.1：document 级拖拽监听改为「全局仅注册一次」（此前每次重渲染分类栏都会重新注册，
    //         交互次数越多监听器越多，造成泄漏）；拖拽状态改用共享变量，不再逐元素闭包。
    var ysmDragEl = null, ysmDragStartX = 0, ysmDragStartLeft = 0, ysmDragMoved = false, ysmDragGuardEl = null;
    document.addEventListener("mousemove", function(s) {
        if (!ysmDragEl) return;
        var o = s.clientX - ysmDragStartX;
        if (Math.abs(o) > 4) ysmDragMoved = true;
        ysmDragEl.scrollLeft = ysmDragStartLeft - o;
    });
    document.addEventListener("mouseup", function() {
        if (!ysmDragEl) return;
        ysmDragEl.style.cursor = "";
        ysmDragGuardEl = ysmDragMoved ? ysmDragEl : null;
        ysmDragEl = null;
    });
    function enableHScroll(e) {
        if (!e || e.__ysmHScroll) return;
        e.__ysmHScroll = true;
        // 滚轮：垂直滚动（deltaY）转为横向滚动（deltaX），Shift 不依赖
        e.addEventListener("wheel", function(t) {
            if (Math.abs(t.deltaY) > Math.abs(t.deltaX) && e.scrollWidth > e.clientWidth + 2) {
                t.preventDefault();
                e.scrollLeft += t.deltaY;
            }
        }, {
            passive: false
        });
        // 按住拖动（桌面鼠标；移动端原生触摸滚动不受影响）
        e.addEventListener("mousedown", function(s) {
            if (s.button !== 0) return;
            if (e.scrollWidth <= e.clientWidth + 2) return; // 无溢出不启用拖拽，避免干扰点击
            ysmDragEl = e;
            ysmDragMoved = false;
            ysmDragGuardEl = null;
            ysmDragStartX = s.clientX;
            ysmDragStartLeft = e.scrollLeft;
            e.style.cursor = "grabbing";
        });
        // 子元素点击：若刚拖动过则忽略
        e.addEventListener("click", function(ev) {
            if (ysmDragGuardEl === e) {
                ysmDragGuardEl = null;
                ev.stopPropagation();
                ev.preventDefault();
            }
        }, true);
    }
    // v4.0.0：根据 currentCategory/currentTag 过滤 + 归档视图渲染（按年月分组）
        function filteredFiles() {
        // v5.2.1：标签多选 AND——需同时命中全部已选标签（逐步缩小范围）
        return Ie.filter(function(e) {
            if (Be && e.category !== Be) return false;
            if (!Me.length) return true;
            var t = e.tags || [];
            for (var i = 0; i < Me.length; i++) {
                if (t.indexOf(Me[i]) === -1) return false;
            }
            return true;
        });
    }
    function renderHomeContent() {
        if (He) {
            renderArchive();
            return;
        }
        renderCards();
    }
    function renderArchive() {
        if (!L) return;
        E.style.display = "none";
        x.style.display = "none";
        L.style.display = "block";
        var e = filteredFiles();
        if (!e.length) {
            L.innerHTML = '<div class="archive-empty">暂无归档内容</div>';
            return;
        }
        // 按 modified 年月分组（倒序）
                var t = {};
        e.forEach(e => {
            var n = (e.modified || "").slice(0, 7);
            if (!n) n = "未分类";
            (t[n] = t[n] || []).push(e);
        });
        var n = Object.keys(t).sort((e, t) => t.localeCompare(e));
        var i = n.map(e => {
            var n = t[e].map(e => `\n                <a class="archive-item" data-filename="${escapeHTML(e.name)}">\n                    <span class="archive-item-title">${escapeHTML(e.displayName)}</span>\n                    <span class="archive-item-meta">${escapeHTML(e.modified)}${(e.views || 0) > 0 ? " · " + e.views + " 浏览" : ""}</span>\n                </a>`).join("");
            return `<div class="archive-group"><div class="archive-year">${escapeHTML(e)} <span class="archive-year-count">${t[e].length} 篇</span></div><div class="archive-items">${n}</div></div>`;
        }).join("");
        L.innerHTML = i;
        L.querySelectorAll(".archive-item").forEach(e => e.addEventListener("click", () => loadFile(e.dataset.filename)));
    }
    function renderSidebarList(e) {
        if (!ye) return;
        Le.textContent = e.length;
        ye.innerHTML = e.map(e => `\n            <div class="sidebar-item" data-filename="${escapeHTML(e.name)}">\n                <div class="sidebar-item-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>\n                <div style="flex:1;min-width:0;overflow:hidden;">\n                    <div class="sidebar-item-text">${e.pinned ? '<span class="sidebar-pin-icon" title="置顶"><svg viewBox="0 0 24 24" width="14" height="14"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2z" fill="currentColor" stroke="none"/></svg></span>' : ""}${escapeHTML(e.displayName)}</div>\n                    <div style="font-size:11px;color:var(--text-muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${e.modified}</div>\n                </div>\n            </div>`).join("");
        ye.querySelectorAll(".sidebar-item").forEach(e => {
            e.addEventListener("click", () => loadFile(e.dataset.filename));
        });
    }
    function highlightSidebarItem(e) {
        if (!ye) return;
        ye.querySelectorAll(".sidebar-item").forEach(t => {
            t.classList.toggle("active", t.dataset.filename === e);
        });
    }
    function showSidebarToc() {
        if (!ye || !ge || !he) return;
        ye.style.display = "none";
        he.style.display = "block";
        ge.style.display = "block";
        if (xe) xe.parentElement.style.display = "none";
        if (Le) Le.style.display = "none";
        if (Ee) Ee.style.display = "flex";
        if (be) be.style.display = "block";
    }
    function showSidebarFileList() {
        if (!ye || !ge || !he) return;
        ye.style.display = "block";
        he.style.display = "none";
        ge.style.display = "none";
        if (xe) xe.parentElement.style.display = "block";
        if (Le) Le.style.display = "inline";
        if (Ee) Ee.style.display = "none";
        if (be) be.style.display = "none";
    }
    function renderSidebarToc(e) {
        if (!ge) return;
        if (!e.length) {
            ge.innerHTML = '<div style="padding:16px;color:var(--text-muted);text-align:center;font-size:13px;">暂无标题</div>';
            return;
        }
        renderTocItems(ge, e);
    }
    if (xe) {
        // v4.1.0：侧边栏搜索同步升级为后端全文搜索（与顶部搜索一致，防抖 250ms）
        xe.addEventListener("input", function() {
            const e = this.value.trim();
            if (!e) {
                clearTimeout(xe._timer);
                if (sidebarSearchCtrl) sidebarSearchCtrl.abort();
                ++sidebarSearchSeq;
                renderSidebarList(Ie);
                return;
            }
            clearTimeout(xe._timer);
            xe._timer = setTimeout(async function() {
                const seq = ++sidebarSearchSeq;
                if (sidebarSearchCtrl) sidebarSearchCtrl.abort();
                sidebarSearchCtrl = new AbortController();
                try {
                    const t = await fetch("?action=search&q=" + encodeURIComponent(e), { signal: sidebarSearchCtrl.signal });
                    const n = await t.json();
                    if (seq !== sidebarSearchSeq) return;
                    if (n.success) renderSidebarList(n.files || []);
                } catch (e) {
                    if (seq !== sidebarSearchSeq || (e && e.name === "AbortError")) return;
                    /* 失败保持原列表 */
                }
            }, 250);
        });
    }
    if (Ee) {
        Ee.addEventListener("click", showHome);
    }
    v.addEventListener("input", function() {
        const e = this.value.trim();
        if (!e) {
            clearTimeout(v._timer);
            if (searchCtrl) searchCtrl.abort();
            ++searchSeq;
            y.innerHTML = "";
            return;
        }
        // v4.0.0：全文搜索升级——防抖后请求后端 ?action=search（标题/标签/摘要/正文匹配）
                clearTimeout(v._timer);
        v._timer = setTimeout(async function() {
            const seq = ++searchSeq;
            if (searchCtrl) searchCtrl.abort();
            searchCtrl = new AbortController();
            y.innerHTML = '<div style="padding:14px;color:var(--text-muted);text-align:center;font-size:13px">搜索中…</div>';
            try {
                const t = await fetch("?action=search&q=" + encodeURIComponent(e), { signal: searchCtrl.signal });
                const n = await t.json();
                if (seq !== searchSeq) return;
                if (!n.success) {
                    y.innerHTML = '<div style="padding:14px;color:var(--text-muted);text-align:center;font-size:13px">搜索失败</div>';
                    return;
                }
                const i = n.files || [];
                if (!i.length) {
                    y.innerHTML = '<div style="padding:14px;color:var(--text-muted);text-align:center;font-size:13px">未找到匹配「' + escapeHTML(e) + "」的文章</div>";
                    return;
                }
                y.innerHTML = i.map(e => `\n                    <div class="dropdown-item" data-filename="${escapeHTML(e.name)}">\n                        <div class="file-icon"><svg width="16" height="16" viewBox="0 0 24 24" stroke="currentColor" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>\n                        <div class="file-info"><div class="file-name">${escapeHTML(e.displayName)}</div><div style="font-size:11px;color:var(--text-muted);margin-top:2px">${escapeHTML((e.excerpt || "").slice(0, 60))}</div></div>\n                    </div>`).join("");
                document.querySelectorAll("#searchResults .dropdown-item").forEach(e => {
                    e.addEventListener("click", () => {
                        loadFile(e.dataset.filename);
                        closePanel(m);
                        v.value = "";
                        y.innerHTML = "";
                    });
                });
            } catch (e) {
                if (seq !== searchSeq || (e && e.name === "AbortError")) return;
                y.innerHTML = '<div style="padding:14px;color:var(--text-muted);text-align:center;font-size:13px">搜索失败</div>';
            }
        }, 250);
    });
    function renderTocList() {
        g.innerHTML = Ie.map(e => `\n            <div class="dropdown-item" data-filename="${escapeHTML(e.name)}">\n                <div class="file-icon"><svg width="16" height="16" viewBox="0 0 24 24" stroke="currentColor" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>\n                <div class="file-info"><div class="file-name">${escapeHTML(e.displayName)}</div></div>\n            </div>`).join("");
        document.querySelectorAll("#tocFileList .dropdown-item").forEach(e => e.addEventListener("click", () => {
            loadFile(e.dataset.filename);
            closePanel(u);
        }));
    }
    function removeOrdinal(e) {
        return e.replace(/^[\d一二三四五六七八九十]+[\.、\s]+/, "");
    }
    function renderTocItems(e, t) {
        e.innerHTML = t.map((e, t) => {
            const n = e.level;
            const i = n === 1 ? `<div class="toc-block"><svg width="14" height="14" viewBox="0 0 24 24" stroke="currentColor" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>` : `<div class="toc-dot-circle"></div>`;
            return `<div class="toc-item" data-anchor="${e.el.id}" data-level="${n}" style="padding-left:${(n - 1) * 16}px">${i}<div class="toc-text">${escapeHTML(removeOrdinal(e.text))}</div></div>`;
        }).join("");
        e.querySelectorAll(".toc-item").forEach(e => e.addEventListener("click", function(e) {
            e.preventDefault();
            const t = this.dataset.anchor;
            if (t) {
                const e = document.getElementById(t);
                if (e) e.scrollIntoView({
                    behavior: "smooth",
                    block: "start"
                });
            }
            closePanel(u);
            I.classList.remove("active");
        }));
    }
    function renderDocumentOutline() {
        if (!Pe.length) {
            g.innerHTML = '<div style="padding:16px;color:var(--text-muted);text-align:center;">暂无标题</div>';
            return;
        }
        renderTocItems(g, Pe);
        updateActiveHeading(true);
    }
    function renderTocPopup() {
        if (!Pe.length) {
            B.innerHTML = '<div style="padding:16px;color:var(--text-muted);text-align:center;">暂无标题</div>';
            return;
        }
        renderTocItems(B, Pe);
        updateActiveHeading(true);
    }
    function extractHeadings() {
        Pe = [];
        w.querySelectorAll("h1, h2, h3, h4, h5, h6").forEach((e, t) => {
            if (!e.id) {
                e.id = "heading-" + t;
            }
            const n = e.id;
            Pe.push({
                text: e.textContent,
                level: parseInt(e.tagName.charAt(1)),
                el: e
            });
            const i = document.createElement("a");
            i.className = "heading-anchor";
            i.href = "#" + n;
            i.textContent = "#";
            i.addEventListener("click", t => {
                t.preventDefault();
                t.stopPropagation();
                e.scrollIntoView({
                    behavior: "smooth",
                    block: "start"
                });
                history.replaceState(null, "", "#" + n);
            });
            e.appendChild(i);
        });
    }
    function updateActiveHeading(e = false) {
        if (!Pe.length) return;
        let t = -1;
        const n = window.scrollY + 80;
        for (let e = Pe.length - 1; e >= 0; e--) {
            if (Pe[e].el.offsetTop <= n) {
                t = e;
                break;
            }
        }
        if (t !== Re || e) {
            Re = t;
            const applyActive = e => e.forEach((e, n) => e.classList.toggle("active", n === t));
            applyActive(g.querySelectorAll(".toc-item"));
            applyActive(B.querySelectorAll(".toc-item"));
            applyActive(ge.querySelectorAll(".toc-item"));
        }
    }
    function addCopyButtons() {
        document.querySelectorAll(".markdown-body pre").forEach(e => {
            if (e.querySelector(".copy-btn")) return;
            const t = e.querySelector("code");
            if (!t) return;
            const n = t.innerHTML;
            const i = n.replace(/^\n/, "").replace(/\n$/, "").split("\n");
            while (i.length > 0 && i[i.length - 1].trim() === "") i.pop();
            t.innerHTML = i.map(e => `<span class="line">${e || " "}</span>`).join("\n");
            const a = document.createElement("div");
            a.className = "copy-btn";
            a.innerHTML = '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
            a.addEventListener("click", t => {
                t.stopPropagation();
                const n = e.querySelector("code");
                const i = n ? n.textContent : e.textContent;
                function showCopied() {
                    a.classList.add("copied");
                    a.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>';
                    showToast("已复制到剪贴板");
                    setTimeout(() => {
                        a.classList.remove("copied");
                        a.innerHTML = '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
                    }, 2e3);
                }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(i).then(showCopied).catch(function() {
                        var e = document.createElement("textarea");
                        e.value = i;
                        e.style.cssText = "position:fixed;left:-9999px;top:-9999px;opacity:0;";
                        document.body.appendChild(e);
                        e.select();
                        try {
                            document.execCommand("copy");
                            showCopied();
                        } catch (e) {}
                        document.body.removeChild(e);
                    });
                } else {
                    var s = document.createElement("textarea");
                    s.value = i;
                    s.style.cssText = "position:fixed;left:-9999px;top:-9999px;opacity:0;";
                    document.body.appendChild(s);
                    s.select();
                    try {
                        document.execCommand("copy");
                        showCopied();
                    } catch (e) {}
                    document.body.removeChild(s);
                }
            });
            e.appendChild(a);
            const s = i.length;
            if (s > 15) {
                e.classList.add("collapsible");
                const t = document.createElement("div");
                t.className = "collapse-btn";
                t.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>';
                t.title = "折叠/展开代码块";
                t.addEventListener("click", t => {
                    t.stopPropagation();
                    e.classList.toggle("collapsed");
                });
                e.appendChild(t);
            }
        });
    }
    function updatePrevNext() {
        if (!Ae || Ie.length === 0 || Ue === -1) {
            we.style.display = "none";
            return;
        }
        we.style.display = "flex";
        const e = Ue - 1;
        const t = Ue + 1;
        if (e >= 0) {
            ke.classList.remove("disabled");
            Te.textContent = Ie[e].displayName;
        } else {
            ke.classList.add("disabled");
            Te.textContent = "没有了";
        }
        if (t < Ie.length) {
            Se.classList.remove("disabled");
            Ce.textContent = Ie[t].displayName;
        } else {
            Se.classList.add("disabled");
            Ce.textContent = "没有了";
        }
    }
    ke.addEventListener("click", () => {
        if (Ue > 0) loadFile(Ie[Ue - 1].name);
    });
    Se.addEventListener("click", () => {
        if (Ue < Ie.length - 1) loadFile(Ie[Ue + 1].name);
    });
    function getUrlParam(e) {
        return new URL(window.location.href).searchParams.get(e);
    }
    function updateUrl(e) {
        const t = new URL(window.location.href);
        if (e) t.searchParams.set("file", e); else t.searchParams.delete("file");
        window.history.pushState({}, "", t.toString());
    }
    function getShortUrl(e) {
        var id = String(e).replace(/\.md$/i, "").split("_")[0];
        const t = new URL(window.location.origin + window.location.pathname);
        if (/^[A-Za-z0-9]{6,64}$/.test(id)) {
            t.searchParams.set("p", id);
            t.searchParams.delete("file");
        } else {
            t.searchParams.set("file", e);
        }
        return t.toString();
    }
    /** v4.8.0：复制链接降级方案（clipboard API 不可用时使用） */    function copyFallback(e) {
        var t = document.createElement("textarea");
        t.value = e;
        t.style.cssText = "position:fixed;left:-9999px;top:-9999px;opacity:0;";
        document.body.appendChild(t);
        t.select();
        try {
            document.execCommand("copy");
            showToast("链接已复制");
        } catch (e) {
            showToast("复制失败，请手动复制");
        }
        document.body.removeChild(t);
    }
    function showHome(e = true) {
        // v5.4.3：返回首页即作废在途加载——取消在途请求，并推进序号让迟到响应静默丢弃
        if (loadCtrl) loadCtrl.abort();
        ++loadSeq;
        // v5.4.3：返回主页同步一次对比色属性——按当前主题/手动标记收口，避免"回主页后残留暗色"
        ysmSyncTextContrast();
        if (Ae) {
            sessionStorage.setItem("md-read-scroll-" + Fe, window.scrollY);
        } else {
            sessionStorage.setItem("md-list-scroll", window.scrollY);
        }
        h.style.display = "block";
        b.classList.remove("active");
        Ae = false;
        we.style.display = "none";
        k.classList.remove("reading");
 // v4.7.4：回首页仅保留 BGM 按钮（组常驻）
                I.classList.remove("active");
        H.classList.remove("active");
        ce.classList.remove("active");
        re.innerHTML = "";
        // v4.1.3：返回首页显式隐藏阅读进度条并清空文本——此前依赖 scrollTo 触发 scroll 事件，
        // 页面已在顶部时不触发，导致残留 "0%" 进度文本显示在主页右上角
                de.classList.remove("active");
        de.style.width = "0%";
        if (me) {
            me.classList.remove("active");
            me.textContent = "";
        }
        // v4.0.0：返回首页时按当前视图模式（卡片/归档）恢复显示
                if (He) {
            x.style.display = "none";
            if (L) L.style.display = "block";
        } else if (L) L.style.display = "none";
        showSidebarFileList();
        highlightSidebarItem("");
        document.title = window.YSM_SITE_TITLE || "You Super Markdown";
        cmtOnArticleHide();
        const t = sessionStorage.getItem("md-list-scroll");
        if (t) {
            requestAnimationFrame(() => window.scrollTo(0, parseInt(t)));
        } else {
            window.scrollTo(0, 0);
        }
        if (e) updateUrl(null);
    }
    function showReading() {
        h.style.display = "none";
        b.classList.add("active");
        Ae = true;
        k.classList.add("reading");
 // v4.7.4：阅读视图显示 目录/返回主页/回到顶部（BGM 常驻组内）
                showSidebarToc();
        window.scrollTo(0, 0);
    }
    function buildDocHeader(e) {
        const t = e.wordCount;
        const n = Math.max(1, Math.round(t / 300));
        let i = '<div class="doc-header">';
        i += '<div class="doc-stats">';
        i += '<span class="stat-item"><span class="meta-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>' + t + " 字</span>";
        i += '<span class="stat-item"><span class="meta-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>' + n + " 分钟</span>";
        i += "</div>";
        i += '<div class="doc-title">' + escapeHTML(e.displayName) + "</div>";
        i += '<div class="doc-info">';
        i += '<span class="info-item"><span class="meta-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span> ' + e.modified + "</span>";
        if (e.category) i += '<span class="info-item"><span class="meta-icon"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></span> ' + escapeHTML(e.category) + "</span>";
        if (e.tags && e.tags.length) {
            i += '<span class="tag-list"><span class="meta-icon"><svg viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></span><span class="tag-text">' + e.tags.slice(0, 2).map(e => escapeHTML(e)).join("/") + "</span></span>";
        }
        i += "</div></div>";
        return i;
    }
    function buildBottomCards(e) {
        const t = getShortUrl(e.name);
        const n = e.licenseUrl || "";
        const i = e.license || "CC BY-NC-SA 4.0";
        const a = n ? `<a href="${escapeHTML(n)}" target="_blank" rel="noopener">${escapeHTML(i)}</a>` : escapeHTML(i);
        const s = e.author ? `<span class="info-card-author">${escapeHTML(e.author)}</span>` : "";
        return `\n        <div class="article-bottom-cards">\n            <div class="share-card">\n                <div class="share-card-top">\n                    <div class="share-card-icon">\n                        <svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>\n                    </div>\n                    <div>\n                        <div class="share-card-title">分享</div>\n                        <div class="share-card-desc">如果这篇文章对你有帮助，欢迎分享给更多人！</div>\n                    </div>\n                </div>\n                <button class="share-card-btn" id="shareCardBtnInline">分享</button>\n            </div>\n            <div class="article-info-card">\n                <div class="info-card-title">${escapeHTML(e.displayName)}</div>\n                <div class="info-card-url">${escapeHTML(t)}</div>\n                <div class="info-card-meta">\n                    ${s ? `<div class="info-card-meta-item">\n                        <span class="info-card-meta-label">作者</span>\n                        <span>${s}</span>\n                    </div>` : ""}\n                    <div class="info-card-meta-item">\n                        <span class="info-card-meta-label">发布于</span>\n                        <span>${e.modified}</span>\n                    </div>\n                    <div class="info-card-meta-item">\n                        <span class="info-card-meta-label">许可证书</span>\n                        <span class="info-card-license">${a}</span>\n                    </div>\n                </div>\n            </div>\n        </div>`;
    }
    function showNotFound(e) {
        showReading();
        w.innerHTML = '<div style="text-align:center;padding:60px 20px;">' + '<div style="font-size:4em;font-weight:800;color:var(--accent);line-height:1;margin-bottom:12px;">404</div>' + '<div style="font-size:1.2em;font-weight:600;margin-bottom:8px;">文档不存在</div>' + '<div style="color:var(--text-secondary);margin-bottom:24px;">' + (e ? "文件 " + escapeHTML(e) + " 未找到，可能已被删除。" : "你访问的页面不存在。") + "</div>" + '<a href="./" style="display:inline-flex;align-items:center;gap:8px;padding:10px 24px;border-radius:10px;background:var(--accent);color:#fff;text-decoration:none;font-weight:600;">返回首页</a>' + "</div>";
        document.title = "404 - 文档不存在 | " + (window.YSM_SITE_TITLE || "You Super Markdown");
    }
    // v4.6.1：加载失败（网络错误/响应非 JSON）——与「文件不存在」区分，避免误报"文档不存在"
        function showLoadError(e) {
        showReading();
        w.innerHTML = '<div style="text-align:center;padding:60px 20px;">' + '<div style="font-size:4em;font-weight:800;color:var(--accent);line-height:1;margin-bottom:12px;">⚠</div>' + '<div style="font-size:1.2em;font-weight:600;margin-bottom:8px;">加载失败</div>' + '<div style="color:var(--text-secondary);margin-bottom:24px;">' + (e ? "文件 " + escapeHTML(e) + " 暂时无法加载，请检查网络后重试。" : "网络异常，请稍后重试。") + "</div>" + '<a href="./" style="display:inline-flex;align-items:center;gap:8px;padding:10px 24px;border-radius:10px;background:var(--accent);color:#fff;text-decoration:none;font-weight:600;">返回首页</a>' + "</div>";
        document.title = "加载失败 | " + (window.YSM_SITE_TITLE || "You Super Markdown");
    }
    // v4.6.1：内容已获取但渲染过程出错（marked/highlight 等）——独立提示并输出控制台错误，便于定位
        function showRenderError(e) {
        showReading();
        w.innerHTML = '<div style="text-align:center;padding:60px 20px;">' + '<div style="font-size:4em;font-weight:800;color:var(--accent);line-height:1;margin-bottom:12px;">!</div>' + '<div style="font-size:1.2em;font-weight:600;margin-bottom:8px;">内容渲染失败</div>' + '<div style="color:var(--text-secondary);margin-bottom:24px;">文件内容已获取，但页面渲染时发生错误。请查看浏览器控制台或联系管理员。</div>' + '<a href="./" style="display:inline-flex;align-items:center;gap:8px;padding:10px 24px;border-radius:10px;background:var(--accent);color:#fff;text-decoration:none;font-weight:600;">返回首页</a>' + "</div>";
        document.title = "渲染失败 | " + (window.YSM_SITE_TITLE || "You Super Markdown");
    }
    async function loadFile(e, t = true) {
        // v5.4.3：请求序号 + AbortController——开启新加载即取消旧请求；迟到的旧响应静默丢弃
        const seq = ++loadSeq;
        if (loadCtrl) loadCtrl.abort();
        loadCtrl = new AbortController();
        showReading();
        w.innerHTML = '<p style="text-align:center;color:var(--text-muted);padding:40px;">⏳ 加载中...</p>';
        Ue = Ie.findIndex(t => t.name === e);
        updatePrevNext();
        if (t) updateUrl(e);
        let n = null;
        // v4.6.1：网络/解析阶段独立 try——失败提示「加载失败」，不再误报「文档不存在」
                try {
            const t = await fetch(`?action=read&file=${encodeURIComponent(e)}`, { signal: loadCtrl.signal });
            const i = await t.text();
            if (seq !== loadSeq) return;
            try {
                n = JSON.parse(i);
            } catch (e) {
                n = null;
            }
        } catch (t) {
            if (seq !== loadSeq || (t && t.name === "AbortError")) return;
            console.error("[loadFile network error]", t);
            showLoadError(e);
            cmtOnArticleHide();
            return;
        }
        if (seq !== loadSeq) return;
        if (!n || !n.success) {
            showNotFound(e);
            cmtOnArticleHide();
            return;
        }
        // v4.6.1：渲染阶段独立 try——渲染错误提示「内容渲染失败」并输出控制台，便于定位
                try {
            // v3.3.15：公告关联文章（如「更新历史」META hidden）不在 allFiles 列表，
            //          findIndex 为 -1 时旧逻辑回退 wordCount:0 → 文章详情显示"0 字"。
            //          改用 read 接口返回的字数（服务端统一算法）修正。
            const t = Ie[Ue];
            const s = Object.assign({
                displayName: e.replace(/\.md$/i, ""),
                name: e,
                wordCount: 0,
                modified: "",
                tags: [],
                category: "",
                author: "",
                license: "CC BY-NC-SA 4.0",
                licenseUrl: "",
                pinned: false
            }, t || {}, {
                displayName: n.displayName || t && t.displayName || e.replace(/\.md$/i, ""),
                wordCount: n.wordCount > 0 ? n.wordCount : t && t.wordCount || 0,
                modified: n.modified || t && t.modified || ""
            });
            document.title = s.displayName + " - " + (window.YSM_SITE_TITLE || "You Super Markdown");
            Fe = e;
            let o = n.content;
            // v3.3.0：提取 !video[标题](站内相对路径) 语法为占位符，marked 渲染后转 <video> 播放器（防跳转）
                        var i = [];
            o = o.replace(/!video\[([^\]]*)\]\(([^)\s]+)\)/g, function(e, t, n) {
                i.push({
                    title: t,
                    src: n.trim()
                });
                return "@@YSM_VIDEO_" + (i.length - 1) + "@@";
            });
            o = o.replace(/^(<!--.*?-->)?\s*#\s+.*\r?\n?/, "");
            // v4.9.2-fix：先保护 \(...\)/\[...\] 定界符（CommonMark 会剥反斜杠），再交给 marked 渲染
                        o = protectLatexDelims(o);
            const c = typeof marked !== "undefined" ? marked.parse(o) : "<pre>" + escapeHTML(o) + "</pre>";
            w.innerHTML = buildDocHeader(s) + c + buildBottomCards(s);
            w.querySelectorAll("table").forEach(e => {
                if (!e.parentElement.classList.contains("table-wrapper")) {
                    const t = document.createElement("div");
                    t.className = "table-wrapper";
                    e.parentNode.insertBefore(t, e);
                    t.appendChild(e);
                }
            });
            // 外链安全：禁止外链在当前页直接跳转离开本站；统一新窗口 + noopener noreferrer（防 tabnabbing）
                        w.querySelectorAll("a[href]").forEach(function(e) {
                var t = e.getAttribute("href") || "";
                if (/^(https?:)?\/\//i.test(t) && t.indexOf(window.location.host) === -1) {
                    e.setAttribute("target", "_blank");
                    e.setAttribute("rel", "noopener noreferrer");
                }
            });
            // v4.9.0：正文含公式（$$ / \( \[ / 成对 $）时按需加载 MathJax 并排版正文容器（无公式则零加载）
                        if (hasMathInMd(o)) {
                ensureMathJax(function() {
                    // v5.2.1：等引擎就绪后，把公式排版推迟到空闲时段——正文文字先上屏，公式随后补齐
                    whenIdle(function() {
                        renderMathBlocks(w);
                    });
                });
            }
            // v3.2.5：mermaid 流程图渲染（```mermaid 代码块 → 实际图表；优先于 hljs 高亮处理）；v4.2.2 起按需加载
            // v4.7.14：mermaid 渲染完成后执行 hljs 和 addCopyButtons，避免异步加载时序问题
                        renderMermaidBlocks(w, function() {
                // v5.2.1：代码高亮改为分片 + 空闲调度（长文大量代码块不再首帧同步全量高亮）；完成后加复制按钮
                highlightCodeBlocks(w, addCopyButtons);
            });
            // v3.3.0：视频语法 → 播放器（仅站内 data/videos/ 相对路径；外链按纯文本保留）
                        renderYmVideos(w, i);
            // v3.3.2：正文图片懒加载（首屏外的大图不预先下载，滚动到才加载）
                        w.querySelectorAll("img").forEach(function(e) {
                if (!e.getAttribute("loading")) e.setAttribute("loading", "lazy");
                e.setAttribute("decoding", "async");
            });
            extractHeadings();
            w.querySelectorAll("p").forEach(e => {
                const t = e.querySelectorAll("a");
                if (t.length === 1 && e.textContent.trim() === t[0].textContent.trim() && t[0].getAttribute("href") && t[0].getAttribute("href").startsWith("#")) {
                    e.classList.add("toc-link");
                }
            });
            w.querySelectorAll('a[href^="#"]').forEach(e => {
                e.addEventListener("click", function(e) {
                    e.preventDefault();
                    const t = this.getAttribute("href");
                    const n = decodeURIComponent(t.substring(1));
                    let i = document.getElementById(n);
                    // v4.4.1：精确 id 未命中时按 ysmSlug 归一化模糊匹配标题——
                    //         兼容手写锚点（#一项目概述）与 marked slug 生成 id 的标点差异
                                        if (!i) {
                        const e = ysmSlug(n);
                        if (e) {
                            w.querySelectorAll("h1, h2, h3, h4, h5, h6").forEach(t => {
                                if (!i && t.id && ysmSlug(t.id) === e) i = t;
                            });
                        }
                    }
                    if (i) {
                        i.scrollIntoView({
                            behavior: "smooth",
                            block: "start"
                        });
                        history.replaceState(null, "", "#" + n);
                    }
                });
            });
            renderTocPopup();
            renderSidebarToc(Pe);
            bindImageLightbox();
            highlightSidebarItem(e);
            if (be) be.textContent = s.displayName;
            if (u.classList.contains("active") && Ae) {
                renderDocumentOutline();
                oe.style.display = "block";
            }
            updateActiveHeading(true);
            updatePrevNext();
            const r = document.getElementById("shareCardBtnInline");
            if (r) {
                r.addEventListener("click", () => {
                    ce.classList.add("active");
                    re.innerHTML = "";
                    var t = getShortUrl(e);
                    new QRCode(re, {
                        text: t,
                        width: 180,
                        height: 180
                    });
                    var n = document.getElementById("shareCopyLinkBtn");
                    if (n) {
                        n.onclick = null;
                        n.addEventListener("click", function() {
                            var t = getShortUrl(e);
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(t).then(function() {
                                    showToast("链接已复制");
                                }).catch(function() {
                                    copyFallback(t);
                                });
                            } else {
                                copyFallback(t);
                            }
                        });
                    }
                });
            }
            updatePrevNext();
            if (!localStorage.getItem("md-keyboard-hint-shown")) {
                setTimeout(() => showToast("← → 切换文章 | ESC 返回首页 | T 回到顶部", 3e3), 500);
                localStorage.setItem("md-keyboard-hint-shown", "1");
            }
            const l = sessionStorage.getItem("md-read-scroll-" + e);
            if (l) {
                requestAnimationFrame(() => window.scrollTo(0, parseInt(l)));
            }
            // v3.3.11：公告为单向通知——公告关联文章（如「更新历史」）不显示评论区
                        var a = {};
            (window.YSM_ANNOUNCEMENTS || []).forEach(function(e) {
                if (e.article) a[e.article] = 1;
            });
            if (a[e]) {
                cmtOnArticleHide();
            } else {
                cmtOnArticleLoad();
            }
        } catch (t) {
            if (seq !== loadSeq || (t && t.name === "AbortError")) return;
            console.error("[loadFile render error]", t);
            showRenderError(e);
            cmtOnArticleHide();
        }
    }
    window.addEventListener("popstate", () => {
        // v5.4.3：前进/后退同样作废在途加载（loadFile/showHome 内部会再次取消，幂等）
        if (loadCtrl) loadCtrl.abort();
        const e = getUrlParam("file") || window.YSM_FILE || "";
        if (e && Ie.some(t => t.name === e)) {
            loadFile(e, false);
        } else {
            showHome(false);
        }
    });
    if (typeof marked !== "undefined") {
        const e = new marked.Renderer;
        e.html = function(e) {
            const t = e && typeof e === "object" && e.text !== undefined ? e.text : e;
            return escapeHTML(String(t));
        };
        e.list = e => e;
        e.listitem = e => `<p>${e}</p>`;
        e.hr = () => "";
        // v4.4.1：标题 id 生成改用 ysmSlug——去除中文顿号/括号/全角标点等，与文章正文手写锚点
        //         （如 [目录](#一项目概述)）保持一致；否则 marked 默认 slugger 保留「、」导致
        //         id=“一、项目概述”≠链接“#一项目概述”，点击目录无法跳转。
        //         slugger 参数仅用于同级重复标题去重（同 slug 追加 -2/-3…）。
                e.heading = function(e, t, n, i) {
            const a = ysmSlug(n);
            const s = i ? i.slug(a) : a;
            return `<h${t} id="${s}">${e}</h${t}>`;
        };
        marked.setOptions({
            gfm: true,
            breaks: false,
            smartLists: true,
            renderer: e
        });
    }
    async function init() {
        await loadFileList();
        const e = getUrlParam("file") || window.YSM_FILE || "";
        if (e) loadFile(e, false); else {
            showHome(false);
            // v4.7.3：首页场景也检查登录态——设备验证进行中（切后台看邮箱后页面被移动端重载）时恢复验证弹窗
            // v4.7.4：?admin_login=1 首页场景同样自动弹出登录弹窗
                        cmtCheckAuth().then(cmtHandleAdminLoginHint);
        }
    }
    let De = null;
    function showToast(e, t = 2e3) {
        ue.textContent = e;
        ue.classList.add("show");
        clearTimeout(De);
        De = setTimeout(() => ue.classList.remove("show"), t);
    }
    let Ve = [];
    let Ye = 0;
    function openLightbox(e, t, n) {
        Ve = Array.from(w.querySelectorAll("img")).map(e => ({
            src: e.src,
            alt: e.alt
        }));
        Ye = typeof n === "number" ? n : Ve.findIndex(t => t.src === e);
        if (Ye < 0) Ye = 0;
        fe.src = e;
        fe.alt = t || "";
        pe.classList.add("active");
        document.body.style.overflow = "hidden";
        pe.querySelectorAll(".img-lightbox-close,.img-lightbox-prev,.img-lightbox-next").forEach(e => e.remove());
        const i = document.createElement("button");
        i.className = "img-lightbox-close";
        i.innerHTML = '<svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        i.addEventListener("click", closeLightbox);
        pe.appendChild(i);
        if (Ve.length > 1) {
            const e = document.createElement("button");
            e.className = "img-lightbox-prev";
            e.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>';
            e.addEventListener("click", e => {
                e.stopPropagation();
                navigateLightbox(-1);
            });
            pe.appendChild(e);
            const t = document.createElement("button");
            t.className = "img-lightbox-next";
            t.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>';
            t.addEventListener("click", e => {
                e.stopPropagation();
                navigateLightbox(1);
            });
            pe.appendChild(t);
        }
    }
    function navigateLightbox(e) {
        Ye = (Ye + e + Ve.length) % Ve.length;
        fe.src = Ve[Ye].src;
        fe.alt = Ve[Ye].alt || "";
    }
    function closeLightbox() {
        pe.classList.remove("active");
        document.body.style.overflow = "";
    }
    pe.addEventListener("click", e => {
        if (e.target === pe) closeLightbox();
    });
    document.addEventListener("keydown", e => {
        if (e.key === "Escape" && pe.classList.contains("active")) closeLightbox();
    });
    function bindImageLightbox() {
        w.querySelectorAll("img").forEach((e, t) => {
            e.style.cursor = "zoom-in";
            e.addEventListener("click", () => openLightbox(e.src, e.alt, t));
        });
    }
    document.addEventListener("keydown", e => {
        if (e.target.tagName === "INPUT" || e.target.tagName === "TEXTAREA" || e.target.tagName === "SELECT") return;
        if (e.key === "Escape" && pe.classList.contains("active")) {
            closeLightbox();
            return;
        }
        if (e.key === "ArrowLeft" && pe.classList.contains("active")) {
            navigateLightbox(-1);
            return;
        }
        if (e.key === "ArrowRight" && pe.classList.contains("active")) {
            navigateLightbox(1);
            return;
        }
        if (Ae) {
            if (e.key === "ArrowLeft" || e.key === "ArrowUp") {
                e.preventDefault();
                if (Ue > 0) loadFile(Ie[Ue - 1].name);
            } else if (e.key === "ArrowRight" || e.key === "ArrowDown") {
                e.preventDefault();
                if (Ue < Ie.length - 1) loadFile(Ie[Ue + 1].name);
            } else if (e.key === "t" || e.key === "T") {
                e.preventDefault();
                window.scrollTo({
                    top: 0,
                    behavior: "smooth"
                });
            } else if (e.key === "Escape") {
                e.preventDefault();
                showHome();
            }
        } else {
            if (e.key === "Escape") {
                closeAllPanels();
            }
        }
    });
    window.toggleKbdHelp = function toggleKbdHelp() {
        let e = document.querySelector(".kbd-help-overlay");
        if (!e) {
            e = document.createElement("div");
            e.className = "kbd-help-overlay";
            e.innerHTML = `\n        <div class="kbd-help-box">\n            <div class="kbd-help-title">\n                <svg viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><line x1="6" y1="10" x2="6" y2="10"/><line x1="10" y1="10" x2="10" y2="10"/><line x1="14" y1="10" x2="14" y2="10"/><line x1="18" y1="10" x2="18" y2="10"/><line x1="8" y1="14" x2="16" y2="14"/></svg>\n                快捷键\n            </div>\n            <div class="kbd-help-section">\n                <h4>导航</h4>\n                <div class="kbd-row"><span class="kbd-label">搜索文档（全文）</span><span class="kbd-keys"><kbd>/</kbd></span></div>\n                <div class="kbd-row"><span class="kbd-label">归档视图切换</span><span class="kbd-keys"><kbd>A</kbd></span></div>\n                <div class="kbd-row"><span class="kbd-label">打开 RSS 订阅</span><span class="kbd-keys"><kbd>R</kbd></span></div>\n                <div class="kbd-row"><span class="kbd-label">上一篇文章</span><span class="kbd-keys"><kbd>←</kbd></span></div>\n                <div class="kbd-row"><span class="kbd-label">下一篇文章</span><span class="kbd-keys"><kbd>→</kbd></span></div>\n                <div class="kbd-row"><span class="kbd-label">返回首页</span><span class="kbd-keys"><kbd>Esc</kbd></span></div>\n                <div class="kbd-row"><span class="kbd-label">回到顶部</span><span class="kbd-keys"><kbd>T</kbd></span></div>\n            </div>\n            <div class="kbd-help-section">\n                <h4>阅读</h4>\n                <div class="kbd-row"><span class="kbd-label">打印文章</span><span class="kbd-keys"><kbd>P</kbd></span></div>\n                <div class="kbd-row"><span class="kbd-label">折叠/展开侧边栏</span><span class="kbd-keys"><kbd>S</kbd></span></div>\n                <div class="kbd-row"><span class="kbd-label">关闭弹窗</span><span class="kbd-keys"><kbd>Esc</kbd></span></div>\n            </div>\n            <div class="kbd-help-section">\n                <h4>灯箱</h4>\n                <div class="kbd-row"><span class="kbd-label">上一张图片</span><span class="kbd-keys"><kbd>←</kbd></span></div>\n                <div class="kbd-row"><span class="kbd-label">下一张图片</span><span class="kbd-keys"><kbd>→</kbd></span></div>\n                <div class="kbd-row"><span class="kbd-label">关闭</span><span class="kbd-keys"><kbd>Esc</kbd></span></div>\n            </div>\n        </div>`;
            e.addEventListener("click", t => {
                if (t.target === e) e.classList.remove("show");
            });
            document.body.appendChild(e);
        }
        e.classList.toggle("show");
    };
    function toggleSidebar() {
        const e = document.getElementById("sidebar");
        if (!e) return;
        const t = e.style.display === "none";
        e.style.display = t ? "flex" : "none";
        document.body.style.paddingLeft = t ? "280px" : "0";
        // v2.6.5：折叠按钮在 sidebar 内，收起后自身也消失；由外部 restore 按钮提供恢复入口
                const n = document.getElementById("sidebarRestoreBtn");
        if (n) n.style.display = t ? "none" : "flex";
        localStorage.setItem("md-sidebar-hidden", t ? "0" : "1");
    }
    window.toggleSidebar = toggleSidebar;
    if (localStorage.getItem("md-sidebar-hidden") === "1") {
        const e = document.getElementById("sidebar");
        if (e) {
            e.style.display = "none";
            document.body.style.paddingLeft = "0";
        }
        const t = document.getElementById("sidebarRestoreBtn");
        if (t) t.style.display = "flex";
    }
    (function() {
        const isTyping = () => {
            const e = document.activeElement;
            return e && (e.tagName === "INPUT" || e.tagName === "TEXTAREA" || e.tagName === "SELECT" || e.isContentEditable);
        };
        document.addEventListener("keydown", e => {
            if (isTyping() || pe.classList.contains("active")) return;
            if ((e.key === "/" || e.ctrlKey && e.key === "k") && !Ae) {
                e.preventDefault();
                xe ? xe.focus() : v.focus();
                return;
            }
            if (e.key === "?" && !e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                toggleKbdHelp();
                return;
            }
            if (e.key === "s" && !e.ctrlKey && !e.metaKey && !Ae) {
                e.preventDefault();
                toggleSidebar();
                return;
            }
            // v4.1.0：新增功能快捷键——A 归档视图切换、R 打开 RSS 订阅
                        if (e.key === "a" && !e.ctrlKey && !e.metaKey && !Ae) {
                e.preventDefault();
                He = !He;
                renderCategoryBar();
                renderHomeContent();
                return;
            }
            if (e.key === "r" && !e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                window.open("/index.php?action=rss_guide", "_blank", "noopener");
                return;
            }
            if (e.key === "p" && !e.ctrlKey && !e.metaKey && Ae) {
                e.preventDefault();
                window.print();
                return;
            }
        });
    })();
    const Je = document.getElementById("commentSection");
    const We = document.getElementById("commentArea");
    const Qe = document.getElementById("commentListSection");
    const Ge = document.getElementById("cmtCapsuleBar");
    const Xe = document.getElementById("cmtCapsuleBtn");
    const Ke = document.getElementById("cmtUserBar");
    const Ze = document.getElementById("cmtUserInner");
    const et = document.getElementById("cmtUserAvatar");
    const tt = document.getElementById("cmtUserGreeting");
    const nt = document.getElementById("cmtLogoutBtn");
    const it = document.getElementById("cmtTextarea");
    const at = document.getElementById("cmtSendBtn");
    const st = document.getElementById("cmtList");
    const ot = document.getElementById("cmtAuthModal");
    const ct = document.getElementById("cmtAuthTitle");
    const rt = document.getElementById("cmtAuthSlide");
    const lt = document.getElementById("cmtLoginForm");
    const dt = document.getElementById("cmtRegForm");
    const mt = document.getElementById("cmtLoginQQ");
    const ut = document.getElementById("cmtLoginPw");
    const pt = document.getElementById("cmtLoginErr");
    const ft = document.getElementById("cmtLoginBtn");
    const vt = document.getElementById("cmtRegQQ");
    const yt = document.getElementById("cmtRegNick");
    const gt = document.getElementById("cmtRegPw");
    const ht = document.getElementById("cmtRegErr");
    const xt = document.getElementById("cmtRegBtn");
    // v3.3.16：密码显示切换（登录/注册）——线稿眼睛两态图标 + 输入框 type 切换
    // 此前按钮仅有 emoji 👁 且无绑定事件（死按钮），本次补齐逻辑并统一为 SVG 线稿
        (function bindPwToggles() {
        const e = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>';
        const t = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/><line x1="3" y1="3" x2="21" y2="21"/></svg>';
        [ "cmtLoginPwToggle", "cmtRegPwToggle", "cmtResetPwToggle" ].forEach(n => {
            const i = document.getElementById(n);
            const a = i ? i.closest(".cmt-pw-row").querySelector("input") : null;
            if (!i || !a) return;
            i.innerHTML = e;
            i.addEventListener("click", () => {
                const n = a.type === "password";
                a.type = n ? "text" : "password";
                i.innerHTML = n ? t : e;
                i.setAttribute("aria-pressed", n ? "true" : "false");
                i.title = n ? "隐藏密码" : "显示/隐藏密码";
            });
        });
    })();
    // v2.9.0 注册验证控件
        const Lt = document.getElementById("cmtRegVerifyBox");
    const Et = document.getElementById("cmtRegEmail");
    const bt = document.getElementById("cmtRegSendCode");
    const wt = document.getElementById("cmtRegCode");
    const kt = document.body.getAttribute("data-reg-verify") === "1";
    // v4.4.0：注册算术人机验证弹窗 + 注册蜜罐字段
        const St = document.getElementById("cmtArithModal");
    const Tt = document.getElementById("cmtArithQuestion");
    const Ct = document.getElementById("cmtArithAnswer");
    const It = document.getElementById("cmtArithErr");
    const Bt = document.getElementById("cmtArithOk");
    const Mt = document.getElementById("cmtArithCancel");
    const Ht = document.getElementById("cmtRegHoneypot");
    // v2.11.0：滑块人机验证已彻底移除（cmtRegCaptchaBox / regCaptchaOn 删除）
    // v2.10.0：邮箱验证开关（控制前台个人设置里的邮箱绑定/更换入口）与 CSRF token
        const At = document.body.getAttribute("data-email-change") === "1";
    const Pt = document.body.getAttribute("data-csrf") || "";
    const Rt = document.getElementById("cmtSwitchText");
    const Ot = document.getElementById("cmtSwitchBtn");
    // v4.7.0：陌生设备登录邮件二次验证 + 找回密码
        const Ut = document.getElementById("cmtDevForm");
    const Ft = document.getElementById("cmtDevTip");
    const jt = document.getElementById("cmtDevCode");
    const Nt = document.getElementById("cmtDevErr");
    const qt = document.getElementById("cmtDevBtn");
    const _t = document.getElementById("cmtDevBack");
    const $t = document.getElementById("cmtResetForm");
    const zt = document.getElementById("cmtResetQQ");
    const Dt = document.getElementById("cmtResetEmail");
    const Vt = document.getElementById("cmtResetSendCode");
    const Yt = document.getElementById("cmtResetCode");
    const Jt = document.getElementById("cmtResetPw");
    const Wt = document.getElementById("cmtResetErr");
    const Qt = document.getElementById("cmtResetBtn");
    const Gt = document.getElementById("cmtResetBack");
    const Xt = document.getElementById("cmtForgotLink");
    const Kt = document.getElementById("cmtProfileModal");
    const Zt = document.getElementById("cmtEditNick");
    const en = document.getElementById("cmtEditSign");
    const tn = document.getElementById("cmtProfileErr");
    const nn = document.getElementById("cmtProfileSave");
    const an = document.getElementById("cmtAdminModal");
    const sn = document.getElementById("cmtAdminQQ");
    const on = document.getElementById("cmtAdminNick");
    const cn = document.getElementById("cmtAdminPw");
    const rn = document.getElementById("cmtAdminPw2");
    const ln = document.getElementById("cmtAdminErr");
    const dn = document.getElementById("cmtAdminSave");
    const mn = document.getElementById("cmtConfirmOverlay");
    const un = document.getElementById("cmtConfirmOk");
    const pn = document.getElementById("cmtConfirmCancel");
    const fn = document.body.dataset.guestComments === "1";
    let vn = null;
    let yn = "login";
    let gn = null;
    let hn = false;
    let xn = null;
    let Ln = new Set;
    function cmtEscape(e) {
        if (!e) return "";
        const t = document.createElement("div");
        t.textContent = e;
        return t.innerHTML;
    }
    function cmtGreeting() {
        const e = (new Date).getHours();
        if (e < 12) return "上午好";
        if (e < 14) return "中午好";
        return "下午好";
    }
    function cmtFormatTime(e) {
        if (!e) return "";
        const t = new Date(e.replace(/-/g, "/"));
        const n = Date.now();
        const i = (n - t.getTime()) / 1e3;
        if (i < 60) return "刚刚";
        if (i < 3600) return Math.floor(i / 60) + "分钟前";
        if (i < 86400) return Math.floor(i / 3600) + "小时前";
        const a = t.getFullYear(), s = t.getMonth() + 1, o = t.getDate();
        const c = t.getHours().toString().padStart(2, "0");
        const r = t.getMinutes().toString().padStart(2, "0");
        return a + "-" + s + "-" + o + " " + c + ":" + r;
    }
    function cmtAvatarHtml(e, t, n) {
        const i = cmtEscape((t || "?").charAt(0));
        // v2.10.0：优先使用自定义头像（data/avatars/...），未上传时回退 QQ 头像
                const a = e ? e : n ? "api.php?action=avatar&account=" + encodeURIComponent(n) : "";
        if (a) return '<img src="' + cmtEscape(a) + '" alt="" class="cmt-avatar-img" onerror="this.style.display=\'none\';this.parentNode.querySelector(\'.cmt-avatar-text\').style.display=\'flex\'"/><span class="cmt-avatar-text" style="display:none">' + i + "</span>";
        return '<span class="cmt-avatar-text">' + i + "</span>";
    }
    function cmtOpenModal(e) {
        e.classList.add("show");
    }
    function cmtCloseModal(e) {
        e.classList.remove("show");
    }
    [ ot, Kt, an ].forEach(e => {
        if (e) e.addEventListener("click", t => {
            if (t.target === e) e.classList.remove("show");
        });
    });
    if (Xe) Xe.addEventListener("click", () => {
        yn = "login";
        cmtUpdateAuthUI();
        if (rt) {
            rt.classList.remove("slide-out");
            rt.classList.remove("slide-in");
        }
        cmtOpenModal(ot);
    });
    // v2.11.3：打开编辑资料弹窗（主页用户下拉「编辑资料」与评论区用户栏共用）
        function cmtOpenProfileModal() {
        // v3.0.1：主页停留时 cmtUser 可能为 null（cmtCheckAuth 仅在文章加载时执行），而顶部下拉
        // 「编辑资料」按 user-status 显示已登录——点击时若状态缺失则实时恢复，避免静默无反应
        const doOpen = () => {
            Zt.value = vn.nickname || "";
            en.value = vn.signature || "";
            // v2.10.0：打开编辑资料弹窗时填充头像预览 + 邮箱区
                        const e = document.getElementById("cmtProfileAvatar");
            if (e) {
                const t = vn.avatar || "";
                e.innerHTML = t ? '<img src="' + cmtEscape(t) + '" alt="" onerror="this.style.display=\'none\'">' : "";
            }
            const t = document.getElementById("cmtProfileEmailWrap");
            if (t) t.style.display = At ? "block" : "none";
            const n = document.getElementById("cmtEditEmail");
            if (n) {
                n.value = "";
                n.placeholder = vn.email ? "当前绑定：" + vn.email + "，输入新邮箱更换" : "输入邮箱进行绑定";
            }
            const i = document.getElementById("cmtEditEmailCode");
            if (i) i.value = "";
            const a = document.getElementById("cmtEmailErr");
            if (a) a.textContent = "";
            const s = document.getElementById("cmtEmailSendCode");
            if (s) {
                s.disabled = false;
                s.textContent = "获取验证码";
            }
            cmtOpenModal(Kt);
        };
        if (vn) {
            doOpen();
            return;
        }
        fetch("api.php?action=check").then(e => e.json()).then(e => {
            if (e.success && e.loggedIn) {
                vn = e.user;
                if (e.isAdminFirstLogin) hn = true;
                cmtUpdateUI();
                doOpen();
            } else {
                vn = null;
                cmtUpdateUI();
                if (ot) {
                    yn = "login";
                    cmtUpdateAuthUI();
                    cmtOpenModal(ot);
                }
            }
        }).catch(() => {});
    }
    if (Ze) Ze.addEventListener("click", () => {
        cmtOpenProfileModal();
    });
    if (nt) nt.addEventListener("click", e => {
        e.stopPropagation();
        fetch("api.php?action=logout", {
            method: "POST"
        }).catch(() => {});
        vn = null;
        cmtUpdateUI();
    });
    // v2.11.4：切换防抖——动画期间忽略重复点击（此前快速双击会排队两个 setTimeout，
    // 状态被来回切两次，表现为「要点两下才切过去」）
        let En = false;
    if (Ot) Ot.addEventListener("click", () => {
        if (En) return;
        En = true;
        rt.classList.remove("slide-in");
        rt.classList.add("slide-out");
        setTimeout(() => {
            yn = yn === "login" ? "register" : "login";
            cmtUpdateAuthUI();
            rt.classList.remove("slide-out");
            rt.classList.add("slide-in");
            setTimeout(() => {
                rt.classList.remove("slide-in");
                En = false;
            }, 300);
        }, 200);
    });
    function cmtUpdateAuthUI() {
        const e = yn === "login";
        if (ct) ct.textContent = e ? "登录" : "注册";
        if (lt) lt.style.display = e ? "flex" : "none";
        if (dt) dt.style.display = e ? "none" : "flex";
        if (Rt) Rt.textContent = e ? "还没有账号？" : "已有账号？";
        if (Ot) Ot.textContent = e ? "立即注册" : "去登录";
        if (pt) pt.textContent = "";
        if (ht) ht.textContent = "";
        // v4.7.0：登录/注册切换时隐藏设备验证与找回视图
                if (Ut) Ut.style.display = "none";
        if ($t) $t.style.display = "none";
    }
    if (ft) ft.addEventListener("click", async () => {
        const e = mt.value.trim(), t = ut.value;
        pt.textContent = "";
        if (!e || !t) {
            pt.textContent = "请填写QQ号和密码";
            return;
        }
        // v2.11.1：提交前动态刷新 token（与当前 session 绑定，杜绝「会话失败」）
                await ensureFreshCsrf();
        ft.disabled = true;
        ft.textContent = "登录中...";
        fetch("api.php?action=login", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                account: e,
                password: t
            })
        }).then(e => e.json()).then(e => {
            // v4.7.0：管理角色陌生设备 → 切到设备验证视图（邮件验证码确认后完成登录）
            if (e.need_device_verify) {
                cmtShowDevVerify(e.masked_email || "");
                return;
            }
            if (e.success) {
                vn = e.user;
                if (e.isAdminFirstLogin) hn = true;
                cmtCloseModal(ot);
                cmtUpdateUI();
                cmtLoad();
                cmtMaybeGoAdmin();
            } else {
                // v2.10.2：CSRF 校验失败多为会话 cookie 未生效（浏览器 cookie 策略/瞬态），自动刷新页面一次重取 token+cookie
                if (e.error === "CSRF 校验失败" && !window.__csrfRetried) {
                    window.__csrfRetried = true;
                    pt.textContent = "会话校验失败，正在刷新页面，请重新登录...";
                    setTimeout(function() {
                        location.reload();
                    }, 600);
                    return;
                }
                // v2.11.0：锁定（429）时显示剩余秒数倒计时
                                if (e.locked_seconds > 0) {
                    let t = e.locked_seconds;
                    const fmt = () => "登录失败次数过多，请 " + Math.floor(t / 60) + " 分 " + t % 60 + " 秒后重试";
                    pt.textContent = fmt();
                    const n = setInterval(() => {
                        t--;
                        if (t <= 0) {
                            clearInterval(n);
                            pt.textContent = "";
                        } else pt.textContent = fmt();
                    }, 1e3);
                } else {
                    pt.textContent = e.error || "登录失败";
                }
            }
        }).catch(() => {
            pt.textContent = "网络错误";
        }).finally(() => {
            ft.disabled = false;
            ft.textContent = "登录";
        });
    });
    // ---- v4.7.0：陌生设备登录邮件二次验证 + 找回密码 ----
    // v4.7.3：切换到设备验证视图（登录返回 need_device_verify 或刷新后 check 报告 pending 时共用，
    //         解决「切后台看邮箱返回后弹窗丢失」——页面被移动端浏览器重载时通过 check 恢复弹窗）
        function cmtShowDevVerify(e) {
        if (Ft) Ft.textContent = e ? "验证码已发送至 " + e + "，请查收邮箱" : "验证码已发送，请查收邮箱";
        if (Nt) Nt.textContent = "";
        if (jt) jt.value = "";
        if (lt) lt.style.display = "none";
        if (dt) dt.style.display = "none";
        if ($t) $t.style.display = "none";
        if (Ut) Ut.style.display = "flex";
        if (ct) ct.textContent = "设备验证";
        setTimeout(() => {
            if (jt) jt.focus();
        }, 60);
    }
    function cmtBackToLogin() {
        lt.style.display = "flex";
        if (Ut) Ut.style.display = "none";
        if ($t) $t.style.display = "none";
        if (dt) dt.style.display = "none";
        ct.textContent = "登录";
        if (Rt) Rt.textContent = "还没有账号？";
        if (Ot) Ot.textContent = "立即注册";
    }
    // 设备验证码提交
        if (qt) qt.addEventListener("click", async () => {
        const e = jt.value.trim();
        Nt.textContent = "";
        if (!/^\d{6}$/.test(e)) {
            Nt.textContent = "请输入6位数字验证码";
            return;
        }
        await ensureFreshCsrf();
        qt.disabled = true;
        qt.textContent = "验证中...";
        fetch("api.php?action=device_verify", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                code: e
            })
        }).then(e => e.json()).then(e => {
            if (e.success) {
                vn = e.user;
                if (e.isAdminFirstLogin) hn = true;
                cmtCloseModal(ot);
                cmtUpdateUI();
                cmtLoad();
            } else {
                Nt.textContent = e.error || "验证失败";
                // 连续输错过多 → 自动返回登录（后端已清 pending）
                                if (e.fails >= 5) setTimeout(() => {
                    cmtBackToLogin();
                    Nt.textContent = "验证次数过多，请重新登录";
                }, 600);
            }
        }).catch(() => {
            Nt.textContent = "网络错误";
        }).finally(() => {
            qt.disabled = false;
            qt.textContent = "确认设备";
        });
    });
    if (_t) _t.addEventListener("click", () => {
        cmtBackToLogin();
        pt.textContent = "";
    });
    // 忘记密码 → 找回视图
        if (Xt) Xt.addEventListener("click", () => {
        lt.style.display = "none";
        if (Ut) Ut.style.display = "none";
        if (dt) dt.style.display = "none";
        $t.style.display = "flex";
        ct.textContent = "找回密码";
        Wt.textContent = "";
        setTimeout(() => {
            if (zt) zt.focus();
        }, 60);
    });
    if (Gt) Gt.addEventListener("click", () => {
        cmtBackToLogin();
        pt.textContent = "";
    });
    // 发送找回验证码
        if (Vt) Vt.addEventListener("click", async () => {
        const e = zt.value.trim(), t = Dt.value.trim();
        Wt.textContent = "";
        if (!e || !t) {
            Wt.textContent = "请填写账号与绑定邮箱";
            return;
        }
        await ensureFreshCsrf();
        Vt.disabled = true;
        Vt.textContent = "发送中...";
        fetch("api.php?action=password_reset", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                mode: "send_code",
                account: e,
                email: t
            })
        }).then(e => e.json()).then(e => {
            if (e.success) {
                Wt.textContent = "验证码已发送至 " + (e.masked_email || "绑定邮箱") + "，请在5分钟内完成重置";
                Wt.style.color = "";
            } else {
                Wt.textContent = e.error || "发送失败";
                Wt.style.color = "#e5484d";
            }
        }).catch(() => {
            Wt.textContent = "网络错误";
            Wt.style.color = "#e5484d";
        }).finally(() => {
            Vt.disabled = false;
            Vt.textContent = "发送验证码";
        });
    });
    // 重置密码
        if (Qt) Qt.addEventListener("click", async () => {
        const e = zt.value.trim(), t = Yt.value.trim(), n = Jt.value;
        Wt.textContent = "";
        Wt.style.color = "";
        if (!e || !t || !n) {
            Wt.textContent = "请完整填写账号、验证码与新密码";
            return;
        }
        await ensureFreshCsrf();
        Qt.disabled = true;
        Qt.textContent = "重置中...";
        fetch("api.php?action=password_reset", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                mode: "do_reset",
                account: e,
                code: t,
                new_password: n
            })
        }).then(e => e.json()).then(e => {
            if (e.success) {
                Wt.textContent = "密码已重置，请使用新密码登录";
                Jt.value = "";
                Yt.value = "";
                setTimeout(() => {
                    cmtBackToLogin();
                }, 1200);
            } else {
                Wt.textContent = e.error || "重置失败";
            }
        }).catch(() => {
            Wt.textContent = "网络错误";
        }).finally(() => {
            Qt.disabled = false;
            Qt.textContent = "重置密码";
        });
    });
    // ---- v2.11.0：滑块人机验证组件已彻底移除 ----
        function cmtRegShowVerify() {
        if (Lt) Lt.style.display = "flex";
    }
    // 切到注册时显示验证区块
        if (Ot) {
        const e = Ot;
        e.addEventListener("click", () => {
            setTimeout(() => {
                if (dt && dt.style.display !== "none") {
                    if (kt) cmtRegShowVerify(); else if (Lt) Lt.style.display = "none";
                } else if (Lt) Lt.style.display = "none";
            }, 250);
        });
    }
    // 发码按钮：v4.4.0 先弹算术人机验证，答对后才真正发码（60s 倒计时；后端同样限制）
        if (bt) bt.addEventListener("click", () => {
        const e = Et.value.trim();
        const t = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        if (!t.test(e)) {
            ht.textContent = "请输入正确的邮箱";
            return;
        }
        ht.textContent = "";
        // 获取随机算术题并弹窗
                fetch("api.php?action=arith_challenge").then(e => e.json()).then(e => {
            if (!e.success) {
                ht.textContent = "人机验证获取失败，请重试";
                return;
            }
            Tt.textContent = e.expression;
            Ct.value = "";
            It.textContent = "";
            Bt.disabled = false;
            cmtOpenModal(St);
            setTimeout(() => Ct.focus(), 50);
        }).catch(() => {
            ht.textContent = "网络错误";
        });
    });
    // 算术题确认：答对才置 pending，随后才真正发码
        if (Bt) Bt.addEventListener("click", () => {
        const e = Ct.value.trim();
        if (!e) {
            It.textContent = "请输入计算结果";
            return;
        }
        Bt.disabled = true;
        It.textContent = "验证中...";
        const t = Et.value.trim();
        fetch("api.php?action=send_register_code", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                email: t,
                arith_answer: e
            })
        }).then(e => e.json()).then(e => {
            if (e.success) {
                cmtCloseModal(St);
                ht.textContent = "";
                let e = 60;
                bt.textContent = e + "s 后重发";
                const t = setInterval(() => {
                    e--;
                    if (e <= 0) {
                        clearInterval(t);
                        bt.textContent = "获取验证码";
                        bt.disabled = false;
                    } else bt.textContent = e + "s 后重发";
                }, 1e3);
            } else {
                It.textContent = e.error || "验证失败";
                // 答错/过期：自动换一题重试
                                fetch("api.php?action=arith_challenge").then(e => e.json()).then(e => {
                    if (e.success) {
                        Tt.textContent = e.expression;
                        Ct.value = "";
                    }
                }).catch(() => {});
                Bt.disabled = false;
            }
        }).catch(() => {
            It.textContent = "网络错误";
            Bt.disabled = false;
        });
    });
    if (Mt) Mt.addEventListener("click", () => {
        cmtCloseModal(St);
        Bt.disabled = false;
    });
    if (xt) xt.addEventListener("click", async () => {
        const e = vt.value.trim(), t = yt.value.trim(), n = gt.value;
        const i = kt ? Et.value.trim() : "";
        const a = kt ? wt.value.trim() : "";
        ht.textContent = "";
        if (!e || !n) {
            ht.textContent = "请填写QQ号和密码";
            return;
        }
        if (n.length < 8 || !/[a-z]/.test(n) || !/[A-Z]/.test(n) || !/[0-9]/.test(n)) {
            ht.textContent = "密码至少8位，且需包含大写字母、小写字母与数字";
            return;
        }
        if (kt) {
            if (!i) {
                ht.textContent = "请填写邮箱";
                return;
            }
            if (!a) {
                ht.textContent = "请填写邮箱验证码";
                return;
            }
        }
        // v2.11.1：提交前动态刷新 token（与当前 session 绑定）
                await ensureFreshCsrf();
        xt.disabled = true;
        xt.textContent = "注册中...";
        // v4.4.0：注册请求携带蜜罐字段原值（机器人自动填充会被后端静默拒绝）
                const s = Ht ? Ht.value.trim() : "";
        fetch("api.php?action=register", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                account: e,
                nickname: t,
                password: n,
                email: i,
                code: a,
                website: s
            })
        }).then(e => e.json()).then(e => {
            if (e.success) {
                if (e.user) {
                    vn = e.user;
                }
                cmtCloseModal(ot);
                cmtUpdateUI();
                cmtLoad();
            } else {
                ht.textContent = e.error || "注册失败";
            }
        }).catch(() => {
            ht.textContent = "网络错误";
        }).finally(() => {
            xt.disabled = false;
            xt.textContent = "注册";
        });
    });
    if (nn) nn.addEventListener("click", () => {
        const e = Zt.value.trim(), t = en.value.trim();
        tn.textContent = "";
        if (!e) {
            tn.textContent = "昵称不能为空";
            return;
        }
        fetch("api.php?action=update_profile", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                nickname: e,
                signature: t
            })
        }).then(e => e.json()).then(n => {
            if (n.success) {
                vn.nickname = e;
                vn.signature = t;
                cmtCloseModal(Kt);
                cmtUpdateUI();
            } else {
                tn.textContent = n.error || "保存失败";
            }
        }).catch(() => {
            tn.textContent = "网络错误";
        });
    });
    // ===== v2.10.0：头像上传（选择文件后立即上传；登录用户，含 CSRF） =====
        const bn = document.getElementById("cmtAvatarFile");
    const wn = document.getElementById("cmtEmailSendCode");
    const kn = document.getElementById("cmtEmailSave");
    if (bn) bn.addEventListener("change", () => {
        const e = bn.files && bn.files[0];
        if (!e) return;
        if (!/\.(jpg|jpeg|png|webp)$/i.test(e.name)) {
            tn.textContent = "仅支持 JPG / PNG / WEBP 格式";
            bn.value = "";
            return;
        }
        if (e.size > 2 * 1024 * 1024) {
            tn.textContent = "图片不能超过 2MB";
            bn.value = "";
            return;
        }
        tn.textContent = "头像上传中...";
        const t = new FormData;
        t.append("avatar", e);
        fetch("api.php?action=avatar_upload", {
            method: "POST",
            headers: {
                "X-CSRF-Token": Pt
            },
            body: t
        }).then(e => e.json()).then(e => {
            if (e.success) {
                vn.avatar = e.avatar;
                const t = document.getElementById("cmtProfileAvatar");
                if (t) t.innerHTML = '<img src="' + cmtEscape(e.avatar) + '" alt="" onerror="this.style.display=\'none\'">';
                tn.textContent = "";
                showToast("头像已更新");
                cmtUpdateUI();
            } else {
                tn.textContent = e.error || "上传失败";
            }
            bn.value = "";
        }).catch(() => {
            tn.textContent = "网络错误";
            bn.value = "";
        });
    });
    // 邮箱更换：发送验证码（60s 倒计时，复用后端冷却）
        if (wn) wn.addEventListener("click", () => {
        const e = cmtEditEmail.value.trim();
        const t = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        const n = document.getElementById("cmtEmailErr");
        if (!t.test(e)) {
            if (n) n.textContent = "请输入正确的邮箱";
            return;
        }
        if (n) n.textContent = "";
        wn.disabled = true;
        fetch("api.php?action=send_email_change_code", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": Pt
            },
            body: JSON.stringify({
                email: e
            })
        }).then(e => e.json()).then(e => {
            if (e.success) {
                if (n) n.textContent = "";
                let e = 60;
                wn.textContent = e + "s 后重发";
                const t = setInterval(() => {
                    e--;
                    if (e <= 0) {
                        clearInterval(t);
                        wn.textContent = "获取验证码";
                        wn.disabled = false;
                    } else wn.textContent = e + "s 后重发";
                }, 1e3);
            } else {
                if (n) n.textContent = e.error || "发送失败";
                wn.disabled = false;
            }
        }).catch(() => {
            if (n) n.textContent = "网络错误";
            wn.disabled = false;
        });
    });
    // 邮箱更换：验证码确认
        if (kn) kn.addEventListener("click", () => {
        const e = cmtEditEmail.value.trim();
        const t = cmtEditEmailCode.value.trim();
        const n = document.getElementById("cmtEmailErr");
        if (!e) {
            if (n) n.textContent = "请先填写新邮箱";
            return;
        }
        if (!t) {
            if (n) n.textContent = "请填写邮箱验证码";
            return;
        }
        if (n) n.textContent = "";
        kn.disabled = true;
        fetch("api.php?action=update_email", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": Pt
            },
            body: JSON.stringify({
                email: e,
                code: t
            })
        }).then(e => e.json()).then(e => {
            if (e.success) {
                vn.email = e.email;
                cmtEditEmail.placeholder = "当前绑定：" + e.email + "，输入新邮箱更换";
                cmtEditEmail.value = "";
                cmtEditEmailCode.value = "";
                if (n) n.textContent = "";
                showToast("邮箱绑定成功");
            } else {
                if (n) n.textContent = e.error || "绑定失败";
            }
            kn.disabled = false;
        }).catch(() => {
            if (n) n.textContent = "网络错误";
            kn.disabled = false;
        });
    });
    if (dn) dn.addEventListener("click", () => {
        const e = sn.value.trim(), t = on.value.trim(), n = cn.value, i = rn.value;
        ln.textContent = "";
        if (!e) {
            ln.textContent = "请填写QQ号";
            return;
        }
        if (!t) {
            ln.textContent = "请填写昵称";
            return;
        }
        if (n && (n.length < 8 || !/[a-z]/.test(n) || !/[A-Z]/.test(n) || !/[0-9]/.test(n))) {
            ln.textContent = "密码至少8位，且需包含大写字母、小写字母与数字";
            return;
        }
        if (n !== i) {
            ln.textContent = "两次密码不一致";
            return;
        }
        fetch("api.php?action=admin_setup", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                account: e,
                nickname: t,
                password: n
            })
        }).then(e => e.json()).then(e => {
            if (e.success) {
                if (vn) {
                    vn.nickname = t;
                    vn.avatar = e.user.avatar || vn.avatar;
                }
                cmtCloseModal(an);
                cmtUpdateUI();
            } else {
                ln.textContent = e.error || "保存失败";
            }
        }).catch(() => {
            ln.textContent = "网络错误";
        });
    });
    function cmtUpdateUI() {
        const e = !!vn;
        const t = e || fn;
        if (Ge) Ge.style.display = e ? "none" : "block";
        if (Ke) Ke.style.display = e ? "block" : "none";
        if (it) it.disabled = !t;
        if (e) {
            const e = vn.nickname || "用户";
            const t = vn.avatar || "";
            if (et) {
                const t = vn.avatar || "";
                if (t) {
                    et.innerHTML = '<img src="' + cmtEscape(t) + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%" onerror="this.style.display=\'none\'">';
                } else {
                    et.textContent = e.charAt(0);
                }
            }
            if (tt) tt.textContent = cmtGreeting() + "，" + e;
        } else {
            if (et) et.textContent = "";
        }
        if (e && vn.role === "admin" && hn) {
            hn = false;
            setTimeout(() => cmtOpenModal(an), 300);
        }
    }
    if (it) it.addEventListener("input", () => {
        if (at) at.classList.toggle("has-content", it.value.trim().length > 0);
    });
    if (at) at.addEventListener("click", () => {
        const e = it.value.trim();
        if (!e) return;
        if (!vn && !fn) return;
        at.disabled = true;
        fetch("api.php?action=post", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                article: Fe,
                content: e
            })
        }).then(e => e.json()).then(e => {
            if (e.success) {
                it.value = "";
                at.classList.remove("has-content");
                Sn = 1;
                cmtLoad();
            } else showToast(e.error || "评论失败");
        }).catch(() => showToast("网络错误")).finally(() => {
            at.disabled = false;
        });
    });
    var Sn = 1, Tn = 20;
 // v3.3.15：评论区根评论分页（默认每页 20 条，独立实现）
    // v3.3.16：回复展开后默认只显示前 5 条，更多点「查看全部」——大量回复不刷屏
        var Cn = 5;
    var In = [];
 // 「查看全部」需要重新读取当前页根评论数据
        function cmtLoad() {
        if (!Fe) return;
        Ln.clear();
        st.querySelectorAll(".cmt-item").forEach(e => {
            const t = e.querySelector(".cmt-reply-expand");
            const n = e.querySelector(".cmt-reply-list");
            if (t && t.classList.contains("open")) {
                Ln.add(e.dataset.id);
            }
            if (n && n.classList.contains("show")) {
                Ln.add(e.dataset.id);
            }
        });
        fetch("api.php?action=get&article=" + encodeURIComponent(Fe) + "&page=" + Sn + "&per_page=" + Tn).then(e => e.json()).then(e => {
            if (e.success) {
                cmtRender(e.comments, e);
                cmtRestoreExpandState();
            }
        }).catch(() => {});
    }
    function cmtRestoreExpandState() {
        Ln.forEach(e => {
            const t = st.querySelector('.cmt-item[data-id="' + e + '"]');
            if (t) {
                const e = t.querySelector(".cmt-reply-expand");
                const n = t.querySelector(".cmt-reply-list");
                if (e) e.classList.add("open");
                if (n) n.classList.add("show");
            }
        });
    }
    function cmtRenderReply(e, t, n) {
        if (t > 10) return '<div class="cmt-reply-item"><div class="cmt-reply-text" style="color:var(--text-muted)">[嵌套过深]</div></div>';
        const i = vn && vn.role === "admin";
        const a = i || vn && vn.id === e.user_id;
        let s = "";
        if (e.replies && e.replies.length) {
            s = '<div class="cmt-reply-nested">' + e.replies.map(n => cmtRenderReply(n, t + 1, e.nickname || "")).join("") + "</div>";
        }
        const o = t >= 2 && n ? '<span class="cmt-reply-arrow">▶︎</span><span class="cmt-reply-to">' + cmtEscape(n) + "</span>" : "";
        // v2.10.0：评论头像/昵称可点击打开个人详情页（无 user_id 的匿名/游客评论不生成链接）
                const c = e.user_id ? '<a class="cmt-user-link" href="user.php?id=' + encodeURIComponent(e.user_id) + '">' : "";
        const r = e.user_id ? "</a>" : "";
        return '<div class="cmt-reply-item" data-id="' + e.id + '" data-del="' + a + '">' + '<div class="cmt-reply-top"><div class="cmt-reply-avatar">' + c + cmtAvatarHtml(e.avatar, e.nickname, e.account) + r + "</div>" + '<div class="cmt-reply-info"><div class="cmt-reply-name-row">' + c + '<span class="cmt-reply-name">' + cmtEscape(e.nickname || "") + "</span>" + r + o + '<span class="cmt-reply-time">' + cmtFormatTime(e.created_at) + "</span></div>" + "</div></div>" + '<div class="cmt-reply-text">' + cmtEscape(e.content) + "</div>" + '<div class="cmt-reply-actions">' + '<button class="cmt-reply-act cmt-like-btn"><svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg><span>' + (e.likes || 0) + "</span></button>" + '<button class="cmt-reply-act cmt-reply-btn"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>回复</button>' + (a ? '<button class="cmt-reply-act cmt-del-btn" data-id="' + e.id + '"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>删除</button>' : "") + "</div>" + s + "</div>";
    }
    function cmtRender(e, t) {
        if (!e || !e.length) {
            st.innerHTML = '<div class="cmt-empty"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>暂无评论，快来抢沙发吧</div>';
            return;
        }
        const n = vn && vn.role === "admin";
        In = e;
        st.innerHTML = e.map(e => {
            const t = vn && vn.id === e.user_id;
            const i = n || t;
            const a = e.signature ? '<div class="cmt-sign">' + cmtEscape(e.signature) + "</div>" : "";
            let s = "";
            if (e.replies && e.replies.length) {
                // v3.3.16：回复展开后默认只显示前 5 条，更多点「查看全部」——大量回复不刷屏
                const t = e.replies.slice(0, Cn);
                s = '<div class="cmt-reply-list">' + t.map(t => cmtRenderReply(t, 1, e.nickname || "")).join("");
                if (e.replies.length > Cn) {
                    s += '<button class="cmt-more-replies" data-id="' + e.id + '"><svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>查看全部 ' + e.replies.length + " 条回复</button>";
                }
                s += "</div>";
            }
            const o = function countReplies(e) {
                return e.reduce((e, t) => e + 1 + countReplies(t.replies || []), 0);
            }(e.replies || []);
            const c = o ? '<button class="cmt-reply-expand"><span>' + o + '条回复</span><svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></button>' : "";
            const r = !e.signature ? " cmt-no-sign" : "";
            // v2.10.0：评论头像/昵称可点击打开个人详情页（无 user_id 的匿名/游客评论不生成链接）
                        const l = e.user_id ? '<a class="cmt-user-link" href="user.php?id=' + encodeURIComponent(e.user_id) + '">' : "";
            const d = e.user_id ? "</a>" : "";
            return '<div class="cmt-item" data-id="' + e.id + '" data-del="' + i + '">' + '<div class="cmt-top' + r + '"><div class="cmt-avatar">' + l + cmtAvatarHtml(e.avatar, e.nickname, e.account) + d + "</div>" + '<div class="cmt-info"><div class="cmt-name-row">' + l + '<span class="cmt-name">' + cmtEscape(e.nickname || "") + "</span>" + d + '<span class="cmt-time">' + cmtFormatTime(e.created_at) + "</span></div>" + a + "</div></div>" + '<div class="cmt-text">' + cmtEscape(e.content) + "</div>" + '<div class="cmt-actions">' + '<button class="cmt-act cmt-like-btn"><svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg><span>' + (e.likes || 0) + "</span></button>" + '<button class="cmt-act cmt-reply-btn"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>回复</button>' + (i ? '<button class="cmt-act cmt-del-btn" data-id="' + e.id + '"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>删除</button>' : "") + c + "</div>" + s + '<div class="cmt-reply-input-wrap"><textarea placeholder="写下你的回复..."></textarea><div class="cmt-reply-input-bottom"><button class="cmt-reply-send-btn">发送</button></div></div></div>';
        }).join("") + renderCmtPager(t || {});
        cmtBindEvents();
        cmtBindPagerEvents();
    }
    // v3.3.15：评论区根评论分页器（独立实现，不复用后台分页组件）
        function renderCmtPager(e) {
        if (!e || !e.total || e.total <= (e.per_page || 20)) return "";
        var t = e.page, n = e.total_pages;
        var i = '<div class="cmt-pager">' + '<span class="cmt-pager-info">共 ' + e.total + " 条评论 · 第 " + t + "/" + n + " 页</span>" + '<div class="cmt-pager-btns">' + '<button class="cmt-pg-btn" data-pg="' + (t - 1) + '"' + (t <= 1 ? " disabled" : "") + ">上一页</button>";
        var a = Math.max(1, t - 2), s = Math.min(n, t + 2);
        if (a > 1) i += '<span class="cmt-pg-ellipsis">…</span>';
        for (var o = a; o <= s; o++) i += '<button class="cmt-pg-btn' + (o === t ? " active" : "") + '" data-pg="' + o + '">' + o + "</button>";
        if (s < n) i += '<span class="cmt-pg-ellipsis">…</span>';
        i += '<button class="cmt-pg-btn" data-pg="' + (t + 1) + '"' + (t >= n ? " disabled" : "") + ">下一页</button>" + "</div></div>";
        return i;
    }
    function cmtBindPagerEvents() {
        st.querySelectorAll(".cmt-pg-btn").forEach(function(e) {
            e.addEventListener("click", function() {
                if (e.disabled) return;
                Sn = parseInt(e.getAttribute("data-pg"), 10) || 1;
                cmtLoad();
                var t = Qe.getBoundingClientRect().top + (window.pageYOffset || document.documentElement.scrollTop) - 80;
                window.scrollTo({
                    top: t,
                    behavior: "smooth"
                });
            });
        });
    }
    function cmtSendReply(e, t) {
        const n = e.querySelector("textarea");
        const i = n.value.trim();
        if (!i || !vn) return;
        const a = t.dataset.id;
        let s = t.parentElement;
        while (s && s !== st) {
            if (s.classList && s.classList.contains("cmt-item")) {
                Ln.add(s.dataset.id);
            }
            s = s.parentElement;
        }
        fetch("api.php?action=reply", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                article: Fe,
                parent_id: a,
                content: i
            })
        }).then(e => e.json()).then(t => {
            if (t.success) {
                n.value = "";
                e.classList.remove("show");
                cmtLoad();
            } else showToast(t.error || "回复失败");
        }).catch(() => showToast("网络错误"));
    }
    function cmtBindEvents(e) {
        // v3.3.16：支持传入子树 root——「查看全部」插入的回复节点单独绑定交互事件
        e = e || st;
        e.querySelectorAll(".cmt-like-btn").forEach(e => {
            e.addEventListener("click", () => {
                e.classList.toggle("liked");
                const t = e.querySelector("span");
                if (t) {
                    let n = parseInt(t.textContent);
                    t.textContent = e.classList.contains("liked") ? n + 1 : Math.max(0, n - 1);
                }
            });
        });
        e.querySelectorAll(".cmt-reply-btn").forEach(e => {
            e.addEventListener("click", () => {
                if (!vn && !fn) {
                    Xe && Xe.click();
                    return;
                }
                const t = e.closest(".cmt-item, .cmt-reply-item");
                let n = t.querySelector(":scope > .cmt-reply-input-wrap");
                if (!n) {
                    n = document.createElement("div");
                    n.className = "cmt-reply-input-wrap";
                    n.innerHTML = '<textarea placeholder="写下你的回复..."></textarea><div class="cmt-reply-input-bottom"><button class="cmt-reply-send-btn">发送</button></div>';
                    t.appendChild(n);
                    n.querySelector("textarea").addEventListener("input", function() {
                        const e = n.querySelector(".cmt-reply-send-btn");
                        if (e) e.classList.toggle("has-content", this.value.trim().length > 0);
                    });
                    n.querySelector(".cmt-reply-send-btn").addEventListener("click", function() {
                        cmtSendReply(n, t);
                    });
                }
                n.classList.toggle("show");
                if (n.classList.contains("show")) n.querySelector("textarea").focus();
            });
        });
        e.querySelectorAll(".cmt-reply-send-btn").forEach(e => {
            e.addEventListener("click", () => {
                const t = e.closest(".cmt-reply-input-wrap");
                const n = e.closest(".cmt-item, .cmt-reply-item");
                cmtSendReply(t, n);
            });
        });
        e.querySelectorAll(".cmt-reply-input-wrap textarea").forEach(e => {
            e.addEventListener("input", () => {
                const t = e.closest(".cmt-reply-input-wrap").querySelector(".cmt-reply-send-btn");
                if (t) t.classList.toggle("has-content", e.value.trim().length > 0);
            });
        });
        e.querySelectorAll(".cmt-del-btn").forEach(e => {
            e.addEventListener("click", t => {
                t.stopPropagation();
                const n = e.dataset.id;
                if (n) {
                    xn = () => {
                        fetch("api.php?action=delete", {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/json"
                            },
                            body: JSON.stringify({
                                id: n,
                                article: Fe
                            })
                        }).then(e => e.json()).then(e => {
                            if (e.success) cmtLoad(); else showToast(e.error || "删除失败");
                        }).catch(() => showToast("网络错误"));
                    };
                    mn.classList.add("show");
                }
            });
        });
        e.querySelectorAll(".cmt-reply-expand").forEach(e => {
            e.addEventListener("click", () => {
                e.classList.toggle("open");
                const t = e.closest(".cmt-item").querySelector(".cmt-reply-list");
                if (t) t.classList.toggle("show");
            });
        });
        // v3.3.16：回复「查看全部」——把剩余回复渲染进当前回复列表（数据已保存在 cmtLoadedComments）
                e.querySelectorAll(".cmt-more-replies").forEach(e => {
            e.addEventListener("click", () => {
                const t = e.dataset.id;
                const n = In.find(e => e.id === t);
                if (!n || !n.replies || n.replies.length <= Cn) return;
                const i = n.replies.slice(Cn);
                const a = [];
                i.forEach(t => {
                    const i = document.createElement("div");
                    i.innerHTML = cmtRenderReply(t, 1, n.nickname || "");
                    const s = i.firstElementChild;
                    if (s) {
                        e.parentNode.insertBefore(s, e);
                        a.push(s);
                    }
                });
                e.remove();
                // 新插入的回复单独绑定交互（点赞/回复/删除等）
                                if (a.length) {
                    const e = document.createElement("div");
                    a.forEach(t => e.appendChild(t));
                    cmtBindEvents(e);
                }
            });
        });
        e.querySelectorAll('.cmt-reply-item[data-del="true"]').forEach(e => {
            let t;
            const start = n => {
                n.stopPropagation();
                t = setTimeout(() => cmtShowConfirm(e), 600);
            };
            const clear = () => clearTimeout(t);
            e.addEventListener("pointerdown", start);
            e.addEventListener("pointerup", clear);
            e.addEventListener("pointercancel", clear);
            e.addEventListener("pointermove", clear);
        });
        e.querySelectorAll('.cmt-item[data-del="true"]').forEach(e => {
            let t;
            const start = () => {
                t = setTimeout(() => cmtShowConfirm(e), 600);
            };
            const clear = () => clearTimeout(t);
            e.addEventListener("pointerdown", start);
            e.addEventListener("pointerup", clear);
            e.addEventListener("pointercancel", clear);
            e.addEventListener("pointermove", clear);
            const n = e.querySelector(".cmt-text");
            if (n) {
                n.style.cursor = "pointer";
                n.addEventListener("pointerdown", n => {
                    n.stopPropagation();
                    t = setTimeout(() => cmtShowConfirm(e), 600);
                });
                n.addEventListener("pointerup", clear);
                n.addEventListener("pointercancel", clear);
            }
        });
    }
    function cmtShowConfirm(e) {
        xn = () => {
            const t = e.dataset.id;
            fetch("api.php?action=delete", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    id: t,
                    article: Fe
                })
            }).then(e => e.json()).then(e => {
                if (e.success) cmtLoad(); else showToast(e.error || "删除失败");
            }).catch(() => showToast("网络错误"));
        };
        mn.classList.add("show");
    }
    if (un) un.addEventListener("click", () => {
        if (xn) xn();
        mn.classList.remove("show");
        xn = null;
    });
    if (pn) pn.addEventListener("click", () => {
        mn.classList.remove("show");
        xn = null;
    });
    // v4.5.0：登录态过期/环境变化时尝试用 refresh token 自动续期（换环境则失败，需重新登录）
        function ysmTryRefresh() {
        return fetch("api.php?action=refresh", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            }
        }).then(e => e.json()).then(e => {
            if (e.success && e.user) {
                vn = e.user;
                return true;
            }
            return false;
        }).catch(() => false);
    }
    // v4.7.3：恢复设备验证弹窗的标记（避免 adminLogin 分支把它覆盖回登录视图）
        var Bn = false;
    // v4.7.4：URL 带 ?admin_login=1（站长/写作者后台被拦截跳回首页）时——
    //         未登录则自动弹出登录弹窗；已登录则登录完成后自动跳回对应后台。
        function cmtHandleAdminLoginHint() {
        if (getUrlParam("admin_login") !== "1") return;
        if (!vn && !Bn) {
            yn = "login";
            cmtUpdateAuthUI();
            cmtOpenModal(ot);
            history.replaceState(null, "", location.pathname);
        }
    }
    function cmtMaybeGoAdmin() {
        if (getUrlParam("admin_login") !== "1") return;
        fetch("api.php?action=user-status").then(e => e.json()).then(e => {
            if (e.success && e.loggedIn && e.canAccessAdmin && e.adminUrl) {
                window.location.href = e.adminUrl;
            } else {
                history.replaceState(null, "", location.pathname);
            }
        }).catch(() => {});
    }
    function cmtCheckAuth() {
        return fetch("api.php?action=check").then(e => e.json()).then(e => {
            if (e.success && e.loggedIn) {
                vn = e.user;
                if (e.isAdminFirstLogin) hn = true;
            } else if (e.success && e.pending_device_verify) {
                // v4.7.3：陌生设备验证进行中（切后台看邮箱/移动端页面被重载导致弹窗丢失）→ 恢复设备验证弹窗
                vn = null;
                Bn = true;
                yn = "login";
                cmtShowDevVerify(e.masked_email || "");
                if (ot) cmtOpenModal(ot);
            } else if (e.success && e.env_invalid) {
                // v4.5.0：环境/会话异常——先尝试 refresh 自动续期，失败则提示重新登录
                return ysmTryRefresh().then(e => {
                    if (e) {
                        return fetch("api.php?action=check").then(e => e.json()).then(e => {
                            if (e.success && e.loggedIn) {
                                vn = e.user;
                                cmtUpdateUI();
                                return;
                            }
                            cmtUpdateUI();
                        });
                    }
                    vn = null;
                    showToast("登录环境已变化，请重新登录");
                    cmtUpdateUI();
                });
            }
            cmtUpdateUI();
        }).catch(() => cmtUpdateUI());
    }
    const Mn = typeof loadFile === "function" ? loadFile : null;
    const Hn = typeof showHome === "function" ? showHome : null;
    function cmtOnArticleLoad() {
        if (Je) Je.style.display = "block";
        if (We) We.style.display = "block";
        if (Qe) Qe.style.display = "block";
        cmtCheckAuth().then(() => {
            cmtLoad();
            // v4.7.4：?admin_login=1 未登录 → 自动弹出登录弹窗（替代此前从未被设置的 data-admin-login 死代码）
                        cmtHandleAdminLoginHint();
        });
    }
    function cmtOnArticleHide() {
        if (Je) Je.style.display = "none";
        if (We) We.style.display = "none";
        if (Qe) Qe.style.display = "none";
    }
    // ============================================================
    // v4.5.0：本地背景音——独立于网易云播放器的第二路音频（互不干扰），单曲循环。
    // 曲目由站长/超管后台上传（<100MB 自动转码），前端只能开/关不能选曲。
    // 首次播放从服务器取并存 Cache Storage，之后一律从浏览器缓存播放，不重复消耗服务器流量。
    // ============================================================
        var An = document.getElementById("bgmToggle");
    var Pn = document.getElementById("bgmAudio");
    var Rn = document.getElementById("bgmRow");
    var On = "ymd-bgm-on";
    var Un = "data/bgm/background.mp3";
    var Fn = "ymd-bgm-v1";
    function bgmLoadSource() {
        if ("caches" in window) {
            return caches.open(Fn).then(function(e) {
                return e.match(Un).then(function(t) {
                    if (t) return t.blob();
 // 命中浏览器缓存 → 不再请求服务器
                                        return fetch(Un, {
                        cache: "force-cache"
                    }).then(function(t) {
                        if (!t.ok) throw new Error("HTTP " + t.status);
                        var n = t.clone();
                        e.put(Un, n).catch(function() {});
                        return t.blob();
                    });
                });
            });
        }
        return Promise.resolve(null);
 // 无 Cache API（非 https 环境）→ 直接走浏览器 HTTP 缓存
        }
    function bgmStart() {
        // v4.7.4：背景音乐与播放器音乐互斥——BGM 开播时暂停网易云/QQ/酷狗播放器
        if (X && typeof X.pause === "function") {
            try {
                X.pause();
            } catch (e) {}
        }
        bgmLoadSource().then(function(e) {
            if (e) Pn.src = URL.createObjectURL(e); else Pn.src = Un;
            return Pn.play();
        }).catch(function() {
            // 自动播放策略拦截或加载失败：静默处理，用户点开关重试即可
        });
    }
    function bgmStop() {
        Pn.pause();
        Pn.src = "";
    }
    function bgmSetOn(e) {
        bgmSyncUi(e);
        try {
            localStorage.setItem(On, e ? "1" : "0");
        } catch (e) {}
        if (e) bgmStart(); else bgmStop();
    }
    // v4.6.2：背景音 UI 同步（弹窗内开关 + 首页浮动按钮双向一致）
        function bgmSyncUi(e) {
        if (An) An.checked = !!e;
        if (jn) jn.classList.toggle("playing", !!e);
    }
    var jn = document.getElementById("bgmFloatBtn");
    if (document.body.dataset.bgMusic === "1") {
        if (Rn) Rn.style.display = "flex";
        if (jn) {
            jn.style.display = "flex";
            jn.addEventListener("click", function(e) {
                e.stopPropagation();
                bgmSetOn(!(An ? An.checked : false));
            });
        }
        if (An && Pn) {
            var Nn = false;
            try {
                Nn = localStorage.getItem(On) === "1";
            } catch (e) {}
            An.addEventListener("change", function() {
                bgmSetOn(An.checked);
            });
            // 记忆上次开启状态：进站尝试自动播放（浏览器自动播放策略拦截则静默，点浮动钮重试）
                        if (Nn) {
                bgmSyncUi(true);
                bgmStart();
            }
        }
    }
    // v4.7.4：滑块点击兜底——修复部分移动端浏览器零尺寸 checkbox 点击无响应（滑块点了没反应）。
    // .bgm-switch input 已改为全尺寸透明覆盖，正常点击 e.target 即 input 走原生 change，本兜底自然跳过；
    // 若个别浏览器仍不触发，则手动翻转勾选态并派发 change。
        document.querySelectorAll(".bgm-switch").forEach(function(e) {
        var t = e.querySelector('input[type="checkbox"]');
        if (!t) return;
        e.addEventListener("click", function(e) {
            if (e.target === t) return;
            e.preventDefault();
            t.checked = !t.checked;
            t.dispatchEvent(new Event("change", {
                bubbles: true
            }));
        });
    });
    var qn = document.body.dataset.musicPlaylist || "3778678";
    // v4.7.4：多平台音乐——网易云/QQ音乐/酷狗，每平台独立榜单；播放地址播放时懒解析（不预取，减少外呼）
        var _n = {
        netease: "网易云",
        qq: "QQ音乐",
        kugou: "酷狗"
    };
    var $n = {
        netease: [ "热歌榜", "新歌榜", "原创榜", "飙升榜" ],
        qq: [ "热歌榜", "新歌榜", "飙升榜" ],
        kugou: [ "热歌榜", "新歌榜", "飙升榜" ]
    };
    var zn = "netease";
    var Dn = "热歌榜";
    function musicLoadSrcPref() {
        try {
            var e = localStorage.getItem("ymd-music-src");
            if (e) {
                var t = JSON.parse(e);
                if (t && _n[t.platform] && ($n[t.platform] || []).indexOf(t.chart) >= 0) {
                    zn = t.platform;
                    Dn = t.chart;
                }
            }
        } catch (e) {}
    }
    function musicSaveSrcPref() {
        try {
            localStorage.setItem("ymd-music-src", JSON.stringify({
                platform: zn,
                chart: Dn
            }));
        } catch (e) {}
    }
    function musicRenderChartChips() {
        if (!ei) return;
        var e = $n[zn] || [ "热歌榜" ];
        ei.innerHTML = e.map(function(e) {
            return '<button type="button" class="music-src-chip' + (e === Dn ? " active" : "") + '" data-chart="' + escHtml(e) + '">' + escHtml(e) + "</button>";
        }).join("");
    }
    var Vn = [];
    var Yn = -1;
    var Jn = false;
    var Wn = false;
    var Qn = false;
    var Gn = "ymd-music-state";
    var Xn = 0;
    // v4.4.0：默认自动播放目标——后台「默认播放歌曲」关键词（留空则不自动播放）
        var Kn = (document.body.dataset.musicAutoPlay || "").trim();
    function musicSaveState() {
        try {
            var e = {
                index: Yn,
                time: X.currentTime || 0,
                volume: X.volume,
                listOpen: Qn,
                loopMode: Xn
            };
            localStorage.setItem(Gn, JSON.stringify(e));
        } catch (e) {}
    }
    function musicLoadState() {
        try {
            var e = localStorage.getItem(Gn);
            if (e) return JSON.parse(e);
        } catch (e) {}
        return null;
    }
    // v4.7.4：平台/榜单切换——初始化 + 事件绑定（列表面板顶部的平台/榜单 chips）
        var Zn = document.getElementById("musicSrcRow");
    var ei = document.getElementById("musicChartRow");
    var ti = document.getElementById("musicSongList");
    musicLoadSrcPref();
    musicRenderChartChips();
    if (Zn) Zn.addEventListener("click", function(e) {
        var t = e.target.closest(".music-src-chip");
        if (!t) return;
        // v4.7.6：阻止冒泡——切换平台后 musicRenderChartChips() 重建榜单行，被点按钮脱离 DOM，
        // 冒泡到 document 时 musicPopup.contains(target) 返回 false，会被「点击外部关闭」误关弹窗
                e.stopPropagation();
        var n = t.getAttribute("data-platform");
        if (!n || n === zn) return;
        zn = n;
        Dn = ($n[n] || [ "热歌榜" ])[0];
        musicSaveSrcPref();
        Zn.querySelectorAll(".music-src-chip").forEach(function(e) {
            e.classList.toggle("active", e === t);
        });
        musicRenderChartChips();
        Wn = true;
        loadMusicHotSongs();
    });
    if (ei) ei.addEventListener("click", function(e) {
        var t = e.target.closest(".music-src-chip");
        if (!t) return;
        // v4.7.6：同上——切换榜单时本行 innerHTML 重建，必须阻止冒泡防误关弹窗
                e.stopPropagation();
        var n = t.getAttribute("data-chart");
        if (!n || n === Dn) return;
        Dn = n;
        musicSaveSrcPref();
        musicRenderChartChips();
        Wn = true;
        loadMusicHotSongs();
    });
    // 音乐按钮仅在后台配置 music_cookies 后渲染，未配置时跳过音乐初始化
        if (M && H) {
        M.addEventListener("click", function(e) {
            e.stopPropagation();
            H.classList.toggle("active");
            I.classList.remove("active");
            if (!Wn) {
                Wn = true;
                loadMusicHotSongs();
            }
        });
        document.addEventListener("click", function(e) {
            if (!H.contains(e.target) && !M.contains(e.target)) H.classList.remove("active");
        });
    }
    if (K) K.addEventListener("click", function(e) {
        e.stopPropagation();
        Qn = !Qn;
        A.classList.toggle("open", Qn);
        K.classList.toggle("open", Qn);
        musicSaveState();
    });
    function loadMusicHotSongs() {
        P.textContent = "加载中...";
        // v4.7.4：按当前平台+榜单拉取（QQ/酷狗榜单经 music.php 服务端聚合，播放地址播放时懒解析）
                var e = "music.php?platform=" + encodeURIComponent(zn) + "&sortAll=" + encodeURIComponent(Dn);
        fetch(e).then(function(e) {
            if (!e.ok) throw new Error("HTTP " + e.status);
            return e.json();
        }).then(function(e) {
            if (Array.isArray(e) && e.length > 0) {
                Vn = e.map(function(e) {
                    return {
                        id: e.id,
                        name: e.name || "",
                        artist: e.artistsname || "",
                        cover: e.picurl || "",
                        url: e.url || "",
                        duration: (e.duration || 0) * 1e3
                    };
                });
                if (G) G.textContent = (_n[zn] || zn) + " · " + Dn + " · " + Vn.length + " 首";
                renderMusicList();
                var t = musicLoadState();
                if (t && t.index >= 0 && t.index < Vn.length) {
                    Qn = t.listOpen !== false;
                    A.classList.toggle("open", Qn);
                    K.classList.toggle("open", Qn);
                    musicPlaySong(t.index, t.time || 0);
                } else {
                    Qn = true;
                    A.classList.add("open");
                    K.classList.add("open");
                    // v4.4.0：首次进入且无历史播放状态时，按后台「默认播放歌曲」关键词模糊匹配
                    //         定位并播放（匹配不到则播放列表第一首；关键词留空则不自动播放）
                                        var n = -1;
                    if (Kn) {
                        for (var i = 0; i < Vn.length; i++) {
                            var a = String(Vn[i].name || "").toLowerCase();
                            if (a.indexOf(Kn) !== -1) {
                                n = i;
                                break;
                            }
                        }
                        if (n === -1 && Vn.length > 0) n = 0;
                        if (n >= 0) musicPlaySong(n, 0);
                    }
                }
                if (t && typeof t.volume === "number") {
                    X.volume = t.volume;
                }
                if (t && typeof t.loopMode === "number") {
                    Xn = t.loopMode;
                    musicUpdateModeIcon();
                }
            } else if (e.error) {
                P.innerHTML = escHtml(e.error) + '，<a href="javascript:void(0)" id="musicRetry">点击重试</a>';
                bindRetry();
            } else {
                P.innerHTML = '数据异常，<a href="javascript:void(0)" id="musicRetry">点击重试</a>';
                bindRetry();
            }
        }).catch(function(e) {
            P.innerHTML = "网络错误（" + escHtml(String(e)) + '），<a href="javascript:void(0)" id="musicRetry">点击重试</a>';
            bindRetry();
        });
    }
    function bindRetry() {
        var e = document.getElementById("musicRetry");
        if (e) e.addEventListener("click", function() {
            Wn = false;
            loadMusicHotSongs();
        });
    }
    function renderMusicList() {
        var e = "";
        Vn.forEach(function(t, n) {
            e += '<div class="music-item" data-index="' + n + '">' + '<div class="mi-idx">' + (n + 1) + "</div>" + '<div class="mi-cover"><img src="' + (t.cover || "") + '" alt="" loading="lazy" onerror="this.style.display=\'none\'"></div>' + '<div class="mi-info"><div class="mi-name">' + escHtml(t.name) + '</div><div class="mi-artist">' + escHtml(t.artist) + "</div></div>" + '<div class="mi-dur">' + fmtTime(t.duration / 1e3) + "</div>" + "</div>";
        });
        // v4.7.4：只渲染歌曲容器（平台/榜单 chips 在 #musicList 里，不再被覆盖）
                if (ti) ti.innerHTML = e;
        ti.querySelectorAll(".music-item").forEach(function(e) {
            e.addEventListener("click", function() {
                musicPlaySong(parseInt(this.dataset.index));
            });
        });
    }
    function musicStartPlay(e, t, n) {
        X.src = e;
        X.play().then(function() {
            musicSetPlaying(true);
            di = 0;
            if (n > 0) X.currentTime = n;
        }).catch(function() {
            musicTryFallbackUrl(t, n);
        });
    }
    function musicPlaySong(e, t) {
        if (e < 0 || e >= Vn.length) return;
        Yn = e;
        var n = Vn[e];
        O.textContent = n.name;
        U.textContent = n.artist;
        if (n.cover) R.src = n.cover;
        ti.querySelectorAll(".music-item").forEach(function(t, n) {
            t.classList.toggle("active", n === e);
        });
        var i = n.url;
        if (i) {
            musicStartPlay(i, n, t);
        } else if (zn === "netease") {
            musicStartPlay("https://music.163.com/song/media/outer/url?id=" + n.id, n, t);
        } else {
            // v4.7.4：QQ/酷狗歌单不预取播放地址 → 播放时懒解析直链（拿到新鲜有效的 CDN 地址）
            P.textContent = "解析播放地址...";
            fetch("music.php?platform=" + encodeURIComponent(zn) + "&songId=" + encodeURIComponent(n.id)).then(function(e) {
                return e.json();
            }).then(function(e) {
                if (e && e.url) {
                    n.url = e.url;
                    musicStartPlay(e.url, n, t);
                } else {
                    P.textContent = "获取播放地址失败";
                    musicHandlePlayFail();
                }
            }).catch(function() {
                P.textContent = "获取播放地址失败";
                musicHandlePlayFail();
            });
        }
        musicSaveState();
        var a = ti.querySelector(".music-item.active");
        if (a) a.scrollIntoView({
            behavior: "smooth",
            block: "nearest"
        });
    }
    function musicTryFallbackUrl(e, t) {
        // v4.7.4：网易云走 xfyun blob 兜底；QQ/酷狗直链解析失败不再跨平台兜底，直接失败跳下一首
        if (zn !== "netease") {
            musicHandlePlayFail();
            return;
        }
        var n = "https://api.xfyun.club/musicAll/?songId=" + e.id + "&mp3Url=mp3";
        fetch(n).then(function(e) {
            if (!e.ok) throw new Error("fallback failed");
            return e.blob();
        }).then(function(e) {
            var t = URL.createObjectURL(e);
            X.src = t;
            return X.play();
        }).then(function() {
            musicSetPlaying(true);
            di = 0;
            if (t > 0) X.currentTime = t;
        }).catch(function() {
            musicHandlePlayFail();
        });
    }
    function musicHandlePlayFail() {
        di++;
        musicSetPlaying(false);
        if (di >= 5) {
            di = 0;
            return;
        }
        if (Vn.length > 1) {
            setTimeout(function() {
                musicPlaySong((Yn + 1) % Vn.length);
            }, 500);
        }
    }
    function musicSetPlaying(e) {
        Jn = e;
        j.innerHTML = e ? '<path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>' : '<path d="M8 5v14l11-7z"/>';
        if (ee) ee.classList.toggle("spinning", e);
        te.forEach(function(t) {
            t.classList.toggle("paused", !e);
        });
        // v4.6.2：播放中脉冲光环（统一播放状态视觉语言）
                if (F) F.classList.toggle("playing", e);
    }
    F.addEventListener("click", function() {
        if (Yn === -1) {
            musicPlaySong(0);
            return;
        }
        if (Jn) X.pause(); else X.play();
    });
    N.addEventListener("click", function() {
        if (!Vn.length) return;
        musicPlaySong((Yn - 1 + Vn.length) % Vn.length);
    });
    q.addEventListener("click", function() {
        if (!Vn.length) return;
        musicPlaySong((Yn + 1) % Vn.length);
    });
    function musicUpdateModeIcon() {
        var e = [ '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/><text x="12" y="15" text-anchor="middle" font-size="8" fill="currentColor" stroke="none">1</text></svg>', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>' ];
        var t = [ "顺序播放", "列表循环", "单曲循环" ];
        if (Z) {
            Z.innerHTML = e[Xn] || e[0];
            Z.title = t[Xn] || t[0];
            Z.classList.toggle("active", Xn > 0);
        }
    }
    if (Z) Z.addEventListener("click", function(e) {
        e.stopPropagation();
        Xn = (Xn + 1) % 3;
        musicUpdateModeIcon();
        musicSaveState();
    });
    musicUpdateModeIcon();
    X.addEventListener("play", function() {
        // v4.7.4：播放器音乐与背景音乐互斥——播放器开播时关闭背景音乐
        if (typeof bgmSetOn === "function") bgmSetOn(false);
        musicSetPlaying(true);
        musicStartWordAnim();
    });
    X.addEventListener("pause", function() {
        musicSetPlaying(false);
        musicStopWordAnim();
        musicSaveState();
    });
    X.addEventListener("ended", function() {
        if (Xn === 2) {
            musicPlaySong(Yn);
        } else if (Xn === 1) {
            musicPlaySong((Yn + 1) % Vn.length);
        } else {
            if (Yn < Vn.length - 1) musicPlaySong(Yn + 1); else musicSetPlaying(false);
        }
    });
    var ni = false;
    var ii = [];
    var ai = -1;
    var si = false;
    var oi = null;
    var ci = false;
    // v4.6.2：歌词显示开关由「词」文字按钮改为滑块（checkbox change；滑过来=显示歌词、滑过去=隐藏）
        if (_) _.addEventListener("change", function() {
        ni = !!_.checked;
        if (ni) {
            D.style.display = "none";
            $.classList.add("active");
            if (Yn >= 0 && Yn < Vn.length) {
                var e = Vn[Yn];
                if (ai !== e.id) musicLoadLyric(e.id);
            }
        } else {
            D.style.display = "";
            $.classList.remove("active");
        }
    });
    function musicLoadLyric(e) {
        z.innerHTML = '<div class="music-lyric-hint">加载中...</div>';
        ii = [];
        ai = e;
        fetch("music.php?platform=" + zn + "&lyric=" + e).then(function(e) {
            return e.json();
        }).then(function(e) {
            if (!e.success) {
                z.innerHTML = '<div class="music-lyric-hint">暂无歌词</div>';
                return;
            }
            if (e.yrc) {
                ii = musicParseYrc(e.yrc);
            }
            if (!ii.length && e.lrc) {
                ii = musicParseLrc(e.lrc);
            }
            if (ii.length) {
                if (e.tlrc) {
                    var t = musicParseLrc(e.tlrc);
                    musicMergeTranslation(ii, t);
                }
                musicRenderLyric();
                if (ci && !X.paused) musicStartWordAnim();
            } else {
                z.innerHTML = '<div class="music-lyric-hint">暂无歌词</div>';
            }
        }).catch(function() {
            z.innerHTML = '<div class="music-lyric-hint">歌词加载失败</div>';
        });
    }
    function musicParseYrc(e) {
        var t = e.split("\n");
        var n = [];
        var i = /^\[(\d+),(\d+)\](.*)/;
        var a = /\((\d+),(\d+),\d+\)([^\(]*)/g;
        for (var s = 0; s < t.length; s++) {
            var o = t[s].match(i);
            if (!o) continue;
            var c = parseInt(o[1]) / 1e3;
            var r = parseInt(o[2]) / 1e3;
            var l = o[3];
            if (!l.trim()) continue;
            var d = [];
            var m;
            while ((m = a.exec(l)) !== null) {
                var u = m[3];
                if (u) d.push({
                    start: parseInt(m[1]) / 1e3,
                    dur: parseInt(m[2]) / 1e3,
                    text: u
                });
            }
            if (d.length > 0) {
                ci = true;
                var p = d.map(function(e) {
                    return e.text;
                }).join("");
                n.push({
                    time: c,
                    text: p,
                    words: d
                });
            } else {}
        }
        n.sort(function(e, t) {
            return e.time - t.time;
        });
        return n;
    }
    function musicMergeTranslation(e, t) {
        var n = 0;
        for (var i = 0; i < e.length && n < t.length; i++) {
            while (n < t.length - 1 && Math.abs(t[n].time - e[i].time) > Math.abs(t[n + 1].time - e[i].time)) {
                n++;
            }
            if (Math.abs(t[n].time - e[i].time) < 2 && t[n].text) {
                e[i].trans = t[n].text;
                n++;
            }
        }
    }
    function musicParseLrc(e) {
        var t = e.split("\n");
        var n = [];
        var i = /\[(\d{2}):(\d{2})\.?(\d{0,3})\](.*)/;
        var a = /<(\d+),(\d+),\d+>([^<]*)/g;
        for (var s = 0; s < t.length; s++) {
            var o = t[s].match(i);
            if (o) {
                var c = parseInt(o[3] || "0");
                if (o[3].length === 2) c *= 10;
                if (o[3].length === 1) c *= 100;
                var r = parseInt(o[1]) * 60 + parseInt(o[2]) + c / 1e3;
                var l = o[4];
                var d = [];
                var m;
                var u = false;
                while ((m = a.exec(l)) !== null) {
                    u = true;
                    d.push({
                        start: parseInt(m[1]) / 1e3,
                        dur: parseInt(m[2]) / 1e3,
                        text: m[3]
                    });
                }
                if (u && d.length > 0) {
                    ci = true;
                    var p = d.map(function(e) {
                        return e.text;
                    }).join("");
                    if (p) n.push({
                        time: r,
                        text: p,
                        words: d
                    });
                } else {
                    var p = l.trim();
                    if (p) n.push({
                        time: r,
                        text: p
                    });
                }
            }
        }
        n.sort(function(e, t) {
            return e.time - t.time;
        });
        return n;
    }
    function musicRenderLyric() {
        if (!ii.length) {
            z.innerHTML = '<div class="music-lyric-hint">暂无歌词</div>';
            return;
        }
        var e = "";
        for (var t = 0; t < ii.length; t++) {
            var n = ii[t];
            var i = n.trans ? '<div class="lyr-trans">' + escHtml(n.trans) + "</div>" : "";
            if (n.words && n.words.length > 0) {
                var a = "";
                for (var s = 0; s < n.words.length; s++) {
                    a += '<span class="lyr-word" data-start="' + n.words[s].start + '" data-dur="' + n.words[s].dur + '">' + escHtml(n.words[s].text) + "</span>";
                }
                e += '<div class="music-lyric-line" data-idx="' + t + '">' + a + i + "</div>";
            } else {
                e += '<div class="music-lyric-line" data-idx="' + t + '">' + escHtml(n.text) + i + "</div>";
            }
        }
        z.innerHTML = e;
        z.querySelectorAll(".music-lyric-line").forEach(function(e) {
            e.addEventListener("click", function() {
                var e = parseInt(this.dataset.idx);
                if (e >= 0 && e < ii.length && X.duration) {
                    X.currentTime = ii[e].time;
                    si = false;
                    if (X.paused) X.play();
                }
            });
        });
    }
    function musicSetAllWordsGray(e) {
        var t = e.querySelectorAll(".lyr-word");
        for (var n = 0; n < t.length; n++) {
            t[n].classList.remove("sung", "filling");
            t[n].style.removeProperty("--fill-pct");
        }
    }
    var ri = null;
    function musicWordAnimLoop() {
        if (!ni || !ci || !ii.length || X.paused) {
            ri = null;
            return;
        }
        var e = X.currentTime || 0;
        var t = -1;
        for (var n = ii.length - 1; n >= 0; n--) {
            if (e >= ii[n].time) {
                t = n;
                break;
            }
        }
        var i = z.querySelectorAll(".music-lyric-line");
        if (t >= 0 && t < i.length && ii[t].words) {
            var a = ii[t].words;
            var s = i[t].querySelectorAll(".lyr-word");
            for (var n = 0; n < a.length; n++) {
                if (!s[n]) continue;
                var o = a[n];
                var c = o.start + o.dur;
                if (e >= c) {
                    s[n].className = "lyr-word sung";
                } else if (e >= o.start) {
                    var r = (e - o.start) / o.dur * 100;
                    s[n].className = "lyr-word filling";
                    s[n].style.setProperty("--fill-pct", r + "%");
                } else {
                    s[n].className = "lyr-word";
                    s[n].style.removeProperty("--fill-pct");
                }
            }
        }
        ri = requestAnimationFrame(musicWordAnimLoop);
    }
    function musicStartWordAnim() {
        if (!ri) ri = requestAnimationFrame(musicWordAnimLoop);
    }
    function musicStopWordAnim() {
        if (ri) {
            cancelAnimationFrame(ri);
            ri = null;
        }
    }
    if (z) z.addEventListener("scroll", function() {
        if (!ni) return;
        si = true;
        clearTimeout(oi);
        oi = setTimeout(function() {
            si = false;
        }, 4e3);
    });
    if (z) {
        z.addEventListener("touchstart", function() {
            if (!ni) return;
            si = true;
            clearTimeout(oi);
        }, {
            passive: true
        });
        z.addEventListener("touchend", function() {
            if (!ni) return;
            clearTimeout(oi);
            oi = setTimeout(function() {
                si = false;
            }, 4e3);
        });
    }
    function musicUpdateLyricScroll() {
        if (!ni || !ii.length) return;
        var e = X.currentTime || 0;
        var t = -1;
        for (var n = ii.length - 1; n >= 0; n--) {
            if (e >= ii[n].time) {
                t = n;
                break;
            }
        }
        var i = z.querySelectorAll(".music-lyric-line");
        for (var n = 0; n < i.length; n++) {
            var a = n === t;
            if (a !== i[n].classList.contains("active")) {
                i[n].classList.toggle("active", a);
            }
            if (ci && ii[n].words && n !== t) {
                musicSetAllWordsGray(i[n]);
            }
        }
        if (!si && t >= 0 && i[t]) {
            var s = z;
            var o = i[t];
            var c = o.offsetTop - s.offsetHeight / 2 + o.offsetHeight / 2;
            s.scrollTop = Math.max(0, c);
        }
    }
    X.addEventListener("timeupdate", musicUpdateLyricScroll);
    var li = musicPlaySong;
    musicPlaySong = function(e, t) {
        ai = -1;
        ci = false;
        if (ni) {
            z.scrollTop = 0;
            z.innerHTML = '<div class="music-lyric-hint">加载中...</div>';
        }
        li(e, t);
        if (ni && e >= 0 && e < Vn.length) {
            musicLoadLyric(Vn[e].id);
        }
    };
    var di = 0;
    X.addEventListener("error", function() {
        if (Vn.length > 0 && Yn >= 0 && Yn < Vn.length) {
            var e = Vn[Yn];
            if (!e._fallbackTried) {
                e._fallbackTried = true;
                musicTryFallbackUrl(e, 0);
            } else {
                e._fallbackTried = false;
                di++;
                if (di >= 5) {
                    musicSetPlaying(false);
                    di = 0;
                    return;
                }
                if (Vn.length > 1) {
                    setTimeout(function() {
                        musicPlaySong((Yn + 1) % Vn.length);
                    }, 1e3);
                }
            }
        }
    });
    X.addEventListener("timeupdate", function() {
        if (!X.duration) return;
        var e = X.currentTime / X.duration * 100;
        Y.style.width = e + "%";
        if (J) J.style.left = e + "%";
        W.textContent = fmtTime(X.currentTime);
        Q.textContent = fmtTime(X.duration);
        if (Math.floor(X.currentTime) % 5 === 0) musicSaveState();
    });
    (function() {
        var e = false;
        var t = false;
        function seekTo(e) {
            if (!X.duration) return;
            var t = V.getBoundingClientRect();
            var n = e.touches ? e.touches[0].clientX : e.clientX;
            var i = Math.max(0, Math.min(1, (n - t.left) / t.width));
            X.currentTime = i * X.duration;
            Y.style.width = i * 100 + "%";
            if (J) J.style.left = i * 100 + "%";
            W.textContent = fmtTime(X.currentTime);
        }
        function onStart(n) {
            n.preventDefault();
            e = true;
            t = !X.paused;
            if (t) X.pause();
            V.classList.add("dragging");
            seekTo(n);
        }
        function onMove(t) {
            if (!e) return;
            t.preventDefault();
            seekTo(t);
        }
        function onEnd(n) {
            if (!e) return;
            e = false;
            V.classList.remove("dragging");
            if (t) X.play();
        }
        V.addEventListener("mousedown", onStart);
        document.addEventListener("mousemove", onMove);
        document.addEventListener("mouseup", onEnd);
        V.addEventListener("touchstart", onStart, {
            passive: false
        });
        document.addEventListener("touchmove", onMove, {
            passive: false
        });
        document.addEventListener("touchend", onEnd);
        var n = X.ontimeupdate;
        X.addEventListener("timeupdate", function() {
            if (e) return;
        });
    })();
    var mi = musicLoadState();
    X.volume = mi && typeof mi.volume === "number" ? mi.volume : .8;
    function fmtTime(e) {
        if (!e || isNaN(e)) return "0:00";
        var t = Math.floor(e / 60);
        var n = Math.floor(e % 60);
        return t + ":" + (n < 10 ? "0" : "") + n;
    }
    function escHtml(e) {
        var t = document.createElement("div");
        t.textContent = e;
        return t.innerHTML;
    }
    // ---- 用户按钮 + 下拉菜单 ----
        (function() {
        var e = document.getElementById("btnUser");
        var t = document.getElementById("userDropdown");
        var n = document.getElementById("userDropdownName");
        var i = document.getElementById("userDropdownRole");
        var a = document.getElementById("userDropdownAvatar");
        var s = document.getElementById("userDropdownLogin");
        var o = document.getElementById("userDropdownAdmin");
        var c = document.getElementById("userDropdownDivider");
        var r = document.getElementById("userDropdownLogout");
        var l = document.getElementById("userDropdownProfile");
        var d = document.getElementById("cmtAuthModal");
        var m = document.getElementById("cmtAuthTitle");
        var u = document.getElementById("cmtLoginForm");
        var p = document.getElementById("cmtRegForm");
        var f = document.getElementById("cmtAuthSlide");
        var v = document.getElementById("cmtSwitchText");
        var y = document.getElementById("cmtSwitchBtn");
        if (!e || !t) return;
        var g = {
            super_admin: "高级管理员",
            station_admin: "站长",
            author: "写作者",
            user: "注册用户",
            guest: "访客"
        };
        function openLoginModal() {
            if (d && m && u && p && f) {
                m.textContent = "登录";
                u.style.display = "flex";
                p.style.display = "none";
                // v4.7.0：打开时隐藏设备验证与找回视图（上次会话残留清理）
                                var e = document.getElementById("cmtDevForm");
                if (e) e.style.display = "none";
                var t = document.getElementById("cmtResetForm");
                if (t) t.style.display = "none";
                if (v) v.textContent = "还没有账号？";
                if (y) y.textContent = "立即注册";
                // v2.11.4：清理残留的切换动画状态（防止打开弹窗时表单仍处于 slide-out/in 中间态）
                                f.classList.remove("slide-out");
                f.classList.remove("slide-in");
                d.classList.add("show");
            }
        }
        function updateDropdown(e) {
            if (e) {
                var t = e.nickname || "用户";
                var d = e.role || "user";
                // v2.6.5：超管在主页显示「超管」身份，但无登录/管理/退出入口（需到超管后台退出）
                                var m = e.isSuperAdmin ? "超管" : g[d] || d;
                if (n) n.textContent = t;
                if (i) i.textContent = m;
                if (a) {
                    var u = t.charAt(0).toUpperCase();
                    // v2.11.1：API 返回的 account 已打码，头像一律用 avatar 字段（不再用 account 拼 URL）
                                        if (e.avatar) {
                        a.innerHTML = '<img src="' + escHtml(e.avatar) + '" alt="" onerror="this.style.display=\'none\';this.parentNode.textContent=\'' + escHtml(u) + "'\">";
                    } else {
                        a.textContent = u;
                    }
                }
                if (s) s.style.display = "none";
                if (e.isSuperAdmin) {
                    // v2.10.2：超管在主页可退出登录（原先无退出入口，需回后台）；无「快捷进入管理」按钮（超管后台仅 SSH/OTP 入口）
                    if (c) c.style.display = "block";
                    if (r) r.style.display = "flex";
                    if (o) o.style.display = "none";
                    // v2.11.3：超管隐身，不提供「编辑资料」入口（超管走 OTP/SSH 安全通道）
                                        if (l) l.style.display = "none";
                } else {
                    if (c) c.style.display = "block";
                    if (r) r.style.display = "flex";
                    // v2.11.3：普通用户/站长/写作者显示「编辑资料」入口
                                        if (l) l.style.display = "flex";
                    if (o) {
                        o.style.display = e.canAccessAdmin ? "flex" : "none";
                    }
                }
            } else {
                if (n) n.textContent = "未登录";
                if (i) i.textContent = "访客";
                if (a) a.textContent = "?";
                if (s) s.style.display = "flex";
                if (c) c.style.display = "none";
                if (r) r.style.display = "none";
                if (o) o.style.display = "none";
                if (l) l.style.display = "none";
            }
        }
        e.addEventListener("click", function(e) {
            e.stopPropagation();
            if (t.classList.contains("active")) {
                t.classList.remove("active");
                return;
            }
            fetch("api.php?action=user-status").then(function(e) {
                return e.json();
            }).then(function(e) {
                if (e.success && e.loggedIn) {
                    updateDropdown(e);
                } else {
                    updateDropdown(null);
                }
                t.classList.add("active");
            }).catch(function() {
                updateDropdown(null);
                t.classList.add("active");
            });
        });
        if (s) {
            s.addEventListener("click", function(e) {
                e.stopPropagation();
                t.classList.remove("active");
                openLoginModal();
            });
        }
        if (o) {
            o.addEventListener("click", function(e) {
                e.stopPropagation();
                t.classList.remove("active");
                var n = this.dataset.url || "/admin/dashboard.php";
                fetch("api.php?action=user-status").then(function(e) {
                    return e.json();
                }).then(function(e) {
                    if (e.success && e.loggedIn && e.canAccessAdmin && e.adminUrl) {
                        window.location.href = e.adminUrl;
                    } else {
                        showToast("权限不足，无法进入管理界面");
                    }
                }).catch(function() {
                    showToast("获取权限信息失败");
                });
            });
        }
        if (r) {
            r.addEventListener("click", function(e) {
                e.stopPropagation();
                t.classList.remove("active");
                fetch("api.php?action=logout", {
                    method: "POST"
                }).catch(function() {});
                if (typeof vn !== "undefined") {
                    vn = null;
                }
                if (typeof cmtUpdateUI === "function") cmtUpdateUI();
                updateDropdown(null);
                showToast("已退出登录");
            });
        }
        // v2.11.3：用户下拉「编辑资料」→ 打开个人资料弹窗
                if (l) {
            l.addEventListener("click", function(e) {
                e.stopPropagation();
                t.classList.remove("active");
                if (typeof cmtOpenProfileModal === "function") cmtOpenProfileModal();
            });
        }
        document.addEventListener("click", function() {
            t.classList.remove("active");
        });
        // 点击用户按钮时不关闭
                t.addEventListener("click", function(e) {
            e.stopPropagation();
        });
    })();
    init();
})();