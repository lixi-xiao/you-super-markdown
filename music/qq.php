<?php
// QQ音乐平台处理器（v4.7.4：由墨澜音乐源 v2.2 的 QQ 后端适配——
// 榜单/歌词走 QQ 官方接口，播放地址走星海/星海备后端并 https 化；128k/320k 无需 Cookie，浏览器直连 CDN 播放）
define('MUSIC_PLATFORM', 'qq');

/** QQ 榜单 topid 映射（fcg_v8_toplist_cp.fcg：26=巅峰榜·热歌，27=巅峰榜·新歌，62=飙升榜） */
function qq_topid($sortAll) {
    $map = ['热歌榜' => '26', '新歌榜' => '27', '飙升榜' => '62'];
    return $map[$sortAll] ?? '26';
}

/** 无 Cookie 请求辅助（musicSafeRequest 已带受限重定向 + 域名白名单 + SSRF 防护） */
function qq_apiGet($url) {
    return musicSafeRequest($url, 12, '', null, 'https://y.qq.com/');
}

/** 播放地址 https 化（第三方后端返回 http 直链，HTTPS 站点需转 https 避免混合内容拦截） */
function qq_httpsify($url) {
    return (is_string($url) && strpos($url, 'http://') === 0) ? 'https://' . substr($url, 7) : $url;
}

function qq_getPlaylist($id, $sortAll = '', $cookies = '') {
    $list = [];
    // 榜单优先（热歌榜/新歌榜/飙升榜）
    $chartData = qq_apiGet('https://c.y.qq.com/v8/fcg-bin/fcg_v8_toplist_cp.fcg?page=detail&topid=' . urlencode(qq_topid($sortAll)) . '&type=top&tpl=3&format=json');
    if (is_array($chartData) && isset($chartData['songlist']) && is_array($chartData['songlist'])) {
        foreach ($chartData['songlist'] as $item) {
            $s = $item['data'] ?? [];
            if (empty($s['songmid'])) continue;
            $artists = [];
            foreach ($s['singer'] ?? [] as $a) { if (!empty($a['name'])) $artists[] = $a['name']; }
            $list[] = [
                'name' => $s['songname'] ?? '',
                'id' => $s['songmid'],
                'url' => '', // 懒解析：播放时经 music.php?platform=qq&songId= 取直链
                'picurl' => !empty($s['albummid']) ? 'https://y.gtimg.cn/music/photo_new/T002R300x300M000' . $s['albummid'] . '.jpg' : '',
                'artistsname' => implode(' / ', $artists),
                'duration' => (int)($s['interval'] ?? 0),
                'platform' => 'qq',
            ];
        }
    }
    // 歌单兜底（后台配置的 QQ 歌单 disstid）
    if (!$list && !empty($id)) {
        $plData = qq_apiGet('https://c.y.qq.com/qzone/fcg-bin/fcg_ucc_getcdinfo_byids_cp.fcg?type=1&json=1&utf8=1&onlysong=0&new_format=1&disstid=' . urlencode($id));
        if (is_array($plData) && isset($plData['cdlist'][0]['songlist']) && is_array($plData['cdlist'][0]['songlist'])) {
            foreach ($plData['cdlist'][0]['songlist'] as $s) {
                if (empty($s['songmid'])) continue;
                $artists = [];
                foreach ($s['singer'] ?? [] as $a) { if (!empty($a['name'])) $artists[] = $a['name']; }
                $list[] = [
                    'name' => $s['songname'] ?? '',
                    'id' => $s['songmid'],
                    'url' => '',
                    'picurl' => !empty($s['albummid']) ? 'https://y.gtimg.cn/music/photo_new/T002R300x300M000' . $s['albummid'] . '.jpg' : '',
                    'artistsname' => implode(' / ', $artists),
                    'duration' => (int)($s['interval'] ?? 0),
                    'platform' => 'qq',
                ];
            }
        }
    }
    if (!$list) return ['error' => 'QQ音乐源无数据'];
    return $list;
}

function qq_getLyric($songmid) {
    $d = qq_apiGet('https://c.y.qq.com/lyric/fcgi-bin/fcg_query_lyric_new.fcg?songmid=' . urlencode($songmid) . '&format=json&nobase64=1&g_tk=5381');
    if (is_array($d) && !empty($d['lyric'])) {
        return ['success' => true, 'lrc' => $d['lyric'], 'tlrc' => $d['trans'] ?? ''];
    }
    return ['success' => false, 'error' => '暂无歌词'];
}

function qq_getSongUrl($songmid, $cookies = '') {
    // 星海主后端 → 星海备后端 → 空（前端按失败处理）
    foreach ([
        'https://yy.zddyr.top/lx/api/?source=qq&songmid=',
        'https://zrcdy.dpdns.org/lx/api/api.php?source=qq&songmid=',
    ] as $base) {
        $d = musicSafeRequest($base . urlencode($songmid) . '&quality=320k', 10, '', null, 'https://y.qq.com/');
        if (is_array($d) && !empty($d['url'])) return qq_httpsify($d['url']);
    }
    return '';
}
