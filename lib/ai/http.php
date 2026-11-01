<?php
/**
 * v6.4.0-beta：AI 模块 —— http
 * 由 utils.php 拆分而来（原 utils.php 第 1862-2024 行），经 lib/ai/bootstrap.php 加载。
 */

/** 将服务商响应/错误映射为可读原因（脱敏：只返回固定可读文案 + HTTP 码/错误类别，
 *  绝不回传上游原始响应体片段；$raw 形参仅为保持调用签名不变）。 */
function aiReadableError($httpCode, $curlErr = '', $raw = '') {
    if ($curlErr !== '') {
        if (stripos($curlErr, 'timed out') !== false || stripos($curlErr, 'timeout') !== false) return tn('ai.err_conn_timeout');
        if (stripos($curlErr, 'resolve') !== false || stripos($curlErr, 'Could not resolve') !== false) return tn('ai.err_dns');
        return tn('ai.err_network');
    }
    switch ((int)$httpCode) {
        case 401: return tn('ai.err_http_401');
        case 403: return tn('ai.err_http_403');
        case 404: return tn('ai.err_http_404');
        case 400: return tn('ai.err_http_400');
        case 429: return tn('ai.err_http_429');
        case 500: case 502: case 503: case 504: return tn('ai.err_http_5xx', ['code' => (int)$httpCode]);
    }
    if ($httpCode >= 200 && $httpCode < 300) return '';
    return tn('ai.err_http_other', ['code' => (int)$httpCode]);
}

/** v6.2.0-beta：第三方依赖降级熔断（按服务商维度，全站共享）。
 *  连续 3 次「网络不可达 / 5xx / 429 / 408」→ 打开熔断 5 分钟，期间直接返回可读降级提示、不再外呼；
 *  冷却结束后的第一次调用即半开探测，成功即闭合、失败则重新计时。
 *  仅 Key/模型类错误（400/401/403/404/422）不计入熔断——那是个体配置问题，不应牵连全站。 */
define('AI_BREAKER_THRESHOLD', 3);
define('AI_BREAKER_COOLDOWN', 300);
function aiBreakerKey($pid) { return 'ai_breaker:' . $pid; }
function aiBreakerState($pid) {
    $r = db_one('SELECT value FROM meta WHERE key = ?', [aiBreakerKey($pid)]);
    $s = $r ? json_decode((string)$r['value'], true) : null;
    if (!is_array($s)) $s = ['fails' => 0, 'until' => 0, 'last' => 0, 'code' => 0];
    return $s;
}
/** 熔断是否打开（打开期间禁止外呼） */
function aiBreakerOpen($pid) {
    if ($pid === '') return false;
    $s = aiBreakerState($pid);
    return !empty($s['until']) && (int)$s['until'] > time();
}
function aiBreakerRemaining($pid) {
    $s = aiBreakerState($pid);
    $left = (int)($s['until'] ?? 0) - time();
    return $left > 0 ? $left : 0;
}
/** 打开期间的统一可读提示（含剩余分钟数，不暴露上游细节） */
function aiBreakerNotice($pid) {
    $m = max(1, (int)ceil(aiBreakerRemaining($pid) / 60));
    return tn('ai.err_breaker', ['n' => $m]);
}
/** 记录一次外呼结果，维护熔断状态 */
function aiBreakerRecord($pid, $ok, $code = 0, $curlErr = '') {
    if ($pid === '') return;
    $code = (int)$code;
    $s = aiBreakerState($pid);
    if ($ok) {
        if ((int)$s['fails'] !== 0 || !empty($s['until'])) {
            db_exec('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)',
                [aiBreakerKey($pid), json_encode(['fails' => 0, 'until' => 0, 'last' => time(), 'code' => 0])]);
        }
        return;
    }
    // 个体配置类错误不计熔断
    if (in_array($code, [400, 401, 403, 404, 422], true)) return;
    $systemic = ($code === 0 && $curlErr !== '') || $code >= 500 || $code === 429 || $code === 408;
    if (!$systemic) return;
    $s['fails'] = (int)$s['fails'] + 1;
    $s['last'] = time();
    $s['code'] = $code;
    if ($s['fails'] >= AI_BREAKER_THRESHOLD && empty($s['until'])) {
        $s['until'] = time() + AI_BREAKER_COOLDOWN;
        @error_log('ai breaker open: ' . $pid . ' (fails=' . $s['fails'] . ', code=' . $code . ')');
    }
    db_exec('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)',
        [aiBreakerKey($pid), json_encode($s)]);
}
/** 面向界面：各启用服务商的熔断状态（open/remaining_seconds），供前端提示降级 */
function aiBreakerMap() {
    $out = [];
    foreach (aiEnabledProviderIds() as $pid) {
        $out[$pid] = ['open' => aiBreakerOpen($pid), 'remaining' => aiBreakerRemaining($pid)];
    }
    return $out;
}

