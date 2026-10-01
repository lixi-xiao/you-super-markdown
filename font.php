<?php
// ============================================================
// v5.3.1-beta：受控字体下载端点（读者可选阅读字体）
//   安全约束：
//     · 仅按「白名单 id」（16 位 hex）命中数据库记录，绝不使用请求输入拼接路径（防目录穿越）；
//     · 只输出「已启用」字体（停用/不存在一律 404）；
//     · Content-Type 按格式显式设置 + X-Content-Type-Options: nosniff；
//     · data/fonts/ 目录本身禁止脚本执行（.htaccess / nginx 规则），此处为唯一读取入口。
// ============================================================
require_once __DIR__ . '/utils.php';

$id = isset($_GET['f']) ? (string)$_GET['f'] : '';
$font = getFontById($id);

if (!$font || (int)($font['enabled'] ?? 0) !== 1) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo 'Not Found';
    exit;
}

$path = fontStoredPath($font);              // 仅用库内随机文件名拼接
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo 'Not Found';
    exit;
}

$mimes = fontFormatMap();
$mime = $mimes[$font['ext']] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=2592000, immutable');
header('Content-Disposition: inline; filename="' . $font['filename'] . '"');
readfile($path);
exit;
