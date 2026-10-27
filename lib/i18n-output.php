<?php
/**
 * v6.1.0：服务端输出层 i18n
 *
 * 目标：页面 PHP 仍按简体中文编写，服务端在输出前把 **HTML 文本节点与白名单属性**
 * 按当前语言词表替换掉，从而做到「切换语言后后端直接输出译文」——无需 JS、无首屏闪烁。
 *
 * 安全边界：
 *  - 只做「整段精确匹配」，不做子串替换，避免把文章正文里的词翻掉；
 *  - 跳过 script / style / pre / code / textarea 内部；
 *  - 跳过用户内容容器（article body / 评论 / 卡片 / 标签 / 搜索与侧栏列表等）。
 *  - 简体中文（zh-CN）直接返回原文，零开销。
 */

if (!function_exists('ysmTranslateHtml')) {
    /** 不该被翻译的容器选择器（按 class / id 判断，命中则整块跳过） */
    function ysmSkipContainers() {
        return [
            'markdown-body', 'cmt-list', 'comment-list', 'cmt-content', 'comment-content',
            'doc-card', 'card-title', 'card-excerpt', 'card-tags', 'tag-cloud', 'tag-cloud-item',
            'home-popular-list', 'home-links-list', 'ann-card-title', 'ann-card-summary', 'ann-card-tags',
            'links-grid', 'link-card', 'link-name', 'link-desc',
            'search-results', 'sidebar-list', 'sidebar-toc-list', 'toc-list',
            'user-content', 'ai-output', 'ai-result', 'code-block',
        ];
    }

    /** 允许翻译的属性（不含 value：value 多为表单默认值/用户数据，翻译会改坏数据） */
    function ysmAttrWhitelist() {
        return ['placeholder', 'title', 'aria-label', 'alt'];
    }

    /** 文本翻译：整段精确匹配 → {n} 模式匹配；保留原空白（含 &nbsp; 造成的 U+00A0） */
    function ysmTranslateText($text, $dict, $patterns) {
        if ($text === '' || !preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) return $text;
        $lead = preg_match('/^[\s\x{00A0}]*/u', $text, $m) ? $m[0] : '';
        $trail = preg_match('/[\s\x{00A0}]*$/u', $text, $m) ? $m[0] : '';
        $core = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $text);
        if ($core === '') return $text;
        if (isset($dict[$core])) {
            return $lead . htmlspecialchars((string)$dict[$core], ENT_QUOTES, 'UTF-8') . $trail;
        }
        // 源码里常写成 &nbsp;歌词 —— 先解码实体再匹配（&nbsp; 属于排版留白，可直接丢弃）
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded !== $text) {
            $core2 = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $decoded);
            if ($core2 !== '' && isset($dict[$core2])) {
                return htmlspecialchars((string)$dict[$core2], ENT_QUOTES, 'UTF-8');
            }
            foreach ($patterns as $p) {
                if (preg_match($p['rx'], $core2, $mm2)) {
                    $idx2 = 0;
                    $out2 = preg_replace_callback('/\{[a-zA-Z0-9_]+\}/', function () use ($mm2, &$idx2) {
                        $idx2++;
                        return isset($mm2[$idx2]) ? $mm2[$idx2] : '';
                    }, $p['to']);
                    return htmlspecialchars((string)$out2, ENT_QUOTES, 'UTF-8');
                }
            }
        }
        foreach ($patterns as $p) {
            if (preg_match($p['rx'], $core, $mm)) {
                $idx = 0;
                $out = preg_replace_callback('/\{[a-zA-Z0-9_]+\}/', function () use ($mm, &$idx) {
                    $idx++;
                    return isset($mm[$idx]) ? $mm[$idx] : '';
                }, $p['to']);
                return $lead . htmlspecialchars((string)$out, ENT_QUOTES, 'UTF-8') . $trail;
            }
        }
        return $text;
    }

    /** 转义后的 HTML：按标签切段，仅翻译文本段与白名单属性 */
    function ysmTranslateHtml($html, $dict) {
        if ($html === '' || !$dict) return $html;
        // {n} 占位模式（编译一次）
        $patterns = [];
        foreach ($dict as $k => $v) {
            if (strpos((string)$k, '{') === false) continue;
            $segs = preg_split('/\{[a-zA-Z0-9_]+\}/', (string)$k);
            $rx = '/^' . implode('(.+?)', array_map(function ($s) {
                return preg_quote($s, '/');
            }, $segs)) . '$/u';
            $patterns[] = ['rx' => $rx, 'to' => (string)$v];
        }

        $skipTags = ['script', 'style', 'pre', 'code', 'textarea', 'kbd', 'samp'];
        $skipContainers = ysmSkipContainers();
        $attrs = ysmAttrWhitelist();

        $parts = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) return $html;

        $out = '';
        $stack = [];      // [tag, skip]
        $skipDepth = 0;

        foreach ($parts as $seg) {
            if ($seg === '') continue;
            if ($seg[0] === '<') {
                $isClose = (bool)preg_match('/^<\s*\//', $seg);
                $selfClose = (bool)preg_match('/\/\s*>$/', $seg);
                $name = '';
                if (preg_match('/^<\s*\/?\s*([a-zA-Z0-9:-]+)/', $seg, $mm)) $name = strtolower($mm[1]);

                if ($isClose) {
                    // 出栈到匹配的标签
                    for ($i = count($stack) - 1; $i >= 0; $i--) {
                        if ($stack[$i][0] === $name) {
                            for ($j = count($stack) - 1; $j >= $i; $j--) {
                                if (!empty($stack[$j][1])) $skipDepth--;
                            }
                            array_splice($stack, $i);
                            break;
                        }
                    }
                    $out .= $seg;
                    continue;
                }

                $isSkip = in_array($name, $skipTags, true);
                if (!$isSkip && $skipDepth === 0) {
                    // 用户内容容器：跳过整块
                    if (preg_match('/\b(?:id|class)\s*=\s*"([^"]*)"/i', $seg, $cm)) {
                        $tokens = preg_split('/\s+/', strtolower($cm[1]));
                        foreach ($skipContainers as $sc) {
                            if (in_array($sc, $tokens, true)) { $isSkip = true; break; }
                        }
                    }
                }
                if (!$selfClose) $stack[] = [$name, $isSkip];
                if ($isSkip) $skipDepth++;

                // 属性翻译（当前不在跳过块内）
                if ($skipDepth === 0 && !$isSkip) {
                    $seg = preg_replace_callback(
                        '/(\s(?:' . implode('|', array_map('preg_quote', $attrs)) . '))="([^"]*)"/iu',
                        function ($m) use ($dict, $patterns) {
                            $val = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
                            $tr = ysmTranslateText($val, $dict, $patterns);
                            if ($tr === $val) return $m[0];
                            return $m[1] . '="' . htmlspecialchars($tr, ENT_QUOTES, 'UTF-8') . '"';
                        },
                        $seg
                    );
                }
                $out .= $seg;
                continue;
            }

            if ($skipDepth > 0) { $out .= $seg; continue; }
            $out .= ysmTranslateText($seg, $dict, $patterns);
        }
        return $out;
    }
}