/** 带熔断的出站调用（唯一推荐入口）：熔断打开时直接降级，不产生外呼；
 *  $ignoreBreaker=true 用于管理端「连通性测试」，让管理员能主动探测恢复。 */
function aiHttpProvider($provider, $url, $apiKey, $payload, $timeout = 60, $ignoreBreaker = false) {
    if ($provider !== '' && !$ignoreBreaker && aiBreakerOpen($provider)) {
        return ['ok' => false, 'code' => 503, 'data' => null, 'raw' => '', 'err' => '',
                'degraded' => true, 'retry_after' => aiBreakerRemaining($provider),
                'message' => aiBreakerNotice($provider)];
    }
    $res = aiHttpJson($url, $apiKey, $payload, $timeout);
    if ($provider !== '') {
        aiBreakerRecord($provider, !empty($res['ok']), (int)($res['code'] ?? 0), (string)($res['err'] ?? ''));
    }
    return $res;
}

/** 统一 JSON POST（Authorization: Bearer）；返回 ['ok','code','data','raw','err']。
 *  仅允许调用白名单 base_url 拼出的地址（SSRF 防护：调用方已保证 base 来自白名单）。 */
function aiHttpJson($url, $apiKey, $payload, $timeout = 60) {
    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');
    if ($host === '' || isPrivateHost($host)) {
        return ['ok' => false, 'code' => 0, 'data' => null, 'raw' => '', 'err' => tn('ai.err_blocked'), 'blocked' => true];
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => max(5, (int)$timeout),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) return ['ok' => false, 'code' => $code, 'data' => null, 'raw' => '', 'err' => $err ?: tn('ai.err_request_failed')];
        $data = json_decode($raw, true);
        return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'data' => is_array($data) ? $data : null, 'raw' => (string)$raw, 'err' => ''];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'timeout' => max(5, (int)$timeout),
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return ['ok' => false, 'code' => 0, 'data' => null, 'raw' => '', 'err' => tn('ai.err_request_failed')];
    $code = 0;
    foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1]; }
    $data = json_decode($raw, true);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'data' => is_array($data) ? $data : null, 'raw' => (string)$raw, 'err' => ''];
}

/** 连通性测试：用所选白名单 base_url + 该账号 Key + 其填写的模型名发一次最小请求。
 *  成功返回 [true,'ok']，失败返回 [false, 可读原因]。绝不落库。 */
function aiConnectivityTest($providerId, $apiKey, $model) {
    $base = aiProviderBaseUrl($providerId);
    if ($base === '') return [false, tn('ai.err_provider_unavailable')];
    if (trim((string)$apiKey) === '') return [false, tn('ai.err_key_required')];
    if (trim((string)$model) === '') return [false, tn('ai.err_model_required')];
    $url = rtrim($base, '/') . '/chat/completions';
    $payload = [
        'model' => trim($model),
        'messages' => [['role' => 'user', 'content' => 'ping']],
        'max_tokens' => 1,
        'stream' => false,
    ];
    // 管理端主动探测：忽略熔断（成功即闭合），但不跳过安全校验
    $res = aiHttpProvider($providerId, $url, trim($apiKey), $payload, 30, true);
    if (!empty($res['blocked'])) return [false, tn('ai.err_blocked_policy')];
    if ($res['ok']) return [true, 'ok'];
    $reason = aiReadableError($res['code'], $res['err'], $res['raw']);
    return [false, $reason !== '' ? $reason : tn('ai.err_call_failed')];
}
