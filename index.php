<?php
session_start();
require_once __DIR__ . '/utils.php';

// v3.0.8 统一安全入口：扫描器 UA 黑名单检测（命中返回 403 + 记录 + 封禁来源 IP）
security_check();

// 守护进程 MD5 校验钩子：每次请求检查 index.php 自身完整性
$guardStateFile = '/opt/you-markdown/guard-state.json';
$indexMd5 = md5_file(__FILE__);
$baseMd5File = '/opt/you-markdown/install-base/index.php';
if (file_exists($baseMd5File)) {
    $baseMd5 = md5_file($baseMd5File);
    if ($baseMd5 && $indexMd5 !== $baseMd5) {
        copy($baseMd5File, __FILE__);
        if (function_exists('sendAlert')) {
            sendAlert('index.php 自校验恢复', 'index.php MD5 不匹配，已从母本自动恢复');
        }
    }
}

function isAdmin() {
    return checkRole(ROLE_STATION_ADMIN);
}
function jsonOut($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
if (!is_dir('./data/articles')) {
    mkdir('./data/articles', 0755, true);
}
$_siteConfig = loadSiteConfig();
$_siteTitle = $_siteConfig['site_title'] ?? 'You Markdown';
// v3.1.6：首页公告卡片数据（公告表 + 关联文章提取封面图/标签/字数；纯文字公告无关联文章）
function _annCoverTag($article) {
    $cover = ''; $tags = []; $words = 0; $meta = [];
    $p = __DIR__ . '/data/articles/' . basename($article);
    if ($article !== '' && is_file($p)) {
        $raw = @file_get_contents($p);
        if ($raw !== false) {
            if (preg_match('/<!--META(.*?)-->/s', $raw, $m)) {
                $meta = json_decode(trim($m[1]), true) ?: [];
            }
            // 封面图：正文第一张 markdown 图片
            if (preg_match('/!\[[^\]]*\]\(([^)\s]+)\)/', $raw, $im)) {
                $cover = $im[1];
                if (strpos($cover, 'data/images/') === 0) $cover = '/' . $cover; // 站内图片补根路径
            }
            // 标签：META tags → 正文 #tag
            if (!empty($meta['tags'])) {
                $tags = array_filter(array_map('trim', explode(',', $meta['tags'])));
            }
            if (empty($tags) && preg_match_all('/#([\p{L}\p{N}_-]+)/u', $raw, $tm)) {
                $tags = array_slice(array_unique($tm[1]), 0, 5);
            }
            $words = mb_strlen(preg_replace('/\s+/u', '', preg_replace('/<!--.*?-->/s', '', $raw)), 'UTF-8');
        }
    }
    return [$cover, array_slice($tags, 0, 5), $words, $meta];
}
// v3.1.10：公告可见范围（站长后台可调）——all 所有人 / users 仅登录用户 / managers 仅站长及以上
// v3.1.12：可见范围仅作用于「更新公告」（更新历史.md）；其他公告始终可见
//          封面不再作为公告展示（图片仅作为文章内配图，cover 恒为空）
$_annVis = $_siteConfig['announce_visibility'] ?? 'all';
$_announcements = [];
foreach (getAnnouncements(20) as $_an) {
    $isUpdate = ($_an['article'] ?? '') === '更新历史.md';
    if ($isUpdate && $_annVis === 'managers' && !checkRole(ROLE_STATION_ADMIN)) continue;
    if ($isUpdate && $_annVis === 'users' && !checkRole(ROLE_USER)) continue;
    [, $tg, $wc, ] = _annCoverTag($_an['article'] ?? '');
    $_announcements[] = [
        'id' => $_an['id'], 'type' => $_an['type'], 'article' => $_an['article'],
        'title' => $_an['title'], 'summary' => $_an['summary'], 'date' => $_an['date'],
        'body' => $_an['body'] ?? '',
        'cover' => '', 'tags' => $tg, 'words' => $wc,
    ];
}
// 音乐播放器：网易云（music_cookies）或 QQ（music_cookies_qq）任一配置后显示入口（v2.6.0 起支持双平台独立开关）
$musicEnabled = !empty($_siteConfig['music_cookies']) || !empty($_siteConfig['music_cookies_qq']);
// v4.3.0：默认音乐平台——已配置 QQ cookie + QQ 歌单时默认 QQ（否则网易云）；前端加载该平台歌单
$_defaultMusicPlatform = 'netease';
if (!empty($_siteConfig['music_cookies_qq']) && !empty($_siteConfig['music_playlist_id_qq'])) {
    $_defaultMusicPlatform = 'qq';
}
// 置顶列表读写由 utils.php 提供（SQLite）；此处仅作全局别名
// 自定义入口路径路由（L1 隐藏入口扩展）
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$urlPath = parse_url($requestUri, PHP_URL_PATH);
$urlPath = trim($urlPath, '/');
$pathParts = explode('/', $urlPath);
$firstSegment = $pathParts[0] ?? '';

$stationPath = getStationPath();
$authorPath = getAuthorPath();
$hideDefaults = isDefaultPathHidden();

// 自定义路径匹配 → 转发到对应 dashboard
if ($stationPath !== 'station' && $firstSegment === $stationPath) {
    if (isset($pathParts[1]) && $pathParts[1] === 'dashboard.php') {
        require __DIR__ . '/station/dashboard.php';
        exit;
    }
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}
if ($authorPath !== 'author' && $firstSegment === $authorPath) {
    if (isset($pathParts[1]) && $pathParts[1] === 'dashboard.php') {
        require __DIR__ . '/author/dashboard.php';
        exit;
    }
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// 隐藏默认路径：自定义路径生效后，默认路径返回 404
if ($hideDefaults) {
    if ($stationPath !== 'station' && $firstSegment === 'station') {
        http_response_code(404);
        require __DIR__ . '/404.php';
        exit;
    }
    if ($authorPath !== 'author' && $firstSegment === 'author') {
        http_response_code(404);
        require __DIR__ . '/404.php';
        exit;
    }
}

// v4.0.0：未知路径返回 404 页（nginx try_files 会把不存在的路径 fallback 到 index.php，
//          此时非空路径且不是已知入口/接口/静态页 → 统一 404，避免裸首页外壳）
if ($firstSegment !== '' && !in_array($firstSegment, [
    'index.php', 'api.php', 'img.php', 'music.php', 'sc.php', 'user.php',
    'verify-author.php', 'verify-confirm.php', '404.php', 'robots.txt', 'favicon.ico',
], true)) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// CSRF 防护：index.php 所有 POST 操作（pin/unpin/delete/update）统一校验
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        jsonOut(['success' => false, 'error' => 'CSRF 校验失败'], 403);
    }
}

