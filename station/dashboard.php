<?php
session_start();
require_once __DIR__ . '/../utils.php';

if (!checkRole(ROLE_STATION_ADMIN)) {
    logUnauthorized('越权尝试访问站长后台');
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
// v2.6.0 起 tab 结构（authors 写作者管理 / background 网站背景 / music 音乐设置 / banlog 封禁日志只读）；v2.6.3 新增 profile 个人信息；v3.1.6 新增 announce 公告管理
$tab = $_GET['tab'] ?? 'authors';
if (!in_array($tab, ['authors', 'background', 'music', 'banlog', 'profile', 'announce'], true)) $tab = 'authors';

// v3.1.6：文章列表（公告选择关联文章用：读 META title / 一级标题 / 文件名）
function stArticleOptions() {
    $opts = [];
    if (!is_dir(__DIR__ . '/../data/articles')) return $opts;
    foreach (glob(__DIR__ . '/../data/articles/*.md') as $af) {
        $fn = basename($af);
        if (strpos($fn, '.') === 0) continue;
        $title = preg_replace('/\.md$/i', '', $fn);
        $raw = @file_get_contents($af);
        if ($raw && preg_match('/<!--META(.*?)-->/s', $raw, $am)) {
            $meta = json_decode(trim($am[1]), true);
            if (!empty($meta['title'])) $title = $meta['title'];
        }
        if (!$raw || !preg_match('/<!--META(.*?)-->/s', $raw)) {
            if (preg_match('/^#\s+(.+)/m', $raw, $tm)) $title = trim($tm[1]);
        }
        $opts[$fn] = $title;
    }
    return $opts;
}

// 站长只能管理自己的写作者
$myAuthors = array_filter($users, fn($u) => ($u['role'] ?? '') === ROLE_AUTHOR && ($u['station_id'] ?? '') === $myId);

// v3.0.6：名下写作者关联统计（评论数/文章数）——详情数据仅在渲染本站长名下写作者时生成并嵌入页面，
// 无独立查询接口、无 user_id 参数遍历面（防越权 / 防枚举他人数据）
$st_statComments = [];
foreach (db_all('SELECT user_id, COUNT(*) c FROM comments GROUP BY user_id') as $r) $st_statComments[$r['user_id']] = (int)$r['c'];
$st_statArticles = [];
if (is_dir(__DIR__ . '/../data/articles')) {
    foreach (glob(__DIR__ . '/../data/articles/*.md') as $af) {
        $raw = @file_get_contents($af);
        if ($raw && preg_match('/<!--META(.*?)-->/s', $raw, $am)) {
            $meta = json_decode(trim($am[1]), true) ?: [];
            $aid = $meta['author_id'] ?? '';
            if ($aid !== '') $st_statArticles[$aid] = ($st_statArticles[$aid] ?? 0) + 1;
        }
    }
}
$stMyNick = $currentUser['nickname'] ?? '';
function stAuthorDetail($a, $statComments, $statArticles, $myNick) {
    return [
        'nickname' => $a['nickname'] ?? '', 'qq' => maskQQ($a['qq'] ?? ''),
        'email' => $a['email'] ?? '未绑定', 'station' => $myNick,
        'disabled' => !empty($a['disabled']),
        'created' => $a['created'] ?? '', 'signature' => $a['signature'] ?? '',
        'last_login' => $a['last_login'] ?? '从未登录',
        'login_count' => (int)($a['login_count'] ?? 0),
        'comments' => $statComments[$a['id']] ?? 0,
        'articles' => $statArticles[$a['id']] ?? 0,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['logout'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = 'csrf_error';
    } elseif (isset($_POST['action'])) {
        if ($_POST['action'] === 'send_author_code') {
            // v2.9.0：发送验证码到写作者邮箱（v2.11.0 起滑块校验已移除；邮箱格式/唯一检查 + 60s 冷却）
            $email = trim($_POST['email'] ?? '');
            if (!email_valid($email)) {
                $msg = 'email_invalid';
            } elseif (email_exists($email)) {
                $msg = 'email_duplicate';
            } else {
                [$ok, $err] = email_code_send($email, 'author_verify', '站长创建写作者', ROLE_STATION_ADMIN);
                $msg = $ok ? 'code_sent' : 'send_fail';
            }
        } elseif ($_POST['action'] === 'create_author') {
            $newNick = trim($_POST['nickname'] ?? '');
            $newQQ = trim($_POST['qq'] ?? '');
            $newPwd = trim($_POST['password'] ?? '');
            $newEmail = trim($_POST['email'] ?? '');
            // v2.11.0：滑块人机验证已彻底移除；新增 QQ 号格式校验（5-12 位数字，防注入/异常账号）
            if (!preg_match('/^[1-9][0-9]{4,11}$/', $newQQ)) {
                $msg = 'qq_invalid';
            } elseif ($newNick && $newQQ && $newPwd) {
                $vp = validatePassword($newPwd);
                if ($vp !== true) {
                    $msg = 'pw_weak';
                } else {
                    // v2.9.0：邮箱校验（格式 + 唯一）——启用双重确认时必填
                    if (!empty($config['author_dual_verify_enabled'])) {
                        if (!email_valid($newEmail)) { $msg = 'email_invalid'; }
                        elseif (email_exists($newEmail)) { $msg = 'email_duplicate'; }
                        else {
                            $qqExists = false;
                            foreach ($users as $uu) { if (($uu['qq'] ?? '') === $newQQ) { $qqExists = true; break; } }
                            if ($qqExists) {
                                $msg = 'qq_duplicate';
                            } else {
                                // 建 verify_pending 中间态（等写作者通过邮件链接自助验证码）
                                [$pid, ] = create_pending_author(
                                    $newEmail, $newNick, $newQQ,
                                    password_hash($newPwd, PASSWORD_DEFAULT), $myId, '', 'verify_pending'
                                );
                                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                                $vlink = "{$scheme}://{$host}/verify-author.php?pid={$pid}&email=" . rawurlencode($newEmail);
                                [$ok, $verr] = email_code_send($newEmail, 'author_verify', "写作者:{$newNick}", ROLE_STATION_ADMIN, $vlink);
                                if (!$ok) {
                                    update_pending_author_status($pid, 'expired');
                                    $msg = 'send_fail';
                                } else {
                                    auditLog('author_create_init', $newQQ, "站长发起创建写作者: {$newNick}（等待写作者验证邮箱）");
                                    $msg = 'code_sent_await_author';
                                }
                            }
                        }
                    } else {
                        // 双重确认关闭：直接创建（原逻辑）
                        $qqExists = false;
                        foreach ($users as $uu) { if (($uu['qq'] ?? '') === $newQQ) { $qqExists = true; break; } }
                        if ($qqExists) {
                            $msg = 'qq_duplicate';
                        } else {
                            $users[] = [
                                'id' => bin2hex(random_bytes(8)),
                                'qq' => $newQQ,
                                'email' => $newEmail,
                                'nickname' => $newNick,
                                'password' => password_hash($newPwd, PASSWORD_DEFAULT),
                                'role' => ROLE_AUTHOR,
                                'station_id' => $myId,
                                'created' => date('Y-m-d H:i:s'),
                                'created_by' => $myId,
                            ];
                            saveUsers($users);
                            auditLog('author_create', $newQQ, "站长创建写作者: {$newNick}");
                            $msg = 'author_created';
                        }
                    }
                }
            }
        } elseif ($_POST['action'] === 'delete_author') {
            $delId = $_POST['user_id'] ?? '';
            foreach ($users as $i => $u) {
                if ($u['id'] === $delId && ($u['role'] ?? '') === ROLE_AUTHOR && ($u['station_id'] ?? '') === $myId) {
                    auditLog('author_delete', $u['qq'] ?? $delId, "站长删除写作者: {$u['nickname']}");
                    array_splice($users, $i, 1);
                    saveUsers($users);
                    $msg = 'author_deleted';
                    break;
                }
            }
        }
    } elseif (isset($_FILES['bg_image']) && $_FILES['bg_image']['error'] === UPLOAD_ERR_OK) {
        // v2.6.0：站长上传背景图片（校验逻辑与超管一致：MIME 白名单 + 10MB）
        $file = $_FILES['bg_image'];
        $extMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
        $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = null;
        if (function_exists('getimagesize')) { $info = @getimagesize($file['tmp_name']); if ($info && isset($info['mime'])) $mime = $info['mime']; }
        if (!$mime && isset($extMap[$origExt])) $mime = $extMap[$origExt];
        if ($mime && in_array($mime, array_values($extMap)) && $file['size'] <= 10*1024*1024) {
            $ext = array_search($mime, $extMap) ?: $origExt;
            $fname = 'bg_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
            $dir = __DIR__.'/../data/bg/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (move_uploaded_file($file['tmp_name'], $dir.$fname)) {
                $config['bg_type'] = 'image';
                $config['bg_image'] = 'data/bg/'.$fname;
                saveSiteConfig($config);
                auditLog('bg_upload', 'site_config', '站长上传背景图片');
                $msg = 'uploaded';
            } else {
                $msg = 'upload_error';
            }
        } else {
            $msg = 'upload_error';
        }
    } elseif (isset($_POST['_bg_save'])) {
        // v2.6.0：站长保存背景配置（校验逻辑与超管一致）
        $config['bg_type'] = in_array($_POST['bg_type']??'', ['none','image','api']) ? $_POST['bg_type'] : 'none';
        // bg_image 仅允许站内 data/bg/ 路径或 http(s) URL，防止 CSS 值注入
        $bgImage = trim($_POST['bg_image']??'');
        if ($bgImage !== '' && strpos($bgImage, 'data/bg/') !== 0 && !preg_match('#^https?://#i', $bgImage)) $bgImage = '';
        $config['bg_image'] = $bgImage;
        $config['bg_api_url'] = trim($_POST['bg_api_url']??'');
        $config['bg_blur_enabled'] = !empty($_POST['bg_blur_enabled']);
        $config['bg_blur_level'] = max(0, min(50, intval($_POST['bg_blur_level']??0)));
        $config['bg_card_opacity'] = max(20, min(100, intval($_POST['bg_card_opacity']??100)));
        saveSiteConfig($config);
        auditLog('bg_config', 'site_config', '站长修改网站背景');
        $msg = 'saved';
    } elseif (isset($_POST['music_save'])) {
        // v2.6.0：站长保存音乐设置（与超管共享同一 config，后保存者生效）
        $config['music_playlist_id'] = trim($_POST['music_playlist_id'] ?? '3778678');
        $config['music_playlist_id_qq'] = trim($_POST['music_playlist_id_qq'] ?? '');
        $config['music_cookies'] = trim($_POST['music_cookies'] ?? '');
        $config['music_cookies_qq'] = trim($_POST['music_cookies_qq'] ?? '');
        saveSiteConfig($config);
        auditLog('music_config', 'site_config', '站长修改音乐设置');
        $msg = 'music_saved';
    } elseif (isset($_POST['profile_save'])) {
        // v2.6.3：站长修改个人信息（昵称/签名/新密码）
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
            auditLog('profile_update', $myId, '站长修改个人信息');
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
                    auditLog('email_change', $myId, '站长更换绑定邮箱为 ' . $newEmail);
                    $msg = 'email_saved';
                }
            }
        }
    } elseif (isset($_POST['avatar_upload'])) {
        // v2.10.0：头像上传（JPG/PNG/WEBP ≤2MB）
        [$ok, $res] = avatar_upload($myId, $_FILES['avatar'] ?? null);
        if ($ok) {
            auditLog('avatar_update', $myId, '站长更新头像');
            $msg = 'avatar_saved';
        } else {
            $msg = 'avatar_fail';
        }
    } elseif (isset($_POST['announce_action'])) {
        // v3.1.6：公告管理（站长全权：添加/编辑/删除/排序，含更新公告）。内容管理操作，CSRF 校验 + 审计，无需挑战码。
        $annAct = $_POST['announce_action'];
        if ($annAct === 'add') {
            $aType = ($_POST['a_type'] ?? '') === 'update' ? 'update' : 'manual';
            $aArticle = trim($_POST['a_article'] ?? '');
            $aTitle = trim($_POST['a_title'] ?? '');
            $aSummary = trim($_POST['a_summary'] ?? '');
            if ($aTitle === '') $aTitle = $aArticle !== '' ? $aArticle : '公告';
            if ($aArticle !== '') { // 校验文章存在
                if (!is_file(__DIR__ . '/../data/articles/' . basename($aArticle))) $aArticle = '';
            }
            addAnnouncement($aType, $aArticle !== '' ? basename($aArticle) : '', $myId, $aTitle, $aSummary);
            auditLog('announce_add', $aTitle, "站长新增公告: {$aTitle}" . ($aArticle !== '' ? "（关联文章 {$aArticle}）" : ''));
            $msg = 'announce_added';
        } elseif ($annAct === 'update') {
            $aId = $_POST['a_id'] ?? '';
            $aArticle = trim($_POST['a_article'] ?? '');
            $aTitle = trim($_POST['a_title'] ?? '');
            $aSummary = trim($_POST['a_summary'] ?? '');
            $ex = getAnnouncement($aId);
            if ($ex) {
                if ($aArticle !== '' && !is_file(__DIR__ . '/../data/articles/' . basename($aArticle))) $aArticle = '';
                if ($aTitle === '') $aTitle = $ex['title'] ?? '公告';
                updateAnnouncement($aId, $aArticle !== '' ? basename($aArticle) : '', $aTitle, $aSummary);
                auditLog('announce_update', $aId, "站长编辑公告: {$aTitle}");
                $msg = 'announce_updated';
            } else {
                $msg = 'announce_not_found';
            }
        } elseif ($annAct === 'delete') {
            $aId = $_POST['a_id'] ?? '';
            $ex = getAnnouncement($aId);
            if ($ex) {
                deleteAnnouncement($aId);
                auditLog('announce_delete', $aId, "站长删除公告: {$ex['title']}");
                $msg = 'announce_deleted';
            } else {
                $msg = 'announce_not_found';
            }
        } elseif ($annAct === 'reorder') {
            // 上移/下移：读取当前列表，交换相邻两条的 ord
            $aId = $_POST['a_id'] ?? '';
            $dir = $_POST['dir'] ?? 'up';
            $list = getAnnouncements();
            $ids = array_column($list, 'id');
            $idx = array_search($aId, $ids, true);
            if ($idx !== false) {
                $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
                if ($swap >= 0 && $swap < count($ids)) {
                    $tmp = $ids[$idx]; $ids[$idx] = $ids[$swap]; $ids[$swap] = $tmp;
                    reorderAnnouncements($ids);
                    auditLog('announce_reorder', $aId, '站长调整公告排序');
                }
            }
            $msg = 'announce_reordered';
        } elseif ($annAct === 'vis') {
            // v3.1.10：公告可视范围调控（all 所有人 / users 仅登录用户 / managers 仅站长及以上）
            // v3.1.15：仅作用于「更新历史」更新公告，其他公告始终可见
            $vis = in_array($_POST['a_vis'] ?? '', ['all', 'users', 'managers'], true) ? $_POST['a_vis'] : 'all';
            $config['announce_visibility'] = $vis;
            saveSiteConfig($config);
            auditLog('announce_vis', 'site_config', "站长设置更新公告可视范围: {$vis}");
            $msg = 'announce_vis_saved';
        }
    }
    header("Location: dashboard.php?msg={$msg}&tab={$tab}");
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-admin="station">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>站长后台 - <?= htmlspecialchars($siteTitle) ?></title>
<link rel="stylesheet" href="../css/admin.css?v=<?= @filemtime(__DIR__ . '/../css/admin.css') ?>">
</head>
<body>

