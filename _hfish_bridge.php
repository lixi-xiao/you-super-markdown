<?php
/**
 * v4.8.0：HFish 蜜罐联动桥接脚本（一次性临时脚本，用完即删）
 * 
 * 由 ysm-hfish-sync.py 调用：写入 threat_events 后触发 maybeLinkedBlock 升级检查。
 * 用法: php _hfish_bridge.php <IP地址>
 * 
 * 注意：此脚本在服务器上运行，必须包含 WEB_ROOT 下的 utils.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
if (empty($argv[1])) { fwrite(STDERR, "用法: php _hfish_bridge.php <IP>\n"); exit(1); }

$ip = trim($argv[1]);
if (!filter_var($ip, FILTER_VALIDATE_IP)) { fwrite(STDERR, "无效IP: $ip\n"); exit(1); }

// 确定 WEB_ROOT（优先环境变量，其次当前目录上级）
$webRoot = getenv('YSM_WEB_ROOT') ?: dirname(__DIR__);
$utilsPath = $webRoot . '/utils.php';
if (!file_exists($utilsPath)) {
    // 尝试从当前目录向上查找
    $utilsPath = __DIR__ . '/utils.php';
}
if (!file_exists($utilsPath)) {
    fwrite(STDERR, "无法找到 utils.php\n"); exit(1);
}
require_once $utilsPath;

// 写入威胁事件并触发联动封锁升级
logThreat('hfish_attack', $ip, '', 86400);  // 24h 去重窗口
echo "OK: hfish_attack logged for $ip\n";