if (!function_exists('ysmI18nStart')) {
    /** 在入口页最前面调用：非中文时开启输出缓冲，把整页翻成当前语言 */
    function ysmI18nStart() {
        static $started = false;
        if ($started) return;
        $started = true;
        if (!function_exists('ysmLocale')) {
            $f = __DIR__ . '/i18n.php';
            if (is_file($f)) require_once $f;
        }
        if (!function_exists('ysmLocale')) return;
        $lang = ysmLocale();
        if ($lang === 'zh-CN') return;
        if (!function_exists('ysmDict')) return;
        $dict = ysmDict($lang);
        $phrases = (isset($dict['@phrases']) && is_array($dict['@phrases'])) ? $dict['@phrases'] : [];
        if (isset($dict['@admin_phrases']) && is_array($dict['@admin_phrases'])) {
            $phrases = array_merge($phrases, $dict['@admin_phrases']);
        }
        if (!$phrases) return;
        // 响应随 Cookie 变化，避免被中间缓存串语言
        if (!headers_sent()) header('Vary: Cookie', false);
        // 注意：不能按固定块大小分段回调——分块会把 HTML 标签/text 切断，导致半截文本无法精确命中词表
        ob_start(function ($html) use ($phrases) {
            return ysmTranslateHtml($html, $phrases);
        });
        $GLOBALS['__ysmI18nBuf'] = true;
    }
}

if (!function_exists('ysmI18nFlush')) {
    /**
     * 输出 JSON 前调用：结束并丢弃 HTML 翻译缓冲。
     * JSON 里的文案由 ysmTranslateApiPayload() 逐字段翻译，不能被 HTML 翻译器再处理一遍。
     */
    function ysmI18nFlush() {
        if (empty($GLOBALS['__ysmI18nBuf'])) return;
        $GLOBALS['__ysmI18nBuf'] = false;
        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) break;
        }
    }
}