<!-- v3.1.5：移动端顶栏（菜单按钮融入顶栏，与后台统一视觉） -->
<div class="mobile-topbar" id="mobileTopbar">
    <button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleSidebar()" aria-label="打开菜单">
        <svg viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="mobile-topbar-title">站长后台</div>
    <div class="mobile-topbar-user">
        <span class="mobile-topbar-name"><?= htmlspecialchars($currentUser['nickname'] ?? '站长') ?></span>
    </div>
</div>
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
        <a href="dashboard.php" class="sidebar-link <?= $tab==='authors'?'active':'' ?>">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            写作者管理
        </a>
        <a href="dashboard.php?tab=background" class="sidebar-link <?= $tab==='background'?'active':'' ?>">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            网站背景
        </a>
        <a href="dashboard.php?tab=music" class="sidebar-link <?= $tab==='music'?'active':'' ?>">
            <svg viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
            音乐设置
        </a>
        <a href="dashboard.php?tab=banlog" class="sidebar-link <?= $tab==='banlog'?'active':'' ?>">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            封禁日志（只读）
        </a>
        <a href="dashboard.php?tab=announce" class="sidebar-link <?= $tab==='announce'?'active':'' ?>">
            <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            公告管理
        </a>
        <a href="dashboard.php?tab=profile" class="sidebar-link <?= $tab==='profile'?'active':'' ?>">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            个人信息
        </a>
        <a href="/sc.php" class="sidebar-link">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            发表文章
        </a>
        <!-- v2.11.4：快捷返回主界面 -->
        <a href="/" class="sidebar-link">
            <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            返回主页
        </a>
        <a href="#" onclick="logoutSubmit(event)" class="sidebar-link danger">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            退出登录
        </a>
    </nav>
