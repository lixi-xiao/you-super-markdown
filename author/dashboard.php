<?php
session_start();
require_once __DIR__ . '/../utils.php';

if (!checkRole(ROLE_AUTHOR)) {
    logUnauthorized('越权尝试访问写作者后台');
    header('Location: /?admin_login=1');
    exit;
}

// 自定义入口路径验证：hide_default_paths 开启时，拒绝通过默认路径访问
$config = loadSiteConfig();
if (!empty($config['hide_default_paths'])) {
    $customPath = $config['author_path'] ?? 'author';
    $reqUri = $_SERVER['REQUEST_URI'] ?? '';
    $reqPath = parse_url($reqUri, PHP_URL_PATH);
    $firstSeg = explode('/', trim($reqPath, '/'))[0] ?? '';
    if ($customPath !== 'author' && $firstSeg !== $customPath) {
        http_response_code(404);
        exit('Not Found');
    }
}

$config = loadSiteConfig();
$siteTitle = $config['site_title'] ?? 'You Markdown';
$currentUser = $_SESSION['cmt_user'] ?? [];
$myId = $currentUser['id'] ?? '';

// 写作者只能看自己的文章
$articlesDir = __DIR__ . '/../data/articles/';
$myArticles = [];
if (is_dir($articlesDir)) {
    $files = glob($articlesDir . '*.json');
    foreach ($files as $f) {
        $article = json_decode(file_get_contents($f), true);
        if ($article && ($article['author_id'] ?? '') === $myId) {
            $article['file'] = basename($f);
            $myArticles[] = $article;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-admin="author">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>写作者后台 - <?= htmlspecialchars($siteTitle) ?></title>
<link rel="stylesheet" href="../css/admin.css">
</head>
<body>

<button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleSidebar()">
    <svg viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
</button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        <div class="sidebar-title"><span>写作者</span>后台</div>
        <div class="sidebar-user">
            <div class="sidebar-user-avatar"><?php if (!empty($currentUser['qq'])): ?><img src="https://q1.qlogo.cn/g?b=qq&nk=<?= urlencode($currentUser['qq']) ?>&s=100" alt="avatar"><?php else: ?><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><?php endif; ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?= htmlspecialchars($currentUser['nickname'] ?? '写作者') ?></div>
                <div class="sidebar-user-role">写作者</div>
            </div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="sidebar-link active">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            我的文章
        </a>
        <a href="?logout=1" class="sidebar-link danger">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            退出登录
        </a>
    </nav>
</div>

<div class="main">
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            我的文章
        </div>
        <div class="page-subtitle">管理你的创作内容</div>
    </div>

    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            文章列表（<?= count($myArticles) ?> 篇）
        </div>
        <a href="/" class="btn btn-primary" style="margin-bottom:16px">+ 写新文章</a>
        <?php if (empty($myArticles)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <p>暂无文章，点击上方按钮创建你的第一篇</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th>标题</th><th>创建时间</th><th>操作</th></tr>
            <?php foreach ($myArticles as $a): ?>
            <tr>
                <td><a class="inline-link" href="/?article=<?= urlencode($a['id'] ?? '') ?>"><?= htmlspecialchars($a['title'] ?? '无标题') ?></a></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($a['created'] ?? '') ?></td>
                <td>
                    <a href="/?article=<?= urlencode($a['id'] ?? '') ?>&edit=1" class="btn-link" style="color:#f59e0b">编辑</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}
</script>
<?php if (isset($_GET['logout'])): ?>
<?php auditLog('logout', $myId, '写作者登出'); session_unset(); session_destroy(); header('Location: /'); exit; ?>
<?php endif; ?>
</body>
</html>