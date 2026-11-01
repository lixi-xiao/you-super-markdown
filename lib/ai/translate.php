<?php
/**
 * v6.4.0-beta：AI 模块 —— translate
 * 由 utils.php 拆分而来（原 utils.php 第 1506-1575 行），经 lib/ai/bootstrap.php 加载。
 */

// ---- v6.0.0-beta：AI 内容翻译（写作者/站长后台触发；预生成译本缓存，用户前台只读） ----
/** 分段翻译整篇 Markdown：按空行分段，逐段送 AI，段失败回落原文（不阻断整篇）与保留结构。
 *  $sender: 触发者 actor（用于复用一个已取好的 provider/key；空则自动按默认服务商）。 */
function aiTranslateMd($md, $targetLang, $actor) {
    $provider = aiDefaultProvider($actor['id']);
    if ($provider === '' || aiProviderBaseUrl($provider) === '') return [false, tn('ai.err_no_key')];
    $row = db_one('SELECT * FROM ai_keys WHERE user_id = ? AND provider = ?', [$actor['id'], $provider]);
    if (!$row) return [false, tn('ai.err_provider_no_key')];
    $apiKey = aiDecryptKey($row['key_cipher']);
    if ($apiKey === '') return [false, tn('ai.err_key_invalid')];
    $model = trim((string)$row['model']);
    $base = aiProviderBaseUrl($provider);
    $url = rtrim($base, '/') . '/chat/completions';
    $rt = aiModelRuntime($provider, $model);
    // v6.2.0-beta：熔断打开期间直接降级，不做整篇（多段）外呼
    if (aiBreakerOpen($provider)) return [false, aiBreakerNotice($provider)];
    // 段落切分：保留空行分隔（markdown 结构与代码块完整保留）
    $paras = preg_split('/(\r?\n){2,}/u', (string)$md);
    $out = [];
    foreach ($paras as $i => $seg) {
        $seg = trim($seg);
        if ($seg === '') { $out[] = ''; continue; }
        if (mb_strlen($seg) > (int)$rt['max_input_chars']) {
            $out[] = $seg; // 单段超限回落原文
            continue;
        }
        // 段中途触发熔断 → 终止整篇，避免产出「半译半原文」的译本
        if (aiBreakerOpen($provider)) return [false, aiBreakerNotice($provider)];
        $messages = aiBuildMessages('translate', $seg, '', $targetLang);
        $res = aiHttpProvider($provider, $url, $apiKey, ['model' => $model, 'messages' => $messages, 'stream' => false], (int)$rt['timeout']);
        if ($res['ok']) {
            $out[] = (string)($res['data']['choices'][0]['message']['content'] ?? '');
        } elseif (!empty($res['degraded'])) {
            return [false, (string)($res['message'] ?? aiBreakerNotice($provider))];
        } else {
            $out[] = $seg; // 段失败回落原文
        }
    }
    return [true, implode("\n\n", $out)];
}
/** v6.3.1：内容原语种（超管可配；默认 zh-CN）。文章正文被视为该语言，译本只对其他语言生成/展示。 */
function ysmSourceLang() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $dft = 'zh-CN';
    if (function_exists('loadSiteConfig')) {
        $v = (string)(loadSiteConfig()['source_lang'] ?? '');
        if ($v !== '' && function_exists('ysmSupportedLangs') && isset(ysmSupportedLangs()[$v])) $dft = $v;
    }
    $cache = $dft;
    return $cache;
}
/** 源语种显示名（用于「原文：xx」提示） */
function ysmSourceLangName() {
    $l = ysmSourceLang();
    if (function_exists('ysmAllLangs')) {
        $all = ysmAllLangs();
        if (isset($all[$l]['name'])) return (string)$all[$l]['name'];
    }
    return $l;
}
/** 读某文章某语种译本，返回 [status, md(可空), meta]，判 stale（源文 digest 变化） */
function articleTranslationStatus($aid, $lang) {
    $row = db_one('SELECT * FROM article_translations WHERE article_id = ? AND target_lang = ?', [$aid, $lang]);
    if (!$row) return [null, null, null];
    $src = (string)@file_get_contents(YSM_ROOT . '/data/articles/' . $aid);
    $digest = $src !== '' && $src !== false ? hash('sha256', $src) : '';
    $status = ($digest !== '' && $digest !== $row['src_digest']) ? 'stale' : $row['status'];
    return [$status, $row['md'], $row];
}
