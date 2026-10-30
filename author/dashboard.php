<?php
require_once __DIR__ . '/../utils.php';
// v6.1.0：服务端输出层 i18n
secureSessionStart();

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

// v4.6.0：后台会话 24 小时显式过期。
// v5.3.1：过期只失效**后台会话标记**并回退首页（可读提示），**绝不清前台登录态**（cmt_user / refresh 保持）；
//         前台登录态不得用于直接进后台——重新进入须重新验证身份（本页下一段环境校验同样保留）。
if (backendSessionExpired()) {
    clearBackendSession();
    header('Location: /?admin_login=1&expired=1');
    exit;
}

// v4.5.0：升级即全量重登 + 并发踢旧——旧会话（无 cmt_fp/cmt_tv）或 token_version 不匹配（被新登录踢出）→ 强制重登
if (empty($_SESSION['cmt_fp']) || !isset($_SESSION['cmt_tv']) || (int)$_SESSION['cmt_tv'] !== getUserTV($_SESSION['cmt_user']['id'] ?? '')) {
    // v6.3.6：有指纹但 tv 不一致 = 账号在别处重新登录被踢，不能再报「登录环境已变化」
    $__kicked = !empty($_SESSION['cmt_fp']) && isset($_SESSION['cmt_tv'])
        && (int)$_SESSION['cmt_tv'] !== getUserTV($_SESSION['cmt_user']['id'] ?? '');
    session_unset();
    session_destroy();
    header('Location: /?admin_login=1&' . ($__kicked ? 'kicked=1' : 'env=1'));
    exit;
}

