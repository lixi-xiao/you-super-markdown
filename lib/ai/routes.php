<?php
/**
 * v6.4.0-beta：AI 接口路由（原 api.php 第 1502-1958 行）
 * 由 api.php 末尾按「文件存在」require；加载时 $action / 会话校验等上下文已就绪。
 */

// ============================================================================
// v5.4.0-beta：AI 写作（服务端代理调用）
//   - 使用者：站长 / 写作者；超管不参与创作（其会话在校验中被排除 → 403）。
//   - 前端只发"动作 + 选中文本 + 目标服务商"；Key 永不出后端、永不回传明文。
//   - base_url 只取自服务端白名单（不接受任何用户传入 URL → 防 SSRF）。
// ============================================================================
/** 校验当前用户为"可参与创作"的站长/写作者（未登录/超管/普通用户一律 403） */
function aiActionUser() {
    $actor = verifyHomeUser();
    if (!$actor) sendJson(['success' => false, 'error' => tn('auth.err_no_permission')], 403);
    $role = $actor['role'] ?? '';
    if (!in_array($role, [ROLE_STATION_ADMIN, ROLE_AUTHOR], true)) {
        logUnauthorized('越权尝试使用 AI 写作接口');
        sendJson(['success' => false, 'error' => tn('auth.err_no_permission')], 403);
    }
    return $actor;
}
/** 校验当前用户且该角色已被超管开放 AI */
function aiActionUserAllowed() {
    $actor = aiActionUser();
    if (!aiRoleAllowed($actor['role'] ?? '')) sendJson(['success' => false, 'error' => tn('ai.err_not_enabled')], 403);
    return $actor;
}
/** v5.4.1-beta：AI 写接口叠加后台会话有效期校验。
 *  环境一致性校验（requireSessionEnv）+ 后台会话 24h 显式过期（backendSessionExpired）双闸；
 *  AI 写作入口全部位于后台页面（sc.php 编辑器 / station|author dashboard），与此二页面的后台会话判定一致。 */
function aiRequireFreshBackendSession() {
    if (!requireSessionEnv()) sendJson(['success' => false, 'error' => tn('admin.err_env_invalid'), 'env_invalid' => true], 401);
    if (backendSessionExpired()) sendJson(['success' => false, 'error' => tn('auth.err_backend_expired'), 'backend_expired' => true], 401);
}
/** 流式输出一个 SSE 事件并立即 flush */
function aiSseEmit($payload) {
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    @flush();
}
function aiJsonBody() {
    $j = json_decode(file_get_contents('php://input'), true);
    return is_array($j) ? $j : [];
}

// GET ai_config：编辑器/后台据此决定入口可见性与选项（不含任何 Key 明文）
if ($action === 'ai_config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $actor = aiActionUser();
    $role = $actor['role'] ?? '';
    sendJson([
        'success' => true,
        'enabled' => !empty(loadSiteConfig()['ai_enabled']),
        'allowed' => aiRoleAllowed($role),
        'providers' => aiProvidersForClient(),
        'models' => aiPublicModels(),
        'actions' => aiActions(),
        'styles' => aiStyleOptions(),
        'langs' => aiLangOptions(),
        'privacy_ack' => aiPrivacyAcked($actor['id']),
        'privacy_version' => aiPrivacyVersion(),
        'keys' => aiKeysForClient($actor['id']),
        'default_provider' => aiDefaultProvider($actor['id']),
        'thinking' => aiThinkingEnabled($actor['id']),
        'thinking_support' => aiThinkingSupportMap(),
        'breaker' => aiBreakerMap(),
    ]);
}

// GET ai_models：只读——当前启用服务商的可用模型清单（仅 id/展示名/上限值；绝不回 base_url、绝不回 Key）
if ($action === 'ai_models' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    aiActionUser();
    sendJson([
        'success' => true,
        'providers' => aiProvidersForClient(),
        'models' => aiPublicModels(),
        'thinking_support' => aiThinkingSupportMap(),
    ]);
}

