<?php
session_start();
require_once __DIR__ . '/../utils.php';

if (!checkRole(ROLE_STATION_ADMIN)) {
    logUnauthorized('越权尝试访问站长后台');
    header('Location: /?admin_login=1');
    exit;
}

// 自定义入口路径验证：hide_default_paths 开启时，拒绝通过默认路径访问
$config = loadSiteConfig();
if (!empty($config['hide_default_paths'])) {
    $customPath = $config['station_path'] ?? 'station';
    $reqUri = $_SERVER['REQUEST_URI'] ?? '';
    $reqPath = parse_url($reqUri, PHP_URL_PATH);
    $firstSeg = explode('/', trim($reqPath, '/'))[0] ?? '';
    if ($customPath !== 'station' && $firstSeg !== $customPath) {
        http_response_code(404);
        exit('Not Found');
    }
}

$users = loadUsers();
$config = loadSiteConfig();
$siteTitle = $config['site_title'] ?? 'You Markdown';
$currentUser = $_SESSION['cmt_user'] ?? [];
$myId = $currentUser['id'] ?? '';
$msg = $_GET['msg'] ?? '';

// 站长只能管理自己的写作者
$myAuthors = array_filter($users, fn($u) => ($u['role'] ?? '') === ROLE_AUTHOR && ($u['station_id'] ?? '') === $myId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = 'csrf_error';
    } elseif ($_POST['action'] === 'create_author') {
        $newNick = trim($_POST['nickname'] ?? '');
        $newQQ = trim($_POST['qq'] ?? '');
        $newPwd = trim($_POST['password'] ?? '');
        if ($newNick && $newQQ && $newPwd) {
            // QQ 唯一性检查（避免同 QQ 账号登录歧义）
            $qqExists = false;
            foreach ($users as $uu) { if (($uu['qq'] ?? '') === $newQQ) { $qqExists = true; break; } }
            if ($qqExists) {
                $msg = 'qq_duplicate';
            } else {
                $users[] = [
                    'id' => bin2hex(random_bytes(8)),
                    'qq' => $newQQ,
                    'nickname' => $newNick,
                    'password' => password_hash($newPwd, PASSWORD_DEFAULT),
                    'role' => ROLE_AUTHOR,
                    'station_id' => $myId,
                    'created' => date('Y-m-d H:i:s'),
                    'created_by' => $myId,
                ];
                file_put_contents(__DIR__ . '/../data/.users.json', json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
                auditLog('author_create', $newQQ, "站长创建写作者: {$newNick}");
                $msg = 'author_created';
            }
        }
    } elseif ($_POST['action'] === 'delete_author') {
        $delId = $_POST['user_id'] ?? '';
        foreach ($users as $i => $u) {
            if ($u['id'] === $delId && ($u['role'] ?? '') === ROLE_AUTHOR && ($u['station_id'] ?? '') === $myId) {
                auditLog('author_delete', $u['qq'] ?? $delId, "站长删除写作者: {$u['nickname']}");
                array_splice($users, $i, 1);
                file_put_contents(__DIR__ . '/../data/.users.json', json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
                $msg = 'author_deleted';
                break;
            }
        }
    }
    header("Location: dashboard.php?msg={$msg}");
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-admin="station">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>站长后台 - <?= htmlspecialchars($siteTitle) ?></title>
<link rel="stylesheet" href="../css/admin.css">
</head>
<body>

<button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleSidebar()">
    <svg viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
</button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        <div class="sidebar-title"><span>站长</span>后台</div>
        <div class="sidebar-user">
            <div class="sidebar-user-avatar"><?php if (!empty($currentUser['qq'])): ?><img src="https://q1.qlogo.cn/g?b=qq&nk=<?= urlencode($currentUser['qq']) ?>&s=100" alt="avatar"><?php else: ?><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><?php endif; ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?= htmlspecialchars($currentUser['nickname'] ?? '站长') ?></div>
                <div class="sidebar-user-role">站长</div>
            </div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="sidebar-link active">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            写作者管理
        </a>
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
    <?php if ($msg === 'author_created'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>写作者已创建</div><?php endif; ?>
    <?php if ($msg === 'author_deleted'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>写作者已删除</div><?php endif; ?>
    <?php if ($msg === 'qq_duplicate'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>该账号已存在，请更换</div><?php endif; ?>

    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            写作者管理
        </div>
        <div class="page-subtitle">管理你的写作者团队</div>
    </div>

    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
            创建写作者
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_author">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">昵称</label>
                    <input class="form-input" name="nickname" placeholder="写作者昵称">
                </div>
                <div class="form-group">
                    <label class="form-label">QQ号</label>
                    <input class="form-input" name="qq" placeholder="登录账号">
                </div>
                <div class="form-group">
                    <label class="form-label">密码</label>
                    <input class="form-input" name="password" type="password" placeholder="******">
                </div>
                <div class="form-group" style="flex:0">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">创建</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            我的写作者（<?= count($myAuthors) ?> 人）
        </div>
        <?php if (empty($myAuthors)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            <p>暂无写作者，在上方创建第一个</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th>昵称</th><th>QQ</th><th>创建时间</th><th>操作</th></tr>
            <?php foreach ($myAuthors as $a): ?>
            <tr>
                <td><?= htmlspecialchars($a['nickname'] ?? '') ?></td>
                <td style="color:var(--text-muted)"><?= htmlspecialchars($a['qq'] ?? '') ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($a['created'] ?? '') ?></td>
                <td>
                    <form method="post" style="display:inline" onsubmit="return confirm('确定删除 <?= htmlspecialchars($a['nickname'] ?? '') ?>？')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                        <input type="hidden" name="action" value="delete_author">
                        <input type="hidden" name="user_id" value="<?= htmlspecialchars($a['id']) ?>">
                        <button type="submit" class="btn-link danger">删除</button>
                    </form>
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
    auditLog('logout', $myId, '站长登出');
    session_unset();
    session_destroy();
}
header('Location: /');
exit;
?>
<?php endif; ?>
</body>
</html>