<?php
/**
 * v6.4.0-beta：AI 模块入口
 *
 * 由 utils.php 末尾按「文件存在」require（master 无 lib/ai/ 时不加载，行为不变）。
 * 约定：
 *  - YSM_ROOT = 站点根目录；密钥/数据路径一律基于它，模块目录搬动不影响运行；
 *  - 本目录只放 AI 实现；链接巡检（linkFetchMeta / linkCheckAlive）等非 AI 逻辑仍留在 utils.php。
 */
if (!defined('YSM_ROOT')) define('YSM_ROOT', dirname(__DIR__, 2));

// AI Key 加密密钥文件：优先 /opt 下 secrets/（安装脚本生成），不可读时回退 webroot data/（0600）
if (!defined('AI_ENC_KEY_FILE')) define('AI_ENC_KEY_FILE', '/opt/you-super-markdown/secrets/ai_enc_key');
if (!defined('AI_ENC_KEY_FALLBACK')) define('AI_ENC_KEY_FALLBACK', YSM_ROOT . '/data/.ai_enc_key');
// 请求体/响应长度与超时上限（单次输入上限 / 最大输出 / 超时优先按模型配置取，以下为兜底默认）
if (!defined('AI_MAX_INPUT')) define('AI_MAX_INPUT', 20000);
if (!defined('AI_TIMEOUT')) define('AI_TIMEOUT', 60);
if (!defined('AI_TIMEOUT_STREAM')) define('AI_TIMEOUT_STREAM', 120);

require_once __DIR__ . '/providers.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/translate.php';
require_once __DIR__ . '/keys.php';
require_once __DIR__ . '/privacy.php';
require_once __DIR__ . '/thinking.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/quota.php';
require_once __DIR__ . '/http.php';
