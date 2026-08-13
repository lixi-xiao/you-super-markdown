<?php
// QQ音乐平台处理器
define('MUSIC_PLATFORM', 'qq');

function qq_getPlaylist($id, $sortAll = '', $cookies = '') {
    $playlistId = $id;
    $qqCharts = [
        '热歌榜' => '26',
        '新歌榜' => '27',
        '飙升榜' => '62',
        '流行榜' => '4',
        '内地榜' => '5',
        '港台榜' => '6',
        '欧美榜' => '3',
        '日韩榜' => '16',
    ];
    if (!empty($sortAll) && isset($qqCharts[$sortAll])) {
        $playlistId = $qqCharts[$sortAll];
    } elseif (empty($playlistId)) {
        $playlistId = '26';
    }

    // 获取歌单详情
    $apiUrl = 'https://c.y.qq.com/qzone/fcg-bin/fcg_ucc_getcdinfo_byids_cp.fcg?type=1&json=1&utf8=1&onlysong=0&disstid=' . urlencode($playlistId) . '&format=json&inCharset=utf8&outCharset=utf-8&notice=0&platform=yqq.json&needNewCode=0';
    $data = qq_apiRequest($apiUrl);

    if (!$data || !isset($data['cdlist']) || count($data['cdlist']) === 0) {
        return ['error' => 'QQ音乐歌单获取失败'];
    }

    $cd = $data['cdlist'][0];
    $songList = $cd['songlist'] ?? [];

    if (count($songList) === 0) {
        return ['error' => 'QQ音乐歌单为空'];
    }

    $songs = [];
    $songmids = [];
    foreach ($songList as $song) {
        $songmid = $song['songmid'] ?? '';
        $songs[] = [
            'name' => $song['songname'] ?? '',
            'id' => $songmid, // 使用songmid作为ID
            'songid' => $song['songid'] ?? '',
            'url' => '',
            'picurl' => 'https://y.qq.com/music/photo_new/T002R300x300M000' . ($song['albummid'] ?? '') . '.jpg',
            'artistsname' => qq_getArtists($song['singer'] ?? []),
            'duration' => ($song['interval'] ?? 0),
            'platform' => 'qq',
        ];
        $songmids[] = $songmid;
    }

    // 批量获取播放地址
    if (!empty($songmids)) {
        $urlMap = qq_batchGetSongUrls($songmids);
        foreach ($songs as &$s) {
            if (isset($urlMap[$s['id']])) {
                $s['url'] = $urlMap[$s['id']];
            }
        }
        unset($s);
    }

    return $songs;
}

function qq_batchGetSongUrls($songmids) {
    // 分批处理，每批最多50首
    $urlMap = [];
    $chunks = array_chunk($songmids, 50);
    foreach ($chunks as $chunk) {
        $mids = array_map(function($m) { return '"' . $m . '"'; }, $chunk);
        $midList = implode(',', $mids);
        $guid = sprintf('%d', rand(1000000000, 9999999999));
        $dataParam = '{"req_0":{"module":"vkey.GetVkey","method":"CgiGetVkey","param":{"guid":"' . $guid . '","songmid":[' . $midList . '],"songtype":[0],"uin":"0","loginflag":1,"platform":"20"}}}';
        $url = 'https://u.y.qq.com/cgi-bin/musicu.fcg?data=' . urlencode($dataParam);
        $resp = qq_apiRequest($url);
        if ($resp && isset($resp['req_0']['data']['midurlinfo'])) {
            foreach ($resp['req_0']['data']['midurlinfo'] as $info) {
                $mid = $info['songmid'] ?? '';
                $vkey = $info['vkey'] ?? '';
                $purl = $info['purl'] ?? '';
                if ($vkey && $purl) {
                    $urlMap[$mid] = 'http://ws.stream.qqmusic.qq.com/' . $purl . '?vkey=' . $vkey . '&fromtag=66';
                } elseif ($purl) {
                    $urlMap[$mid] = 'http://ws.stream.qqmusic.qq.com/' . $purl;
                }
            }
        }
        // 对小部分没获取到的，单独请求
        foreach ($chunk as $mid) {
            if (!isset($urlMap[$mid])) {
                $singleUrl = qq_getSingleSongUrl($mid);
                if ($singleUrl) $urlMap[$mid] = $singleUrl;
            }
        }
    }
    return $urlMap;
}

function qq_getSingleSongUrl($songmid) {
    $guid = sprintf('%d', rand(1000000000, 9999999999));
    $dataParam = '{"req_0":{"module":"vkey.GetVkey","method":"CgiGetVkey","param":{"guid":"' . $guid . '","songmid":["' . $songmid . '"],"songtype":[0],"uin":"0","loginflag":1,"platform":"20"}}}';
    $url = 'https://u.y.qq.com/cgi-bin/musicu.fcg?data=' . urlencode($dataParam);
    $resp = qq_apiRequest($url);
    if ($resp && isset($resp['req_0']['data']['midurlinfo'][0])) {
        $info = $resp['req_0']['data']['midurlinfo'][0];
        $vkey = $info['vkey'] ?? '';
        $purl = $info['purl'] ?? '';
        if ($vkey && $purl) {
            return 'http://ws.stream.qqmusic.qq.com/' . $purl . '?vkey=' . $vkey . '&fromtag=66';
        } elseif ($purl) {
            return 'http://ws.stream.qqmusic.qq.com/' . $purl;
        }
    }
    return '';
}

function qq_getLyric($id) {
    // $id 是 songmid
    $lyricUrl = 'https://c.y.qq.com/lyric/fcgi-bin/fcg_query_lyric_new.fcg?songmid=' . urlencode($id) . '&format=json&nobase64=1';
    $headers = [
        'Referer: https://y.qq.com/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $lyricUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        return ['success' => false, 'error' => '暂无歌词'];
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['lyric'])) {
        return ['success' => false, 'error' => '暂无歌词'];
    }

    $lrc = base64_decode($data['lyric']);
    $tlrc = isset($data['trans']) ? base64_decode($data['trans']) : '';

    return [
        'success' => true,
        'lrc' => $lrc,
        'tlrc' => $tlrc,
        'yrc' => '',
    ];
}

function qq_getSongUrl($id) {
    return qq_getSingleSongUrl($id);
}

function qq_getArtists($singers) {
    $names = [];
    foreach ($singers as $s) {
        $names[] = $s['name'] ?? '';
    }
    return implode(' / ', $names);
}

function qq_apiRequest($url, $timeout = 15) {
    $headers = [
        'Referer: https://y.qq.com/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error || $httpCode !== 200 || empty($response)) return null;
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}