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
    window.ysmGetFp = ysmGetFp;
})();
