<?php
// v3.3.12：站内图片缩略图服务（首页卡片封面加速）——/img.php?src=/data/images/xxx.jpg&w=640
// 安全：仅允许站内 data/images/ 下安全文件；GD 生成缩略图并磁盘缓存；无 GD 时透传原图（功能降级不中断）
require_once __DIR__ . '/utils.php';
security_check();

error_reporting(0);
$src = isset($_GET['src']) ? trim((string)$_GET['src']) : '';
$w = isset($_GET['w']) ? max(1, min(1600, (int)$_GET['w'])) : 640;
$dataDir = __DIR__ . '/data';

// 1. 校验 src：仅站内 /data/images/ 路径
if ($src === '' || $src[0] !== '/') { http_response_code(400); echo 'bad src'; exit; }
$rel = ltrim($src, '/');
if (strpos($rel, 'data/images/') !== 0) { http_response_code(403); echo 'forbidden'; exit; }
$path = __DIR__ . '/' . $rel;
$imgRoot = realpath($dataDir . '/images');
$real = realpath($path);
if ($real === false || $imgRoot === false || strpos($real, $imgRoot . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404); echo 'not found'; exit;
}
if (!preg_match('/\.(jpe?g|png|webp|gif)$/i', $real)) { http_response_code(403); echo 'bad ext'; exit; }

// 2. 磁盘缓存（源图更新后自动失效；v3.3.17 起 key 含质量档，与输出段生成逻辑一致）
// v4.1.1 修复：快速命中 key 与生成段命名统一为 md5(rel|w)|q.jpg——此前写 md5(rel|w|78).jpg 与
//   生成段 md5(rel|targetW)|q.jpg 不一致，导致缓存永远不命中、每次请求都重新解码原图（大图卡顿）
$cacheKey = md5($rel . '|' . $w);
$cacheDir = $dataDir . '/cache/thumbs';
$cacheFile = $cacheDir . '/' . $cacheKey . '|78.jpg';
$srcMtime = filemtime($real);
if (is_file($cacheFile) && filemtime($cacheFile) >= $srcMtime) {
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($cacheFile));
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($cacheFile)) . ' GMT');
    readfile($cacheFile);
    exit;
}

// 3. 无 GD 扩展 → 透传原图（保持功能可用，仅不缩略）
if (!function_exists('imagecreatetruecolor')) {
    $mime = function_exists('finfo_open') ? (new finfo(FILEINFO_MIME_TYPE))->file($real) : 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($real));
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $srcMtime) . ' GMT');
    readfile($real);
    exit;
}

// 4. GD 生成缩略图（等比缩放，输出 JPEG q78）
$info = @getimagesize($real);
if ($info === false) { http_response_code(400); echo 'bad image'; exit; }
switch ($info[2]) {
    case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg($real); break;
    case IMAGETYPE_PNG:  $im = @imagecreatefrompng($real);  break;
    case IMAGETYPE_WEBP: $im = @imagecreatefromwebp($real); break;
    case IMAGETYPE_GIF:  $im = @imagecreatefromgif($real);  break;
    default: http_response_code(400); echo 'unsupported'; exit;
}
if (!$im) { http_response_code(400); echo 'decode fail'; exit; }
$ow = imagesx($im); $oh = imagesy($im);
// v3.3.17：保证缩略图产物 ≤2MB——先等比缩放到目标宽；产物超限自动降质（q78→q63→…→q33），
//           仍超限再按 0.8 逐级降宽（轻量站，阅读页大图转缩略图后不拖慢加载）
$maxBytes = 2 * 1024 * 1024;
$targetW = $w;
$thumb = $im;
if ($ow > $targetW) {
    $nh = max(1, (int)round($oh * $targetW / $ow));
    $thumb = imagecreatetruecolor($targetW, $nh);
    if ($thumb) {
        imagecopyresampled($thumb, $im, 0, 0, 0, 0, $targetW, $nh, $ow, $oh);
    } else {
        $thumb = $im;
    }
}
$cacheFile = '';
for ($r = 0; $r < 4; $r++) {
    $base = $dataDir . '/cache/thumbs/' . md5($rel . '|' . $targetW);
    $done = false;
    for ($q = 78; $q >= 33; $q -= 15) {
        $cf = $base . '|' . $q . '.jpg';
        if (is_file($cf) && filemtime($cf) >= $srcMtime) { $cacheFile = $cf; $done = true; break; }
        @unlink($cf);
        imagejpeg($thumb, $cf, $q);
        if (is_file($cf) && filesize($cf) <= $maxBytes) { $cacheFile = $cf; $done = true; break; }
        @unlink($cf);
    }
    if ($done) break;
    // 全部质量档仍超限 → 降宽重缩放
    $targetW = max(200, (int)round($targetW * 0.8));
    if ($targetW < $ow) {
        $nh = max(1, (int)round($oh * $targetW / $ow));
        $t2 = imagecreatetruecolor($targetW, $nh);
        if ($t2) { imagecopyresampled($t2, $im, 0, 0, 0, 0, $targetW, $nh, $ow, $oh); $thumb = $t2; }
    }
}
if ($cacheFile === '' || !is_file($cacheFile)) { http_response_code(500); echo 'cache fail'; exit; }
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($cacheFile));
header('Cache-Control: public, max-age=31536000, immutable');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($cacheFile)) . ' GMT');
readfile($cacheFile);
