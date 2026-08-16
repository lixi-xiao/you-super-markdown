<?php
// v4.1.15-fix：首页无图卡片封面图片池代理（增量构建 + 节流，紧急修复带宽占用）
// 用「桌面 UA」抓取后台配置的封面 API（自适应壁纸 API 桌面端返回 1920x1080 横屏，
// 手机 UA 返回竖屏——本代理统一拉横屏），缩略 640px 后池化缓存。
// 关键：页面请求永不阻塞、构建永远后台且节流——
//   - 每次请求至多后台补 1 张图，且距上次构建 ≥5 秒（峰值带宽 ≈1.4MB/5s，不拖垮页面）
//   - 池空时立即 404（卡片自动降级无图），不会同步抓图
//   - 池满 24 张后每 6 小时按槽位轮换刷新，全程服务旧图
// 安全：不接收外部 URL（只读站点配置的 card_cover_api_url / bg_api_url），
//       复用 fetchHttpContent 的 SSRF 加固（内网拒绝 + 单次解析 pin IP + TLS 域名校验），无新攻击通道。
require_once __DIR__ . '/utils.php';
security_check();
set_time_limit(0);
ignore_user_abort(true);

$cfg = loadSiteConfig();
$apiUrl = trim((string)($cfg['card_cover_api_url'] ?? ''));
if ($apiUrl === '') $apiUrl = trim((string)($cfg['bg_api_url'] ?? ''));
if ($apiUrl === '' || !preg_match('#^https?://#i', $apiUrl)) {
    http_response_code(404);
    exit;
}

$poolDir  = __DIR__ . '/data/cache/covers';
$poolN    = 24;           // 池大小（张）
$refreshT = 21600;        // 刷新周期（6 小时）
$thumbW   = 640;          // 缩略图宽度（首页卡片 ≤360px，640 足够清晰且省流量）
$throttle = 5;            // 构建节流（秒）：每次请求至多补 1 张，间隔 ≥5s → 峰值 ≈1.4MB/5s
$manifest = $poolDir . '/manifest.json';
$flagFile = $poolDir . '/.building';
if (!is_dir($poolDir)) @mkdir($poolDir, 0755, true);

function coverPoolRead($m) {
    return is_file($m) ? (json_decode(@file_get_contents($m), true) ?: []) : [];
}
function coverPoolWrite($m, $st) {
    file_put_contents($m, json_encode($st), LOCK_EX);
}

// 后台补 1 张（增量/轮换），单张抓取 → 去重 → 缩略 → 原子落盘
function coverPoolGrowOne($apiUrl, $poolDir, $poolN, $thumbW) {
    $m = $poolDir . '/manifest.json';
    $st = coverPoolRead($m);
    $count = (int)($st['count'] ?? 0);
    $next = (int)($st['next_slot'] ?? 0);
    $hashes = isset($st['hashes']) && is_array($st['hashes']) ? $st['hashes'] : [];
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
    $raw = fetchHttpContent($apiUrl, $ua);   // SSRF 加固复用；桌面 UA → 横屏
    if ($raw === false || $raw === '') return;   // 网络失败：跳过，下次请求再试
    $bytes = $raw;
    unset($raw);
    $tmp = $poolDir . '/.tmp.jpg';
    $hasGd = function_exists('imagecreatefromstring');
    if ($hasGd) {
        $img = @imagecreatefromstring($bytes);
        unset($bytes);
        if (!$img) return;
        $w = imagesx($img);
        $h = imagesy($img);
        if ($h > $w) { imagedestroy($img); return; }   // 竖屏丢弃
        $tw = min($thumbW, $w);
        $th = (int)round($h * $tw / $w);
        $dst = imagecreatetruecolor($tw, $th);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($img);
        imagejpeg($dst, $tmp, 80);
        imagedestroy($dst);
        unset($dst);
    } else {
        file_put_contents($tmp, $bytes, LOCK_EX);   // 无 GD 降级：原图直存（仍横屏）
        unset($bytes);
    }
    $hash = md5_file($tmp);
    if (in_array($hash, $hashes, true)) { @unlink($tmp); return; }   // 去重
    if ($count < $poolN) {
        // 初始填充：追加到 cover_<count>
        $slot = $count;
        rename($tmp, $poolDir . '/cover_' . $slot . '.jpg');
        $count++;
        $hashes[] = $hash;
        $st['count'] = $count;
        $st['hashes'] = $hashes;
    } else {
        // 轮换刷新：替换 next_slot 槽位（全程服务旧图）
        $slot = $next;
        rename($tmp, $poolDir . '/cover_' . $slot . '.jpg');
        $hashes[$slot] = $hash;
        $next = ($next + 1) % $poolN;
        $st['next_slot'] = $next;
        $st['hashes'] = $hashes;
        if ($next === 0) $st['refreshed_at'] = time();   // 整轮换完，标记刷新完成
    }
    coverPoolWrite($m, $st);
}

// 读取状态
$st = coverPoolRead($manifest);
$count = (int)($st['count'] ?? 0);
$lastRefresh = (int)($st['refreshed_at'] ?? 0);
$stale = (time() - $lastRefresh) >= $refreshT;

// 先服务（池非空立即响应；池空本次 404，不阻塞页面）
$served = false;
if ($count >= 1) {
    $idx = isset($_GET['i']) ? (int)$_GET['i'] : 0;
    $idx = (($idx % $count) + $count) % $count;
    $file = $poolDir . '/cover_' . $idx . '.jpg';
    if (is_file($file)) {
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=' . $refreshT);
        readfile($file);
        $served = true;
    }
}
if (!$served) http_response_code(404);

// 服务完成后，后台补 1 张（未满 / 过期轮换 / 池空冷启动均触发，节流 ≥ throttle 秒）
$need = ($count < $poolN) || $stale;
$recent = is_file($flagFile) && (time() - filemtime($flagFile)) < $throttle;
if ($need && !$recent) {
    @touch($flagFile);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    coverPoolGrowOne($apiUrl, $poolDir, $poolN, $thumbW);   // 后台单张补图
}
exit;
