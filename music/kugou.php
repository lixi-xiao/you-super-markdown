<?php
// 酷狗音乐平台处理器（v4.7.4：由墨澜音乐源 v2.2 的酷狗后端适配——
// 榜单走 mobilecdn 官方接口，播放地址走长青海棠 resolve-url 并 https 化，
// 歌词走 m.kugou.com krc.php；128k/320k 无需 Cookie，浏览器直连 CDN 播放）
define('MUSIC_PLATFORM', 'kugou');

/** 酷狗榜单 rankid 映射（mobilecdn rank/song：8888=TOP500 热歌榜，74534=新歌榜，6666=飙升榜） */
function kugou_rankid($sortAll) {
    $map = ['热歌榜' => '8888', '新歌榜' => '74534', '飙升榜' => '6666'];
    return $map[$sortAll] ?? '8888';
}

/** 播放地址 https 化（长青海棠返回 http 直链，HTTPS 站点转 https 避免混合内容拦截） */
function kugou_httpsify($url) {
    return (is_string($url) && strpos($url, 'http://') === 0) ? 'https://' . substr($url, 7) : $url;
}

/** 纯文本抓取（歌词用；SSRF 防护 + 无 Cookie） */
function kugou_fetchText($url) {
    $host = @parse_url($url, PHP_URL_HOST);
    if (!$host || isPrivateHost(strtolower(trim($host, '[]')))) return null;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Referer: https://m.kugou.com/', 'User-Agent: Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 Mobile'],
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return ($raw === false || $raw === '') ? null : $raw;
}

function kugou_getPlaylist($id, $sortAll = '', $cookies = '') {
    $url = 'http://mobilecdn.kugou.com/api/v3/rank/song?rankid=' . urlencode(kugou_rankid($sortAll)) . '&page=1&pagesize=30&format=json';
    $d = musicSafeRequest($url, 12, '', null, 'https://m.kugou.com/');
    if (!is_array($d) || !isset($d['data']['info']) || !is_array($d['data']['info'])) {
        return ['error' => '酷狗音乐源无数据'];
    }
    $list = [];
    foreach ($d['data']['info'] as $s) {
        if (empty($s['hash'])) continue;
        $artists = [];
        foreach ($s['authors'] ?? [] as $a) { if (!empty($a['author_name'])) $artists[] = $a['author_name']; }
        $list[] = [
            'name' => $s['songname'] ?? '',
            'id' => $s['hash'],
            'url' => '', // 懒解析：播放时经 music.php?platform=kugou&songId=<hash> 取直链
            'picurl' => '',
            'artistsname' => implode(' / ', $artists),
            'duration' => (int)($s['duration'] ?? 0),
            'platform' => 'kugou',
        ];
    }
    if (!$list) return ['error' => '酷狗音乐源无数据'];
    return $list;
}

function kugou_getLyric($hash) {
    // timelength 参数必填（缺失时接口返回空）；值仅作时长提示，不影响歌词内容解析
    $text = kugou_fetchText('https://m.kugou.com/app/i/krc.php?cmd=100&hash=' . urlencode($hash) . '&timelength=599000');
    if ($text !== null && trim($text) !== '' && strpos(ltrim($text), '[') === 0) {
        return ['success' => true, 'lrc' => $text];
    }
    return ['success' => false, 'error' => '暂无歌词'];
}

function kugou_getSongUrl($hash, $cookies = '') {
    // 长青海棠 resolve-url（墨澜音源酷狗主后端；HTTP 201 亦为成功）
    if (isPrivateHost('musicserver.haitangw.cc')) return '';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://musicserver.haitangw.cc/v1/music/resolve-url',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['source' => 'kg', 'rid' => (string)$hash, 'level' => 'exhigh'], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: Mozilla/5.0'],
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $d = json_decode((string)$raw, true);
    if (is_array($d) && ($d['code'] ?? '') === 0 && !empty($d['data']['url'])) {
        return kugou_httpsify($d['data']['url']);
    }
    return '';
}
