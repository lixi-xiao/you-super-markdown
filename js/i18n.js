/**
 * v6.3.0：i18n 运行时（key 词表）
 *
 * - 服务端注入 window.I18N = {lang, dict, langs}，dict 为「key => 译文」（已与 zh-CN 合并）；
 * - window.TK(key, vars) 取词；缺 key 返回 key 本身；
 * - 不再做 DOM 扫描翻译：源码只写 key，中文原文（正文/标题/昵称）永不参与匹配。
 */
(function () {
    'use strict';
    var CONF = window.I18N || {};
    var DICT = CONF.dict || {};

    window.TK = function (key, vars) {
        var k = String(key == null ? '' : key);
        var s = Object.prototype.hasOwnProperty.call(DICT, k) ? String(DICT[k]) : k;
        if (vars) {
            for (var p in vars) {
                if (Object.prototype.hasOwnProperty.call(vars, p)) {
                    s = s.split('{' + p + '}').join(vars[p]);
                }
            }
        }
        return s;
    };

    // 兼容：I18N.s(key) / 旧代码里的 window.ysmT
    window.ysmT = window.TK;
    try {
        if (window.I18N) window.I18N.s = window.TK;
    } catch (e) {}
})();
