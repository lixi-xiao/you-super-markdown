<?php
/**
 * v6.4.0-beta：AI 模块 —— privacy
 * 由 utils.php 拆分而来（原 utils.php 第 1682-1697 行），经 lib/ai/bootstrap.php 加载。
 */

/** v6.2.0-beta：数据出境 / 第三方处理告知的版本号。
 *  文案或处理范围发生实质变化时必须递增——旧确认作废，使用者会被要求重新确认。 */
define('AI_PRIVACY_VERSION', '2026-10');
function aiPrivacyVersion() { return AI_PRIVACY_VERSION; }
/** 隐私提示：按「说明版本」确认（版本变化即需重新确认，不再是终身一次） */
function aiPrivacyAcked($uid) {
    if ($uid === '') return false;
    $r = db_one('SELECT value FROM meta WHERE key = ?', ['ai_privacy_ack:' . $uid]);
    return $r && trim((string)$r['value']) === AI_PRIVACY_VERSION;
}
function aiSetPrivacyAck($uid, $version = '') {
    if ($uid === '') return false;
    $v = $version !== '' ? $version : AI_PRIVACY_VERSION;
    db_exec('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)', ['ai_privacy_ack:' . $uid, $v]);
    return $v === AI_PRIVACY_VERSION;
}
