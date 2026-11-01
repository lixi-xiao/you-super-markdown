<?php
/**
 * v6.4.0-beta：AI 模块 —— quota
 * 由 utils.php 拆分而来（原 utils.php 第 1812-1860 行），经 lib/ai/bootstrap.php 加载。
 */

/** AI 出站调用限速（复用 ai_rates 表） */
function aiRateBlocked($ip, $fp, $max = 20, $window = 60) {
    return db_rate_count('ai_rates', $ip, $window, $fp) >= $max;
}
function aiRateHit($ip, $fp) {
    db_rate_add('ai_rates', $ip, $fp);
}
/** v6.0.0-beta：账号维度限速（滑动窗口内账号调用次数 > max 判定超限；与 IP+指纹并存计数） */
function aiRateBlockedUid($uid, $max = 20, $window = 60) {
    return (int)db_one('SELECT COUNT(*) AS c FROM ai_rates WHERE user_id = ? AND t > ?', [$uid, time() - $window])['c'] >= $max;
}
function aiRateHitUid($uid) {
    db_exec('INSERT INTO ai_rates (ip, fp, user_id, t) VALUES (?, ?, ?, ?)', [getClientIP(), $_SESSION['cmt_fp'] ?? '', $uid, time()]);
}

// ---- v6.0.0-beta：AI 并发闸（user_id 唯一，过期租约防死锁；调用方用 register_shutdown 确保释放） ----
function aiInflightAcquire($uid, $lease = 120) {
    if ($uid === '') return false;
    $now = time();
    // 已占用且未过期 → 拒绝（严格并发）
    $row = db_one('SELECT started FROM ai_inflight WHERE user_id = ?', [$uid]);
    if ($row && (int)$row['started'] > $now - $lease) return false;
    // 无占用或租约过期 → 原子占位（INSERT OR REPLACE 在并发下仍可能冲突，捕获唯一键异常视为并发失败）
    try {
        db_exec('INSERT INTO ai_inflight (user_id, started) VALUES (?, ?) '
            . 'ON CONFLICT(user_id) DO UPDATE SET started = excluded.started', [$uid, $now]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}
function aiInflightRelease($uid) {
    if ($uid === '') return;
    db_exec('DELETE FROM ai_inflight WHERE user_id = ?', [$uid]);
}
/** v6.0.0-beta：每日配额（写死常量；预留 config.ai_daily_quota 可配置，缺省回落常量） */
define('AI_DAILY_QUOTA', 300);
function aiDailyQuotaExceeded($uid) {
    if ($uid === '') return true;
    $d = gmdate('Ymd'); // 自然日（UTC）
    $row = db_one('SELECT cnt FROM ai_daily WHERE d = ? AND user_id = ?', [$d, $uid]);
    return $row ? (int)$row['cnt'] >= AI_DAILY_QUOTA : false;
}
function aiDailyQuotaAdd($uid) {
    if ($uid === '') return;
    $d = gmdate('Ymd');
    db_exec('INSERT INTO ai_daily (d, user_id, cnt) VALUES (?, ?, 1) '
        . 'ON CONFLICT(d, user_id) DO UPDATE SET cnt = cnt + 1', [$d, $uid]);
}