// v4.5.0：后台写操作环境校验（JS 上报校验模式，见 fp_report 接口）
function requireAdminEnv() {
    if (!empty($_SESSION['cmt_fp_ok'])) return true;
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => '登录环境已变化，请重新登录', 'env_invalid' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: /?admin_login=1&env=1');
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
$siteTitle = $config['site_title'] ?? 'You Super Markdown';
$currentUser = $_SESSION['cmt_user'] ?? [];
$myId = $currentUser['id'] ?? '';

// v2.6.3：写作者后台 tab 结构（articles 我的文章 / profile 个人信息）
$tab = $_GET['tab'] ?? 'articles';
if (!in_array($tab, ['articles', 'profile'], true)) $tab = 'articles';
$msg = $_GET['msg'] ?? '';

// v2.6.3：写作者修改个人信息（昵称/签名/新密码）；v2.10.0：扩展头像上传 + 邮箱更换
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['logout'])) {
    // v4.5.0：后台写操作环境校验（登录环境变化/被踢 → 拒绝并强制重登）
    requireAdminEnv();
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
            $users = fetchAllUsers();
            foreach ($users as &$usr) {
                if ($usr['id'] === $myId) {
                    $usr['nickname'] = $nick;
                    $usr['signature'] = $sign;
                    if ($newPw !== '') $usr['password'] = password_hash($newPw, PASSWORD_DEFAULT);
                    break;
                }
            }
            unset($usr);
            replaceAllUsers($users);
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
                    $users = fetchAllUsers();
                    foreach ($users as &$usr) {
                        if ($usr['id'] === $myId) { $usr['email'] = $newEmail; break; }
                    }
                    unset($usr);
                    replaceAllUsers($users);
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
<html lang="<?= ysmLocale() ?>" data-admin="author">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('admin.605') ?> - <?= htmlspecialchars($siteTitle) ?></title>
<link rel="stylesheet" href="../css/admin.css?v=<?= @filemtime(__DIR__ . '/../css/admin.css') ?>">
<!-- v6.1.0：后台 JS 动态文案（弹窗/提示/异步渲染）运行时翻译 -->
<script>window.I18N = <?= json_encode(ysmClientI18n('admin'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="../js/i18n.js?v=<?= @filemtime(__DIR__ . '/../js/i18n.js') ?>"></script>
<!-- v6.3.6：统一环境指纹（含 api.php 请求自动注入 X-Fp/X-FpS） -->
<script src="../js/fp.js?v=<?= @filemtime(__DIR__ . '/../js/fp.js') ?>"></script>
</head>
<body>

<!-- v3.1.5：移动端顶栏（菜单按钮融入顶栏，与后台统一视觉） -->
<div class="mobile-topbar" id="mobileTopbar">
    <button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleSidebar()" aria-label="<?= t('author.001') ?>">
        <svg viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="mobile-topbar-title"><?= t('author.002') ?></div>
    <div class="mobile-topbar-user">
        <span class="mobile-topbar-name"><?= htmlspecialchars($currentUser['nickname'] ?? t('admin.557')) ?></span>
    </div>
</div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        <div class="sidebar-title"><span><?= t('author.003') ?></span><?= t('author.004') ?></div>
        <div class="sidebar-user">
            <div class="sidebar-user-avatar"><?php if (!empty($currentUser['account'])): ?><img src="<?= htmlspecialchars(ysmAvatarUrl($currentUser['username'] ?? ($currentUser['account'] ?? ''), 100)) ?>" alt="avatar"><?php else: ?><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><?php endif; ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?= htmlspecialchars($currentUser['nickname'] ?? t('admin.354')) ?></div>
                <div class="sidebar-user-role"><?= t('author.003') ?></div>
            </div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="sidebar-link <?= $tab==='articles'?'active':'' ?>">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <?= t('admin.505') ?>
        </a>
        <a href="dashboard.php?tab=profile" class="sidebar-link <?= $tab==='profile'?'active':'' ?>">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?= t('admin.502') ?>
        </a>
        <!-- v2.6.5：与站长后台一致的「发表文章」入口 -->
        <a href="/sc.php" class="sidebar-link">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <?= t('admin.510') ?>
        </a>
        <!-- v2.11.4：快捷返回主界面 -->
        <a href="/" class="sidebar-link">
            <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <?= t('admin.503') ?>
        </a>
        <a href="#" onclick="bindLogoutSubmit(event)" class="sidebar-link danger">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <?= t('admin.480') ?>
        </a>
    </nav>
</div>

<div class="main">
    <?php if ($msg === 'profile_saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('station.172') ?></div><?php endif; ?>
    <?php if ($msg === 'csrf_error'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('admin.447') ?></div><?php endif; ?>
    <?php if ($msg === 'nick_empty'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('api.951') ?></div><?php endif; ?>
    <?php if ($msg === 'pw_weak'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('author.033') ?></div><?php endif; ?>
    <?php if ($msg === 'pw_mismatch'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('author.034') ?></div><?php endif; ?>

    <?php if ($tab === 'articles'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <?= t('admin.505') ?>
        </div>
        <div class="page-subtitle"><?= t('author.005') ?></div>
    </div>

    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <?= t('admin.599', ['n' => count($myArticles)]) ?>
        </div>
        <a href="/sc.php" class="btn btn-primary" style="margin-bottom:16px"><?= t('author.006') ?></a>
        <?php if (empty($myArticles)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <p><?= t('author.007') ?></p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th><?= t('author.008') ?></th><th><?= t('author.009') ?></th><th><?= t('author.010') ?></th></tr>
            <?php foreach ($myArticles as $a): ?>
            <tr>
                <td><a class="inline-link" href="/?article=<?= urlencode($a['id'] ?? '') ?>"><?= htmlspecialchars($a['title'] ?? t('author.035')) ?></a></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($a['created'] ?? '') ?></td>
                <td>
                    <a href="/sc.php?edit=<?= urlencode($a['file'] ?? '') ?>" class="btn-link" style="color:#f59e0b"><?= t('links.011') ?></a>
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
            <?= t('admin.502') ?>
        </div>
        <div class="page-subtitle"><?= t('author.011') ?></div>
    </div>
    <?php if ($msg === 'profile_saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('station.172') ?></div><?php endif; ?>
    <?php if ($msg === 'avatar_saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('js.124') ?></div><?php endif; ?>
    <?php if ($msg === 'avatar_fail'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('station.173') ?></div><?php endif; ?>
    <?php if ($msg === 'email_saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('station.174') ?></div><?php endif; ?>
    <?php if ($msg === 'email_invalid'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('api.907') ?></div><?php endif; ?>
    <?php if ($msg === 'email_taken'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('api.911') ?></div><?php endif; ?>
    <?php if ($msg === 'email_code_bad'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('api.938') ?></div><?php endif; ?>
    <?php if ($msg === 'email_disabled'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('station.175') ?></div><?php endif; ?>
    <?php if ($msg === 'csrf_error'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('admin.447') ?></div><?php endif; ?>
    <?php $myAvatar = $currentUser['avatar'] ?? '';
          $avatarSrc = ($myAvatar !== '' && strpos($myAvatar, 'data/') === 0) ? '../' . $myAvatar : ($myAvatar !== '' ? $myAvatar : '../api.php?action=avatar&account=' . urlencode($currentUser['account'] ?? '')); ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?= t('admin.501') ?>
        </div>
        <div class="table-wrap">
        <table>
            <tr><th style="width:120px"><?= t('author.012') ?></th><th><?= t('author.013') ?></th></tr>
            <tr><td style="color:var(--text-muted)"><?= t('station.016') ?></td><td><code><?= htmlspecialchars($currentUser['account'] ?? '') ?></code><?= t('station.176') ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('js.102') ?></td><td><?= htmlspecialchars($currentUser['email'] ?? '未绑定') ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('author.014') ?></td><td><?= t('author.003') ?></td></tr>
        </table>
        </div>
    </div>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            <?= t('admin.511') ?>
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
                    <div class="form-hint"><?= t('author.015') ?></div>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px">
                <button type="submit" class="btn btn-primary"><?= t('author.016') ?></button>
            </div>
        </form>
    </div>
    <?php if (!empty($config['email_verify_enabled'])): ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <?= t('admin.499') ?>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="email_change" value="1">
            <div class="form-group">
                <label class="form-label"><?= t('author.017') ?></label>
                <div class="form-row">
                    <input class="form-input" type="email" name="email_new" placeholder="<?= t('author.018') ?>">
                    <button type="button" class="btn" id="dashEmailSend"><?= t('author.019') ?></button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('author.020') ?></label>
                <input class="form-input" type="text" name="email_code" maxlength="6" placeholder="<?= t('author.021') ?>">
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px">
                <button type="submit" class="btn btn-primary"><?= t('author.022') ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            <?= t('admin.500') ?>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="profile_save" value="1">
            <div class="form-group">
                <label class="form-label"><?= t('author.023') ?></label>
                <input class="form-input" name="nickname" value="<?= htmlspecialchars($currentUser['nickname'] ?? '') ?>" maxlength="20" placeholder="<?= t('station.177') ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('author.024') ?></label>
                <input class="form-input" name="signature" value="<?= htmlspecialchars($currentUser['signature'] ?? '') ?>" maxlength="16" placeholder="<?= t('station.178') ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('author.025') ?></label>
                <input class="form-input" name="password" type="password" placeholder="******">
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('author.026') ?></label>
                <input class="form-input" name="password2" type="password" placeholder="******">
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px">
                <button type="submit" class="btn btn-primary"><?= t('author.027') ?></button>
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
function bindLogoutSubmit(e) {
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
        if (!re.test(email)) { alert(TK('author.028')); return; }
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
                sendBtn.textContent = TK('author.029').replace('{n}', left);
                var t = setInterval(function() {
                    left--;
                    if (left <= 0) { clearInterval(t); sendBtn.textContent = TK('author.019'); sendBtn.disabled = false; }
                    else sendBtn.textContent = TK('author.029').replace('{n}', left);
                }, 1000);
            } else {
                alert(d.error || TK('author.030'));
                sendBtn.disabled = false;
            }
        }).catch(function() { alert(TK('author.031')); sendBtn.disabled = false; });
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
<script>
// v6.3.6：环境指纹上报——完整指纹 + 稳定指纹（X-FpS）；失败重试 1 次，仍失败才提示
(function() {
    function fpFnv(s) { var h = 0x811c9dc5; for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 0x01000193); } return ('00000000' + (h >>> 0).toString(16)).slice(-8); }
    function fpCanvas() { try { var c = document.createElement('canvas'); c.width = 200; c.height = 40; var x = c.getContext('2d'); x.textBaseline = 'top'; x.font = '14px Arial'; x.fillStyle = '#f60'; x.fillRect(0, 0, 200, 40); x.fillStyle = '#069'; x.fillText('YouSuperMarkdown\u2620' + navigator.userAgent.length, 5, 12); var d = c.toDataURL(); return d.length + ':' + d.slice(-64); } catch (e) { return ''; } }
    function fullFp() {
        if (typeof window.ysmGetFp === 'function') return window.ysmGetFp();
        var parts = [navigator.language || '', new Date().getTimezoneOffset(), (screen.width || 0) + 'x' + (screen.height || 0), fpCanvas(), navigator.userAgent];
        return fpFnv(parts.join('|')) + fpFnv(parts.join('~')) + fpFnv(navigator.userAgent);
    }
    function stableFp() {
        if (typeof window.ysmGetStableFp === 'function') return window.ysmGetStableFp();
        var b = Math.round((new Date()).getTimezoneOffset() / 120) * 120;
        var s = [navigator.language || '', String(b), navigator.userAgent];
        return fpFnv(s.join('|')) + fpFnv(s.join('~'));
    }
    var fp = fullFp(), sfp = stableFp(), tries = 0;
    function report() {
        fetch('/api.php?action=fp_report', { method: 'POST', headers: { 'X-Fp': fp, 'X-FpS': sfp, 'X-CSRF-Token': '<?= generateCsrfToken() ?>' } })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res && res.success) return;
                if (tries++ < 1) { setTimeout(report, 800); return; }
                var _b = document.createElement('div');
                _b.style.cssText = 'position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:99999;background:#dc2626;color:#fff;padding:10px 18px;border-radius:10px;font-size:13px;box-shadow:0 4px 16px rgba(0,0,0,.25);max-width:92vw';
                _b.textContent = TK('author.032');
                document.body.appendChild(_b);
                setTimeout(function() { if (_b.parentNode) _b.parentNode.removeChild(_b); }, 7000);
            })
            .catch(function() { if (tries++ < 1) setTimeout(report, 800); });
    }
    report();
})();
</script>
</body>
</html>