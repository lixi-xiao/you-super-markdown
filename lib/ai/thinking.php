<?php
/**
 * v6.4.0-beta：AI 模块 —— thinking
 * 由 utils.php 拆分而来（原 utils.php 第 1699-1751 行），经 lib/ai/bootstrap.php 加载。
 */

// ---- v5.4.3-beta.2：思考模式（账号级，默认关闭）----
// 存储于 meta 表 ai_thinking:<uid>（'on'/'off'）；删除/禁用/吊销账号时随 purgeUserResiduals 一并清理。
/** 该账号是否开启思考模式（默认关闭） */
function aiThinkingEnabled($uid) {
    if ($uid === '') return false;
    $r = db_one('SELECT value FROM meta WHERE key = ?', ['ai_thinking:' . $uid]);
    return $r && trim((string)$r['value']) === 'on';
}
function aiSetThinkingEnabled($uid, $enabled) {
    if ($uid === '') return false;
    db_exec('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)', ['ai_thinking:' . $uid, $enabled ? 'on' : 'off']);
    return true;
}
/** 实测发现"不接受思考参数"的服务商（缓存于站点配置，避免重复下发导致请求被拒） */
function aiThinkingUnsupportedSet() {
    $c = loadSiteConfig();
    $v = $c['ai_thinking_unsupported'] ?? null;
    if (!is_array($v)) return [];
    return array_values(array_filter(array_map('strval', $v)));
}
function aiThinkingMarkUnsupported($pid) {
    if ($pid === '') return;
    $set = aiThinkingUnsupportedSet();
    if (in_array($pid, $set, true)) return;
    $set[] = $pid;
    $c = loadSiteConfig();
    $c['ai_thinking_unsupported'] = $set;
    saveSiteConfig($c);
}
/** 服务商"思考"支持能力（参数名/类型）；未知或已实测不支持 → supported=false（一律不下发）。
 *  参数口径：qwen（DashScope 兼容）用 enable_thinking（bool）；deepseek / mimo-* 用 thinking:{type}。
 *  ⚠ 若某服务商/模型实测不接受该参数，运行时将回退"忽略并不报错"并标记为不支持（界面随之提示）。 */
function aiThinkingCapability($pid) {
    if (in_array($pid, aiThinkingUnsupportedSet(), true)) return ['supported' => false];
    if ($pid === 'qwen') return ['supported' => true, 'kind' => 'enable_thinking'];
    if (in_array($pid, ['deepseek', 'mimo-payg', 'mimo-plan'], true)) return ['supported' => true, 'kind' => 'thinking'];
    return ['supported' => false];
}
/** 依据服务商能力 + 账号开关，返回需并入请求体的思考参数；未支持 → 空数组（绝不发未知参数） */
function aiThinkingParams($provider, $enabled) {
    $cap = aiThinkingCapability($provider);
    if (empty($cap['supported'])) return [];
    $kind = $cap['kind'] ?? '';
    if ($kind === 'enable_thinking') return ['enable_thinking' => $enabled ? true : false];
    if ($kind === 'thinking') return ['thinking' => ['type' => $enabled ? 'enabled' : 'disabled']];
    return [];
}
/** 面向使用者的思考支持情况（provider id → 是否可开关） */
function aiThinkingSupportMap() {
    $out = [];
    foreach (aiEnabledProviderIds() as $pid) $out[$pid] = !empty(aiThinkingCapability($pid)['supported']);
    return $out;
}
