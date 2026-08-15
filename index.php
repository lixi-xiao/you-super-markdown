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
    if ($files) {
        usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
        foreach ($files as $file) {
            $filename = basename($file);
            if (strpos($filename, '.') === 0) continue;
            $content = file_get_contents($file);
            // v3.1.10：META 标记 hidden 的文章不进首页列表（如「更新历史」仅保留公告入口）
            if (preg_match('/<!--META(.*?)-->/s', $content, $hm)) {
                $hmeta = json_decode(trim($hm[1]), true);
                if (!empty($hmeta['hidden'])) continue;
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
                    $tags = array_map('trim', explode(',', $meta['tags'] ?? ''));
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
            $fileList[] = [
                'name' => $filename, 'displayName' => $title, 'category' => $category,
                'size' => filesize($file), 'modified' => date('Y-m-d', filemtime($file)),
                'modifiedTimestamp' => filemtime($file), 'excerpt' => $excerpt,
                'wordCount' => $wordCount, 'tags' => $tags, 'author' => $author,
                'license' => $license, 'licenseUrl' => $licenseUrl, 'pinned' => $isPinned,
                'cover' => $cover,
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
    $content = file_get_contents($filepath);
    $displayName = preg_replace('/\.md$/i', '', $filename);
    if (preg_match('/<!--META(.*?)-->/s', $content, $metaMatch)) {
        $meta = json_decode(trim($metaMatch[1]), true);
        if ($meta && !empty($meta['title'])) {
            $displayName = $meta['title'];
        }
    }
    $content = preg_replace('/<!--META.*?-->\n?/s', '', $content);
    $contentWithoutCode = preg_replace('/```[\s\S]*?```/', '', $content);
    if ($displayName === preg_replace('/\.md$/i', '', $filename) && preg_match('/^#\s+(.+)/m', $contentWithoutCode, $tm)) $displayName = $tm[1];
    echo json_encode([
        'success' => true, 'name' => $filename,
        'displayName' => $displayName,
        'content' => $content, 'size' => filesize($filepath),
        'modified' => date('Y-m-d H:i', filemtime($filepath))
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
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
    <title><?= htmlspecialchars($_siteTitle) ?></title>
    <!-- v3.1.4：链接解析/分享预览卡片使用自定义站名（微信/QQ/Telegram 等读取 og 标签） -->
    <meta property="og:title" content="<?= htmlspecialchars($_siteTitle) ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars($_siteTitle) ?>">
    <meta property="og:type" content="website">
    <meta property="og:description" content="一个基于PHP语言开发的轻量、优雅、简洁的 Markdown 在线阅读器">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📝</text></svg>" type="image/svg+xml">
    <meta name="description" content="一个基于PHP语言开发的轻量、优雅、简洁的 Markdown 在线阅读器">
    <script src="https://cdn.jsdelivr.net/npm/marked@4.3.0/marked.min.js" crossorigin="anonymous"
    integrity="sha384-QsSpx6a0USazT7nK7w8qXDgpSAPhFsb2XtpoLFQ5+X2yFN6hvCKnwEzN8M5FWaJb"
    ></script>
    <!-- v3.2.5：mermaid 流程图渲染（文章 markdown 中的 ```mermaid 代码块自动绘制） -->
    <script src="https://cdn.jsdelivr.net/npm/mermaid@10.9.3/dist/mermaid.min.js" crossorigin="anonymous"
    integrity="sha384-R63zfMfSwJF4xCR11wXii+QUsbiBIdiDzDbtxia72oGWfkT7WHJfmD/I/eeHPJyT"
    ></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.9.0/build/styles/atom-one-light.min.css" id="hljsTheme">
    <script src="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.9.0/build/highlight.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
</head>
<body data-guest-comments="<?= !empty($_siteConfig['guest_comments_enabled']) ? '1' : '0' ?>" data-reg-verify="<?= !empty($_siteConfig['email_verify_enabled']) ? '1' : '0' ?>" data-email-change="<?= !empty($_siteConfig['email_verify_enabled']) ? '1' : '0' ?>" data-csrf="<?= htmlspecialchars(generateCsrfToken()) ?>" data-bg-type="<?= htmlspecialchars($_siteConfig['bg_type'] ?? 'none') ?>" data-bg-image="<?= htmlspecialchars($_siteConfig['bg_image'] ?? '') ?>" data-bg-api-url="<?= htmlspecialchars($_siteConfig['bg_api_url'] ?? '') ?>" data-bg-blur="<?= !empty($_siteConfig['bg_blur_enabled']) ? '1' : '0' ?>" data-bg-blur-level="<?= intval($_siteConfig['bg_blur_level'] ?? 0) ?>" data-bg-card-opacity="<?= intval($_siteConfig['bg_card_opacity'] ?? 100) ?>" data-music-playlist="<?= htmlspecialchars($_siteConfig['music_playlist_id'] ?? '3778678') ?>" data-music-playlist-qq="<?= htmlspecialchars($_siteConfig['music_playlist_id_qq'] ?? '') ?>">
<header class="top-bar" id="topBar">
    <div class="header-left"><a href="./" class="brand" style="text-decoration:none;cursor:pointer;"><?= htmlspecialchars($_siteTitle) ?></a></div>
    <div class="header-right">
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
    <div id="homeView"><div class="category-bar" id="categoryBar"></div><!-- v3.1.6：公告卡片区块（有公告才显示） --><div class="announcement-section" id="announcementSection"></div><div class="cards-grid" id="cardsGrid"></div><div class="empty-state" id="emptyHome" style="display:none;">📭 暂无文档</div></div>
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
    <?php if ($musicEnabled): ?>
    <button class="float-btn" id="floatMusicBtn" title="音乐"><svg viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></button>
    <?php endif; ?>
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
        <div class="ann-modal-content"></div>
        <button class="ann-modal-link" id="announceModalLink">查看文章 →</button>
    </div>
</div>
<div class="toc-popup" id="tocPopup"><div class="toc-popup-header"><div class="toc-popup-title">目录</div></div><div class="toc-popup-list" id="tocPopupList"></div></div>
<div class="music-popup" id="musicPopup">
    <button class="music-lyric-toggle" id="musicLyrToggle" title="歌词">词</button>
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
            <div class="music-platform-tabs" id="musicPlatformTabs">
                <span class="music-platform-tab active" data-platform="netease">网易云</span>
                <span class="music-platform-tab" data-platform="qq">QQ</span>
                
            </div>
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
                    <button type="button" class="cmt-pw-toggle" id="cmtLoginPwToggle" tabindex="-1" title="显示/隐藏密码">👁</button>
                </div>
                <div class="cmt-modal-err" id="cmtLoginErr"></div>
                <button class="cmt-modal-submit" id="cmtLoginBtn">登录</button>
            </div>
            <div class="cmt-modal-form cmt-reg-form" id="cmtRegForm" style="display:none">
                <!-- v2.10.2：注册表单统一设计——账号信息 / 身份验证分组分层 -->
                <div class="cmt-sec-title"><span>账号信息</span></div>
                <input class="cmt-modal-input" type="text" placeholder="QQ号" maxlength="15" id="cmtRegQQ">
                <input class="cmt-modal-input" type="text" placeholder="昵称" maxlength="20" id="cmtRegNick">
                <input class="cmt-modal-input" type="password" placeholder="密码（至少8位，含大小写与数字）" id="cmtRegPw">
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
<script src="js/main.js?v=<?= filemtime(__DIR__ . '/js/main.js') ?>"></script>
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
        body.style.backgroundImage = 'url(' + bgImage + ')';
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
