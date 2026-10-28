/* You Super Markdown — 公共环境指纹（v5.4.0-beta.4）
 * 从 js/main.js 抽取，供**不加载 main.js** 的页面复用（编辑器 AI 浮层 sc.php / 站长、写作者后台 AI 页签）。
 * 算法与 js/main.js **完全一致**：hash(lang|时区|分辨率|canvas 像素|UA)，同步生成、同一会话内稳定。
 * 对外暴露 window.ysmGetFp()（与 main.js 注入 api.php 请求头 X-Fp 时取值一致）。
 */
(function () {
    'use strict';
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
    // v6.0.0-beta（E）：稳定指纹——忽略分辨率原始值（旋转互换）、忽略 canvas 末段字节、时区按 ±2h 归桶。
    // 供已信任设备在登录态环境校验时放宽比对（手机端漂移不再误判换设备）；未知设备/高敏写入仍用原始 ysmGetFp。
    window.ysmGetStableFp = function () {
        try {
            var tz = (new Date()).getTimezoneOffset();
            var tzBucket = Math.round(tz / 120) * 120;
            var s = [ navigator.language || "", String(tzBucket), navigator.userAgent ];
            return ysmFnvHash(s.join("|")) + ysmFnvHash(s.join("~"));
        } catch (err) {
            return "";
        }
    };
    window.ysmGetFp = ysmGetFp;
    // v6.3.6：后台统一注入指纹头——站长/写作者/超管后台不加载 main.js，
    //   此前只有个别脚本手工带 X-Fp，漏带即被判定「登录环境已变化」。
    //   这里对同源 api.php 请求统一补 X-Fp（完整）+ X-FpS（稳定），已有头不覆盖。
    (function ysmPatchFetch() {
        if (window.__ysmFpPatched) return;
        var orig = window.fetch;
        if (!orig) return;
        window.__ysmFpPatched = true;
        window.fetch = function (input, init) {
            init = init || {};
            var url = String(input);
            if (url.indexOf('api.php') !== -1) {
                var h = {};
                var src = init.headers || {};
                if (typeof src.forEach === 'function' && !Array.isArray(src) && typeof src.get === 'function') {
                    src.forEach(function (v, k) { h[k] = v; });
                } else if (Array.isArray(src)) {
                    src.forEach(function (p) { if (p && p.length === 2) h[p[0]] = p[1]; });
                } else {
                    for (var k in src) if (Object.prototype.hasOwnProperty.call(src, k)) h[k] = src[k];
                }
                if (!h['X-Fp'] && !h['x-fp']) h['X-Fp'] = ysmGetFp();
                if (!h['X-FpS'] && !h['x-fps']) h['X-FpS'] = window.ysmGetStableFp();
                init.headers = h;
            }
            return orig.call(this, input, init);
        };
    })();
})();
