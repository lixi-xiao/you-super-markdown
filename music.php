<?php
header('Content-Type: application/json; charset=utf-8');
// 同源调用无需 CORS；不输出跨域头，公网模式下避免任意来源滥用（见 14.10 公网/内网模式）

// 加载配置（SQLite）
require_once __DIR__ . '/utils.php';
$config = loadSiteConfig();

// 获取参数
$platform = $_GET['platform'] ?? $config['music_platform'] ?? 'netease';
if (!in_array($platform, ['netease', 'qq'], true)) {
    http_response_code(400);
    echo json_encode(['error' => '不支持的音乐平台'], JSON_UNESCAPED_UNICODE);
    exit;
}
$sortAll = $_GET['sortAll'] ?? '';
$playlistId = $_GET['playlistId'] ?? '';
$lyricId = $_GET['lyric'] ?? '';
$songId = $_GET['songId'] ?? '';

// v2.6.0：按平台取 Cookies（网易云 music_cookies / QQ 音乐 music_cookies_qq 独立配置）
$musicCookies = ($platform === 'qq')
    ? ($config['music_cookies_qq'] ?? '')
    : ($config['music_cookies'] ?? '');

// v4.2.4：带 Cookie 的外呼必须走安全跟随——禁止 CURLOPT_FOLLOWLOCATION 自动跟随
//         （curl 会把 CURLOPT_COOKIE 原样发送给重定向目标，若 API 被劫持重定向到第三方
//          域名将泄露音乐账号 Cookie）。这里改为手动跟随 + 目标域名白名单校验，
//          非白名单域名一律不跟随（返回 null，调用方按失败处理）。
define('MUSIC_REDIRECT_ALLOW', [
    'qq.com', 'y.qq.com', 'qqmusic.qq.com', 'music.qq.com', 'i.y.qq.com', 'c.y.qq.com', 'u.y.qq.com', 'ws.stream.qqmusic.qq.com',
    '163.com', 'music.163.com', 'api.xfyun.club',
]);
function musicSafeRequest($url, $timeout = 15, $cookies = '', $postData = null, $referer = 'https://y.qq.com/') {
    $cur = $url;
    $maxRedirects = 3;
    for ($redirect = 0; $redirect <= $maxRedirects; $redirect++) {
        $parts = @parse_url($cur);
        if (!$parts || empty($parts['host'])) return null;
        $host = strtolower(trim($parts['host'], '[]'));
        $allowed = false;
        foreach (MUSIC_REDIRECT_ALLOW as $suffix) {
            if ($host === $suffix || substr($host, -strlen('.' . $suffix)) === '.' . $suffix) { $allowed = true; break; }
        }
        if (!$allowed) return null; // 非白名单域名（含被劫持/恶意重定向）不发起请求，Cookie 不外泄
        if (isPrivateHost($host)) return null; // 内网拒绝（SSRF 加固，与 fetchHttpContent 同源判定）
        $headers = [
            'Referer: ' . $referer,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ];
        if ($postData !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $cur,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false, // 关键：不自动跟随，Cookie 不随重定向外发
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
        ];
        if ($postData !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $postData;
        }
        if (!empty($cookies)) $opts[CURLOPT_COOKIE] = $cookies;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) return null;
        $headerSize = 0;
        $headerStr = '';
        // 从原始响应分离响应头
        $sep = strpos($raw, "\r\n\r\n");
        $headerStr = $sep !== false ? substr($raw, 0, $sep) : '';
        $body = $sep !== false ? substr($raw, $sep + 4) : $raw;
        if ($error || $httpCode === 0) return null;
        if ($httpCode >= 300 && $httpCode < 400) {
            // 仅当 Location 指向白名单域名时才跟随，否则放弃（不泄露 Cookie）
            $loc = null;
            foreach (explode("\r\n", $headerStr) as $line) {
                if (stripos($line, 'Location:') === 0) { $loc = trim(substr($line, 9)); break; }
            }
            if (!$loc) return null;
            $next = $loc;
            if (!preg_match('#^https?://#i', $next)) {
                $base = $parts['scheme'] . '://' . $parts['host'] . ($parts['port'] ? ':' . $parts['port'] : '');
                $next = $base . ($next[0] === '/' ? $next : '/' . $next);
            }
            $np = @parse_url($next);
            if (!$np || empty($np['host'])) return null;
            $nh = strtolower(trim($np['host'], '[]'));
            $nAllowed = false;
            foreach (MUSIC_REDIRECT_ALLOW as $suffix) {
                if ($nh === $suffix || substr($nh, -strlen('.' . $suffix)) === '.' . $suffix) { $nAllowed = true; break; }
            }
            if (!$nAllowed || isPrivateHost($nh)) return null; // 重定向目标不在白名单 → 拒绝跟随，Cookie 不外泄
            $cur = $next;
            continue;
        }
        if ($httpCode !== 200 || $body === '') return null;
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }
    return null;
}

// 平台到歌单ID的映射
$platformPlaylistKeys = [
    'netease' => 'music_playlist_id',
    'qq' => 'music_playlist_id_qq',
];

// 如果未提供 playlistId，从配置中获取
$defaultPlaylistId = '';
if (empty($playlistId) && empty($sortAll)) {
    $key = $platformPlaylistKeys[$platform] ?? 'music_playlist_id';
    $defaultPlaylistId = $config[$key] ?? '';
    if (!empty($defaultPlaylistId)) {
        $playlistId = $defaultPlaylistId;
    }
}

// 加载平台处理器
$handlerFile = __DIR__ . '/music/' . $platform . '.php';
if (!file_exists($handlerFile)) {
    http_response_code(400);
    echo json_encode(['error' => '不支持的音乐平台: ' . $platform], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $handlerFile;

// 歌词请求
if (!empty($lyricId)) {
    $funcName = $platform . '_getLyric';
    $result = $funcName($lyricId);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// 单曲播放地址请求
if (!empty($songId)) {
    $funcName = $platform . '_getSongUrl';
    $url = $funcName($songId, $musicCookies);
    echo json_encode(['url' => $url], JSON_UNESCAPED_UNICODE);
    exit;
}

// 歌单请求
$funcName = $platform . '_getPlaylist';
$result = $funcName($playlistId, $sortAll, $musicCookies);

if (isset($result['error'])) {
    http_response_code(502);
    echo json_encode([
        'error' => $result['error'],
        'platform' => $platform,
        'playlistId' => $playlistId,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);