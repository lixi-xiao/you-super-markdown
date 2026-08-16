<?php
// 网易云音乐平台处理器
define('MUSIC_PLATFORM', 'netease');

function netease_getPlaylist($id, $sortAll = '', $cookies = '') {
    $playlistId = $id;
    $chartMap = [
        '热歌榜' => '3778678',
        '新歌榜' => '3779629',
        '原创榜' => '2884035',
        '飙升榜' => '19723756',
    ];
    if (!empty($sortAll) && isset($chartMap[$sortAll])) {
        $playlistId = $chartMap[$sortAll];
    } elseif (empty($playlistId)) {
        $playlistId = '3778678';
    }

    $apiUrl = !empty($sortAll)
        ? 'https://api.xfyun.club/musicAll/?sortAll=' . urlencode($sortAll)
        : 'https://api.xfyun.club/musicAll/?playlistId=' . urlencode($playlistId);

    $data = netease_apiRequest($apiUrl);
    if (!$data) {
        return ['error' => 'API源无响应'];
    }

    $tracks = null;
    if (is_array($data) && count($data) > 0 && isset($data[0]['name'])) {
        $tracks = $data;
    } elseif (isset($data['playlist']['tracks'])) {
        $tracks = [];
        foreach ($data['playlist']['tracks'] as $t) {
            $artists = [];
            foreach ($t['ar'] ?? $t['artists'] ?? [] as $a) {
                $artists[] = $a['name'] ?? '';
            }
            $tracks[] = [
                'name' => $t['name'] ?? '',
                'id' => $t['id'] ?? 0,
                'url' => $t['url'] ?? '',
                'picurl' => ($t['al']['picUrl'] ?? $t['album']['picUrl'] ?? ''),
                'artistsname' => implode(' / ', $artists),
                'duration' => (($t['dt'] ?? $t['duration'] ?? 0) / 1000),
            ];
        }
    }

    if (!$tracks || count($tracks) === 0) {
        return ['error' => 'API源格式不识别'];
    }

    $songs = [];
    foreach ($tracks as $t) {
        $songs[] = [
            'name' => $t['name'] ?? '',
            'id' => $t['id'] ?? 0,
            'url' => $t['url'] ?? '',
            'picurl' => $t['picurl'] ?? '',
            'artistsname' => $t['artistsname'] ?? '',
            'duration' => $t['duration'] ?? 0,
            'platform' => 'netease',
        ];
    }

    // 如果有Cookie，获取高品质播放地址
    if (!empty($cookies) && $songs) {
        $songIds = array_column($songs, 'id');
        $urlMap = [];
        $idsJson = json_encode($songIds);
        $playerUrl = 'https://music.163.com/api/song/enhance/player/url?ids=' . urlencode($idsJson) . '&br=999000';
        $playerData = netease_apiRequest($playerUrl, 15, $cookies);
        if ($playerData && isset($playerData['data'])) {
            foreach ($playerData['data'] as $item) {
                if (!empty($item['url'])) $urlMap[$item['id']] = $item['url'];
            }
        }
        $missingIds = array_filter($songIds, function($id) use ($urlMap) { return !isset($urlMap[$id]); });
        if (!empty($missingIds)) {
            $v1Data = netease_apiRequestPost('https://music.163.com/api/song/enhance/player/url/v1', $missingIds, $cookies);
            if ($v1Data && isset($v1Data['data'])) {
                foreach ($v1Data['data'] as $item) {
                    if (!empty($item['url']) && !isset($urlMap[$item['id']])) $urlMap[$item['id']] = $item['url'];
                }
            }
        }
        foreach ($songs as &$s) {
            if (isset($urlMap[$s['id']])) $s['url'] = $urlMap[$s['id']];
        }
        unset($s);
    }

    // 无Cookie时，使用标准outer链接
    if (empty($cookies)) {
        foreach ($songs as &$s) {
            if (empty($s['url']) || strpos($s['url'], '/outer/url?id=') !== false) {
                $s['url'] = 'https://music.163.com/song/media/outer/url?id=' . $s['id'];
            }
        }
        unset($s);
    }

    return $songs;
}

function netease_getLyric($id) {
    $lyricUrl = 'https://music.163.com/api/song/lyric?os=pc&id=' . urlencode($id) . '&yv=-1&lv=-1&tv=-1&rv=-1';
    $lyricData = netease_apiRequest($lyricUrl, 10);
    if ($lyricData && (isset($lyricData['lrc']) || isset($lyricData['yrc']))) {
        return [
            'success' => true,
            'lrc' => $lyricData['lrc']['lyric'] ?? '',
            'tlrc' => $lyricData['tlyric']['lyric'] ?? '',
            'yrc' => $lyricData['yrc']['lyric'] ?? '',
            'romalrc' => $lyricData['romalrc']['lyric'] ?? '',
        ];
    }
    // Fallback to xfyun
    $fbUrl = 'https://api.xfyun.club/musicAll/?lyric=' . urlencode($id);
    $fbData = netease_apiRequest($fbUrl);
    if ($fbData && isset($fbData['lrc']['lyric'])) {
        return [
            'success' => true,
            'lrc' => $fbData['lrc']['lyric'],
            'tlrc' => $fbData['tlyric']['lyric'] ?? '',
        ];
    }
    return ['success' => false, 'error' => '暂无歌词'];
}

function netease_getSongUrl($id) {
    return 'https://music.163.com/song/media/outer/url?id=' . $id;
}

function netease_apiRequest($url, $timeout = 15, $cookies = '') {
    // v4.2.4：改走 musicSafeRequest（受限重定向 + 白名单域名校验，Cookie 不外泄）
    return musicSafeRequest($url, $timeout, $cookies, null, 'https://music.163.com/');
}

function netease_apiRequestPost($url, $songIds, $cookies = '') {
    // v4.2.4：改走 musicSafeRequest（受限重定向 + 白名单域名校验，Cookie 不外泄）
    return musicSafeRequest($url, 15, $cookies, 'ids=' . urlencode(json_encode($songIds)) . '&level=exhigh&encodeType=flac', 'https://music.163.com/');
}