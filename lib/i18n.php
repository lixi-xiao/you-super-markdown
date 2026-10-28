<?php
/**
 * v6.3.0：i18n 核心（key 词表）
 *
 * 设计要点：
 *  - 源码只写 key（如 home.012），词表 lang/<lang>.php 为「key => 译文」的扁平数组；
 *  - zh-CN 是唯一真源；任一语种缺 key 回落到 zh-CN，再回落 key 本身；
 *  - 只有 key 参与翻译，中文原文（正文/标题/昵称/语言名）永不参与匹配；
 *  - 邮件仍按「收件人语言」取词，走 legacy-<lang>.php 的 msgid 表（审计动作名等非 UI 文案的安全区）；
 *  - locale 解析链：?lang= → 账号 home_lang → Cookie → Accept-Language → default_lang → zh-CN。
 */

if (!function_exists('ysmAllLangs')) {
    /** 扫描 lang/ 目录得到全部语言（legacy-* 不参与） */
    function ysmAllLangs() {
        static $cache = null;
        if ($cache !== null) return $cache;
        $dir = __DIR__ . '/../lang';
        $langs = [];
        $files = is_dir($dir) ? (array)@scandir($dir) : [];
        $names = ['zh-CN' => '简体中文', 'zh-TW' => '繁體中文', 'en-US' => 'English', 'ja-JP' => '日本語'];
        foreach ($files as $f) {
            if (!preg_match('/^([A-Za-z-]+)\.php$/', $f, $m)) continue;
            // v6.3.0：legacy-<code>.php 是邮件 msgid 表，不是语言，禁止出现在语言切换器
            if (strpos($f, 'legacy-') === 0) continue;
            $code = $m[1];
            $langs[$code] = ['name' => $names[$code] ?? ucfirst(str_replace('-', ' ', $code)), 'code' => $code];
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

    /** 对前台生效的语言列表 */
    function ysmSupportedLangs() {
        $disabled = ysmDisabledLangs();
        $langs = ysmAllLangs();
        foreach ($disabled as $d) unset($langs[$d]);
        if (!isset($langs['zh-CN'])) $langs['zh-CN'] = ['name' => '简体中文', 'code' => 'zh-CN'];
        return $langs;
    }

    /** 读取某语言的 key 词表 */
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

    /** 解析当前语言 */
    function ysmLocale() {
        static $locale = null;
        if ($locale !== null) return $locale;
        $supported = array_keys(ysmSupportedLangs());
        $try = [];
        if (!empty($_GET['lang'])) $try[] = (string)$_GET['lang'];
        if (!empty($_SESSION['cmt_user']['home_lang'])) $try[] = (string)$_SESSION['cmt_user']['home_lang'];
        if (!empty($_COOKIE['ysm_lang'])) $try[] = (string)$_COOKIE['ysm_lang'];
        if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            foreach (explode(',', (string)$_SERVER['HTTP_ACCEPT_LANGUAGE']) as $part) {
                $tag = trim(explode(';', $part)[0]);
                if ($tag !== '') $try[] = $tag;
            }
        }
        foreach ($try as $cand) {
            $cand = trim((string)$cand);
            if ($cand === '') continue;
            if (in_array($cand, $supported, true)) { $locale = $cand; return $locale; }
            $base = strtolower(explode('-', $cand)[0]);
            foreach ($supported as $s) {
                if (strtolower(explode('-', $s)[0]) === $base) { $locale = $s; return $locale; }
            }
        }
        if (function_exists('loadSiteConfig')) {
            $dft = (string)(loadSiteConfig()['default_lang'] ?? '');
            if ($dft !== '' && in_array($dft, $supported, true)) { $locale = $dft; return $locale; }
        }
        $locale = 'zh-CN';
        return $locale;
    }

    /** 取词（原文，不转义）：用于 JSON 响应、邮件正文等非 HTML 场景 */
    function tn($key, $vars = [], $lang = null) {
        $key = (string)$key;
        $lang = $lang === null ? ysmLocale() : (string)$lang;
        $dict = ysmDict($lang);
        $str = isset($dict[$key]) ? (string)$dict[$key] : '';
        if ($str === '') {
            $zh = ysmDict('zh-CN');
            $str = isset($zh[$key]) ? (string)$zh[$key] : $key;
        }
        if ($str === '') $str = $key;
        if ($vars) {
            foreach ($vars as $k => $v) {
                $str = str_replace('{' . $k . '}', (string)$v, $str);
            }
        }
        return $str;
    }

    /** 取词：key => 译文；缺 key 回落 zh-CN；再缺回落 key 本身。默认 HTML 转义 */
    function t($key, $vars = [], $lang = null) {
        return htmlspecialchars(tn($key, $vars, $lang), ENT_QUOTES, 'UTF-8');
    }

    /** 指定语言取词（邮件等「收件人语言 ≠ 请求语言」场景），默认不转义 */
    function tIn($key, $lang, $vars = [], $escape = false) {
        return t($key, $vars, $lang) === '' ? '' : ($escape ? t($key, $vars, $lang) : html_entity_decode(t($key, $vars, $lang), ENT_QUOTES, 'UTF-8'));
    }

    /** 直接输出 */
    function te($key, $vars = []) {
        echo t($key, $vars);
    }

    /** 前端注入：当前语言词表（与 zh-CN 合并，缺 key 自动回落） */
    function ysmClientI18n($scope = 'front') {
        $lang = ysmLocale();
        $dict = array_merge(ysmDict('zh-CN'), ysmDict($lang));
        return ['lang' => $lang, 'dict' => $dict, 'langs' => ysmSupportedLangs()];
    }

    /** 邮件 msgid 表（legacy；仅邮件/通知使用，UI 不再走这里） */
    function ysmLegacyPhrases($lang) {
        static $cache = [];
        $lang = (string)$lang;
        if (isset($cache[$lang])) return $cache[$lang];
        $out = [];
        $f = __DIR__ . '/../lang/legacy-' . basename($lang) . '.php';
        if (is_file($f)) {
            $d = require $f;
            if (is_array($d)) {
                if (isset($d['@phrases']) && is_array($d['@phrases'])) $out = $d['@phrases'];
                if (isset($d['@admin_phrases']) && is_array($d['@admin_phrases'])) $out = array_merge($out, $d['@admin_phrases']);
            }
        }
        $cache[$lang] = $out;
        return $out;
    }

    /** 邮件取词：msgid → 指定语言；缺则回落原文（与 v6.2 行为一致） */
    function ysmTIn($zh, $lang, $vars = [], $escape = false) {
        $zh = (string)$zh;
        $out = $zh;
        $lang = (string)$lang;
        if ($lang !== '' && $lang !== 'zh-CN') {
            $ph = ysmLegacyPhrases($lang);
            if (isset($ph[$zh])) $out = (string)$ph[$zh];
        }
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', (string)$v, $out);
        }
        return $escape ? htmlspecialchars($out, ENT_QUOTES, 'UTF-8') : $out;
    }

    /** 日期格式化（按语言，不引入 Intl 扩展） */
    function fmtDate($ts, $pattern = null, $lang = null) {
        $lang = $lang === null ? ysmLocale() : (string)$lang;
        $ts = is_numeric($ts) ? (int)$ts : strtotime((string)$ts);
        if ($ts === false || $ts <= 0) return '';
        if ($pattern !== null && $pattern !== '') return date($pattern, $ts);
        if ($lang === 'en-US') return date('M j, Y', $ts);
        if ($lang === 'ja-JP') return date('Y年n月j日', $ts);
        return date('Y年n月j日', $ts);
    }
}
