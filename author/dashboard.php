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

// v2.6.3：写作者修改个人信息（昵称/签名/新密码）；v2.10.0：扩展头像上传 + 邮箱更换
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['logout'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = 'csrf_error';
    } elseif (isset($_POST['profile_save'])) {
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
    } elseif (isset($_POST['email_change'])) {
        // v2.10.0：更换绑定邮箱（受 email_verify_enabled 开关控制，后台关闭则表单不渲染）
        $cfg = loadSiteConfig();
        if (empty($cfg['email_verify_enabled'])) {
            $msg = 'email_disabled';
        } else {
            $newEmail = trim($_POST['email_new'] ?? '');
            $code = trim($_POST['email_code'] ?? '');
            if (!email_valid($newEmail)) {
                $msg = 'email_invalid';
            } elseif (email_exists($newEmail)) {
                $msg = 'email_taken';
            } else {
                [$ok, $verr] = email_code_verify($newEmail, $code, 'email_change');
                if (!$ok) {
                    $msg = 'email_code_bad';
                } else {
                    $users = loadUsers();
                    foreach ($users as &$usr) {
                        if ($usr['id'] === $myId) { $usr['email'] = $newEmail; break; }
                    }
                    unset($usr);
                    saveUsers($users);
                    $_SESSION['cmt_user']['email'] = $newEmail;
                    auditLog('email_change', $myId, '写作者更换绑定邮箱为 ' . $newEmail);
                    $msg = 'email_saved';
                }
            }
        }
    } elseif (isset($_POST['avatar_upload'])) {
        // v2.10.0：头像上传（JPG/PNG/WEBP ≤2MB）
        [$ok, $res] = avatar_upload($myId, $_FILES['avatar'] ?? null);
        if ($ok) {
            auditLog('avatar_update', $myId, '写作者更新头像');
            $msg = 'avatar_saved';
        } else {
            $msg = 'avatar_fail';
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
        <div class="page-subtitle">修改你的账号头像、昵称、签名、密码与绑定邮箱</div>
    </div>
    <?php if ($msg === 'profile_saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>个人信息已保存</div><?php endif; ?>
    <?php if ($msg === 'avatar_saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>头像已更新</div><?php endif; ?>
    <?php if ($msg === 'avatar_fail'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>头像上传失败（仅支持 JPG/PNG/WEBP，≤2MB，且 data/avatars/ 需可写）</div><?php endif; ?>
    <?php if ($msg === 'email_saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>绑定邮箱已更新</div><?php endif; ?>
    <?php if ($msg === 'email_invalid'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>邮箱格式不正确</div><?php endif; ?>
    <?php if ($msg === 'email_taken'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>该邮箱已被其他账号使用</div><?php endif; ?>
    <?php if ($msg === 'email_code_bad'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>验证码不正确或已过期</div><?php endif; ?>
    <?php if ($msg === 'email_disabled'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>邮箱验证已关闭，无法更换邮箱</div><?php endif; ?>
    <?php if ($msg === 'csrf_error'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>请求已过期，请重试</div><?php endif; ?>
    <?php $myAvatar = $currentUser['avatar'] ?? '';
          $avatarSrc = ($myAvatar !== '' && strpos($myAvatar, 'data/') === 0) ? '../' . $myAvatar : ($myAvatar !== '' ? $myAvatar : '../api.php?action=avatar&qq=' . urlencode($currentUser['qq'] ?? '')); ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            账号信息
        </div>
        <div class="table-wrap">
        <table>
            <tr><th style="width:120px">项目</th><th>内容</th></tr>
            <tr><td style="color:var(--text-muted)">登录账号（QQ）</td><td><code><?= htmlspecialchars($currentUser['qq'] ?? '') ?></code>（不可修改）</td></tr>
            <tr><td style="color:var(--text-muted)">绑定邮箱</td><td><?= htmlspecialchars($currentUser['email'] ?? '未绑定') ?></td></tr>
            <tr><td style="color:var(--text-muted)">角色</td><td>写作者</td></tr>
        </table>
        </div>
    </div>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            头像
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="avatar_upload" value="1">
            <div class="dash-avatar-row">
                <div class="dash-avatar"><img src="<?= htmlspecialchars($avatarSrc) ?>" alt="" onerror="this.style.display='none'"></div>
                <div style="flex:1">
                    <div class="form-group">
                        <input class="form-input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp">
                    </div>
                    <div class="form-hint">支持 JPG / PNG / WEBP，≤2MB；上传后立即生效</div>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px">
                <button type="submit" class="btn btn-primary">上传头像</button>
            </div>
        </form>
    </div>
    <?php if (!empty($config['email_verify_enabled'])): ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            更换绑定邮箱
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="email_change" value="1">
            <div class="form-group">
                <label class="form-label">新邮箱地址</label>
                <div class="form-row">
                    <input class="form-input" type="email" name="email_new" placeholder="输入新的邮箱地址">
                    <button type="button" class="btn" id="dashEmailSend">获取验证码</button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">邮箱验证码（6位）</label>
                <input class="form-input" type="text" name="email_code" maxlength="6" placeholder="输入邮件中的验证码">
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px">
                <button type="submit" class="btn btn-primary">确认更换</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
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
// v2.10.0：更换绑定邮箱——发送验证码（60s 倒计时）
(function() {
    var sendBtn = document.getElementById('dashEmailSend');
    if (!sendBtn) return;
    sendBtn.addEventListener('click', function() {
        var emailInput = document.querySelector('input[name=email_new]');
        if (!emailInput) return;
        var email = emailInput.value.trim();
        var re = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        if (!re.test(email)) { alert('请输入正确的邮箱'); return; }
        var csrfEl = document.querySelector('input[name=csrf_token]');
        var csrf = csrfEl ? csrfEl.value : '';
        sendBtn.disabled = true;
        fetch('../api.php?action=send_email_change_code', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ email: email })
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) {
                var left = 60;
                sendBtn.textContent = left + 's 后重发';
                var t = setInterval(function() {
                    left--;
                    if (left <= 0) { clearInterval(t); sendBtn.textContent = '获取验证码'; sendBtn.disabled = false; }
                    else sendBtn.textContent = left + 's 后重发';
                }, 1000);
            } else {
                alert(d.error || '发送失败');
                sendBtn.disabled = false;
            }
        }).catch(function() { alert('网络错误'); sendBtn.disabled = false; });
    });
})();
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