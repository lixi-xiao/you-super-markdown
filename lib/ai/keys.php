<?php
/**
 * v6.4.0-beta：AI 模块 —— keys
 * 由 utils.php 拆分而来（原 utils.php 第 1610-1680 行），经 lib/ai/bootstrap.php 加载。
 */

/** 该账号已配置的 Key 行 */
function aiLoadKeys($uid) {
    if ($uid === '') return [];
    return db_all('SELECT user_id, provider, key_cipher, model, is_default, created, updated FROM ai_keys WHERE user_id = ? ORDER BY provider', [$uid]);
}
/** 面向使用者的 Key 列表：仅返回 状态 + 末四位 + 模型 + 是否默认（绝不含明文/密文） */
function aiKeysForClient($uid) {
    $all = aiBuiltinProviders();
    $rows = [];
    foreach (aiLoadKeys($uid) as $r) $rows[$r['provider']] = $r;
    $out = [];
    foreach (aiEnabledProviderIds() as $id) {
        $r = $rows[$id] ?? null;
        if ($r) {
            $plain = aiDecryptKey($r['key_cipher']);
            $hint = $plain !== '' ? tn('ai.key_configured', ['k' => aiMaskKey($plain)]) : tn('ai.key_configured_plain');
        } else {
            $hint = tn('ai.key_missing');
        }
        $out[] = [
            'provider' => $id,
            'label' => $all[$id]['label'],
            'label_key' => $all[$id]['label_key'] ?? '',
            'configured' => $r ? true : false,
            'hint' => $hint,
            'model' => $r['model'] ?? '',
            'model_available' => $r ? (aiFindModel($id, $r['model'] ?? '') !== null) : true,
            'is_default' => $r ? !empty($r['is_default']) : false,
        ];
    }
    return $out;
}
/** 该账号默认服务商（无默认则取首个已配置且启用者） */
function aiDefaultProvider($uid) {
    $enabled = aiEnabledProviderIds();
    $rows = aiLoadKeys($uid);
    foreach ($rows as $r) { if (!empty($r['is_default']) && in_array($r['provider'], $enabled, true)) return $r['provider']; }
    foreach ($rows as $r) { if (in_array($r['provider'], $enabled, true)) return $r['provider']; }
    return '';
}
/** 保存（新增/更新）Key：加密后入库；可设默认（设默认时清除同账号其它默认） */
function aiSaveKey($uid, $provider, $model, $plain, $isDefault) {
    $cipher = aiEncryptKey($plain);
    if ($cipher === '') return false;
    $now = time();
    $exist = db_one('SELECT user_id FROM ai_keys WHERE user_id = ? AND provider = ?', [$uid, $provider]);
    if ($isDefault) db_exec('UPDATE ai_keys SET is_default = 0 WHERE user_id = ?', [$uid]);
    if ($exist) {
        db_exec('UPDATE ai_keys SET key_cipher = ?, model = ?, is_default = ?, updated = ? WHERE user_id = ? AND provider = ?',
            [$cipher, $model, $isDefault ? 1 : 0, $now, $uid, $provider]);
    } else {
        db_exec('INSERT INTO ai_keys (user_id, provider, key_cipher, model, is_default, created, updated) VALUES (?,?,?,?,?,?,?)',
            [$uid, $provider, $cipher, $model, $isDefault ? 1 : 0, $now, $now]);
    }
    // 保底：账号下若尚无默认，自动把当前这条设为默认
    if (!$isDefault) {
        $hasDefault = db_one('SELECT 1 AS x FROM ai_keys WHERE user_id = ? AND is_default = 1 LIMIT 1', [$uid]);
        if (!$hasDefault) db_exec('UPDATE ai_keys SET is_default = 1 WHERE user_id = ? AND provider = ?', [$uid, $provider]);
    }
    return true;
}
/** 删除某账号某服务商 Key */
function aiDeleteKey($uid, $provider) {
    return db_exec('DELETE FROM ai_keys WHERE user_id = ? AND provider = ?', [$uid, $provider]);
}
/** 清除某账号全部 AI Key（超管"只能清、不能看"；删除/吊销账号时一并清理），返回清除条数 */
function aiClearUserKeys($uid) {
    if ($uid === '') return 0;
    $n = (int)(db_one('SELECT COUNT(*) AS c FROM ai_keys WHERE user_id = ?', [$uid])['c'] ?? 0);
    db_exec('DELETE FROM ai_keys WHERE user_id = ?', [$uid]);
    return $n;
}