if ($action === 'pin') {
    if (!isAdmin()) { logUnauthorized('越权尝试置顶文章', true); jsonOut(['success' => false, 'error' => '无权限'], 403); }
    header('Content-Type: application/json; charset=utf-8');
    $file = basename($_POST['file'] ?? '');
    if (empty($file)) { echo json_encode(['success' => false]); exit; }
    $pinned = getPinnedList();
    if (!in_array($file, $pinned)) {
        $pinned[] = $file;
        savePinnedList($pinned);
    }
    echo json_encode(['success' => true]);
    exit;
}
if ($action === 'unpin') {
    if (!isAdmin()) { logUnauthorized('越权尝试取消置顶文章', true); jsonOut(['success' => false, 'error' => '无权限'], 403); }
    header('Content-Type: application/json; charset=utf-8');
    $file = basename($_POST['file'] ?? '');
    if (empty($file)) { echo json_encode(['success' => false]); exit; }
    $pinned = getPinnedList();
    $pinned = array_values(array_diff($pinned, [$file]));
    savePinnedList($pinned);
    echo json_encode(['success' => true]);
    exit;
}
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $files = glob('./data/articles/*.md');
    $fileList = [];
    $pinnedList = getPinnedList();
    // v3.1.11：设为公告的文章不在文章列表展示（公告单独展示，不产生文章卡片）
    $annArticles = array_flip(array_column(db_all("SELECT article FROM announcement WHERE article != ''"), 'article'));
    // v4.0.0：浏览量一次取回构建 map，避免逐篇查询
    $viewsMap = [];
    foreach (db_all('SELECT article, views FROM page_views') as $v) $viewsMap[$v['article']] = (int)$v['views'];
    $nowStr = date('Y-m-d H:i');
    if ($files) {
        usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
        foreach ($files as $listIdx => $file) {
            $filename = basename($file);
            if (strpos($filename, '.') === 0) continue;
            $content = file_get_contents($file);
            // v3.1.10：META 标记 hidden 的文章不进首页列表（如「更新历史」仅保留公告入口）
            // v4.0.0：草稿（status=draft）不进列表；定时（status=scheduled 且未到 publish_at）也不进列表
            if (preg_match('/<!--META(.*?)-->/s', $content, $hm)) {
                $hmeta = json_decode(trim($hm[1]), true);
                if (!empty($hmeta['hidden'])) continue;
                $mStatus = $hmeta['status'] ?? 'published';
                $mPublishAt = $hmeta['publish_at'] ?? '';
                if ($mStatus === 'draft') continue;
                if ($mStatus === 'scheduled' && $mPublishAt !== '' && $mPublishAt > $nowStr) continue;
            }
            // v3.1.11：公告关联文章不进列表
            if (isset($annArticles[$filename])) continue;
            $lines = explode("\n", $content);
            $title = '';
            $wordCount = mb_strlen(preg_replace('/\s+/', '', $content), 'UTF-8');
            $category = ''; $tags = []; $excerpt = ''; $author = '';
            $license = 'CC BY-NC-SA 4.0';
            $licenseUrl = 'https://creativecommons.org/licenses/by-nc-sa/4.0/';
            if (preg_match('/<!--META(.*?)-->/s', $content, $metaMatch)) {
                $meta = json_decode(trim($metaMatch[1]), true);
                if ($meta) {
                    $category = $meta['category'] ?? '';
                    // v4.0.0：过滤空标签（META tags 为空字符串时 explode 产生 '' 空项，导致前端标签云出现孤立 #）
                    $rawTags = $meta['tags'] ?? '';
                    $tags = is_array($rawTags) ? array_values(array_filter(array_map('trim', $rawTags), fn($t) => $t !== ''))
                                               : array_values(array_filter(array_map('trim', explode(',', (string)$rawTags)), fn($t) => $t !== ''));
                    $excerpt = $meta['excerpt'] ?? '';
                    $author = $meta['author'] ?? '';
                    if (!empty($meta['license'])) {
                        $license = $meta['license'];
                        $licenseUrl = $meta['licenseUrl'] ?? '';
                    }
                }
            }
            $inCodeBlock = false;
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (preg_match('/^```/', $trimmed)) { $inCodeBlock = !$inCodeBlock; continue; }
                if ($inCodeBlock) continue;
                if (preg_match('/^#\s+(.+)/', $trimmed, $matches)) { $title = $matches[1]; break; }
            }
            if (empty($title)) $title = preg_replace('/\.md$/i', '', $filename);
            if (empty($excerpt)) {
                $textContent = preg_replace('/^<!--.*?-->\n?/s', '', $content);
                $textContent = preg_replace('/^#.*$/m', '', $textContent);
                $textContent = preg_replace('/```.*?```/s', '', $textContent);
                $textContent = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $textContent);
                $textContent = preg_replace('/[#*>`\-_\|~\[\]]/', '', $textContent);
                $textContent = trim(preg_replace('/\s+/', ' ', $textContent));
                $excerpt = mb_substr($textContent, 0, 120, 'UTF-8');
                if (mb_strlen($textContent, 'UTF-8') > 120) $excerpt .= '...';
            }
            if (empty($tags)) {
                if (preg_match_all('/#(\w+)/u', $content, $matches)) $tags = array_slice(array_unique($matches[1]), 0, 5);
                if (empty($tags)) $tags = ['markdown', '文档'];
            }
            $isPinned = in_array($filename, $pinnedList);
            // v3.1.12：文章卡片封面——正文第一张图片（图片仅作为文章展示，公告不再使用）
            $cover = '';
            if (preg_match('/!\[[^\]]*\]\(([^)\s]+)\)/', $content, $im)) {
                $cover = $im[1];
                if (strpos($cover, 'data/') === 0) $cover = '/' . $cover;
            }
            // v4.1.12：无图文章卡片封面回退到网站背景图
            // v4.1.13：卡片封面 API 优先（后台「网站背景 → 卡片封面 API」配置）
            // v4.1.15：API 封面改走服务端图片池代理 cover.php（桌面 UA 拉横屏 + 缩略图 + 6h 缓存），
            //          每卡取池中随机一张（不同卡不同图）；上传背景图仍直连
            // v4.2.1：v4.2.0 直连外部 API 触发单张限流（429）致部分卡片无封面——回退 cover.php 池化代理
            //         （缩略图 960px，手机加载快且清晰），抓取源固定为 FIXED_IMG_API
            // v4.2.2：封面槽位由随机改为「列表位置 % 24」稳定值——同一文章永远同一 URL → 浏览器缓存命中，
            //         重复访问不再重下 ~978KB 封面；不同文章不同槽位，每卡仍不同图（池内每 6h 轮换内容）
            if ($cover === '') {
                $bgType = $_siteConfig['bg_type'] ?? 'none';
                if ($bgType === 'image' && !empty($_siteConfig['bg_image'])) {
                    $cover = $_siteConfig['bg_image'];
                    if (strpos($cover, 'data/') === 0) $cover = '/' . $cover;
                } else {
                    $cover = 'cover.php?i=' . ($listIdx % 24);
                }
            }
            $fileList[] = [
                'name' => $filename, 'displayName' => $title, 'category' => $category,
                'size' => filesize($file), 'modified' => date('Y-m-d', filemtime($file)),
                'modifiedTimestamp' => filemtime($file), 'excerpt' => $excerpt,
                'wordCount' => $wordCount, 'tags' => $tags, 'author' => $author,
                'license' => $license, 'licenseUrl' => $licenseUrl, 'pinned' => $isPinned,
                'cover' => $cover,
                'views' => $viewsMap[$filename] ?? 0,
                'status' => $mStatus ?? 'published',
                'publishAt' => $mPublishAt ?? '',
            ];
        }
    }
    usort($fileList, function($a, $b) {
        if ($a['pinned'] && !$b['pinned']) return -1;
        if (!$a['pinned'] && $b['pinned']) return 1;
        return $b['modifiedTimestamp'] - $a['modifiedTimestamp'];
    });
    $total = count($fileList);
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = max(1, min(200, intval($_GET['per_page'] ?? 100)));
    $pagedList = array_slice($fileList, ($page - 1) * $perPage, $perPage);
    echo json_encode(['success' => true, 'files' => $pagedList, 'count' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => max(1, ceil($total / $perPage))], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($action === 'read') {
    header('Content-Type: application/json; charset=utf-8');
    $requestedFile = isset($_GET['file']) ? $_GET['file'] : '';
    $filename = basename($requestedFile);
    $filepath = './data/articles/' . $filename;
    if (!file_exists($filepath) || !is_file($filepath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '文件不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'md') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '仅支持 .md 文件'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $realDataPath = realpath('./data/articles');
    $realFilePath = realpath($filepath);
    if ($realFilePath === false || strpos($realFilePath, $realDataPath) !== 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '禁止访问'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // v4.0.0：草稿（status=draft）与未到发布时间的定时文章（status=scheduled）对外不可读
    $rawRead = file_get_contents($filepath);
    if (preg_match('/<!--META(.*?)-->/s', $rawRead, $rm)) {
        $rmeta = json_decode(trim($rm[1]), true) ?: [];
        $rStatus = $rmeta['status'] ?? 'published';
        $rPublishAt = $rmeta['publish_at'] ?? '';
        if ($rStatus === 'draft') {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => '文件不存在'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($rStatus === 'scheduled' && $rPublishAt !== '' && $rPublishAt > date('Y-m-d H:i')) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => '文件不存在'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    // v4.0.0：站内访问统计——同 IP 同文章同一天只计一次（views_log 去重），避免刷量
    $viewIp = getClientIP();
    $viewDay = date('Y-m-d');
    $stmtV = db()->prepare('INSERT OR IGNORE INTO views_log (article, ip, day) VALUES (?, ?, ?)');
    $stmtV->execute([$filename, $viewIp, $viewDay]);
    if ($stmtV->rowCount() > 0) {
        db()->prepare('INSERT INTO page_views (article, views, updated) VALUES (?, 1, ?)
                       ON CONFLICT(article) DO UPDATE SET views = views + 1, updated = excluded.updated')
            ->execute([$filename, date('Y-m-d H:i:s')]);
    }
    // v4.0.1：views_log 概率清理（1/64 触发，保留 30 天）——防表无限增长（存储 DoS）
    if (random_int(0, 63) === 0) {
        db_exec('DELETE FROM views_log WHERE day < ?', [date('Y-m-d', time() - 2592000)]);
    }
    $content = $rawRead;
    $displayName = preg_replace('/\.md$/i', '', $filename);
    if (preg_match('/<!--META(.*?)-->/s', $content, $metaMatch)) {
        $meta = json_decode(trim($metaMatch[1]), true);
        if ($meta && !empty($meta['title'])) {
            $displayName = $meta['title'];
        }
    }
    $content = preg_replace('/<!--META.*?-->\n?/s', '', $content);
    // v3.3.17：站内大图超过 3MB 默认展示缩略图、不加载原图（轻量站——富媒体文章里的
    //          MB 级大图（如航拍截图）点进文章不再拖慢加载；原图保留但页面不再请求）。
    //          仅站内 data/images/ 图片、跳过 gif（动图保动画）与已是缩略图的引用。
    $content = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)/', function($m) {
        $url = $m[2];
        if (strpos($url, 'img.php') !== false || preg_match('/\.gif$/i', $url)) return $m[0];
        $p = null;
        if (strpos($url, 'data/images/') === 0) $p = __DIR__ . '/' . $url;
        elseif (strpos($url, '/data/images/') === 0) $p = __DIR__ . $url;
        else return $m[0];
        if (!is_file($p)) return $m[0];
        if (filesize($p) > 3 * 1024 * 1024) {
            $thumb = 'img.php?src=' . urlencode('/' . ltrim($url, '/')) . '&w=1600';
            return '![' . $m[1] . '](' . $thumb;
        }
        return $m[0];
    }, $content);
    $contentWithoutCode = preg_replace('/```[\s\S]*?```/', '', $content);
    if ($displayName === preg_replace('/\.md$/i', '', $filename) && preg_match('/^#\s+(.+)/m', $contentWithoutCode, $tm)) $displayName = $tm[1];
    // v3.3.15：read 接口返回字数——公告关联文章（如「更新历史」，META hidden 不进首页列表）
    //          首页公告卡片能显示字数、点进文章后却因不在 allFiles 显示 0 字，前端据此修正
    $wordCount = mb_strlen(preg_replace('/\s+/', '', $content), 'UTF-8');
    echo json_encode([
        'success' => true, 'name' => $filename,
        'displayName' => $displayName,
        'content' => $content, 'size' => filesize($filepath),
        'modified' => date('Y-m-d H:i', filemtime($filepath)),
        'wordCount' => $wordCount,
        'views' => (int)db_one('SELECT views FROM page_views WHERE article = ?', [$filename])['views'] ?? 0
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($action === 'delete') {
    if (!isAdmin()) { logUnauthorized('越权尝试删除文章: ' . ($_GET['file'] ?? $_POST['file'] ?? ''), true); jsonOut(['success' => false, 'error' => '无权限'], 403); }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['success' => false, 'error' => '请求方式错误'], 405);
    header('Content-Type: application/json; charset=utf-8');
    $requestedFile = $_POST['file'] ?? '';
    $filename = basename($requestedFile);
    $filepath = './data/articles/' . $filename;
    if (!file_exists($filepath) || !is_file($filepath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '文件不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $realDataPath = realpath('./data/articles');
    $realFilePath = realpath($filepath);
    if ($realFilePath === false || strpos($realFilePath, $realDataPath) !== 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '禁止访问'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (unlink($filepath)) {
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'error' => '删除失败'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
if ($action === 'update') {
    if (!isAdmin()) { logUnauthorized('越权尝试修改文章: ' . ($_GET['file'] ?? ''), true); jsonOut(['success' => false, 'error' => '无权限'], 403); }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['success' => false, 'error' => '请求方式错误'], 405);
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true);
    $requestedFile = $input['file'] ?? '';
    $newContent = $input['content'] ?? '';
    $newCategory = $input['category'] ?? '';
    $newTags = $input['tags'] ?? '';
    $newExcerpt = $input['excerpt'] ?? '';
    $newAuthor = $input['author'] ?? '';
    $newLicense = $input['license'] ?? 'CC BY-NC-SA 4.0';
    $newLicenseUrl = $input['licenseUrl'] ?? '';
    $filename = basename($requestedFile);
    $filepath = './data/articles/' . $filename;
    if (!file_exists($filepath) || !is_file($filepath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '文件不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $realDataPath = realpath('./data/articles');
    $realFilePath = realpath($filepath);
    if ($realFilePath === false || strpos($realFilePath, $realDataPath) !== 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '禁止访问'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $meta = json_encode(['category' => $newCategory, 'tags' => $newTags, 'excerpt' => $newExcerpt, 'author' => $newAuthor, 'license' => $newLicense, 'licenseUrl' => $newLicenseUrl], JSON_UNESCAPED_UNICODE);
    $fullContent = "<!--META" . $meta . "-->\n" . $newContent;
    if (file_put_contents($filepath, $fullContent)) {
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'error' => '保存失败'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===================== v4.0.0 新增接口 =====================

/**
 * 收集已发布文章（供全文搜索 / RSS 共用）
 * 过滤：hidden、公告关联、草稿、未到发布时间的定时文章
 * @param string $q 关键词（全文搜索用，可留空取全部）
 * @return array 文章数组
 */
function _publishedArticles($q = '') {
    $files = glob('./data/articles/*.md');
    $out = [];
    $annArticles = array_flip(array_column(db_all("SELECT article FROM announcement WHERE article != ''"), 'article'));
    $nowStr = date('Y-m-d H:i');
    $qLow = mb_strtolower(trim($q), 'UTF-8');
    if ($files) {
        usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
        foreach ($files as $file) {
            $filename = basename($file);
            if (strpos($filename, '.') === 0) continue;
            $content = @file_get_contents($file);
            if ($content === false) continue;
            if (preg_match('/<!--META(.*?)-->/s', $content, $hm)) {
                $hmeta = json_decode(trim($hm[1]), true) ?: [];
                if (!empty($hmeta['hidden'])) continue;
                $mStatus = $hmeta['status'] ?? 'published';
                $mPublishAt = $hmeta['publish_at'] ?? '';
                if ($mStatus === 'draft') continue;
                if ($mStatus === 'scheduled' && $mPublishAt !== '' && $mPublishAt > $nowStr) continue;
            }
            if (isset($annArticles[$filename])) continue;
            $plain = preg_replace('/<!--META.*?-->\n?/s', '', $content);
            $title = '';
            if (preg_match('/^#\s+(.+)/m', $plain, $tm)) $title = trim($tm[1]);
            if ($title === '') $title = preg_replace('/\.md$/i', '', $filename);
            $category = $hmeta['category'] ?? '';
            $rawTags = $hmeta['tags'] ?? '';
            $tags = is_array($rawTags) ? array_values(array_filter(array_map('trim', $rawTags), fn($t) => $t !== ''))
                                       : array_values(array_filter(array_map('trim', explode(',', (string)$rawTags)), fn($t) => $t !== ''));
            $excerpt = $hmeta['excerpt'] ?? '';
            $author = $hmeta['author'] ?? '';
            // v4.0.0：搜索摘要——META 无手写摘要时自动生成（与 list 接口同算法，保证搜索结果有预览）
            if ($excerpt === '') {
                $textContent = preg_replace('/^#.*$/m', '', $plain);
                $textContent = preg_replace('/```.*?```/s', '', $textContent);
                $textContent = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $textContent);
                $textContent = preg_replace('/[#*>`\-_\|~\[\]]/', '', $textContent);
                $textContent = trim(preg_replace('/\s+/', ' ', $textContent));
                $excerpt = mb_substr($textContent, 0, 120, 'UTF-8');
                if (mb_strlen($textContent, 'UTF-8') > 120) $excerpt .= '...';
            }
            // 全文搜索：标题 / 标签 / 摘要 / 正文关键词匹配（忽略大小写）
            if ($qLow !== '') {
                $haystack = mb_strtolower($title . ' ' . $plain . ' ' . implode(' ', $tags) . ' ' . $excerpt . ' ' . $author, 'UTF-8');
                if (mb_strpos($haystack, $qLow, 0, 'UTF-8') === false) continue;
            }
            $out[] = [
                'name' => $filename,
                'displayName' => $title,
                'category' => $category,
                'tags' => $tags,
                'excerpt' => $excerpt,
                'author' => $author,
                'modified' => date('Y-m-d', filemtime($file)),
                'modifiedTimestamp' => filemtime($file),
                'size' => filesize($file),
            ];
        }
    }
    return $out;
}

// v4.0.0：站内全文搜索（标题/标签/摘要/正文），GET /?action=search&q=关键词
if ($action === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q, 'UTF-8') < 1 || mb_strlen($q, 'UTF-8') > 100) {
        echo json_encode(['success' => true, 'query' => $q, 'files' => [], 'count' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // v4.0.1：搜索接口速率限制（滑动窗口，同 IP 每分钟 ≤30 次；复用 comment_rates 表防 DoS 刷量）
    $searchIp = getClientIP();
    db_rate_add('comment_rates', $searchIp);
    if (db_rate_count('comment_rates', $searchIp, 60) > 30) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => '搜索太频繁，请稍后再试'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $hits = _publishedArticles($q);
    echo json_encode(['success' => true, 'query' => $q, 'files' => $hits, 'count' => count($hits)], JSON_UNESCAPED_UNICODE);
    exit;
}

// v4.0.0：RSS 订阅（最近 20 篇已发布文章），GET /?action=rss
if ($action === 'rss') {
    header('Content-Type: application/xml; charset=utf-8');
    $feed = _publishedArticles();
    $siteTitle = htmlspecialchars($_siteTitle, ENT_XML1, 'UTF-8');
    // v4.1.0：Host 头加固——仅接受合法域名/端口格式，防 Host 注入伪造订阅链接域名
    $_rawHost = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/^[a-zA-Z0-9.-]+(?::\d{1,5})?$/', $_rawHost) || $_rawHost === '') {
        $_rawHost = 'localhost';
    }
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_rawHost;
    $self = $baseUrl . '/index.php?action=rss';
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>' . "\n";
    echo '<title>' . $siteTitle . '</title>' . "\n";
    echo '<link>' . htmlspecialchars($baseUrl, ENT_XML1, 'UTF-8') . '</link>' . "\n";
    echo '<description>' . $siteTitle . ' 的最新内容</description>' . "\n";
    echo '<atom:link href="' . htmlspecialchars($self, ENT_XML1, 'UTF-8') . '" rel="self" type="application/rss+xml"/>' . "\n";
    echo '<lastBuildDate>' . date(DATE_RFC2822) . '</lastBuildDate>' . "\n";
    foreach (array_slice($feed, 0, 20) as $f) {
        echo '<item>' . "\n";
        echo '<title>' . htmlspecialchars($f['displayName'], ENT_XML1, 'UTF-8') . '</title>' . "\n";
        echo '<link>' . htmlspecialchars($baseUrl . '/index.php?action=read&file=' . rawurlencode($f['name']), ENT_XML1, 'UTF-8') . '</link>' . "\n";
        echo '<guid>' . htmlspecialchars($baseUrl . '/index.php?action=read&file=' . rawurlencode($f['name']), ENT_XML1, 'UTF-8') . '</guid>' . "\n";
        $desc = $f['excerpt'] !== '' ? $f['excerpt'] : $f['displayName'];
        echo '<description>' . htmlspecialchars($desc, ENT_XML1, 'UTF-8') . '</description>' . "\n";
        echo '<pubDate>' . date(DATE_RFC2822, $f['modifiedTimestamp']) . '</pubDate>' . "\n";
        if ($f['author'] !== '') echo '<author>' . htmlspecialchars($f['author'], ENT_XML1, 'UTF-8') . '</author>' . "\n";
        echo '</item>' . "\n";
    }
    echo '</channel></rss>' . "\n";
    exit;
}

// v4.1.0：RSS 订阅引导页——浏览器点击 RSS 图标不再直接弹 XML 树，改为友好说明页（阅读器抓取仍走 ?action=rss）
if ($action === 'rss_guide') {
    $_rawHost2 = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/^[a-zA-Z0-9.-]+(?::\d{1,5})?$/', $_rawHost2) || $_rawHost2 === '') {
        $_rawHost2 = 'localhost';
    }
    $guideBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_rawHost2;
    $rssAbs = $guideBase . '/index.php?action=rss';
    $feedCount = count(_publishedArticles());
    ?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RSS 订阅 · <?= htmlspecialchars($_siteTitle) ?></title>
<script>
(function() {
    try {
        var saved = localStorage.getItem('md-theme');
        var dark = saved ? saved === 'dark'
            : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    } catch (e) {}
})();
</script>
<link rel="stylesheet" href="/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>">
<style>
    .rss-guide-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
    .rss-guide-card { max-width: 560px; width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 24px; padding: 40px 36px; box-shadow: var(--shadow-md); }
    .rss-guide-card h1 { font-size: 20px; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; }
    .rss-guide-card p { color: var(--text-secondary); font-size: 14px; line-height: 1.75; margin-bottom: 18px; }
    .rss-guide-url { display: flex; gap: 8px; margin: 6px 0 20px; }
    .rss-guide-url input { flex: 1; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; font-size: 13px; color: var(--text); background: var(--bg); min-width: 0; }
    .rss-guide-url button { flex-shrink: 0; }
    .rss-guide-list { list-style: none; padding: 0; display: flex; flex-direction: column; gap: 10px; margin-bottom: 22px; }
    .rss-guide-list li { display: flex; gap: 10px; align-items: flex-start; font-size: 13.5px; color: var(--text-secondary); line-height: 1.6; }
    .rss-guide-list li b { color: var(--text); font-weight: 600; }
    .rss-guide-foot { font-size: 12px; color: var(--text-muted); border-top: 1px solid var(--border); padding-top: 14px; }
    .rss-guide-foot a { color: var(--accent); }
    @media (max-width: 480px) { .rss-guide-card { padding: 28px 20px; border-radius: 18px; } .rss-guide-url { flex-direction: column; } }
</style>
</head>
<body style="padding-left:0">
<div class="rss-guide-page">
    <div class="rss-guide-card">
        <h1>📡 RSS 订阅</h1>
        <p>本站提供标准 RSS 2.0 订阅源（最近 <?= intval($feedCount) ?> 篇文章，发布后自动更新）。用任意 RSS 阅读器添加下面地址即可追更：</p>
        <div class="rss-guide-url">
            <input type="text" readonly value="<?= htmlspecialchars($rssAbs) ?>" id="rssGuideUrl">
            <button class="btn btn-primary" type="button" onclick="var i=document.getElementById('rssGuideUrl'); i.select(); try{document.execCommand('copy');}catch(e){}; this.textContent='已复制'; setTimeout(()=>this.textContent='复制地址',1600);">复制地址</button>
        </div>
        <ol class="rss-guide-list">
            <li>1️⃣ 打开你常用的 RSS 阅读器（如 Feedly、Inoreader、NetNewsWire，或浏览器 RSS 扩展）</li>
            <li>2️⃣ 选择「添加订阅 / Add Feed」，粘贴上面的地址</li>
            <li>3️⃣ 之后新文章发布，阅读器会自动拉取提醒，无需再打开本站</li>
        </ol>
        <p style="margin-bottom:0"><a href="/index.php?action=rss" target="_blank" rel="noopener">查看原始 XML 订阅源 →</a></p>
        <p class="rss-guide-foot">也可以直接复制地址到任意支持订阅的客户端使用。</p>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
    <!-- v4.0.0：深色模式跟随系统——在 CSS 加载前同步设置 data-theme，避免闪白 -->
    <script>
        (function() {
            try {
                var saved = localStorage.getItem('md-theme');
                var dark = saved ? saved === 'dark'
                    : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
            } catch (e) {}
        })();
    </script>
    <!-- v4.0.0：RSS 订阅（浏览器可发现） -->
    <link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($_siteTitle) ?> RSS" href="/index.php?action=rss">
    <title><?= htmlspecialchars($_siteTitle) ?></title>
    <!-- v3.1.4：链接解析/分享预览卡片使用自定义站名（微信/QQ/Telegram 等读取 og 标签） -->
    <meta property="og:title" content="<?= htmlspecialchars($_siteTitle) ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars($_siteTitle) ?>">
    <meta property="og:type" content="website">
    <meta property="og:description" content="一个基于PHP语言开发的轻量、优雅、简洁的 Markdown 在线阅读器">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📝</text></svg>" type="image/svg+xml">
    <meta name="description" content="一个基于PHP语言开发的轻量、优雅、简洁的 Markdown 在线阅读器">
    <!-- v4.2.2：mermaid 3.3MB 不再放 <head> 阻塞首屏（render-blocking），改为正文/公告出现 ```mermaid 时按需动态加载（见 js/main.js ensureMermaid）；marked/highlight/qrcode 加 defer 不阻塞首屏渲染 -->
    <script src="https://cdn.jsdelivr.net/npm/marked@4.3.0/marked.min.js" defer crossorigin="anonymous"
    integrity="sha384-QsSpx6a0USazT7nK7w8qXDgpSAPhFsb2XtpoLFQ5+X2yFN6hvCKnwEzN8M5FWaJb"
    ></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.9.0/build/styles/atom-one-light.min.css" id="hljsTheme">
    <script src="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.9.0/build/highlight.min.js" defer crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js" defer crossorigin="anonymous"></script>
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
</head>
<?php
// v4.2.0：API 背景——空值回退固定 16:9 图片源；追加 `_t` 时间参数防浏览器缓存，每次进站换一张随机背景
$_bgApiUrl = trim((string)($_siteConfig['bg_api_url'] ?? ''));
if (($_siteConfig['bg_type'] ?? 'none') === 'api' && $_bgApiUrl === '') $_bgApiUrl = FIXED_IMG_API;
if ($_bgApiUrl !== '') $_bgApiUrl .= (strpos($_bgApiUrl, '?') !== false ? '&' : '?') . '_t=' . time();
?>
<body data-guest-comments="<?= !empty($_siteConfig['guest_comments_enabled']) ? '1' : '0' ?>" data-reg-verify="<?= !empty($_siteConfig['email_verify_enabled']) ? '1' : '0' ?>" data-email-change="<?= !empty($_siteConfig['email_verify_enabled']) ? '1' : '0' ?>" data-csrf="<?= htmlspecialchars(generateCsrfToken()) ?>" data-bg-type="<?= htmlspecialchars($_siteConfig['bg_type'] ?? 'none') ?>" data-bg-image="<?= htmlspecialchars($_siteConfig['bg_image'] ?? '') ?>" data-bg-api-url="<?= htmlspecialchars($_bgApiUrl) ?>" data-bg-blur="<?= !empty($_siteConfig['bg_blur_enabled']) ? '1' : '0' ?>" data-bg-blur-level="<?= intval($_siteConfig['bg_blur_level'] ?? 0) ?>" data-bg-card-opacity="<?= intval($_siteConfig['bg_card_opacity'] ?? 100) ?>" data-music-playlist="<?= htmlspecialchars($_siteConfig['music_playlist_id'] ?? '3778678') ?>" data-music-playlist-qq="<?= htmlspecialchars($_siteConfig['music_playlist_id_qq'] ?? '') ?>" data-music-platform="<?= htmlspecialchars($_defaultMusicPlatform) ?>" data-music-auto-play="<?= htmlspecialchars($_siteConfig['music_auto_play'] ?? '') ?>">
<header class="top-bar" id="topBar">
    <div class="header-left"><a href="./" class="brand" style="text-decoration:none;cursor:pointer;"><?= htmlspecialchars($_siteTitle) ?></a></div>
    <div class="header-right">
        <!-- v4.1.0：RSS 订阅入口（点击进友好引导页，不再直接弹 XML；阅读器自动发现仍走 ?action=rss） -->
        <a class="icon-btn rss-btn" id="btnRss" title="RSS 订阅" href="/index.php?action=rss_guide" target="_blank" rel="noopener" aria-label="RSS 订阅">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <path d="M5 13a6 6 0 0 1 6 6"/>
                <path d="M5 7a12 12 0 0 1 12 12"/>
                <path d="M5 19a1 1 0 1 0 0-2 1 1 0 0 0 0 2z" fill="currentColor" stroke="none"/>
            </svg>
        </a>
        <?php if ($musicEnabled): ?>
        <!-- v4.3.0：音乐入口由右侧浮动按钮移至顶部快捷栏（手机/电脑均常驻，切换界面不打断播放） -->
        <button class="icon-btn" id="floatMusicBtn" title="音乐"><svg viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></button>
        <?php endif; ?>
        <button class="icon-btn" id="btnSearch" title="搜索"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></button>
        <button class="icon-btn" id="btnToc" title="目录"><svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
        <button class="icon-btn" id="btnFont" title="字体设置"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 7 4 4 20 4 20 7"></polyline><line x1="9" y1="20" x2="15" y2="20"></line><line x1="12" y1="4" x2="12" y2="20"></line></svg></button>
        <button class="icon-btn" id="btnThemeToggle" title="明暗切换"><svg viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></button>
        <button class="icon-btn" id="btnKbdHelp" title="快捷键帮助 (?)" onclick="toggleKbdHelp()"><svg viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><line x1="6" y1="10" x2="6" y2="10"/><line x1="10" y1="10" x2="10" y2="10"/><line x1="14" y1="10" x2="14" y2="10"/><line x1="18" y1="10" x2="18" y2="10"/><line x1="8" y1="14" x2="16" y2="14"/></svg></button>
        <button class="icon-btn" id="btnColor" title="调整主题色"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 0 1 0 20 4 4 0 0 1-4-4v-2a2 2 0 0 0-2-2H4a2 2 0 0 1-2-2 10 10 0 0 1 10-10z"/><circle cx="8" cy="6" r="1.5" fill="currentColor" stroke="none"/><circle cx="14" cy="6" r="1.5" fill="currentColor" stroke="none"/><circle cx="18" cy="10" r="1.5" fill="currentColor" stroke="none"/><circle cx="8" cy="12" r="1.5" fill="currentColor" stroke="none"/></svg></button>
        <button class="icon-btn" id="btnUser" title="用户"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></button>
    </div>
</header>
<!-- 用户下拉菜单 -->
<div class="user-dropdown" id="userDropdown">
    <div class="user-dropdown-header" id="userDropdownHeader">
        <div class="user-dropdown-avatar" id="userDropdownAvatar"></div>
        <div class="user-dropdown-info">
            <div class="user-dropdown-name" id="userDropdownName">未登录</div>
            <div class="user-dropdown-role" id="userDropdownRole">访客</div>
        </div>
    </div>
    <div class="user-dropdown-divider"></div>
    <div class="user-dropdown-item" id="userDropdownLogin" data-action="login">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        <span>登录</span>
    </div>
    <div class="user-dropdown-item" id="userDropdownAdmin" data-action="admin" style="display:none">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <span>快捷进入管理界面</span>
    </div>
    <div class="user-dropdown-divider" id="userDropdownDivider" style="display:none"></div>
    <!-- v2.11.3：普通用户/站长/写作者的个人资料入口（超管保持隐身不显示） -->
    <div class="user-dropdown-item" id="userDropdownProfile" data-action="profile" style="display:none">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span>编辑资料</span>
    </div>
    <div class="user-dropdown-item user-dropdown-item-danger" id="userDropdownLogout" data-action="logout" style="display:none">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        <span>退出登录</span>
    </div>
</div>
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <?= htmlspecialchars($_siteTitle) ?>
        </div>
        <div style="display:flex;align-items:center;gap:4px;">
            <span class="sidebar-count" id="sidebarCount">0</span>
            <button class="icon-btn" onclick="toggleSidebar()" title="折叠侧边栏 (S)" style="width:28px;height:28px;"><svg viewBox="0 0 24 24" width="16" height="16"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>
        </div>
    </div>
    <div class="sidebar-search">
        <input type="text" id="sidebarSearchInput" placeholder="搜索文档... (按 / 聚焦)">
    </div>
    <div class="sidebar-back-btn" id="sidebarBackBtn">
        <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        返回文章列表
    </div>
    <div class="sidebar-article-title" id="sidebarArticleTitle"></div>
    <div class="sidebar-toc-header" id="sidebarTocHeader">目录</div>
    <div class="sidebar-list" id="sidebarFileList"></div>
    <div class="sidebar-toc-list" id="sidebarTocList"></div>
</div>
<!-- v2.6.5 修复：折叠按钮在 sidebar 内部，收起后无恢复入口；此按钮独立于 sidebar 外，收起时可见 -->
<button class="sidebar-restore-btn" id="sidebarRestoreBtn" onclick="toggleSidebar()" title="展开侧边栏 (S)" style="display:none" aria-label="展开侧边栏">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg>
</button>
<div class="dropdown-panel" id="searchPanel">
    <div class="dropdown-search"><input type="text" id="searchInput" placeholder="搜索文档..."></div>
    <div class="dropdown-list" id="searchResults"></div>
</div>
<div class="dropdown-panel" id="tocPanel">
    <div class="toc-panel-header" id="tocPanelHeader" style="display:none;"><div class="toc-popup-title">目录</div></div>
    <div class="dropdown-list" id="tocFileList"></div>
</div>
<div class="dropdown-panel" id="fontPanel">
    <div class="font-panel-inner">
        <span style="font-weight:600; color:var(--text);">字体设置</span>
        <div class="font-type-buttons" id="fontTypeButtons">
            <button class="font-type-btn active" data-font="default">默认</button>
            <button class="font-type-btn" data-font="custom">萝莉体</button>
        </div>
        <div class="font-size-slider">
            <span style="font-size:14px; color:var(--text-secondary);">A</span>
            <input type="range" min="12" max="24" value="14" step="1" id="fontSizeSlider">
            <span style="font-size:18px; color:var(--text-secondary);">A</span>
            <span class="font-size-value" id="fontSizeValue">14px</span>
        </div>
    </div>
</div>
<div class="dropdown-panel" id="colorPanel">
    <div class="color-panel-content"><div style="display:flex;align-items:center;justify-content:space-between;"><span style="font-weight:600;">选择主题色</span><button class="color-reset-btn" id="colorResetBtn">重置</button></div><input type="range" min="0" max="360" value="220" class="hue-slider" id="hueSlider"></div>
</div>
<main class="main-container" id="mainContainer">
    <div id="homeView"><div class="category-bar" id="categoryBar"></div><!-- v3.1.6：公告卡片区块（有公告才显示） --><div class="announcement-section" id="announcementSection"></div><div class="cards-grid" id="cardsGrid"></div><!-- v4.0.0：归档视图（按年月分组；默认隐藏，点分类栏「归档」切换） --><div class="archive-view" id="archiveView" style="display:none"></div><div class="empty-state" id="emptyHome" style="display:none;">📭 暂无文档</div></div>
    <div class="reading-view" id="readingView">
        <div class="markdown-body" id="markdownBody"></div>
        <div class="cmt-capsule-section" id="commentSection" style="display:none;">
            <div class="cmt-capsule-bar" id="cmtCapsuleBar">
                <button class="cmt-capsule-btn" id="cmtCapsuleBtn">
                    <span class="cmt-capsule-icon"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                    <span class="cmt-capsule-text">未登录用户</span>
                </button>
            </div>
            <div class="cmt-user-bar" id="cmtUserBar" style="display:none">
                <div class="cmt-user-inner" id="cmtUserInner">
                    <div class="cmt-user-avatar" id="cmtUserAvatar"></div>
                    <span class="cmt-user-greeting" id="cmtUserGreeting"></span>
                    <button class="cmt-logout-btn" id="cmtLogoutBtn" title="退出登录"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></button>
                </div>
            </div>
        </div>
        <div class="cmt-input-section" id="commentArea" style="display:none;">
            <div class="cmt-input-box">
                <textarea id="cmtTextarea" placeholder="说点什么吧..." maxlength="1000" disabled></textarea>
                <div class="cmt-input-divider"></div>
                <div class="cmt-input-bottom">
                    <button class="cmt-send-btn" id="cmtSendBtn"><svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>发送</button>
                </div>
            </div>
        </div>
        <div class="comment-section" id="commentListSection" style="display:none;">
            <div class="cmt-list" id="cmtList"></div>
        </div>
        <div class="prev-next-nav" id="prevNextNav" style="display:none;">
            <button class="prev-next-btn" id="prevBtn"><span class="nav-arrow">‹</span><span class="nav-text" id="prevTitle"></span></button>
            <div class="prev-divider"></div>
            <button class="prev-next-btn next-btn-wrap" id="nextBtn"><span class="nav-text" id="nextTitle"></span><span class="nav-arrow">›</span></button>
        </div>
    </div>
</main>
<div class="floating-buttons" id="floatingButtons" style="display:none;">
    <button class="float-btn" id="floatTocBtn" title="目录"><svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
    <button class="float-btn" id="floatHomeBtn" title="返回主页"><svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></button>
    <button class="float-btn" id="scrollToTopBtn" title="回到顶部"><svg viewBox="0 0 24 24"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg></button>
</div>
<div class="share-modal-overlay" id="shareModalOverlay">
    <div class="share-modal">
        <div class="share-modal-title">分享文章</div>
        <div id="shareQrcode"></div>
        <button class="share-modal-close" id="shareModalClose">关闭</button>
    </div>
</div>
<!-- v3.1.8：公告详情弹窗（无关联文章的公告点击后显示完整内容） -->
<div class="ann-modal-overlay" id="announceModal">
    <div class="ann-modal-box">
        <div class="ann-modal-head">
            <span class="ann-modal-type"></span>
            <button class="ann-modal-close" id="announceModalClose" title="关闭">&times;</button>
        </div>
        <div class="ann-modal-title"></div>
        <div class="ann-modal-date"></div>
        <div class="ann-modal-words"></div>
        <div class="ann-modal-content"></div>
        <button class="ann-modal-link" id="announceModalLink">查看文章 →</button>
    </div>
</div>
<div class="toc-popup" id="tocPopup"><div class="toc-popup-header"><div class="toc-popup-title">目录</div></div><div class="toc-popup-list" id="tocPopupList"></div></div>
<div class="music-popup" id="musicPopup">
    <button class="music-lyric-toggle" id="musicLyrToggle" title="歌词">词</button>
    <!-- v4.4.0：QQ/网易云通道切换滑块（常驻播放器顶部，切换后重载对应平台歌单） -->
    <div class="music-channel-bar">
        <div class="music-platform-tabs" id="musicPlatformTabs">
            <span class="music-platform-tab <?= $_defaultMusicPlatform === 'netease' ? 'active' : '' ?>" data-platform="netease">网易云</span>
            <span class="music-platform-tab <?= $_defaultMusicPlatform === 'qq' ? 'active' : '' ?>" data-platform="qq">QQ</span>
        </div>
    </div>
    <div class="music-player-main" id="musicPlayerMain">
        <div class="music-disc">
            <div class="disc-ring">
                <img class="disc-cover" id="musicCover" src="" alt="">
                <div class="disc-note" style="animation-delay:0s">♪</div>
                <div class="disc-note" style="animation-delay:0.4s">♫</div>
                <div class="disc-note" style="animation-delay:0.8s">♪</div>
            </div>
        </div>
        <div class="music-meta">
            <div class="music-name" id="musicName">未播放</div>
            <div class="music-artist" id="musicArtist">点击下方歌曲开始</div>
        </div>
    </div>
    <div class="music-progress-wrap">
        <div class="music-progress-bar" id="musicProgressBar">
            <div class="music-progress-fill" id="musicProgressFill"></div>
            <div class="music-progress-dot" id="musicProgressDot"></div>
        </div>
        <div class="music-progress-time"><span id="musicCurTime">0:00</span><span id="musicTotalTime">0:00</span></div>
    </div>
    <div class="music-controls">
        <button class="music-ctrl-btn music-mode-btn" id="musicModeBtn" title="循环模式">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
        </button>
        <button class="music-ctrl-btn" id="musicPrev"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg></button>
        <button class="music-ctrl-btn music-play-btn" id="musicPlay"><svg viewBox="0 0 24 24" fill="currentColor" id="musicPlayIcon"><path d="M8 5v14l11-7z"/></svg></button>
        <button class="music-ctrl-btn" id="musicNext"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg></button>
        <button class="music-ctrl-btn music-list-btn" id="musicListToggle" title="歌单">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        </button>
    </div>
    <div class="music-lyric-panel" id="musicLyrPanel">
        <div class="music-lyric-scroll" id="musicLyrScroll">
            <div class="music-lyric-hint">暂无歌词</div>
        </div>
    </div>
    <div class="music-list" id="musicList">
        <div class="music-list-header">
            <span class="music-popup-count" id="musicPopupCount">热歌榜</span>
        </div>
        <div class="music-loading" id="musicLoading">点击加载热歌榜</div>
    </div>
</div>
<audio id="musicAudio" preload="auto"></audio>
<div class="reading-progress" id="readingProgress"></div>
<div class="reading-progress-text" id="readingProgressText"></div>
<div class="toast" id="toast"></div>
<div class="img-lightbox" id="imgLightbox"><img src="" alt="" id="lightboxImg"></div>
<div class="cmt-modal-mask" id="cmtAuthModal">
    <div class="cmt-modal-box">
        <div class="cmt-modal-head"><div class="cmt-modal-title" id="cmtAuthTitle">登录</div></div>
        <div class="cmt-modal-body cmt-auth-slide" id="cmtAuthSlide">
            <div class="cmt-modal-form" id="cmtLoginForm">
                <input class="cmt-modal-input" type="text" placeholder="QQ号" maxlength="15" id="cmtLoginQQ" autocomplete="username">
                <div class="cmt-pw-row">
                    <input class="cmt-modal-input cmt-pw-input" type="password" placeholder="密码" id="cmtLoginPw" autocomplete="current-password">
                    <!-- v3.3.16：密码显示切换——线稿眼睛图标（隐藏/可见两态） -->
                    <button type="button" class="cmt-pw-toggle" id="cmtLoginPwToggle" tabindex="-1" title="显示/隐藏密码" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
                </div>
                <div class="cmt-modal-err" id="cmtLoginErr"></div>
                <button class="cmt-modal-submit" id="cmtLoginBtn">登录</button>
            </div>
            <div class="cmt-modal-form cmt-reg-form" id="cmtRegForm" style="display:none">
                <!-- v2.10.2：注册表单统一设计——账号信息 / 身份验证分组分层 -->
                <div class="cmt-sec-title"><span>账号信息</span></div>
                <!-- v4.4.0：注册蜜罐——CSS 隐藏输入框，真人看不见不会填；机器人自动填充即被后端静默拒绝 -->
                <input type="text" name="website" id="cmtRegHoneypot" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;opacity:0">
                <input class="cmt-modal-input" type="text" placeholder="QQ号" maxlength="15" id="cmtRegQQ">
                <input class="cmt-modal-input" type="text" placeholder="昵称" maxlength="20" id="cmtRegNick">
                <div class="cmt-pw-row">
                    <input class="cmt-modal-input cmt-pw-input" type="password" placeholder="密码（至少8位，含大小写与数字）" id="cmtRegPw">
                    <!-- v3.3.16：注册密码显示切换（与登录一致，线稿眼睛图标） -->
                    <button type="button" class="cmt-pw-toggle" id="cmtRegPwToggle" tabindex="-1" title="显示/隐藏密码" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
                </div>
                <div class="cmt-reg-verify" id="cmtRegVerifyBox" style="display:none">
                    <div class="cmt-sec-title"><span>身份验证</span></div>
                    <div class="cmt-reg-email-row">
                        <input class="cmt-modal-input" type="email" placeholder="邮箱（用于验证）" id="cmtRegEmail">
                        <button type="button" class="cmt-code-btn" id="cmtRegSendCode">获取验证码</button>
                    </div>
                    <input class="cmt-modal-input" type="text" placeholder="邮箱验证码（6位）" maxlength="6" id="cmtRegCode">
                </div>
                <div class="cmt-modal-err" id="cmtRegErr"></div>
                <button class="cmt-modal-submit" id="cmtRegBtn">注册</button>
            </div>
        </div>
        <div class="cmt-modal-switch"><span id="cmtSwitchText">还没有账号？</span><button class="cmt-modal-switch-link" id="cmtSwitchBtn">立即注册</button></div>
    </div>
</div>
<!-- v4.4.0：注册算术人机验证弹窗——点击「获取验证码」弹出随机加减乘除题，答对才发送邮箱验证码 -->
<div class="cmt-modal-mask" id="cmtArithModal">
    <div class="cmt-modal-box cmt-arith-box">
        <div class="cmt-modal-head"><div class="cmt-modal-title">人机验证</div></div>
        <div class="cmt-modal-body">
            <div class="cmt-modal-form">
                <div class="cmt-arith-question" id="cmtArithQuestion">…</div>
                <input class="cmt-modal-input" type="text" placeholder="计算结果" maxlength="6" id="cmtArithAnswer" autocomplete="off" inputmode="numeric">
                <div class="cmt-modal-err" id="cmtArithErr"></div>
                <div class="cmt-arith-actions">
                    <button class="cmt-modal-submit" id="cmtArithOk">验证</button>
                    <button type="button" class="cmt-arith-cancel" id="cmtArithCancel">取消</button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="cmt-modal-mask" id="cmtProfileModal">
    <div class="cmt-modal-box">
        <div class="cmt-modal-head"><div class="cmt-modal-title">编辑资料</div></div>
        <div class="cmt-modal-body">
            <div class="cmt-modal-form">
                <!-- v2.10.0：头像自定义（JPG/PNG/WEBP ≤2MB，选文件后立即上传） -->
                <div class="cmt-profile-avatar-row">
                    <div class="cmt-profile-avatar" id="cmtProfileAvatar"></div>
                    <div class="cmt-profile-avatar-side">
                        <label class="cmt-avatar-btn" for="cmtAvatarFile">上传头像</label>
                        <input type="file" id="cmtAvatarFile" accept="image/jpeg,image/png,image/webp" style="display:none">
                        <div class="cmt-profile-avatar-tip">JPG / PNG / WEBP，≤2MB</div>
                    </div>
                </div>
                <input class="cmt-modal-input" type="text" placeholder="昵称" maxlength="20" id="cmtEditNick">
                <input class="cmt-modal-input" type="text" placeholder="签名（选填，最多16字）" maxlength="16" id="cmtEditSign">
                <div class="cmt-modal-err" id="cmtProfileErr"></div>
                <button class="cmt-modal-submit" id="cmtProfileSave">保存</button>
                <!-- v2.10.0：邮箱绑定/更换（超管关闭邮箱验证后整体隐藏，基础资料编辑不受影响） -->
                <div class="cmt-profile-email" id="cmtProfileEmailWrap" style="display:none">
                    <div class="cmt-profile-sec-title">邮箱绑定</div>
                    <div class="cmt-modal-input-row">
                        <input class="cmt-modal-input" type="email" placeholder="输入新邮箱" id="cmtEditEmail">
                        <button type="button" class="cmt-code-btn" id="cmtEmailSendCode">获取验证码</button>
                    </div>
                    <input class="cmt-modal-input" type="text" placeholder="邮箱验证码（6位）" maxlength="6" id="cmtEditEmailCode">
                    <div class="cmt-modal-err" id="cmtEmailErr"></div>
                    <button class="cmt-modal-submit cmt-email-confirm" id="cmtEmailSave">确认绑定</button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="cmt-modal-mask" id="cmtAdminModal">
    <div class="cmt-modal-box">
        <div class="cmt-modal-head"><div class="cmt-modal-title">完善站长信息</div></div>
        <div class="cmt-modal-body">
            <div class="cmt-modal-form">
                <input class="cmt-modal-input" type="text" placeholder="设置QQ号" maxlength="15" id="cmtAdminQQ">
                <input class="cmt-modal-input" type="text" placeholder="设置昵称" maxlength="20" id="cmtAdminNick" value="站长">
                <input class="cmt-modal-input" type="password" placeholder="设置新密码（至少8位，含大小写与数字）" id="cmtAdminPw">
                <input class="cmt-modal-input" type="password" placeholder="确认新密码" id="cmtAdminPw2">
                <div class="cmt-modal-err" id="cmtAdminErr"></div>
                <button class="cmt-modal-submit" id="cmtAdminSave">保存并进入</button>
            </div>
        </div>
    </div>
</div>
<div class="cmt-confirm-overlay" id="cmtConfirmOverlay">
    <div class="cmt-confirm-box">
        <h3>确认删除</h3>
        <p>删除后不可恢复，确定要删除吗？</p>
        <div class="cmt-confirm-actions">
            <button class="cmt-confirm-cancel" id="cmtConfirmCancel">取消</button>
            <button class="cmt-confirm-ok" id="cmtConfirmOk">删除</button>
        </div>
    </div>
</div>
<script>window.YM_SITE_TITLE = <?= json_encode($_siteTitle, JSON_UNESCAPED_UNICODE) ?>;
// v3.1.6：公告卡片数据（服务端已转义标题/摘要，tags/cover 由文章提取）
window.YM_ANNOUNCEMENTS = <?= json_encode($_announcements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="js/main.js?v=<?= filemtime(__DIR__ . '/js/main.js') ?>" defer></script>
<script>
// 背景图片应用
(function() {
    var body = document.body;
    var bgType = body.getAttribute('data-bg-type');
    var bgImage = body.getAttribute('data-bg-image');
    var bgBlur = body.getAttribute('data-bg-blur') === '1';
    var bgBlurLevel = parseInt(body.getAttribute('data-bg-blur-level') || '0');
    var cardOpacity = parseInt(body.getAttribute('data-bg-card-opacity') || '100');
    if (bgType === 'image' && bgImage) {
        // v3.3.3：背景图相对路径补前导斜杠（与 js/main.js applyBg 一致），避免 /css/data/bg/.. 404
        var bgUrl = (bgImage.indexOf('/') === 0 || /^https?:/i.test(bgImage)) ? bgImage : '/' + bgImage;
        body.style.backgroundImage = 'url(' + bgUrl + ')';
        body.style.backgroundSize = 'cover';
        body.style.backgroundPosition = 'center';
        body.style.backgroundAttachment = 'fixed';
        body.style.backgroundRepeat = 'no-repeat';
        if (bgBlur) {
            body.style.setProperty('--bg', 'rgba(255,255,255,' + (cardOpacity/100/2) + ')');
        } else {
            body.style.setProperty('--bg', 'rgba(255,255,255,' + (cardOpacity/100) + ')');
        }
    }
})();
</script>
<?php if (isset($_GET['logged_out']) && $_GET['logged_out'] === '1'): ?>
<script>
// v2.10.0-fix：超管登出完成提示（复用 toast 组件样式）
document.addEventListener('DOMContentLoaded', function() {
    var t = document.getElementById('toast');
    if (t) {
        t.textContent = '已退出登录';
        t.classList.add('show');
        setTimeout(function() { t.classList.remove('show'); }, 2500);
    }
});
</script>
<?php endif; ?>
</body>
</html>