// POST ai_privacy_ack：确认「内容将发送至第三方服务商」的当前版本告知（版本变更需重新确认）
if ($action === 'ai_privacy_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = aiActionUser();
    $body = aiJsonBody();
    $ver = trim((string)($body['version'] ?? ''));
    // 只接受当前版本；传了旧版本说明前端页面过期，要求刷新后重新确认
    if ($ver !== '' && $ver !== aiPrivacyVersion()) {
        sendJson(['success' => false, 'error' => tn('ai.err_privacy_updated'), 'need_reload' => true, 'privacy_version' => aiPrivacyVersion()], 409);
    }
    aiSetPrivacyAck($actor['id']);
    auditLog('ai_privacy_ack', $actor['id'], '确认 AI 隐私提示 version=' . aiPrivacyVersion());
    sendJson(['success' => true, 'privacy_version' => aiPrivacyVersion()]);
}

// GET ai_keys：返回该账号各服务商状态（已配置(****末四位)/未配置 + 是否默认），无明文
if ($action === 'ai_keys' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $actor = aiActionUser();
    sendJson([
        'success' => true,
        'providers' => aiProvidersForClient(),
        'models' => aiPublicModels(),
        'keys' => aiKeysForClient($actor['id']),
        'default_provider' => aiDefaultProvider($actor['id']),
        'thinking' => aiThinkingEnabled($actor['id']),
        'thinking_support' => aiThinkingSupportMap(),
    ]);
}

// POST ai_thinking：保存该账号的思考模式开关（账号级，默认关闭）
if ($action === 'ai_thinking' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = aiActionUserAllowed();
    aiRequireFreshBackendSession();
    $body = aiJsonBody();
    if (!array_key_exists('enabled', $body)) sendJson(['success' => false, 'error' => tn('common.err_missing_params')], 400);
    $enabled = !empty($body['enabled']);
    aiSetThinkingEnabled($actor['id'], $enabled);
    auditLog('ai_thinking_update', $actor['id'], '思考模式=' . ($enabled ? '开启' : '关闭'));
    sendJson(['success' => true, 'thinking' => aiThinkingEnabled($actor['id']), 'thinking_support' => aiThinkingSupportMap()]);
}

// POST ai_key_test：连通性测试（用所选白名单 base_url + 该 Key + 模型名发最小请求）；不落库
if ($action === 'ai_key_test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = aiActionUserAllowed();
    aiRequireFreshBackendSession();
    $body = aiJsonBody();
    $provider = (string)($body['provider'] ?? '');
    $model = trim((string)($body['model'] ?? ''));
    $key = trim((string)($body['key'] ?? ''));
    if (aiProviderBaseUrl($provider) === '') {
        auditLog('ai_key_test', $provider, '连通性测试：服务商不可用', 'failed');
        sendJson(['success' => false, 'ok' => false, 'message' => tn('ai.err_provider_unavailable')], 400);
    }
    if ($model === '' || aiFindModel($provider, $model) === null) {
        auditLog('ai_key_test', $provider, '连通性测试：模型不在可用名单', 'failed');
        sendJson(['success' => false, 'ok' => false, 'message' => tn('ai.err_model_not_allowed')], 400);
    }
    [$ok, $reason] = aiConnectivityTest($provider, $key, $model);
    auditLog('ai_key_test', $provider, '连通性测试：' . ($ok ? '成功' : '失败'), $ok ? 'success' : 'failed');
    sendJson(['success' => $ok, 'ok' => $ok, 'message' => $ok ? '连接成功' : $reason]);
}

