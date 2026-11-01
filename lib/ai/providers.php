<?php
/**
 * v6.4.0-beta：AI 模块 —— providers
 * 由 utils.php 拆分而来（原 utils.php 第 1217-1356 行），经 lib/ai/bootstrap.php 加载。
 */

/** 固定 base_url 的服务商预设白名单（唯一真源；使用者只能选，不能改 base_url） */
function aiBuiltinProviders() {
    return [
        'deepseek'  => ['id' => 'deepseek',  'label' => 'DeepSeek',              'base_url' => 'https://api.deepseek.com/v1'],
        'mimo-payg' => ['id' => 'mimo-payg', 'label' => 'MiMo（按量付费）',       'base_url' => 'https://api.xiaomimimo.com/v1'],
        'mimo-plan' => ['id' => 'mimo-plan', 'label' => 'MiMo（Token Plan 订阅）', 'base_url' => 'https://token-plan-cn.xiaomimimo.com/v1'],
        'qwen'      => ['id' => 'qwen',      'label' => '千问（DashScope 兼容）',   'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1'],
    ];
}
/** 超管维护的启用白名单（config.ai_providers = 启用的 provider id 列表）；字段缺失视为全部启用 */
function aiEnabledProviderIds() {
    $c = loadSiteConfig();
    $ids = $c['ai_providers'] ?? null;
    $all = aiBuiltinProviders();
    if (!is_array($ids)) return array_keys($all);
    $out = [];
    foreach ($all as $id => $p) { if (in_array($id, $ids, true)) $out[] = $id; }
    return $out;
}
/** 面向使用者的服务商清单（仅 id + 展示名，不含 base_url） */
function aiProvidersForClient() {
    $all = aiBuiltinProviders();
    $out = [];
    foreach (aiEnabledProviderIds() as $id) $out[] = ['id' => $id, 'label' => $all[$id]['label']];
    return $out;
}
/** 取服务商 base_url（仅白名单内且已启用；否则空——调用方据此拒绝） */
function aiProviderBaseUrl($id) {
    $all = aiBuiltinProviders();
    if (!isset($all[$id]) || !in_array($id, aiEnabledProviderIds(), true)) return '';
    return $all[$id]['base_url'];
}

