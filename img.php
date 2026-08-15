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

// 2. 磁盘缓存（源图更新后自动失效）
$cacheKey = md5($rel . '|' . $w);
$cacheDir = $dataDir . '/cache/thumbs';
$cacheFile = $cacheDir . '/' . $cacheKey . '.jpg';
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
if ($ow > $w) {
    $nh = max(1, (int)round($oh * $w / $ow));
    $thumb = imagecreatetruecolor($w, $nh);
    if ($thumb) {
        imagecopyresampled($thumb, $im, 0, 0, 0, 0, $w, $nh, $ow, $oh);
        imagedestroy($im);
        $im = $thumb;
    }
}
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
imagejpeg($im, $cacheFile, 78);
imagedestroy($im);
if (!is_file($cacheFile)) { http_response_code(500); echo 'cache fail'; exit; }
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($cacheFile));
header('Cache-Control: public, max-age=31536000, immutable');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($cacheFile)) . ' GMT');
readfile($cacheFile);
