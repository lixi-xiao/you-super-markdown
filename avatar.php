<?php
/**
 * v6.2.0：默认头像（首字母/缩写 SVG）
 * - 零外链、零 CDN、无隐私泄露：仅由用户名确定性生成
 * - 输入白名单：a-z0-9_-，长度 ≤20；尺寸 24–256
 * - 长缓存 + ETag（同一用户名颜色/字母稳定）
 */
$raw = isset($_GET['u']) ? (string)$_GET['u'] : '';
$u = strtolower(trim($raw));
$u = preg_replace('/[^a-z0-9_-]/', '', $u);
$u = mb_substr($u, 0, 20, 'UTF-8');
$size = isset($_GET['s']) ? (int)$_GET['s'] : 96;
if ($size < 24) $size = 24;
if ($size > 256) $size = 256;
if ($u === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not found';
    exit;
}

$palette = [
    ['#1d4ed8', '#3b82f6'],
    ['#0f766e', '#14b8a6'],
    ['#7c3aed', '#a855f7'],
    ['#b91c1c', '#ef4444'],
    ['#b45309', '#f59e0b'],
    ['#0e7490', '#06b6d4'],
    ['#4d7c0f', '#84cc16'],
    ['#9d174d', '#ec4899'],
];
$h = crc32($u);
$pal = $palette[$h % count($palette)];
$letter = strtoupper(substr($u, 0, 1));
$font = (int)round($size * 0.44);
$cy = (int)round($size * 0.5 + $font * 0.35);
$half = (int)round($size / 2);

$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '">'
    . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
    . '<stop offset="0" stop-color="' . $pal[0] . '"/><stop offset="1" stop-color="' . $pal[1] . '"/>'
    . '</linearGradient></defs>'
    . '<rect width="' . $size . '" height="' . $size . '" rx="' . $half . '" fill="url(#g)"/>'
    . '<text x="' . $half . '" y="' . $cy . '" text-anchor="middle" '
    . 'font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif" '
    . 'font-size="' . $font . '" font-weight="700" fill="#ffffff">' . $letter . '</text>'
    . '</svg>';

$etag = '"' . md5($u . '|' . $size) . '"';
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}
echo $svg;