// ---- v5.4.3-beta.2：模型与长度（超管按服务商维护模型名单，使用者只能从名单里选） ----
// 预设为官方口径的起步值，超管可在后台「模型与长度」按服务商增删模型并改四个数值（建议值，非硬性）。
//   · max_input_chars = 单次输入字符上限（取代写死的 AI_MAX_INPUT；未配置回退 20000）
//   · max_out_tokens  = 最大输出 tokens（未配置=不传，用服务商默认）
//   · timeout / timeout_stream = 超时秒数（未配置回退 60 / 120）
//   · deepseek-chat / deepseek-reasoner 官方已弃用（2026-07-24），不出现在预设名单。
/** 内置默认模型名单（站点配置 ai_model_catalog 字段缺失时回落） */
function aiBuiltinModels() {
    $mimo = [
        ['id' => 'mimo-v2.6-pro',            'label' => 'MiMo V2.6 Pro',            'max_input_chars' => 80000,  'max_out_tokens' => 8192,  'timeout' => 60, 'timeout_stream' => 180],
        ['id' => 'mimo-v2.6-flash',          'label' => 'MiMo V2.6 Flash',          'max_input_chars' => 80000,  'max_out_tokens' => 8192,  'timeout' => 60, 'timeout_stream' => 180],
        ['id' => 'mimo-v2.6-pro-ultraspeed', 'label' => 'MiMo V2.6 Pro UltraSpeed', 'max_input_chars' => 80000,  'max_out_tokens' => 8192,  'timeout' => 60, 'timeout_stream' => 180],
    ];
    $qwen = [
        ['id' => 'qwen3.8-max',   'label' => 'Qwen3.8 Max',   'max_input_chars' => 200000, 'max_out_tokens' => 32768, 'timeout' => 60, 'timeout_stream' => 180],
        ['id' => 'qwen3.7-plus',  'label' => 'Qwen3.7 Plus',  'max_input_chars' => 200000, 'max_out_tokens' => 16384, 'timeout' => 60, 'timeout_stream' => 180],
        ['id' => 'qwen3.8-flash', 'label' => 'Qwen3.8 Flash', 'max_input_chars' => 200000, 'max_out_tokens' => 16384, 'timeout' => 60, 'timeout_stream' => 180],
    ];
    $deepseek = [
        ['id' => 'deepseek-flash',  'label' => 'DeepSeek Flash',  'max_input_chars' => 200000, 'max_out_tokens' => 8192,  'timeout' => 60, 'timeout_stream' => 180],
        ['id' => 'deepseek-v4-pro', 'label' => 'DeepSeek V4 Pro', 'max_input_chars' => 200000, 'max_out_tokens' => 16384, 'timeout' => 60, 'timeout_stream' => 180],
    ];
    return [
        'mimo-payg' => $mimo,
        'mimo-plan' => $mimo,
        'qwen'      => $qwen,
        'deepseek'  => $deepseek,
    ];
}
/** 归一化单条模型配置（字段缺失/非法回落默认；max_out_tokens=0 视为"不传"） */
function aiNormalizeModelEntry($m) {
    if (!is_array($m)) return null;
    $id = trim((string)($m['id'] ?? ''));
    if ($id === '') return null;
    $mic = (int)($m['max_input_chars'] ?? 0);
    if ($mic <= 0) $mic = AI_MAX_INPUT;
    $mot = (int)($m['max_out_tokens'] ?? 0);
    if ($mot < 0) $mot = 0;
    $to = (int)($m['timeout'] ?? 0);
    if ($to <= 0) $to = AI_TIMEOUT;
    $tos = (int)($m['timeout_stream'] ?? 0);
    if ($tos <= 0) $tos = AI_TIMEOUT_STREAM;
    return [
        'id' => $id,
        'label' => trim((string)($m['label'] ?? '')),
        'max_input_chars' => $mic,
        'max_out_tokens' => $mot,
        'timeout' => $to,
        'timeout_stream' => $tos,
    ];
}
/** 站点配置里的模型名单（config.ai_model_catalog）；字段缺失回落内置默认 */
function aiModelsConfig() {
    $c = loadSiteConfig();
    $m = $c['ai_model_catalog'] ?? null;
    $builtin = aiBuiltinModels();
    if (!is_array($m)) return $builtin;
    $out = [];
    foreach (aiBuiltinProviders() as $pid => $p) {
        if (!array_key_exists($pid, $m)) { $out[$pid] = $builtin[$pid] ?? []; continue; }
        $list = [];
        if (is_array($m[$pid])) {
            foreach ($m[$pid] as $r) { $n = aiNormalizeModelEntry($r); if ($n !== null) $list[] = $n; }
        }
        $out[$pid] = $list;
    }
    return $out;
}
/** 某服务商的模型名单 */
function aiProviderModels($pid) {
    $all = aiModelsConfig();
    return $all[$pid] ?? [];
}
/** 在名单中查模型配置；不在名单返回 null */
function aiFindModel($pid, $modelId) {
    $modelId = trim((string)$modelId);
    if ($modelId === '') return null;
    foreach (aiProviderModels($pid) as $m) { if ($m['id'] === $modelId) return $m; }
    return null;
}
/** 取运行时参数（单次输入上限 / 最大输出 / 超时）；模型不在名单时回落全局兜底（调用方应已拒绝） */
function aiModelRuntime($pid, $modelId) {
    $m = aiFindModel($pid, $modelId);
    if ($m) return $m;
    return ['id' => $modelId, 'label' => '', 'max_input_chars' => AI_MAX_INPUT, 'max_out_tokens' => 0, 'timeout' => AI_TIMEOUT, 'timeout_stream' => AI_TIMEOUT_STREAM];
}
/** 面向使用者的模型名单（仅 id / 展示名 / 上限值，不含 base_url 与任何密钥） */
function aiPublicModels() {
    $out = [];
    foreach (aiEnabledProviderIds() as $pid) {
        $list = [];
        foreach (aiProviderModels($pid) as $m) {
            $list[] = ['id' => $m['id'], 'label' => $m['label'], 'max_input_chars' => $m['max_input_chars'], 'max_out_tokens' => $m['max_out_tokens']];
        }
        $out[$pid] = $list;
    }
    return $out;
}

/** 当前角色是否被允许使用 AI 写作（超管一律不参与） */
function aiRoleAllowed($role) {
    $c = loadSiteConfig();
    if (empty($c['ai_enabled'])) return false;
    if ($role === ROLE_STATION_ADMIN) return !empty($c['ai_role_station_admin']);
    if ($role === ROLE_AUTHOR) return !empty($c['ai_role_author']);
    return false;
}