// POST ai_key_save：必须先做连通性测试，通过才固化（失败不落库，返回可读原因）
if ($action === 'ai_key_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = aiActionUserAllowed();
    aiRequireFreshBackendSession();
    $body = aiJsonBody();
    $provider = (string)($body['provider'] ?? '');
    $model = trim((string)($body['model'] ?? ''));
    $key = trim((string)($body['key'] ?? ''));
    $isDefault = !empty($body['is_default']);
    if (aiProviderBaseUrl($provider) === '') {
        auditLog('ai_key_save', $provider, '保存失败：服务商不可用', 'failed');
        sendJson(['success' => false, 'error' => tn('ai.err_provider_unavailable')], 400);
    }
    if ($key === '') sendJson(['success' => false, 'error' => tn('ai.err_key_required')], 400);
    if ($model === '') sendJson(['success' => false, 'error' => tn('ai.err_model_required')], 400);
    if (aiFindModel($provider, $model) === null) {
        auditLog('ai_key_save', $provider, '保存失败：模型不在可用名单', 'failed');
        sendJson(['success' => false, 'error' => tn('ai.err_model_not_allowed')], 400);
    }
    // 未通过连通性测试 → 拒绝固化（不落库）
    [$ok, $reason] = aiConnectivityTest($provider, $key, $model);
    if (!$ok) {
        auditLog('ai_key_save', $provider, '连通性测试未通过，未保存', 'failed');
        sendJson(['success' => false, 'error' => $reason, 'test_failed' => true], 400);
    }
    $exist = db_one('SELECT 1 AS x FROM ai_keys WHERE user_id = ? AND provider = ?', [$actor['id'], $provider]);
    if (!aiSaveKey($actor['id'], $provider, $model, $key, $isDefault)) {
        auditLog('ai_key_save', $provider, '保存失败（加密不可用）', 'failed');
        sendJson(['success' => false, 'error' => tn('admin.err_save_failed')], 500);
    }
    auditLog('ai_key_' . ($exist ? 'update' : 'add'), $provider,
        ($exist ? '更新' : '新增') . ' Key（模型=' . $model . '；测试通过；不留明文）');
    sendJson(['success' => true, 'keys' => aiKeysForClient($actor['id']), 'default_provider' => aiDefaultProvider($actor['id'])]);
}

// POST ai_key_delete：删除该账号某服务商 Key
if ($action === 'ai_key_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = aiActionUserAllowed();
    aiRequireFreshBackendSession();
    $body = aiJsonBody();
    $provider = (string)($body['provider'] ?? '');
    if (!isset(aiBuiltinProviders()[$provider])) sendJson(['success' => false, 'error' => tn('ai.err_provider_not_found')], 400);
    $n = aiDeleteKey($actor['id'], $provider);
    auditLog('ai_key_delete', $provider, '删除 Key');
    // 删除后若默认缺失，自动补一个默认
    sendJson(['success' => true, 'deleted' => $n, 'keys' => aiKeysForClient($actor['id']), 'default_provider' => aiDefaultProvider($actor['id'])]);
}

