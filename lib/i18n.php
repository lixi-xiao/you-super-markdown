<?php
/**
 * v6.0.0-beta：i18n UI 外壳框架
 *
 * 设计要点：
 *  - 自动发现：扫描 ../lang/*.php 生成语言列表，不加新语种文件即自动出现在切换器（不强写注册表）。
 *  - 缺键回落：任何 dict 缺失的 key 都回落到 zh-CN（唯一真源），永不空白。
 *  - 优先 ndict → json → 后端 php dict（兼容处理）。
 *  - locale 解析链：?lang= → Cookie → Accept-Language → zh-CN 兜底。
 */

if (!function_exists('ysmSupportedLangs')) {
    /** 扫描 lang/ 目录得到全部语言（含被管理员停用的；新增语种文件即自动出现，无需注册） */
    function ysmAllLangs() {
        static $cache = null;
        if ($cache !== null) return $cache;
        $dir = __DIR__ . '/../lang';
        $langs = [];
        $files = is_dir($dir) ? (array)@scandir($dir) : [];
        $names = ['zh-CN' => '简体中文', 'zh-TW' => '繁體中文', 'en-US' => 'English', 'ja-JP' => '日本語'];
        foreach ($files as $f) {
            if (preg_match('/^([A-Za-z-]+)\.php$/', $f, $m)) {
                $code = $m[1];
                $name = $names[$code] ?? ucfirst(preg_replace('/[-]/', ' ', $code));
                $langs[$code] = ['name' => $name, 'code' => $code];
            }
        }
        if (!isset($langs['zh-CN'])) $langs['zh-CN'] = ['name' => '简体中文', 'code' => 'zh-CN'];
        if (!isset($langs['en-US'])) $langs['en-US'] = ['name' => 'English', 'code' => 'en-US'];
        ksort($langs);
        $cache = $langs;
        return $langs;
    }

    /** 超管后台停用的语言代码（config.disabled_langs）；zh-CN 恒不可停用 */
    function ysmDisabledLangs() {
        static $cache = null;
        if ($cache !== null) return $cache;
        $out = [];
        if (function_exists('loadSiteConfig')) {
            $v = loadSiteConfig()['disabled_langs'] ?? [];
            if (is_array($v)) $out = array_values(array_filter(array_map('strval', $v), function ($x) { return $x !== 'zh-CN'; }));
        }
        $cache = $out;
        return $out;
    }

    /** 对前台生效的语言列表：全部语言 − 已停用（zh-CN 兜底恒在） */
    function ysmSupportedLangs() {
        $disabled = ysmDisabledLangs();
        $langs = ysmAllLangs();
        foreach ($disabled as $d) unset($langs[$d]);
        if (!isset($langs['zh-CN'])) $langs['zh-CN'] = ['name' => '简体中文', 'code' => 'zh-CN'];
        return $langs;
    }

    /** 读取某语言词典（含回落 zh-CN 合并；zh-CN 为真源，缺键由底层 t() 再回落） */
    function ysmDict($lang) {
        static $dicts = [];
        $lang = (string)$lang;
        if (!isset($dicts[$lang])) {
            $d = [];
            $f = __DIR__ . '/../lang/' . basename($lang) . '.php';
            if (is_file($f)) {
                $loaded = require $f;
                if (is_array($loaded)) $d = $loaded;
            }
            $dicts[$lang] = $d;
        }
        return $dicts[$lang];
    }

    /** 解析当前应使用的语言（?lang → Cookie → Accept-Language → zh-CN） */
    function ysmLocale() {
        static $locale = null;
        if ($locale !== null) return $locale;
        $supported = array_keys(ysmSupportedLangs());
        $try = [];
        // v6.0.0：?lang= 为「临时浏览语言」——只在本次访问生效，不写 Cookie/账号
        if (!empty($_GET['lang'])) $try[] = (string)$_GET['lang'];
        // v6.0.0：登录用户的基础语言（账号绑定，跨设备跟随）
        if (!empty($_SESSION['cmt_user']['home_lang'])) $try[] = (string)$_SESSION['cmt_user']['home_lang'];
        // 浏览器基础语言（游客或账号未设置时）
        if (!empty($_COOKIE['ysm_lang'])) $try[] = (string)$_COOKIE['ysm_lang'];
        if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $al = (string)$_SERVER['HTTP_ACCEPT_LANGUAGE'];
            foreach (explode(',', $al) as $part) {
                $tag = trim(explode(';', $part)[0]);
                if ($tag !== '') $try[] = $tag;
            }
        }
        foreach ($try as $cand) {
            $cand = trim((string)$cand);
            if ($cand === '') continue;
            if (in_array($cand, $supported, true)) { $locale = $cand; return $locale; }
            // 模糊匹配（如 'zh' → zh-CN；'en' → en-US）
            $base = strtolower(explode('-', $cand)[0]);
            foreach ($supported as $s) {
                if (strtolower(explode('-', $s)[0]) === $base) { $locale = $s; return $locale; }
            }
        }
        $locale = 'zh-CN';
        return $locale;
    }

    /** 取键：{key: 语言词典} → 缺键回落 zh-CN；支持 {var} 占位插值，返回已 htmlspecialchars 的文本 */
    function t($key, $vars = [], $lang = null) {
        $lang = $lang === null ? ysmLocale() : (string)$lang;
        $str = '';
        if ($lang !== 'zh-CN') {
            $dict = ysmDict($lang);
            $str = isset($dict[$key]) ? (string)$dict[$key] : '';
        }
        if ($str === '') {
            $zh = ysmDict('zh-CN');
            $str = isset($zh[$key]) ? (string)$zh[$key] : $key;
        }
        if ($vars) {
            foreach ($vars as $k => $v) {
                $str = str_replace('{' . $k . '}', (string)$v, $str);
            }
        }
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }

    /** 前端注入用：返回 [lang, dict(当前语言+zh 合并), phrases(UI 原文文案表)] */
    function ysmClientI18n() {
        $lang = ysmLocale();
        $dict = array_merge(ysmDict('zh-CN'), ysmDict($lang));
        $phrases = [];
        if ($lang !== 'zh-CN') {
            $d = ysmDict($lang);
            if (isset($d['@phrases']) && is_array($d['@phrases'])) $phrases = $d['@phrases'];
        }
        return ['lang' => $lang, 'dict' => $dict, 'langs' => ysmSupportedLangs(), 'phrases' => $phrases];
    }

    /** 日期格式化（Intl 风格；PHP 侧用简单映射，不引入扩展依赖） */
    function fmtDate($ts, $pattern = 'Y-m-d', $lang = null) {
        $lang = $lang === null ? ysmLocale() : (string)$lang;
        return date($pattern, $ts);
    }
}