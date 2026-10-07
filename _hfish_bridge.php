<?php
/**
 * v4.8.0：HFish 蜜罐联动桥接脚本（一次性临时脚本，用完即删）
 *
 * 由 ysm-hfish-sync.py 调用：写入 threat_events 后触发 maybeLinkedBlock 升级检查。
 *
 * v5.4.12（性能关键修复）：支持批量——此前只会话式地"一个 IP 起一个 PHP 进程"，蜜罐历史积压
 * （实测约 760~1000 个超阈值 IP）上线后首次同步会一次性 spawn 近千个 PHP 进程（每个还含一次
 * 同步 SMTP），直接把 2GB 小机器 CPU 打到 90%。现改为**一次进程处理全部 IP**：
 *   ① 命令行：php _hfish_bridge.php <IP> [<IP> ...]
 *   ② 标准输入（大批量推荐，规避命令行长度上限）：每行一个 IP
 *
 * 用法: php _hfish_bridge.php <IP...>            # 少量
 *       printf '%s\n' ip1 ip2 ... | php _hfish_bridge.php   # 大批量
 *
 * 注意：此脚本在服务器上运行，必须包含 WEB_ROOT 下的 utils.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

$ips = array_slice($argv, 1);
if (empty($ips)) {
    // 无命令行参数 → 从 stdin 逐行读取（大批量路径）
    while (($line = fgets(STDIN)) !== false) { $ips[] = trim($line); }
}
$ips = array_values(array_filter(array_map('trim', $ips), function ($v) { return $v !== ''; }));
if (empty($ips)) {
    fwrite(STDERR, "用法: php _hfish_bridge.php <IP> [<IP> ...]（或从 stdin 每行一个 IP）\n");
    exit(1);
}

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

// v5.4.10：仅触发联动封锁升级检查（事件已由 ysm-hfish-sync.py 按攻击次数分级写入，
// 此处若再 logThreat 会造成评分叠加越级，故改为直接调用 maybeLinkedBlock）
$ok = 0;
$bad = 0;
foreach ($ips as $ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) { $bad++; continue; }
    maybeLinkedBlock('ip', $ip);
    $ok++;
}
echo "OK: linked-block escalation checked for {$ok} ip(s)" . ($bad > 0 ? ", skipped {$bad} invalid" : '') . "\n";
