<?php
session_start();
require_once __DIR__ . '/../utils.php';

if (!checkRole(ROLE_AUTHOR)) {
    logUnauthorized('越权尝试访问写作者后台');
    header('Location: /?admin_login=1');
    exit;
}

// v2.7.2：账号被删/吊销后会话立即失效（防已删账号凭残留 Session 继续访问后台）
if (!validateBackendUser()) {
    session_unset();
    session_destroy();
    header('Location: /?admin_login=1&expired=1');
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

// v2.6.3：写作者后台 tab 结构（articles 我的文章 / profile 个人信息）
$tab = $_GET['tab'] ?? 'articles';
if (!in_array($tab, ['articles', 'profile'], true)) $tab = 'articles';
$msg = $_GET['msg'] ?? '';

// v2.6.3：写作者修改个人信息（昵称/签名/新密码）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profile_save']) && !isset($_POST['logout'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = 'csrf_error';
    } else {
        $nick = trim($_POST['nickname'] ?? '');
        $sign = trim($_POST['signature'] ?? '');
        $newPw = $_POST['password'] ?? '';
        $newPw2 = $_POST['password2'] ?? '';
        if (empty($nick)) {
            $msg = 'nick_empty';
        } elseif ($newPw !== '' && validatePassword($newPw) !== true) {
            $msg = 'pw_weak';
        } elseif ($newPw !== $newPw2) {
            $msg = 'pw_mismatch';
        } else {
            $nick = mb_substr($nick, 0, 20, 'UTF-8');
            $sign = mb_substr($sign, 0, 16, 'UTF-8');
            $users = loadUsers();
            foreach ($users as &$usr) {
                if ($usr['id'] === $myId) {
                    $usr['nickname'] = $nick;
                    $usr['signature'] = $sign;
                    if ($newPw !== '') $usr['password'] = password_hash($newPw, PASSWORD_DEFAULT);
                    break;
                }
            }
            unset($usr);
            saveUsers($users);
            $_SESSION['cmt_user']['nickname'] = $nick;
            $_SESSION['cmt_user']['signature'] = $sign;
            if ($newPw !== '') $_SESSION['cmt_user']['pw_hash'] = password_hash($newPw, PASSWORD_DEFAULT);
            auditLog('profile_update', $myId, '写作者修改个人信息');
            $msg = 'profile_saved';
        }
    }
    header("Location: dashboard.php?msg={$msg}&tab={$tab}");
    exit;
}

// 写作者只能看自己的文章（解析文章 META 的 author_id 归属；兼容旧文章按作者昵称匹配）
$articlesDir = __DIR__ . '/../data/articles/';
$myArticles = [];
if (is_dir($articlesDir)) {
    $files = glob($articlesDir . '*.md');
    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        $meta = [];
        $title = preg_replace('/\.md$/i', '', basename($f));
        if ($raw) {
            if (preg_match('/<!--META(.*?)-->/s', $raw, $m)) {
                $meta = json_decode(trim($m[1]), true) ?: [];
            }
            if (preg_match('/^#\s+(.+)/m', $raw, $tm)) $title = $tm[1];
        }
        $belongs = ($meta['author_id'] ?? '') === $myId
            || (empty($meta['author_id']) && ($meta['author'] ?? '') === ($currentUser['nickname'] ?? ''));
        if (!$belongs) continue;
        $myArticles[] = [
            'id' => basename($f, '.md'),
            'file' => basename($f),
            'title' => $title,
            'created' => date('Y-m-d H:i', filemtime($f)),
            'author_id' => $meta['author_id'] ?? '',
        ];
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
        <a href="dashboard.php" class="sidebar-link <?= $tab==='articles'?'active':'' ?>">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            我的文章
        </a>
        <a href="dashboard.php?tab=profile" class="sidebar-link <?= $tab==='profile'?'active':'' ?>">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            个人信息
        </a>
        <!-- v2.6.5：与站长后台一致的「发表文章」入口 -->
        <a href="/sc.php" class="sidebar-link">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            发表文章
        </a>
        <a href="#" onclick="logoutSubmit(event)" class="sidebar-link danger">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            退出登录
        </a>
    </nav>
</div>

<div class="main">
    <?php if ($msg === 'profile_saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>个人信息已保存</div><?php endif; ?>
    <?php if ($msg === 'csrf_error'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>请求已过期，请重试</div><?php endif; ?>
    <?php if ($msg === 'nick_empty'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>昵称不能为空</div><?php endif; ?>
    <?php if ($msg === 'pw_weak'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>新密码至少 8 位，且需包含大写字母、小写字母与数字</div><?php endif; ?>
    <?php if ($msg === 'pw_mismatch'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>两次输入的密码不一致</div><?php endif; ?>

    <?php if ($tab === 'articles'): ?>
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
        <a href="/sc.php" class="btn btn-primary" style="margin-bottom:16px">+ 写新文章</a>
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
                    <a href="/sc.php?edit=<?= urlencode($a['file'] ?? '') ?>" class="btn-link" style="color:#f59e0b">编辑</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($tab === 'profile'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            个人信息
        </div>
        <div class="page-subtitle">修改你的账号昵称、签名与密码</div>
    </div>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            账号信息
        </div>
        <div class="table-wrap">
        <table>
            <tr><th style="width:120px">项目</th><th>内容</th></tr>
            <tr><td style="color:var(--text-muted)">登录账号（QQ）</td><td><code><?= htmlspecialchars($currentUser['qq'] ?? '') ?></code>（不可修改）</td></tr>
            <tr><td style="color:var(--text-muted)">角色</td><td>写作者</td></tr>
        </table>
        </div>
    </div>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            编辑资料
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="profile_save" value="1">
            <div class="form-group">
                <label class="form-label">昵称</label>
                <input class="form-input" name="nickname" value="<?= htmlspecialchars($currentUser['nickname'] ?? '') ?>" maxlength="20" placeholder="你的昵称">
            </div>
            <div class="form-group">
                <label class="form-label">签名（选填，最多 16 字）</label>
                <input class="form-input" name="signature" value="<?= htmlspecialchars($currentUser['signature'] ?? '') ?>" maxlength="16" placeholder="一句话介绍自己">
            </div>
            <div class="form-group">
                <label class="form-label">新密码（选填，至少 8 位且含大小写字母与数字；留空不修改）</label>
                <input class="form-input" name="password" type="password" placeholder="******">
            </div>
            <div class="form-group">
                <label class="form-label">确认新密码</label>
                <input class="form-input" name="password2" type="password" placeholder="******">
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px">
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}
// 登出走 POST + CSRF
function logoutSubmit(e) {
    e.preventDefault();
    var fd = new FormData();
    fd.append('logout', '1');
    fd.append('csrf_token', '<?= generateCsrfToken() ?>');
    fetch(window.location.href.split('?')[0], { method: 'POST', body: fd }).then(function() { location.href = '/'; });
}
</script>
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])): ?>
<?php
if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    auditLog('logout', $myId, '写作者登出');
    session_unset();
    session_destroy();
}
header('Location: /');
exit;
?>
<?php endif; ?>
</body>
</html>