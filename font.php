<?php
/**
 * v6.0.0-beta：自定义首页字体受控端点
 *
 * 背景：nginx 明确拒绝直读 /data/fonts/（字体不随静态规则暴露），
 *       故字体统一由本端点按「已启用 + 文件名白名单」输出。
 * 说明：字体本身是公开资源（访客首页需要加载），无需登录；但只允许读取
 *       data/fonts/ 下的 ttf/otf/woff/woff2，且必须与站点配置一致。
 */
require_once __DIR__ . '/utils.php';

$cfg = loadSiteConfig();
if (empty($cfg['custom_font_enabled'])) {
    http_response_code(404);
    exit;
}

// 允许 ?f= 指定（与配置一致时才输出），否则用配置里的字体
$confFile = basename((string)($cfg['custom_font_name'] ?? ''));
$reqFile = isset($_GET['f']) ? basename((string)$_GET['f']) : $confFile;
if ($reqFile === '' || $reqFile !== $confFile) {
    http_response_code(404);
    exit;
}
if (!preg_match('/^[A-Za-z0-9._-]+\.(ttf|otf|woff2?)$/i', $reqFile)) {
    http_response_code(404);
    exit;
}

$path = __DIR__ . '/data/fonts/' . $reqFile;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($reqFile, PATHINFO_EXTENSION));
$types = ['ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2'];

header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($path);
