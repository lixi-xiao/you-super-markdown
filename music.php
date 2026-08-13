<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 加载配置
$configFile = __DIR__ . '/data/.config.json';
$config = [];
if (file_exists($configFile)) {
    $config = json_decode(file_get_contents($configFile), true) ?: [];
}

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
    $url = $funcName($songId);
    echo json_encode(['url' => $url], JSON_UNESCAPED_UNICODE);
    exit;
}

// 歌单请求
$musicCookies = $config['music_cookies'] ?? '';
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