</div>

<div class="main">
    <?php if ($msg === 'author_created'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>写作者已创建</div><?php endif; ?>
    <?php if ($msg === 'code_sent_await_author'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>已提交！验证码已发送到写作者邮箱，请其打开邮件链接自助验证；验证通过后将通知超管确认</div><?php endif; ?>
    <?php if ($msg === 'qq_invalid'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>QQ号格式不正确（应为 5-12 位数字）</div><?php endif; ?>
    <?php if ($msg === 'email_invalid'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>邮箱格式不正确</div><?php endif; ?>
    <?php if ($msg === 'email_duplicate'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>该邮箱已被使用</div><?php endif; ?>
    <?php if ($msg === 'send_fail'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>验证码发送失败（请确认 SMTP 邮件配置）</div><?php endif; ?>
    <?php if ($msg === 'author_deleted'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>写作者已删除</div><?php endif; ?>
    <?php if ($msg === 'qq_duplicate'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>该账号已存在，请更换</div><?php endif; ?>
    <?php if ($msg === 'csrf_error'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>请求已过期，请重试</div><?php endif; ?>
    <?php if ($msg === 'pw_weak'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>密码至少 8 位，且必须包含大写字母、小写字母与数字</div><?php endif; ?>
    <?php if ($msg === 'saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>保存成功</div><?php endif; ?>
    <?php if ($msg === 'uploaded'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>上传成功</div><?php endif; ?>
    <?php if ($msg === 'upload_error'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>上传失败，请检查文件格式与 data/bg/ 目录权限</div><?php endif; ?>
    <?php if ($msg === 'music_saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>音乐设置已保存</div><?php endif; ?>

    <?php if ($tab === 'authors'): ?>
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
                    <label class="form-label">QQ号（5-12 位数字）</label>
                    <input class="form-input" name="qq" placeholder="登录账号" pattern="[1-9][0-9]{4,11}" title="QQ号应为 5-12 位数字">
                </div>
                <div class="form-group">
                    <label class="form-label">密码（至少 8 位，含大小写字母与数字）</label>
                    <input class="form-input" name="password" type="password" placeholder="******">
                </div>
            </div>
            <?php if (!empty($config['author_dual_verify_enabled'])): ?>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">写作者邮箱（收验证码，由写作者通过邮件链接自助验证，超管确认后生效）</label>
                    <input class="form-input" name="email" id="authorEmail" placeholder="写作者邮箱">
                </div>
            </div>
            <?php endif; ?>
            <div class="form-row">
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
                <td style="color:var(--text-muted)"><?= htmlspecialchars(maskQQ($a['qq'] ?? '')) ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($a['created'] ?? '') ?></td>
                <td>
                    <button type="button" class="btn-link" onclick="stOpenDetail(this)" data-detail='<?= htmlspecialchars(json_encode(stAuthorDetail($a, $st_statComments, $st_statArticles, $stMyNick), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>查看参数</button>
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

    <?php elseif ($tab === 'background'): ?>
    <?php
    $bgType = $config['bg_type'] ?? 'none';
    $bgImage = $config['bg_image'] ?? '';
    $bgApiUrl = $config['bg_api_url'] ?? '';
    $bgBlurEnabled = !empty($config['bg_blur_enabled']);
    $bgBlurLevel = $config['bg_blur_level'] ?? 0;
    $bgCardOpacity = $config['bg_card_opacity'] ?? 100;
    ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            网站背景
        </div>
        <div class="page-subtitle">自定义网站背景图片与效果（与超管共享配置）</div>
    </div>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            背景类型
        </div>
        <div class="bg-type-grid">
            <label class="bg-type-card <?= $bgType==='none'?'active':'' ?>" data-type="none"><input type="radio" name="bg_type" value="none" <?= $bgType==='none'?'checked':'' ?>><div class="type-icon none"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></div><div class="type-name">无背景</div></label>
            <label class="bg-type-card <?= $bgType==='image'?'active':'' ?>" data-type="image"><input type="radio" name="bg_type" value="image" <?= $bgType==='image'?'checked':'' ?>><div class="type-icon upload"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></div><div class="type-name">上传图片</div></label>
            <label class="bg-type-card <?= $bgType==='api'?'active':'' ?>" data-type="api"><input type="radio" name="bg_type" value="api" <?= $bgType==='api'?'checked':'' ?>><div class="type-icon api"><svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></div><div class="type-name">API 获取</div></label>
        </div>
    </div>
    <div class="card" id="imageSection" style="display:<?= $bgType==='image'?'block':'none' ?>">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>上传背景图片</div>
        <?php if ($bgImage && $bgType==='image'): ?>
        <div class="img-preview-thumb"><img src="../<?= htmlspecialchars($bgImage) ?>" alt="当前背景"><button class="remove-img" onclick="removeBgImage()" title="移除">&times;</button></div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <div class="upload-area">
                <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <div class="upload-text">点击上传背景图片</div>
                <div class="upload-hint">支持 JPG / PNG / GIF / WebP，最大 10MB</div>
                <input type="file" name="bg_image" accept="image/jpeg,image/png,image/gif,image/webp" onchange="if(this.files.length)this.form.submit()">
            </div>
        </form>
        <input type="hidden" id="bgImagePath" value="<?= htmlspecialchars($bgImage) ?>">
    </div>
    <div class="card" id="apiSection" style="display:<?= $bgType==='api'?'block':'none' ?>">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>API 背景地址</div>
        <div class="form-group">
            <label class="form-label">图片 API URL</label>
            <div class="api-url-group">
                <input class="form-input" type="url" id="bgApiUrl" value="<?= htmlspecialchars($bgApiUrl) ?>" placeholder="https://api.example.com/random-bg">
                <button class="btn btn-sm btn-outline" type="button" onclick="testApiUrl()">测试</button>
            </div>
        </div>
        <div id="apiTestResult" style="margin-top:8px"></div>
    </div>
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>模糊与透明度</div>
        <div class="toggle-row" id="bgBlurRow" style="display:<?= $bgType!=='none'?'flex':'none' ?>">
            <div><div class="toggle-label">背景模糊</div><div class="toggle-desc">对网站背景应用高斯模糊</div></div>
            <label class="toggle"><input type="checkbox" id="blurToggle" <?= $bgBlurEnabled?'checked':'' ?>><span class="slider"></span></label>
        </div>
        <div id="blurLevelWrap" style="display:<?= ($bgBlurEnabled && $bgType!=='none')?'block':'none' ?>;padding-top:8px">
            <div class="slider-group">
                <div class="slider-header"><label>模糊程度</label><span class="slider-val" id="blurVal"><?= $bgBlurLevel ?>px</span></div>
                <div class="slider-row"><span class="slider-label">清晰</span><input type="range" min="0" max="50" value="<?= $bgBlurLevel ?>" step="2" id="blurSlider"><span class="slider-label">模糊</span></div>
            </div>
        </div>
        <div class="slider-group" style="margin-top:12px">
            <div class="slider-header"><label>卡片透明度</label><span class="slider-val" id="opacityVal"><?= $bgCardOpacity ?>%</span></div>
            <div class="slider-row"><span class="slider-label">透明</span><input type="range" min="20" max="100" value="<?= $bgCardOpacity ?>" step="5" id="opacitySlider"><span class="slider-label">不透明</span></div>
        </div>
    </div>
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>实时预览</div>
        <div class="preview-box" id="previewBox">
            <div class="preview-blur" id="previewBlur"></div>
            <div class="preview-card-sim" id="previewCard"><div class="sim-title">文章卡片</div><div class="sim-line" style="width:80%"></div><div class="sim-line" style="width:100%"></div><div class="sim-line"></div></div>
            <div class="preview-overlay"><span id="previewBgLabel">无背景</span><span id="previewBlurLabel"></span></div>
        </div>
    </div>
    <form method="post" id="bgForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
        <input type="hidden" name="_bg_save" value="1">
        <input type="hidden" name="bg_type" id="formBgType" value="<?= htmlspecialchars($bgType) ?>">
        <input type="hidden" name="bg_image" id="formBgImage" value="<?= htmlspecialchars($bgImage) ?>">
        <input type="hidden" name="bg_api_url" id="formBgApiUrl" value="<?= htmlspecialchars($bgApiUrl) ?>">
        <input type="hidden" name="bg_blur_enabled" id="formBlurEnabled" value="<?= $bgBlurEnabled?'1':'' ?>">
        <input type="hidden" name="bg_blur_level" id="formBlurLevel" value="<?= $bgBlurLevel ?>">
        <input type="hidden" name="bg_card_opacity" id="formCardOpacity" value="<?= $bgCardOpacity ?>">
        <div style="display:flex;justify-content:flex-end;gap:10px">
            <button type="button" class="btn btn-outline" onclick="resetBg()">重置</button>
            <button type="submit" class="btn btn-primary">保存配置</button>
        </div>
    </form>
    <script>
    (function() {
        var typeCards = document.querySelectorAll('.bg-type-card');
        var imageSection = document.getElementById('imageSection');
        var apiSection = document.getElementById('apiSection');
        var bgBlurRow = document.getElementById('bgBlurRow');
        var blurLevelWrap = document.getElementById('blurLevelWrap');
        var bgImagePath = document.getElementById('bgImagePath');
        var bgApiUrl = document.getElementById('bgApiUrl');
        var blurToggle = document.getElementById('blurToggle');
        var blurSlider = document.getElementById('blurSlider');
        var blurVal = document.getElementById('blurVal');
        var opacitySlider = document.getElementById('opacitySlider');
        var opacityVal = document.getElementById('opacityVal');
        var previewBox = document.getElementById('previewBox');
        var previewBlur = document.getElementById('previewBlur');
        var previewCard = document.getElementById('previewCard');
        var previewBgLabel = document.getElementById('previewBgLabel');
        var previewBlurLabel = document.getElementById('previewBlurLabel');
        var currentType = '<?= $bgType ?>';
        var previewApiSrc = '';
        typeCards.forEach(function(card) {
            card.addEventListener('click', function() {
                typeCards.forEach(function(c) { c.classList.remove('active'); });
                card.classList.add('active');
                currentType = card.dataset.type;
                imageSection.style.display = currentType === 'image' ? 'block' : 'none';
                apiSection.style.display = currentType === 'api' ? 'block' : 'none';
                bgBlurRow.style.display = currentType !== 'none' ? 'flex' : 'none';
                if (currentType === 'none') blurLevelWrap.style.display = 'none';
                else if (blurToggle.checked) blurLevelWrap.style.display = 'block';
                updatePreview();
            });
        });
        window.testApiUrl = function() { var url = bgApiUrl.value.trim(); if (!url) return; var result = document.getElementById('apiTestResult'); result.innerHTML = '<span style="color:var(--text-muted);font-size:13px">测试中...</span>'; var img = new Image(); img.onload = function() { previewApiSrc = url; result.innerHTML = '<div class="img-preview-thumb"><img src="'+url+'" style="max-width:200px;max-height:120px"></div><div style="font-size:12px;color:#16a34a;margin-top:4px">✓ API 可用</div>'; updatePreview(); }; img.onerror = function() { result.innerHTML = '<div style="font-size:12px;color:#dc2626">✗ 无法加载图片</div>'; }; img.src = url + (url.indexOf('?')>=0?'&':'?') + '_t=' + Date.now(); };
        bgApiUrl.addEventListener('input', function() { previewApiSrc = ''; updatePreview(); });
        blurToggle.addEventListener('change', function() { blurLevelWrap.style.display = blurToggle.checked ? 'block' : 'none'; updatePreview(); });
        blurSlider.addEventListener('input', function() { blurVal.textContent = blurSlider.value + 'px'; updatePreview(); });
        opacitySlider.addEventListener('input', function() { opacityVal.textContent = opacitySlider.value + '%'; updatePreview(); });
        function updatePreview() {
            var blur = blurToggle.checked ? parseInt(blurSlider.value) : 0;
            var opacity = parseInt(opacitySlider.value) / 100;
            if (currentType === 'none') { previewBox.style.backgroundImage = 'none'; previewBox.style.backgroundColor = 'var(--bg)'; previewBgLabel.textContent = '无背景'; }
            else if (currentType === 'image' && bgImagePath.value) { previewBox.style.backgroundImage = 'url(../' + bgImagePath.value + ')'; previewBox.style.backgroundColor = ''; previewBgLabel.textContent = '自定义图片'; }
            else if (currentType === 'api') { if (previewApiSrc) { previewBox.style.backgroundImage = 'url(' + previewApiSrc + ')'; previewBox.style.backgroundColor = ''; previewBgLabel.textContent = 'API 图片'; } else { previewBox.style.backgroundImage = 'none'; previewBox.style.backgroundColor = 'var(--bg)'; previewBgLabel.textContent = bgApiUrl.value.trim() ? 'API（请先测试）' : '待配置'; } }
            else { previewBox.style.backgroundImage = 'none'; previewBox.style.backgroundColor = 'var(--bg)'; previewBgLabel.textContent = '待配置'; }
            previewBox.style.backgroundSize = 'cover'; previewBox.style.backgroundPosition = 'center';
            previewBlur.style.backdropFilter = 'blur(' + blur + 'px)'; previewBlur.style.webkitBackdropFilter = 'blur(' + blur + 'px)';
            previewCard.style.background = 'rgba(255,255,255,' + opacity + ')';
            var labels = []; if (blur > 0) labels.push('模糊 ' + blur + 'px'); labels.push('卡片 ' + Math.round(opacity*100) + '%'); previewBlurLabel.textContent = labels.join(' · ');
        }
        window.removeBgImage = function() { if (confirm('确定移除背景图片？')) { bgImagePath.value = ''; document.getElementById('formBgImage').value = ''; document.getElementById('formBgType').value = 'none'; currentType = 'none'; typeCards.forEach(function(c) { c.classList.remove('active'); }); typeCards[0].classList.add('active'); imageSection.style.display = 'none'; bgBlurRow.style.display = 'none'; updatePreview(); } };
        window.resetBg = function() { currentType = 'none'; typeCards.forEach(function(c) { c.classList.remove('active'); }); typeCards[0].classList.add('active'); imageSection.style.display = 'none'; apiSection.style.display = 'none'; bgImagePath.value = ''; bgApiUrl.value = ''; previewApiSrc = ''; blurToggle.checked = false; blurSlider.value = 0; blurVal.textContent = '0px'; opacitySlider.value = 100; opacityVal.textContent = '100%'; blurLevelWrap.style.display = 'none'; bgBlurRow.style.display = 'none'; updatePreview(); };
        document.getElementById('bgForm').addEventListener('submit', function() { document.getElementById('formBgType').value = currentType; document.getElementById('formBgImage').value = bgImagePath.value; document.getElementById('formBgApiUrl').value = bgApiUrl.value.trim(); document.getElementById('formBlurEnabled').value = blurToggle.checked ? '1' : ''; document.getElementById('formBlurLevel').value = blurSlider.value; document.getElementById('formCardOpacity').value = opacitySlider.value; });
        <?php if ($bgType === 'api' && $bgApiUrl): ?>
        (function() { var u=<?= json_encode($bgApiUrl) ?>; var img=new Image(); img.onload=function(){previewApiSrc=u;updatePreview();}; img.src=u; })();
        <?php endif; ?>
        updatePreview();
    })();
    </script>

    <?php elseif ($tab === 'music'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
            音乐设置
        </div>
        <div class="page-subtitle">配置前台音乐播放器（与超管共享配置，后保存者生效）</div>
    </div>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
            音乐播放器设置
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="music_save" value="1">
            <div class="form-group">
                <label class="form-label">网易云歌单 ID</label>
                <input class="form-input" name="music_playlist_id" value="<?= htmlspecialchars($config['music_playlist_id'] ?? '3778678') ?>" placeholder="3778678">
                <p class="form-hint">网易云音乐歌单 ID，默认 3778678 为热歌榜</p>
            </div>
            <div class="form-group">
                <label class="form-label">QQ 音乐歌单 ID</label>
                <input class="form-input" name="music_playlist_id_qq" value="<?= htmlspecialchars($config['music_playlist_id_qq'] ?? '') ?>" placeholder="留空则使用 QQ 热歌榜">
                <p class="form-hint">QQ 音乐歌单 ID（前端切换到 QQ 平台时使用），留空则加载 QQ 热歌榜</p>
            </div>
            <div class="form-group">
                <label class="form-label">网易云 Cookies（可选）</label>
                <input class="form-input" name="music_cookies" value="<?= htmlspecialchars($config['music_cookies'] ?? '') ?>" placeholder="MUSIC_U=xxx; __csrf=xxx; ...">
                <p class="form-hint">配置后可播放网易云 VIP 歌曲</p>
            </div>
            <div class="form-group">
                <label class="form-label">QQ 音乐 Cookies（可选）</label>
                <input class="form-input" name="music_cookies_qq" value="<?= htmlspecialchars($config['music_cookies_qq'] ?? '') ?>" placeholder="uin=xxx; p_skey=xxx; skey=xxx; ...">
                <p class="form-hint">配置后可播放 QQ 音乐付费/VIP 歌曲（v2.6.0）</p>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px">
                <button type="submit" class="btn btn-primary">保存设置</button>
            </div>
        </form>
    </div>

    <?php elseif ($tab === 'banlog'): ?>
    <?php
    $typeLabels = ['register' => '注册', 'comment' => '评论', 'login' => '登录'];
    $banTypes = loadBansList();
    $loginLogs = array_reverse(loadLogsList());
    $unauthLogs = db_all('SELECT * FROM unauthorized ORDER BY time DESC');
    ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            封禁日志
        </div>
        <div class="page-subtitle">只读查看封禁与安全日志（管理操作由超管执行）</div>
    </div>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            封禁列表（<?= count($banTypes) ?> 条）
        </div>
        <?php if (empty($banTypes)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            <p>暂无封禁记录</p>
        </div>
        <?php else: ?>
        <div class="ban-list">
            <?php foreach ($banTypes as $ban): ?>
            <div class="ban-item">
                <div class="ban-item-top">
                    <span class="ban-item-ip"><?= htmlspecialchars($ban['ip']) ?></span>
                    <span style="font-size:0.78em;color:var(--text-muted)"><?= htmlspecialchars($ban['time'] ?? '') ?></span>
                </div>
                <div class="ban-item-types">
                    <?php foreach (($ban['types'] ?? []) as $t): ?>
                    <span class="ban-tag type-<?= $t ?>"><?= $typeLabels[$t] ?? $t ?></span>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($ban['reason'])): ?>
                <div style="font-size:0.82em;color:var(--text-muted);margin-bottom:8px">原因：<?= htmlspecialchars($ban['reason']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            登录日志
        </div>
        <?php if (empty($loginLogs)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            <p>暂无登录日志</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th>时间</th><th>IP</th><th>操作</th></tr>
            <?php foreach ($loginLogs as $log): ?>
            <tr>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($log['time'] ?? '') ?></td>
                <td><code><?= htmlspecialchars($log['ip'] ?? '') ?></code></td>
                <td style="color:var(--text-secondary)"><?= htmlspecialchars($log['action'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            越权访问记录
        </div>
        <?php if (empty($unauthLogs)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <p>暂无越权访问记录</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th>时间</th><th>IP</th><th>操作</th><th>用户</th></tr>
            <?php foreach ($unauthLogs as $log): ?>
            <tr>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($log['time'] ?? '') ?></td>
                <td><code><?= htmlspecialchars($log['ip'] ?? '') ?></code></td>
                <td style="color:var(--text-secondary)"><?= htmlspecialchars($log['action'] ?? '') ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($log['user'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($tab === 'announce'): ?>
    <?php
    // v3.1.6：公告管理（站长全权：添加/编辑/删除/排序；type=update 为升级自动生成的更新公告，站长可删可排序）
    // v3.1.10：公告可视范围调控（all 所有人 / users 仅登录用户 / managers 仅站长及以上）
    $annList = getAnnouncements();
    $annArticleOpts = stArticleOptions();
    $annVis = $config['announce_visibility'] ?? 'all';
    ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            公告管理
        </div>
        <div class="page-subtitle">发布首页公告卡片（选择文章或填写标题摘要；排序即首页显示顺序）</div>
    </div>
    <?php if ($msg === 'announce_added'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>公告已发布</div><?php endif; ?>
    <?php if ($msg === 'announce_updated'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>公告已更新</div><?php endif; ?>
    <?php if ($msg === 'announce_deleted'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>公告已删除</div><?php endif; ?>
    <?php if ($msg === 'announce_reordered'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>排序已更新</div><?php endif; ?>
    <?php if ($msg === 'announce_vis_saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>公告可视范围已保存</div><?php endif; ?>
    <?php if ($msg === 'announce_not_found'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>公告不存在或已被删除</div><?php endif; ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            更新公告可视范围
        </div>
        <p class="form-hint" style="margin-bottom:12px">仅控制「更新历史」更新公告对访客 / 普通用户 / 后台角色的开放程度，其他公告始终可见</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="announce_action" value="vis">
            <div class="bg-type-grid ann-vis-grid" style="max-width:640px">
                <label class="bg-type-card <?= $annVis==='all'?'active':'' ?>" data-type="all"><input type="radio" name="a_vis" value="all" <?= $annVis==='all'?'checked':'' ?>><div class="type-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></div><div class="type-name">所有人可见</div><div class="type-desc">访客与普通用户均可看到公告</div></label>
                <label class="bg-type-card <?= $annVis==='users'?'active':'' ?>" data-type="users"><input type="radio" name="a_vis" value="users" <?= $annVis==='users'?'checked':'' ?>><div class="type-icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div><div class="type-name">仅登录用户可见</div><div class="type-desc">访客看不到公告，登录后可见</div></label>
                <label class="bg-type-card <?= $annVis==='managers'?'active':'' ?>" data-type="managers"><input type="radio" name="a_vis" value="managers" <?= $annVis==='managers'?'checked':'' ?>><div class="type-icon"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div><div class="type-name">仅站长及以上可见</div><div class="type-desc">访客与普通用户均看不到公告</div></label>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px">
                <button type="submit" class="btn btn-primary">保存可视范围</button>
            </div>
        </form>
    </div>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
            发布新公告
        </div>
        <form method="post" id="announceAddForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="announce_action" value="add">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">类型</label>
                    <select class="form-input" name="a_type" id="aTypeSel">
                        <option value="manual" selected>手动公告</option>
                        <option value="update">更新公告</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">关联文章（可选，用于封面图/标签/跳转详情）</label>
                    <select class="form-input" name="a_article" id="aArticleSel">
                        <option value="">— 不关联文章（纯文字公告） —</option>
                        <?php foreach ($annArticleOpts as $afn => $atitle): ?>
                        <option value="<?= htmlspecialchars($afn) ?>"><?= htmlspecialchars($atitle) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">公告标题</label>
                <input class="form-input" name="a_title" id="aTitleInput" maxlength="120" placeholder="公告标题（留空则取文章标题）">
            </div>
            <div class="form-group">
                <label class="form-label">公告内容 / 摘要</label>
                <textarea class="form-input" name="a_summary" id="aSummaryInput" rows="3" maxlength="2000" placeholder="填写公告内容（纯文字公告时展示；关联文章时可留空则展示文章摘要）"></textarea>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px">
                <button type="submit" class="btn btn-primary">发布公告</button>
            </div>
        </form>
    </div>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            公告列表（<?= count($annList) ?> 条）
        </div>
        <?php if (empty($annList)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <p>暂无公告，在上方发布第一条</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th style="width:64px">排序</th><th>标题</th><th>类型</th><th>关联文章</th><th>日期</th><th style="width:150px">操作</th></tr>
            <?php foreach ($annList as $ai => $an): ?>
            <tr>
                <td>
                    <div style="display:flex;gap:2px">
                        <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>"><input type="hidden" name="announce_action" value="reorder"><input type="hidden" name="a_id" value="<?= htmlspecialchars($an['id']) ?>"><input type="hidden" name="dir" value="up"><button type="submit" class="btn btn-sm btn-outline" title="上移" <?= $ai === 0 ? 'disabled' : '' ?>>↑</button></form>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>"><input type="hidden" name="announce_action" value="reorder"><input type="hidden" name="a_id" value="<?= htmlspecialchars($an['id']) ?>"><input type="hidden" name="dir" value="down"><button type="submit" class="btn btn-sm btn-outline" title="下移" <?= $ai === count($annList) - 1 ? 'disabled' : '' ?>>↓</button></form>
                    </div>
                </td>
                <td><div style="font-weight:600"><?= htmlspecialchars($an['title']) ?></div><div style="font-size:12px;color:var(--text-muted);max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($an['summary'] ?? '') ?></div></td>
                <td><?= ($an['type'] === 'update') ? '<span class="ban-tag type-login">更新</span>' : '<span class="ban-tag type-comment">公告</span>' ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= $an['article'] !== '' ? htmlspecialchars($an['article']) : '—' ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($an['date']) ?></td>
                <td>
                    <button type="button" class="btn-link" onclick="stEditAnnounce(this)" data-id="<?= htmlspecialchars($an['id']) ?>" data-type="<?= htmlspecialchars($an['type']) ?>" data-article="<?= htmlspecialchars($an['article']) ?>" data-title="<?= htmlspecialchars($an['title']) ?>" data-summary="<?= htmlspecialchars($an['summary']) ?>">编辑</button>
                    <form method="post" style="display:inline" onsubmit="return confirm('确定删除该公告？')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                        <input type="hidden" name="announce_action" value="delete">
                        <input type="hidden" name="a_id" value="<?= htmlspecialchars($an['id']) ?>">
                        <button type="submit" class="btn-link danger">删除</button>
                    </form>
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
            <tr><td style="color:var(--text-muted)">角色</td><td>站长</td></tr>
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

<!-- v3.0.6：名下写作者参数弹窗（骨架放 tab 条件链外，所有 tab 可用；数据为服务端已过滤的本站长名下写作者） -->
<div class="modal-overlay" id="stDetailModal" style="display:none" onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-box user-detail-box">
        <div class="modal-head">
            <div class="modal-title" id="stDetailTitle">写作者参数</div>
            <button class="modal-close" onclick="document.getElementById('stDetailModal').style.display='none'">&times;</button>
        </div>
        <div class="modal-body" id="stDetailBody"></div>
    </div>
</div>

<!-- v3.1.6：公告编辑弹窗（骨架放 tab 链外，所有 tab 可用） -->
<div class="modal-overlay" id="stAnnounceModal" style="display:none" onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title">编辑公告</div>
            <button class="modal-close" onclick="document.getElementById('stAnnounceModal').style.display='none'">&times;</button>
        </div>
        <form method="post" id="stAnnounceForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="announce_action" value="update">
            <input type="hidden" name="a_id" id="stAnnEditId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">关联文章（可选）</label>
                    <select class="form-input" name="a_article" id="stAnnEditArticle">
                        <option value="">— 不关联文章（纯文字公告） —</option>
                        <?php foreach (stArticleOptions() as $afn => $atitle): ?>
                        <option value="<?= htmlspecialchars($afn) ?>"><?= htmlspecialchars($atitle) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">公告标题</label>
                    <input class="form-input" name="a_title" id="stAnnEditTitle" maxlength="120">
                </div>
                <div class="form-group">
                    <label class="form-label">公告内容 / 摘要</label>
                    <textarea class="form-input" name="a_summary" id="stAnnEditSummary" rows="3" maxlength="2000"></textarea>
                </div>
            </div>
            <div class="modal-foot" style="display:flex;justify-content:flex-end;gap:10px;padding:12px 20px">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('stAnnounceModal').style.display='none'">取消</button>
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
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
// v3.0.6：名下写作者参数弹窗（仅展示服务端已过滤的本站长名下写作者，无越权/遍历面）
function stEsc(s) {
    var el = document.createElement('span');
    el.textContent = (s === null || s === undefined) ? '' : String(s);
    return el.innerHTML;
}
function stOpenDetail(btn) {
    var d = {};
    try { d = JSON.parse(btn.getAttribute('data-detail') || '{}'); } catch (e) { return; }
    var rows = [
        ['昵称', d.nickname], ['UID（QQ）', d.qq], ['邮箱', d.email],
        ['归属站长', d.station], ['状态', d.disabled ? '已禁用' : '正常'],
        ['创建时间', d.created], ['签名', d.signature || '—'],
        ['最后登录', d.last_login]
    ];
    var stats = [['登录次数', d.login_count], ['评论数', d.comments], ['文章数', d.articles]];
    var h = '<div class="user-detail-grid">';
    rows.forEach(function(r) {
        h += '<div class="user-detail-item"><span class="user-detail-label">' + r[0] + '</span><span class="user-detail-value">' + stEsc(r[1]) + '</span></div>';
    });
    h += '</div><div class="user-detail-sec">关联统计</div><div class="user-detail-grid">';
    stats.forEach(function(r) {
        h += '<div class="user-detail-item"><span class="user-detail-label">' + r[0] + '</span><span class="user-detail-value">' + stEsc(r[1]) + '</span></div>';
    });
    h += '</div>';
    document.getElementById('stDetailTitle').textContent = '写作者参数 - ' + (d.nickname || d.qq || '');
    document.getElementById('stDetailBody').innerHTML = h;
    document.getElementById('stDetailModal').style.display = 'flex';
}
// v3.1.6：公告编辑弹窗（从行内 data-* 填充表单）
function stEditAnnounce(btn) {
    document.getElementById('stAnnEditId').value = btn.getAttribute('data-id') || '';
    document.getElementById('stAnnEditArticle').value = btn.getAttribute('data-article') || '';
    document.getElementById('stAnnEditTitle').value = btn.getAttribute('data-title') || '';
    document.getElementById('stAnnEditSummary').value = btn.getAttribute('data-summary') || '';
    document.getElementById('stAnnounceModal').style.display = 'flex';
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