// POST ai_run：AI 代理调用（流式 SSE 或普通 JSON）；Key/正文均不出后端记录
if ($action === 'ai_run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = aiActionUserAllowed();
    aiRequireFreshBackendSession();
    $body = aiJsonBody();
    $act = (string)($body['action'] ?? '');
    if (!array_key_exists($act, aiActions())) sendJson(['success' => false, 'error' => tn('common.err_unknown_action')], 400);
    $text = trim((string)($body['text'] ?? ''));
    if ($text === '') sendJson(['success' => false, 'error' => tn('ai.err_no_selection')], 400);
    $style = in_array((string)($body['style'] ?? ''), aiStyleOptions(), true) ? (string)$body['style'] : '';
    $lang = in_array((string)($body['lang'] ?? ''), aiLangOptions(), true) ? (string)$body['lang'] : '';
    if ($act === 'style' && $style === '') sendJson(['success' => false, 'error' => tn('ai.err_style_required')], 400);
    if ($act === 'translate' && $lang === '') sendJson(['success' => false, 'error' => tn('ai.err_target_lang_required')], 400);
    // 目标服务商：仅接受白名单内 id（不接受 base_url）；缺省用账号默认
    $provider = (string)($body['provider'] ?? '');
    if (aiProviderBaseUrl($provider) === '') $provider = aiDefaultProvider($actor['id']);
    if ($provider === '' || aiProviderBaseUrl($provider) === '') {
        sendJson(['success' => false, 'error' => tn('ai.err_no_key_admin')], 400);
    }
    // v6.2.0-beta：服务商熔断中 → 直接降级（不消耗配额、不计限速、不产生外呼）
    if (aiBreakerOpen($provider)) {
        auditLog('ai_call', $provider, '服务商熔断中，请求已降级', 'failed');
        sendJson([
            'success' => false,
            'error' => aiBreakerNotice($provider),
            'degraded' => true,
            'retry_after' => aiBreakerRemaining($provider),
            'breaker' => aiBreakerMap(),
        ], 503);
    }
    $row = db_one('SELECT * FROM ai_keys WHERE user_id = ? AND provider = ?', [$actor['id'], $provider]);
    if (!$row) sendJson(['success' => false, 'error' => tn('ai.err_provider_no_key')], 400);
    $apiKey = aiDecryptKey($row['key_cipher']);
    if ($apiKey === '') sendJson(['success' => false, 'error' => tn('ai.err_key_invalid')], 400);
    $model = trim((string)$row['model']);
    if ($model === '') sendJson(['success' => false, 'error' => tn('ai.err_model_not_selected')], 400);
    // v5.4.3-beta.2：模型必须仍在当前名单内，否则拒绝并提示重选（不静默失败、不自动改写为猜测值）
    if (aiFindModel($provider, $model) === null) {
        auditLog('ai_call', $provider . '/' . $model, '模型不在可用名单，拒绝调用', 'failed');
        sendJson(['success' => false, 'error' => tn('ai.err_model_offline'), 'need_reselect' => true], 400);
    }
    // 按模型取运行时参数（单次输入上限 / 最大输出 / 超时）
    $rt = aiModelRuntime($provider, $model);
    if (mb_strlen($text) > $rt['max_input_chars']) sendJson(['success' => false, 'error' => tn('ai.err_selection_too_long', ['n' => $rt['max_input_chars']])], 400);
    // v6.0.0-beta：AI 加固注入顺序 —— 日配额 → 并发闸（shutdown 释放 + 租约兜底）→ 账号维度限速
    $uid = $actor['id'];
    if (aiDailyQuotaExceeded($uid)) {
        auditLog('ai_call', $provider . '/' . $model, '每日配额已用完，拒绝调用', 'failed');
        sendJson(['success' => false, 'error' => tn('ai.err_quota_exhausted')], 429);
    }
    if (!aiInflightAcquire($uid)) sendJson(['success' => false, 'error' => tn('ai.err_busy')], 429);
    register_shutdown_function(function () use ($uid) { aiInflightRelease($uid); });
    if (aiRateBlockedUid($uid)) {
        auditLog('ai_call', $provider . '/' . $model, '账号维度调用过频，拒绝', 'failed');
        sendJson(['success' => false, 'error' => tn('ai.err_too_frequent')], 429);
    }
    // 出站限速（复用 ai_rates；防滥用放大外呼，IP+指纹维度并存）
    $ip = getClientIP();
    $fp = $_SESSION['cmt_fp'] ?? '';
    if (aiRateBlocked($ip, $fp)) sendJson(['success' => false, 'error' => tn('ai.err_too_frequent')], 429);
    aiRateHit($ip, $fp);
    aiRateHitUid($uid);
    aiDailyQuotaAdd($uid);
    $messages = aiBuildMessages($act, $text, $style, $lang);
    // 请求基线：模型 + 消息（+ 按模型配置的最大输出）；思考参数按账号开关与服务商能力追加。
    // 提示词约束（只输出正文）完全由 messages 决定，思考参数改动不影响之。
    $basePayload = ['model' => $model, 'messages' => $messages];
    if ((int)$rt['max_out_tokens'] > 0) $basePayload['max_tokens'] = (int)$rt['max_out_tokens'];
    $think = aiThinkingParams($provider, aiThinkingEnabled($actor['id']));
    $payload = array_merge($basePayload, $think);
    $base = aiProviderBaseUrl($provider);
    $url = rtrim($base, '/') . '/chat/completions';
    $t0 = microtime(true);
    $stream = !empty($body['stream']);
    if (!$stream) {
        $res = aiHttpProvider($provider, $url, $apiKey, array_merge($payload, ['stream' => false]), (int)$rt['timeout']);
        // 服务商不接受思考参数 → 忽略该参数重试一次（不报错、不阻断；重试成功才标记为不支持）
        if (!$res['ok'] && empty($res['degraded']) && $think !== [] && (int)$res['code'] === 400) {
            $retry = aiHttpProvider($provider, $url, $apiKey, array_merge($basePayload, ['stream' => false]), (int)$rt['timeout']);
            if ($retry['ok']) { aiThinkingMarkUnsupported($provider); $res = $retry; }
        }
        $ms = (int)((microtime(true) - $t0) * 1000);
        if (!$res['ok']) {
            $reason = !empty($res['degraded'])
                ? (string)($res['message'] ?? aiBreakerNotice($provider))
                : aiReadableError($res['code'], $res['err'], $res['raw']);
            auditLog('ai_call', $provider . '/' . $model, "动作={$act}; 耗时={$ms}ms; 失败", 'failed');
            sendJson(['success' => false, 'error' => $reason !== '' ? $reason : '调用失败',
                      'degraded' => !empty($res['degraded']),
                      'retry_after' => (int)($res['retry_after'] ?? 0),
                      'breaker' => aiBreakerMap()], !empty($res['degraded']) ? 503 : 400);
        }
        $out = (string)($res['data']['choices'][0]['message']['content'] ?? '');
        auditLog('ai_call', $provider . '/' . $model, "动作={$act}; 耗时={$ms}ms; 成功");
        sendJson(['success' => true, 'result' => $out]);
    }
    // ===== 流式（SSE）=====
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) { @ob_end_flush(); }
    if (!function_exists('curl_init')) {
        aiSseEmit(['event' => 'error', 'message' => '服务器不支持流式传输']);
        exit;
    }
    aiSseEmit(['event' => 'start', 'provider' => $provider, 'model' => $model]);
    $acc = ''; $rawHead = '';
    // 单次流式尝试：成功即边走边推 delta；返回 [ok, code, cerr]
    $attempt = function ($reqPayload) use ($url, $apiKey, $rt, $provider, &$acc, &$rawHead) {
        $acc = ''; $rawHead = ''; $buf = '';
        // v6.2.0-beta：熔断打开期间不做流式外呼（半开探测走这里：冷却结束后的首次调用即探测）
        if (aiBreakerOpen($provider)) return [false, 503, 'breaker'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($reqPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey, 'Accept: text/event-stream'],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => max(5, (int)$rt['timeout_stream']),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf, &$acc, &$rawHead) {
                if (strlen($rawHead) < 400) $rawHead .= $chunk;
                $buf .= $chunk;
                while (($nl = strpos($buf, "\n")) !== false) {
                    $line = rtrim(substr($buf, 0, $nl), "\r");
                    $buf = substr($buf, $nl + 1);
                    if ($line === '' || strpos($line, 'data:') !== 0) continue;
                    $jsonStr = trim(substr($line, 5));
                    if ($jsonStr === '[DONE]') continue;
                    $j = json_decode($jsonStr, true);
                    if (!is_array($j)) continue;
                    $delta = $j['choices'][0]['delta']['content'] ?? '';
                    if ($delta !== '' && $delta !== null) {
                        $acc .= $delta;
                        aiSseEmit(['event' => 'delta', 'text' => $delta]);
                    }
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        // 记录熔断（流式：拿到内容或 2xx 视为成功）
        aiBreakerRecord($provider, ($acc !== '' || ($ok !== false && $code >= 200 && $code < 400)), $code, $cerr);
        return [$ok, $code, $cerr];
    };
    list($ok, $code, $cerr) = $attempt(array_merge($payload, ['stream' => true]));
    // 服务商不接受思考参数 → 忽略重试（对客户端不可见；重试拿到内容才标记不支持）
    if ($acc === '' && $think !== [] && ($ok === false || $code >= 400)) {
        list($rOk, $rCode, $rCerr) = $attempt(array_merge($basePayload, ['stream' => true]));
        if ($acc !== '') { aiThinkingMarkUnsupported($provider); $ok = $rOk; $code = $rCode; $cerr = $rCerr; }
    }
    $ms = (int)((microtime(true) - $t0) * 1000);
    if ($acc === '' && ($ok === false || $code >= 400)) {
        $degraded = aiBreakerOpen($provider);
        $reason = $degraded ? aiBreakerNotice($provider) : aiReadableError($code, $cerr, $rawHead);
        aiSseEmit(['event' => 'error', 'message' => $reason !== '' ? $reason : '调用失败',
                   'degraded' => $degraded, 'retry_after' => aiBreakerRemaining($provider)]);
        auditLog('ai_call', $provider . '/' . $model, "动作={$act}; 耗时={$ms}ms; 失败", 'failed');
    } else {
        aiSseEmit(['event' => 'done', 'chars' => mb_strlen($acc)]);
        auditLog('ai_call', $provider . '/' . $model, "动作={$act}; 耗时={$ms}ms; 成功");
    }
    exit;
}

// ===== v6.0.0-beta：AI 内容翻译（写作者/站长触发）与友站管理（站长专属）=====
/** 判定 actor 是否可管理某篇（author_id 匹配，或旧文章按 author 昵称匹配；站长/写作者均可），
 *  站长角色可管全部其站内文章；写作者仅管自己。 */
function canManageArticleFp($meta, $actor) {
    $role = $actor['role'] ?? '';
    if ($role === ROLE_STATION_ADMIN) return true;
    if ($role !== ROLE_AUTHOR) return false;
    $myId = $actor['id'] ?? '';
    $myNick = $actor['nickname'] ?? '';
    return ($meta['author_id'] ?? '') === $myId
        || (empty($meta['author_id']) && ($meta['author'] ?? '') === $myNick);
}
/** v6.0.0-beta：AI 翻译要读源文（写作者只能读自己的）（同 sc.php 判定口径）
 *  v6.4.5-beta：改用 YSM_ROOT——本文件已从 api.php 移到 lib/ai/，__DIR__ 不再是站点根（否则一律「文章不存在」） */
function articleSourcePath($slug) {
    if ($slug === '' || !preg_match('/^[\p{L}\p{N}_\-\s\.\(\)（）]+$/u', $slug)) return null;
    $p = YSM_ROOT . '/data/articles/' . basename($slug);
    return is_file($p) ? $p : null;
}

// POST ai_translate：写作者/站长触发某篇生成指定语种译本（预生成落 article_translations；用户前台只读）
if ($action === 'ai_translate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = aiActionUserAllowed();
    aiRequireFreshBackendSession();
    $body = aiJsonBody();
    $slug = trim((string)($body['slug'] ?? ''));
    $lang = (string)($body['lang'] ?? '');
    // v6.0.0-beta：目标语言必须是 i18n 字库内已有的 locale（只翻有字库的语言，缺字典即拒绝）
    if ($lang === '' || !isset(ysmSupportedLangs()[$lang])) sendJson(['success' => false, 'error' => tn('ai.err_no_font')], 400);
    // v6.3.1：目标语言不能等于内容原语种（超管可配，默认 zh-CN）
    if ($lang === ysmSourceLang()) sendJson(['success' => false, 'error' => tn('ai.err_same_lang')], 400);
    $path = articleSourcePath($slug);
    if (!$path) sendJson(['success' => false, 'error' => tn('post.err_not_found')], 404);
    $meta = readArticleMeta($path);
    if (!canManageArticleFp($meta, $actor)) {
        logUnauthorized('越权尝试翻译他人文章: ' . $slug);
        sendJson(['success' => false, 'error' => tn('ai.err_not_own_post')], 403);
    }
    // v6.3.1：与 ai_run 对齐——先取服务商/模型并做白名单校验（下线模型不再继续外呼）
    $provider = aiDefaultProvider($actor['id']);
    if ($provider === '' || aiProviderBaseUrl($provider) === '') {
        auditLog('ai_translate', $slug, '未配置 AI Key，拒绝翻译', 'failed');
        sendJson(['success' => false, 'error' => tn('ai.err_no_key')], 400);
    }
    $keyRow = db_one('SELECT * FROM ai_keys WHERE user_id = ? AND provider = ?', [$actor['id'], $provider]);
    if (!$keyRow) sendJson(['success' => false, 'error' => tn('ai.err_provider_no_key')], 400);
    $model = trim((string)$keyRow['model']);
    if ($model === '' || aiFindModel($provider, $model) === null) {
        auditLog('ai_translate', $provider . '/' . $model, '模型不在可用名单，拒绝翻译', 'failed');
        sendJson(['success' => false, 'error' => tn('ai.err_model_offline'), 'need_reselect' => true], 400);
    }
    if (aiBreakerOpen($provider)) {
        sendJson(['success' => false, 'error' => aiBreakerNotice($provider), 'degraded' => true, 'retry_after' => aiBreakerRemaining($provider)], 503);
    }
    $uid = $actor['id'];
    if (aiDailyQuotaExceeded($uid)) sendJson(['success' => false, 'error' => tn('ai.err_quota_exhausted')], 429);
    if (!aiInflightAcquire($uid)) sendJson(['success' => false, 'error' => tn('ai.err_busy')], 429);
    register_shutdown_function(function () use ($uid) { aiInflightRelease($uid); });
    if (aiRateBlockedUid($uid)) {
        auditLog('ai_translate', $slug, '账号维度调用过频，拒绝', 'failed');
        sendJson(['success' => false, 'error' => tn('ai.err_too_frequent')], 429);
    }
    $ip = getClientIP();
    $fp = $_SESSION['cmt_fp'] ?? '';
    if (aiRateBlocked($ip, $fp)) sendJson(['success' => false, 'error' => tn('ai.err_too_frequent')], 429);
    $src = (string)@file_get_contents($path);
    list($ok, $result) = aiTranslateMd($src, $lang, $actor);
    aiRateHit($ip, $fp);
    aiRateHitUid($uid);
    aiDailyQuotaAdd($uid);
    if (!$ok) {
        auditLog('ai_translate', $slug, '翻译失败 lang=' . $lang, 'failed');
        sendJson(['success' => false, 'error' => $result], 400);
    }
    db_exec('INSERT INTO article_translations (article_id, target_lang, md, src_digest, status, model, created_at, updated_at) '
        . 'VALUES (?,?,?,?,\'done\',?,?,?) ON CONFLICT(article_id, target_lang) DO UPDATE SET md=excluded.md, src_digest=excluded.src_digest, status=\'done\', model=excluded.model, updated_at=excluded.updated_at',
        [$slug, $lang, $result, hash('sha256', $src), $model . '@' . $provider, time(), time()]);
    auditLog('ai_translate', $slug, '生成译本 lang=' . $lang . ' model=' . $model);
    sendJson(['success' => true, 'lang' => $lang]);
}
// GET ai_translation_status：某文章各语种的译本状态（写作者/站长可查自己可管理的文章）
if ($action === 'ai_translation_status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $actor = aiActionUserAllowed();
    $slug = trim((string)($_GET['slug'] ?? ''));
    $path = $slug !== '' ? articleSourcePath($slug) : null;
    if (!$path) sendJson(['success' => false, 'error' => tn('post.err_not_found')], 404);
    $meta = readArticleMeta($path);
    if (!canManageArticleFp($meta, $actor)) sendJson(['success' => false, 'error' => tn('auth.err_no_view')], 403);
    $out = [];
    foreach (array_keys(ysmSupportedLangs()) as $lc) {
        if ($lc === ysmSourceLang()) continue;
        list($st, , $row) = articleTranslationStatus($slug, $lc);
        $out[$lc] = [
            'status' => $st === null ? 'none' : $st,
            'updated' => (string)($row['updated_at'] ?? ''),
            'chars' => isset($row['md']) ? mb_strlen((string)$row['md'], 'UTF-8') : 0,
        ];
    }
    sendJson([
        'success' => true,
        'source' => ysmSourceLang(),
        'source_name' => ysmSourceLangName(),
        'title' => (string)($meta['title'] ?? '') !== '' ? (string)$meta['title'] : preg_replace('/\.md$/i', '', $slug),
        'langs' => $out,
    ]);
}
