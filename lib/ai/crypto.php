<?php
/**
 * v6.4.0-beta：AI 模块 —— crypto
 * 由 utils.php 拆分而来（原 utils.php 第 1358-1504 行），经 lib/ai/bootstrap.php 加载。
 */

// ---- v6.0.0-beta：AI Key 加密密钥双钥自动过渡 ----
// 当前钥固定路径（加密新数据用）；轮换后旧钥另存为 <AI_ENC_KEY_FILE>.<ts> 作为归档钥（解密旧密文）。
// AES-GCM 头（IV+tag）固定，故同一 key 用同一机制解出的旧密文，只要归档钥仍在即可解密 → 无跨钥解密问题。

/** 读取 AI Key 加密密钥（不存在则安全生成；均不可写时返回空 → 加密失败即拒绝保存） */
function getAiEncKey() {
    foreach ([AI_ENC_KEY_FILE, AI_ENC_KEY_FALLBACK] as $f) {
        if (is_readable($f)) {
            $v = trim((string)@file_get_contents($f));
            if ($v !== '') return $v;
        }
        $dir = dirname($f);
        if (is_dir($dir) && is_writable($dir)) {
            $v = bin2hex(random_bytes(32));
            if (@file_put_contents($f, $v, LOCK_EX) !== false) { @chmod($f, 0600); return $v; }
        }
    }
    return '';
}
/** 当前钥路径（返回可读路径，空表示不可用） */
function aiEncKeyPath() {
    foreach ([AI_ENC_KEY_FILE, AI_ENC_KEY_FALLBACK] as $f) {
        if (is_readable($f) && trim((string)@file_get_contents($f)) !== '') return $f;
    }
    return '';
}
/** 解密尝试的 Key 候选：[当前钥, ...归档钥]（仅当前钥存在时非空） */
function getAiDecryptKeys() {
    $cur = getAiEncKey();
    if ($cur === '') return [];
    $keys = [$cur];
    $base = aiEncKeyPath();
    if ($base !== '') {
        $dir = dirname($base);
        $prefix = basename($base) . '.';
        if (is_dir($dir)) {
            $files = @scandir($dir);
            if (is_array($files)) {
                $archive = [];
                foreach ($files as $fn) {
                    if (strpos($fn, $prefix) === 0 && is_readable($dir . '/' . $fn)) {
                        $v = trim((string)@file_get_contents($dir . '/' . $fn));
                        if ($v !== '' && $v !== $cur) $archive[] = $v;
                    }
                }
                // 新→旧：时间戳后缀大者优先（排最后还有多个则按内容去重）
                $keys = array_merge($keys, array_values(array_unique($archive)));
            }
        }
    }
    return $keys;
}
/** 加密 AI Key（AES-256-GCM；返回 'gcm:'+base64(iv|tag|cipher)）；始终用当前钥 */
function aiEncryptKey($plain) {
    if ($plain === '') return '';
    $secret = getAiEncKey();
    if ($secret === '') return '';
    $key = hash('sha256', 'ysm-ai:' . $secret, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) return '';
    return 'gcm:' . base64_encode($iv . $tag . $cipher);
}
/** 解密 AI Key：循环尝试当前钥与归档钥，任一成功即返回明文 */
function aiDecryptKey($stored) {
    if (!is_string($stored) || strpos($stored, 'gcm:') !== 0) return '';
    $raw = base64_decode(substr($stored, 4));
    if ($raw === false || strlen($raw) < 28) return '';
    $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $ct = substr($raw, 28);
    foreach (getAiDecryptKeys() as $secret) {
        $key = hash('sha256', 'ysm-ai:' . $secret, true);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain !== false && $plain !== '') return $plain;
    }
    return '';
}
/** 是否到达轮换窗口（当前钥生成/最后修改时间距今 ≥ ttlDays；无时间戳可判断时按文件 mtime） */
function aiKeyRotationNeeded($ttlDays = 90) {
    $cur = aiEncKeyPath();
    if ($cur === '') return false;
    $t = is_readable($cur) ? @filemtime($cur) : 0;
    if ($t === 0) return false;
    return (time() - (int)$t) >= $ttlDays * 86400;
}
/** 轮换：将当前钥归档为 <路径>.<ts>，写入全新随机钥。归档保留（供解密旧密文 + 渐进重加密）。 */
function aiKeyRotate() {
    $path = aiEncKeyPath();
    if ($path === '') return [false, '当前密钥不可用'];
    $cur = getAiEncKey();
    $ts = time();
    $archive = $path . '.' . $ts;
    if (@file_put_contents($archive, $cur . "\n", LOCK_EX) === false) return [false, '归档失败'];
    @chmod($archive, 0600);
    $new = bin2hex(random_bytes(32));
    if (@file_put_contents($path, $new, LOCK_EX) === false) { @unlink($archive); return [false, '写入新钥失败']; }
    @chmod($path, 0600);
    return [true, '', $ts];
}
/** 渐进重加密：把所有仍用非当前钥加密的 ai_keys 密文用当前钥重加密（每轮最多 $batch 行） */
function aiReencryptBatch($batch = 100) {
    $current = getAiEncKey();
    if ($current === '') return [0, 0];
    $curKey = hash('sha256', 'ysm-ai:' . $current, true);
    $rows = db_all('SELECT user_id, provider, key_cipher FROM ai_keys LIMIT ?', [$batch]);
    // 逐行判断：直接尝试解密，能解出明文即用当前钥重加密
    $done = 0; $churn = 0;
    foreach ($rows as $r) {
        $plain = aiDecryptKey($r['key_cipher']);
        if ($plain === '') continue;
        $tag = ''; $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $curKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) continue;
        $newCipher = 'gcm:' . base64_encode($iv . $tag . $cipher);
        if ($newCipher === $r['key_cipher']) continue; // 已是当前钥
        db_exec('UPDATE ai_keys SET key_cipher = ?, updated = ? WHERE user_id = ? AND provider = ?',
            [$newCipher, time(), $r['user_id'], $r['provider']]);
        $done++; $churn++;
    }
    return [$done, $churn];
}
/** 归档钥自动 GC：删除早于 $olderDays 的归档钥（该日之前应已全部重加密完毕） */
function aiKeyGc($olderDays = 30) {
    $base = aiEncKeyPath();
    if ($base === '') return [0, []];
    $dir = dirname($base); $prefix = basename($base) . '.';
    $cutoff = time() - $olderDays * 86400;
    $removed = []; $cnt = 0;
    if (!is_dir($dir)) return [0, []];
    foreach ((array)@scandir($dir) as $fn) {
        if (strpos($fn, $prefix) !== 0) continue;
        $suffix = substr($fn, strlen($prefix));
        if (!ctype_digit($suffix)) continue;
        if ((int)$suffix < $cutoff) {
            $full = $dir . '/' . $fn;
            if (@unlink($full)) { $cnt++; $removed[] = $fn; }
        }
    }
    return [$cnt, $removed];
}
/** 明文 Key 掩码（仅保留末四位；绝不明文回显的展示口径） */
function aiMaskKey($plain) {
    $plain = (string)$plain;
    if ($plain === '') return '';
    if (strlen($plain) <= 4) return '****';
    return '****' . substr($plain, -4);
}
