<?php
/**
 * v6.0.0：i18n UI 外壳框架
 *
 * 设计要点：
 *  - 自动发现：扫描 ../lang/*.php 生成语言列表，不加新语种文件即自动出现在切换器（不强写注册表）。
 *  - 缺键回落：任何 dict 缺失的 key 都回落到 zh-CN（唯一真源），永不空白。
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
                $langs[$code] = ['name' => $names[$code] ?? ucfirst(preg_replace('/[-]/', ' ', $code)), 'code' => $code];
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

    /** 读取某语言词典（zh-CN 由底层 t() 兜底） */
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
        // v6.0.0：兜底语言改读「首页默认语言」（超管后台可配），未配置或无匹配时回落 zh-CN
        if (function_exists('loadSiteConfig')) {
            $dft = (string)(loadSiteConfig()['default_lang'] ?? '');
            if ($dft !== '' && in_array($dft, $supported, true)) { $locale = $dft; return $locale; }
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

    /** 前端注入用：返回 [lang, dict(当前语言+zh 合并)]；$scope='admin' 时附带后台词组 */
    function ysmClientI18n($scope = 'front') {
        $lang = ysmLocale();
        $dict = array_merge(ysmDict('zh-CN'), ysmDict($lang));
        $phrases = [];
        if ($lang !== 'zh-CN') {
            $d = ysmDict($lang);
            if (isset($d['@phrases']) && is_array($d['@phrases'])) $phrases = $d['@phrases'];
            // v6.1.0：后台词组单独存放，避免前台每页注入上百 KB
            if ($scope === 'admin' && isset($d['@admin_phrases']) && is_array($d['@admin_phrases'])) {
                $phrases = array_merge($phrases, $d['@admin_phrases']);
            }
        }
        return ['lang' => $lang, 'dict' => $dict, 'langs' => ysmSupportedLangs(), 'phrases' => $phrases];
    }

    /** 短语取词（v6.1.0）：以简体中文原文为 msgid，支持 {name} 占位；缺键回落原文 */
    function ysmT($zh, $vars = [], $escape = true) {
        $zh = (string)$zh;
        $out = $zh;
        $lang = ysmLocale();
        if ($lang !== 'zh-CN') {
            $d = ysmDict($lang);
            $ph = (isset($d['@phrases']) && is_array($d['@phrases'])) ? $d['@phrases'] : [];
            // v6.1.0：后台/接口文案单独存放，查词时一并参与
            if (isset($d['@admin_phrases']) && is_array($d['@admin_phrases'])) {
                $ph = array_merge($ph, $d['@admin_phrases']);
            }
            if (isset($ph[$zh])) {
                $out = (string)$ph[$zh];
            } else {
                foreach ($ph as $k => $v) {
                    $k = (string)$k;
                    if (strpos($k, '{') === false) continue;
                    $segs = preg_split('/\{[a-zA-Z0-9_]+\}/', $k);
                    $rx = '/^' . implode('(.+?)', array_map(function ($s) { return preg_quote($s, '/'); }, $segs)) . '$/u';
                    if (preg_match($rx, $zh, $m)) {
                        $idx = 0;
                        $out = preg_replace_callback('/\{[a-zA-Z0-9_]+\}/', function () use ($m, &$idx) {
                            $idx++;
                            return isset($m[$idx]) ? $m[$idx] : '';
                        }, (string)$v);
                        break;
                    }
                }
            }
        }
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', (string)$v, $out);
        }
        return $escape ? htmlspecialchars($out, ENT_QUOTES, 'UTF-8') : $out;
    }

    /** ysmT 的直接输出版本 */
    function ysmE($zh, $vars = []) {
        echo ysmT($zh, $vars);
    }

    /**
     * v6.2.0：按指定语言取词（邮件等「收件人语言 ≠ 当前请求语言」的场景）。
     * 逻辑与 ysmT 一致：精确命中 → {n} 模式命中 → 回落原文；默认不转义（邮件正文由调用方负责转义）。
     */
    function ysmTIn($zh, $lang, $vars = [], $escape = false) {
        $zh = (string)$zh;
        $out = $zh;
        $lang = (string)$lang;
        if ($lang !== '' && $lang !== 'zh-CN') {
            $d = ysmDict($lang);
            $ph = (isset($d['@phrases']) && is_array($d['@phrases'])) ? $d['@phrases'] : [];
            if (isset($d['@admin_phrases']) && is_array($d['@admin_phrases'])) {
                $ph = array_merge($ph, $d['@admin_phrases']);
            }
            if (isset($ph[$zh])) {
                $out = (string)$ph[$zh];
            } else {
                foreach ($ph as $k => $v) {
                    $k = (string)$k;
                    if (strpos($k, '{') === false) continue;
                    $segs = preg_split('/\{[a-zA-Z0-9_]+\}/', $k);
                    $rx = '/^' . implode('(.+?)', array_map(function ($s) { return preg_quote($s, '/'); }, $segs)) . '$/u';
                    if (preg_match($rx, $zh, $m)) {
                        $idx = 0;
                        $out = preg_replace_callback('/\{[a-zA-Z0-9_]+\}/', function () use ($m, &$idx) {
                            $idx++;
                            return isset($m[$idx]) ? $m[$idx] : '';
                        }, (string)$v);
                        break;
                    }
                }
            }
        }
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', (string)$v, $out);
        }
        return $escape ? htmlspecialchars($out, ENT_QUOTES, 'UTF-8') : $out;
    }

    /**
     * v6.1.0：JSON 出口翻译——递归把 error / message / msg 字段按当前语言替换。
     * 只翻「整句命中词表」的文案，未收录的中文原样保留（不猜测）。
     */
    function ysmTranslateApiPayload($data) {
        if (!is_array($data)) return $data;
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = ysmTranslateApiPayload($v);
                continue;
            }
            if (!is_string($v) || $v === '') continue;
            if (!in_array($k, ['error', 'message', 'msg'], true)) continue;
            if (!preg_match('/[\x{4e00}-\x{9fff}]/u', $v)) continue;
            $data[$k] = ysmT($v, [], false);
        }
        return $data;
    }

    /** 日期格式化（PHP 侧简单映射，不引入 Intl 扩展依赖） */
    function fmtDate($ts, $pattern = 'Y-m-d', $lang = null) {
        return date($pattern, $ts);
    }
}