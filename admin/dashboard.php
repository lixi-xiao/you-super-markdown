<?php
session_start();
require_once __DIR__ . '/../utils.php';

// 超管检查：必须通过 OTP 登录，且 JWT 有效
if (!checkRole(ROLE_SUPER_ADMIN)) {
    logUnauthorized('越权尝试访问超管后台');
    header('Location: /?admin_login=1');
    exit;
}

$jwt = $_SESSION['cmt_user']['jwt'] ?? '';
if (!validateJWT($jwt)) {
    session_unset();
    session_destroy();
    header('Location: /?admin_login=1&expired=1');
    exit;
}

$users = loadUsers();
$config = loadSiteConfig();
$siteTitle = $config['site_title'] ?? 'You Super Markdown';
$msg = $_GET['msg'] ?? '';

// AJAX 处理：更新通道切换
if (isset($_POST['ajax']) && $_POST['ajax'] === 'save_channel' && isset($_POST['channel'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!checkCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'csrf_error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ch = ($_POST['channel'] ?? 'stable') === 'beta' ? 'beta' : 'stable';
    $config['update_channel'] = $ch;
    saveSiteConfig($config);
    echo json_encode(['success' => true, 'channel' => $ch], JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX 处理：检查更新
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_update') {
    header('Content-Type: application/json; charset=utf-8');
    $ch = $config['update_channel'] ?? 'stable';
    $result = checkForUpdates($ch);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX 处理：获取更新状态
if (isset($_GET['ajax']) && $_GET['ajax'] === 'update_status') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(getUpdateStatus(), JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX 处理：验证挑战码并触发更新
if (isset($_POST['ajax']) && $_POST['ajax'] === 'trigger_update') {
    header('Content-Type: application/json; charset=utf-8');
    if (!checkCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'csrf_error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $challengeCode = strtoupper(trim($_POST['challenge_code'] ?? ''));
    $targetVersion = trim($_POST['target_version'] ?? '');
    if (!$challengeCode || !$targetVersion) {
        echo json_encode(['success' => false, 'error' => '参数不完整'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 读取上传的更新包路径（仅接受已上传到固定目录的路径，防止任意路径注入）
    $pkgPath = trim($_POST['package_path'] ?? '');
    if ($pkgPath !== '' && strpos($pkgPath, '/tmp/ym-update-packages/') !== 0) {
        echo json_encode(['success' => false, 'error' => '更新包路径非法'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 验证挑战码（不区分大小写）
    $challengeFile = __DIR__ . '/../data/.challenge.json';
    $challenges = file_exists($challengeFile) ? json_decode(file_get_contents($challengeFile), true) : [];
    if (!is_array($challenges)) $challenges = [];
    $valid = false;
    foreach ($challenges as $i => $c) {
        if (strtoupper($c['code'] ?? '') === $challengeCode && ($c['expires'] ?? 0) > time() && empty($c['used'])) {
            $challenges[$i]['used'] = 1;
            $valid = true;
            break;
        }
    }
    file_put_contents($challengeFile, json_encode($challenges, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    if (!$valid) {
        echo json_encode(['success' => false, 'error' => '挑战码无效或已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 验证通过，创建更新请求
    $updateToken = bin2hex(random_bytes(16));
    $updateRequest = [
        'token' => $updateToken,
        'from_version' => APP_VERSION,
        'to_version' => $targetVersion,
        'channel' => $config['update_channel'] ?? 'stable',
        'package_path' => $pkgPath,
        'status' => 'pending',
        'created' => time(),
        'expires' => time() + 600,
        'challenge_verified' => true,
        'started_at' => null,
        'completed_at' => null,
        'error' => '',
    ];
    saveUpdateRequest($updateRequest);
    @chmod(UPDATE_REQUEST_FILE, 0666);
    // 写入守护进程休眠标志
    setUpdateLock($updateToken, 600);
    auditLog('system_update_triggered', '', "触发系统更新: v" . APP_VERSION . " → v{$targetVersion}");
    echo json_encode(['success' => true, 'token' => $updateToken], JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX 处理：上传更新包
if (isset($_POST['ajax']) && $_POST['ajax'] === 'upload_package') {
    header('Content-Type: application/json; charset=utf-8');
    if (!checkCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'csrf_error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!isset($_FILES['update_package']) || $_FILES['update_package']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => '上传失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $file = $_FILES['update_package'];
    $fname = strtolower($file['name']);
    $ext = substr($fname, -7) === '.tar.gz' ? 'tar.gz' : strtolower(pathinfo($fname, PATHINFO_EXTENSION));
    if (!in_array($ext, ['zip', 'gz', 'tar.gz'], true)) {
        echo json_encode(['success' => false, 'error' => '仅支持 zip/tar.gz 格式'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pkgDir = '/tmp/ym-update-packages';
    if (!is_dir($pkgDir)) mkdir($pkgDir, 0755, true);
    $pkgPath = $pkgDir . '/update-package.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $pkgPath)) {
        echo json_encode(['success' => false, 'error' => '保存失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 尝试读取版本信息
    $targetVersion = '';
    $tmpDir = tempnam(sys_get_temp_dir(), 'ym');
    unlink($tmpDir);
    mkdir($tmpDir, 0755, true);
    if ($ext === 'zip') {
        $zip = new ZipArchive();
        if ($zip->open($pkgPath) === true) {
            $zip->extractTo($tmpDir);
            $zip->close();
        }
    } else {
        shell_exec("tar -xzf \"$pkgPath\" -C \"$tmpDir\" 2>/dev/null");
    }
    $verFile = $tmpDir . '/version.json';
    if (file_exists($verFile)) {
        $verData = json_decode(file_get_contents($verFile), true);
        $targetVersion = $verData['version'] ?? '';
    }
    // 清理临时目录
    array_map('unlink', glob("$tmpDir/*.*"));
    rmdir($tmpDir);
    echo json_encode([
        'success' => true,
        'package_path' => $pkgPath,
        'target_version' => $targetVersion,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX 处理：蜜罐立即同步（拉取攻击日志 + 执行封禁检查）
if (isset($_POST['ajax']) && $_POST['ajax'] === 'hfish_sync') {
    header('Content-Type: application/json; charset=utf-8');
    if (!checkCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'csrf_error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $syncScript = __DIR__ . '/../ym-hfish-sync.py';
    $outArr = [];
    $rc = 1;
    if (file_exists($syncScript)) {
        exec('python3 ' . escapeshellarg($syncScript) . ' 2>&1', $outArr, $rc);
    } else {
        $outArr[] = '同步脚本不存在: ym-hfish-sync.py';
    }
    $output = implode("\n", $outArr);
    auditLog('hfish_sync', '', '手动触发蜜罐同步: ' . $output);
    echo json_encode(['success' => $rc === 0, 'output' => $output], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 统一表单处理（在所有 HTML 输出之前，确保重定向正常） ======

// 用户管理表单
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = 'csrf_error';
    } elseif ($_POST['action'] === 'create_user') {
        $newRole = $_POST['role'] ?? ROLE_USER;
        if (!in_array($newRole, [ROLE_STATION_ADMIN, ROLE_AUTHOR, ROLE_USER])) $newRole = ROLE_USER;
        $newNick = trim($_POST['nickname'] ?? '');
        $newQQ = trim($_POST['qq'] ?? '');
        $newPwd = trim($_POST['password'] ?? '');
        if ($newNick && $newQQ && $newPwd) {
            $users[] = [
                'id' => bin2hex(random_bytes(8)),
                'qq' => $newQQ,
                'nickname' => $newNick,
                'password' => password_hash($newPwd, PASSWORD_DEFAULT),
                'role' => $newRole,
                'created' => date('Y-m-d H:i:s'),
                'created_by' => getCurrentUserId(),
            ];
            file_put_contents(__DIR__ . '/../data/.users.json', json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
            auditLog('user_create', $newQQ, "创建用户: {$newNick}, 角色: {$newRole}");
            $msg = 'user_created';
        }
    } elseif ($_POST['action'] === 'delete_user') {
        $delId = $_POST['user_id'] ?? '';
        foreach ($users as $i => $u) {
            if ($u['id'] === $delId && ($u['role'] ?? '') !== ROLE_SUPER_ADMIN) {
                auditLog('user_delete', $u['qq'] ?? $delId, "删除用户: {$u['nickname']}");
                array_splice($users, $i, 1);
                file_put_contents(__DIR__ . '/../data/.users.json', json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
                $msg = 'user_deleted';
                break;
            }
        }
    }
    if ($msg) {
        header("Location: dashboard.php?tab=users&msg={$msg}");
        exit;
    }
}

// 系统配置表单
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save_config'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=config&msg=csrf_error');
        exit;
    }
    $config['site_title'] = trim($_POST['site_title'] ?? 'You Super Markdown');
    $config['registration_enabled'] = !empty($_POST['registration_enabled']);
    $config['guest_comments_enabled'] = !empty($_POST['guest_comments_enabled']);
    $config['update_channel'] = ($_POST['update_channel'] ?? 'stable') === 'beta' ? 'beta' : 'stable';
    $config['admin_email'] = trim($_POST['admin_email'] ?? '');
    $newStationPath = trim($_POST['station_path'] ?? '');
    if ($newStationPath !== '' && $newStationPath !== 'station') {
        $validation = validateCustomPath($newStationPath);
        if ($validation === true) $config['station_path'] = $newStationPath;
    } else {
        $config['station_path'] = 'station';
    }
    $newAuthorPath = trim($_POST['author_path'] ?? '');
    if ($newAuthorPath !== '' && $newAuthorPath !== 'author') {
        $validation = validateCustomPath($newAuthorPath);
        if ($validation === true && $newAuthorPath !== ($config['station_path'] ?? 'station')) {
            $config['author_path'] = $newAuthorPath;
        }
    } else {
        $config['author_path'] = 'author';
    }
    $config['auto_ban'] = !empty($_POST['auto_ban']);
    $config['auto_ban_unauthorized'] = !empty($_POST['auto_ban_unauthorized']);
    $config['max_login_fails'] = max(3, intval($_POST['max_login_fails'] ?? 10));
    $config['max_comments_per_minute'] = max(1, intval($_POST['max_comments_per_minute'] ?? 5));
    $config['max_registrations_per_ip'] = max(1, intval($_POST['max_registrations_per_ip'] ?? 3));
    $config['music_playlist_id'] = trim($_POST['music_playlist_id'] ?? '3778678');
    $config['music_cookies'] = trim($_POST['music_cookies'] ?? '');
    $config['hide_default_paths'] = !empty($_POST['hide_default_paths']);
    saveSiteConfig($config);
    auditLog('config_update', 'site_config', '修改系统配置');
    header('Location: dashboard.php?tab=config&msg=saved');
    exit;
}

// IP 封禁表单
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ban_action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=security&bmsg=csrf_error');
        exit;
    }
    $bansFile = __DIR__ . '/../data/.bans.json';
    $bans = file_exists($bansFile) ? (json_decode(file_get_contents($bansFile), true) ?: []) : [];
    $act = $_POST['ban_action'];
    if ($act === 'add') {
        $ip = trim($_POST['ip'] ?? '');
        $types = $_POST['types'] ?? [];
        $reason = trim($_POST['reason'] ?? '');
        if ($ip && !empty($types)) {
            $exists = false;
            foreach ($bans as &$b) {
                if ($b['ip'] === $ip) {
                    foreach ($types as $t) { if (!in_array($t, $b['types'])) $b['types'][] = $t; }
                    $exists = true; break;
                }
            }
            unset($b);
            if (!$exists) $bans[] = ['ip' => $ip, 'types' => $types, 'reason' => $reason, 'time' => date('Y-m-d H:i:s')];
            file_put_contents($bansFile, json_encode($bans, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
            header('Location: dashboard.php?tab=security&bmsg=' . urlencode('封禁已添加'));
            exit;
        }
    } elseif ($act === 'remove') {
        $ip = trim($_POST['ip'] ?? '');
        $bans = array_values(array_filter($bans, fn($b) => $b['ip'] !== $ip));
        file_put_contents($bansFile, json_encode($bans, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        header('Location: dashboard.php?tab=security&bmsg=' . urlencode('已解除封禁'));
        exit;
    } elseif ($act === 'update_types') {
        $ip = trim($_POST['ip'] ?? '');
        $types = $_POST['types'] ?? [];
        foreach ($bans as &$b) { if ($b['ip'] === $ip) { $b['types'] = $types; break; } }
        unset($b);
        file_put_contents($bansFile, json_encode($bans, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        header('Location: dashboard.php?tab=security&bmsg=' . urlencode('权限已更新'));
        exit;
    }
    header('Location: dashboard.php?tab=security&bmsg=error');
    exit;
}

// 清空操作日志
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_audit'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=security&bmsg=csrf_error');
        exit;
    }
    file_put_contents(AUDIT_LOG_FILE, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    file_put_contents(AUDIT_CHAIN_FILE, '', LOCK_EX);
    header('Location: dashboard.php?tab=security&bmsg=cleared');
    exit;
}

// 从镜像恢复操作日志
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recover_audit'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=security&bmsg=csrf_error');
        exit;
    }
    recoverAuditFromMirror();
    header('Location: dashboard.php?tab=security&bmsg=recovered');
    exit;
}

// 清空越权访问日志
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_unauth'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=security&bmsg=csrf_error');
        exit;
    }
    file_put_contents(__DIR__ . '/../data/.unauthorized.json', json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    header('Location: dashboard.php?tab=security&bmsg=cleared_unauth');
    exit;
}

// 背景设置表单
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_FILES['bg_image']) || isset($_POST['_bg_save']))) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=background&msg=csrf_error');
        exit;
    }
    // 上传背景图片
    if (isset($_FILES['bg_image']) && $_FILES['bg_image']['error'] === UPLOAD_ERR_OK) {
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
                header('Location: dashboard.php?tab=background&msg=uploaded');
                exit;
            }
        }
        header('Location: dashboard.php?tab=background&msg=upload_error');
        exit;
    }
    // 保存背景配置
    if (isset($_POST['_bg_save'])) {
        $config['bg_type'] = in_array($_POST['bg_type']??'', ['none','image','api']) ? $_POST['bg_type'] : 'none';
        $config['bg_image'] = trim($_POST['bg_image']??'');
        $config['bg_api_url'] = trim($_POST['bg_api_url']??'');
        $config['bg_blur_enabled'] = !empty($_POST['bg_blur_enabled']);
        $config['bg_blur_level'] = max(0, min(50, intval($_POST['bg_blur_level']??0)));
        $config['bg_card_opacity'] = max(20, min(100, intval($_POST['bg_card_opacity']??100)));
        saveSiteConfig($config);
        header('Location: dashboard.php?tab=background&msg=saved');
        exit;
    }
}
// 封禁相关函数（全局可用）
$bansFile = __DIR__ . '/../data/.bans.json';
function loadBans() {
    global $bansFile;
    if (!file_exists($bansFile)) return [];
    $data = json_decode(file_get_contents($bansFile), true);
    return is_array($data) ? $data : [];
}
function saveBans($bans) {
    global $bansFile;
    file_put_contents($bansFile, json_encode($bans, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}
$banMsg = $_GET['bmsg'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-admin="super">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>高级管理员后台 - <?= htmlspecialchars($siteTitle) ?></title>
<link rel="stylesheet" href="../css/admin.css">
</head>
<body>

<!-- 移动端菜单按钮 -->
<button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleSidebar()">
    <svg viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
</button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <div class="sidebar-title"><span>超管</span>后台</div>
    </div>
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="sidebar-link <?= (!isset($_GET['tab']) || $_GET['tab'] === 'overview') ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            总览
        </a>
        <a href="dashboard.php?tab=users" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'users' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            用户管理
        </a>
        <a href="dashboard.php?tab=logs" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'logs' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            审计日志
        </a>
        <a href="dashboard.php?tab=config" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'config' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            系统配置
        </a>
        <a href="dashboard.php?tab=security" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'security' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            安全监控
        </a>
        <a href="dashboard.php?tab=background" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'background' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            网站背景
        </a>
        <a href="dashboard.php?tab=update" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'update' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            在线更新
        </a>
        <a href="dashboard.php?tab=guard" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'guard' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
            守护进程
        </a>
        <a href="dashboard.php?tab=hfish" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'hfish' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="3" y1="3" x2="21" y2="21"/></svg>
            蜜罐安全
        </a>
        <a href="?logout=1" class="sidebar-link danger <?= ($_GET['tab'] ?? '') === 'logout' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            退出登录
        </a>
    </nav>
</div>

<div class="main">
    <?php if ($msg === 'saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>配置已保存</div><?php endif; ?>
    <?php if ($msg === 'user_created'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>用户已创建</div><?php endif; ?>
    <?php if ($msg === 'user_deleted'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>用户已删除</div><?php endif; ?>

    <?php
    $tab = $_GET['tab'] ?? 'overview';
    $superCount = count(array_filter($users, fn($u) => ($u['role'] ?? '') === ROLE_SUPER_ADMIN));
    $stationCount = count(array_filter($users, fn($u) => ($u['role'] ?? '') === ROLE_STATION_ADMIN));
    $authorCount = count(array_filter($users, fn($u) => ($u['role'] ?? '') === ROLE_AUTHOR));
    $userCount = count(array_filter($users, fn($u) => ($u['role'] ?? 'user') === ROLE_USER));
    ?>

    <?php if ($tab === 'overview'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            系统总览
        </div>
        <div class="page-subtitle">高级管理员控制面板</div>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-number"><?= $superCount ?></div><div class="stat-label">高级管理员</div></div>
        <div class="stat-card"><div class="stat-number"><?= $stationCount ?></div><div class="stat-label">站长</div></div>
        <div class="stat-card"><div class="stat-number"><?= $authorCount ?></div><div class="stat-label">写作者</div></div>
        <div class="stat-card"><div class="stat-number"><?= $userCount ?></div><div class="stat-label">注册用户</div></div>
    </div>

    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            系统信息
        </div>
        <div class="table-wrap">
        <table>
            <tr><td style="color:var(--text-muted)">版本</td><td><code><?= APP_VERSION ?></code></td></tr>
            <tr><td style="color:var(--text-muted)">注册开关</td><td><?= empty($config['registration_enabled']) ? '关闭' : '开启' ?></td></tr>
            <tr><td style="color:var(--text-muted)">访客评论</td><td><?= empty($config['guest_comments_enabled']) ? '关闭' : '开启' ?></td></tr>
            <tr><td style="color:var(--text-muted)">更新通道</td><td><?= htmlspecialchars($config['update_channel'] ?? 'stable') ?></td></tr>
        </table>
        </div>
    </div>

    <?php elseif ($tab === 'users'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            用户管理
        </div>
        <div class="page-subtitle">创建和管理用户账号</div>
    </div>
    <?php
    $users = loadUsers();
    ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
            创建用户
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_user">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">昵称</label>
                    <input class="form-input" name="nickname" placeholder="用户昵称">
                </div>
                <div class="form-group">
                    <label class="form-label">QQ号</label>
                    <input class="form-input" name="qq" placeholder="登录账号">
                </div>
                <div class="form-group">
                    <label class="form-label">密码</label>
                    <input class="form-input" name="password" type="password" placeholder="******">
                </div>
                <div class="form-group" style="min-width:120px">
                    <label class="form-label">角色</label>
                    <select class="form-select" name="role">
                        <option value="<?= ROLE_STATION_ADMIN ?>">站长</option>
                        <option value="<?= ROLE_AUTHOR ?>">写作者</option>
                        <option value="<?= ROLE_USER ?>">普通用户</option>
                    </select>
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
            <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            用户列表（<?= count($users) ?> 人）
        </div>
        <div class="table-wrap">
        <table>
            <tr><th>昵称</th><th>QQ</th><th>角色</th><th>创建时间</th><th>操作</th></tr>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['nickname'] ?? '') ?></td>
                <td style="color:var(--text-muted)"><?= htmlspecialchars($u['qq'] ?? '') ?></td>
                <td><span class="role-badge role-<?= $u['role'] ?? 'user' ?>"><?= htmlspecialchars($u['role'] ?? 'user') ?></span></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($u['created'] ?? '') ?></td>
                <td>
                    <?php if (($u['role'] ?? '') !== ROLE_SUPER_ADMIN): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('确定删除 <?= htmlspecialchars($u['nickname'] ?? '') ?>？')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" value="<?= htmlspecialchars($u['id']) ?>">
                        <button type="submit" class="btn-link danger">删除</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>

    <?php elseif ($tab === 'logs'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            审计日志
        </div>
        <div class="page-subtitle">操作审计记录 · 哈希链防篡改</div>
    </div>
    <?php
    $auditLogs = [];
    if (file_exists(AUDIT_LOG_FILE)) {
        $auditLogs = json_decode(file_get_contents(AUDIT_LOG_FILE), true) ?: [];
    }
    $auditLogs = array_reverse(array_slice($auditLogs, -200));
    $chainResult = verifyAuditChain();
    ?>
    <?php if (!$chainResult['valid']): ?>
    <div class="card card-danger">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            哈希链校验失败！日志可能已被篡改。
        </div>
        <p style="color:#fca5a5;font-size:0.9em">断裂位置：第 <?= $chainResult['broken_at'] ?> 条</p>
    </div>
    <?php else: ?>
    <div class="card card-success">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            哈希链校验通过（共 <?= $chainResult['count'] ?> 条）
        </div>
    </div>
    <?php endif; ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            最近操作记录
        </div>
        <div class="table-wrap">
        <table>
            <tr><th>时间</th><th>用户</th><th>角色</th><th>操作</th><th>目标</th><th>结果</th><th>IP</th></tr>
            <?php foreach ($auditLogs as $log): ?>
            <tr>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($log['ts'] ?? '') ?></td>
                <td><?= htmlspecialchars($log['user_name'] ?? '') ?></td>
                <td><span class="role-badge role-<?= $log['role'] ?? 'guest' ?>"><?= htmlspecialchars($log['role'] ?? '') ?></span></td>
                <td><?= htmlspecialchars($log['action'] ?? '') ?></td>
                <td style="color:var(--text-muted)"><?= htmlspecialchars($log['target'] ?? '') ?></td>
                <td><?= ($log['result'] ?? '') === 'success' ? '✅' : '❌' ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($log['ip'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>

    <?php elseif ($tab === 'config'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            系统配置
        </div>
        <div class="page-subtitle">管理网站设置和安全参数</div>
    </div>
    <?php ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            站点设置
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="_save_config" value="1">
            <div class="form-group">
                <label class="form-label">网站标题</label>
                <input class="form-input" name="site_title" value="<?= htmlspecialchars($config['site_title'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">管理员邮箱（告警通知）</label>
                <input class="form-input" name="admin_email" value="<?= htmlspecialchars($config['admin_email'] ?? '') ?>" placeholder="admin@example.com">
            </div>
            <div class="form-row" style="margin-bottom:16px">
                <label class="form-check"><input type="checkbox" name="registration_enabled" <?= empty($config['registration_enabled']) ? '' : 'checked' ?>> 允许注册</label>
                <label class="form-check"><input type="checkbox" name="guest_comments_enabled" <?= empty($config['guest_comments_enabled']) ? '' : 'checked' ?>> 允许访客评论</label>
            </div>
            <div class="form-group">
                <label class="form-label">更新通道</label>
                <select class="form-select" name="update_channel" style="max-width:200px">
                    <option value="stable" <?= ($config['update_channel'] ?? 'stable') === 'stable' ? 'selected' : '' ?>>稳定版</option>
                    <option value="beta" <?= ($config['update_channel'] ?? '') === 'beta' ? 'selected' : '' ?>>测试版</option>
                </select>
            </div>
            <div style="padding-top:16px;border-top:1px solid var(--border);margin-bottom:16px">
                <div class="card-title" style="margin-bottom:8px">
                    <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    安全设置
                </div>
            </div>
            <div class="toggle-row">
                <div><div class="toggle-label">自动 IP 封禁</div><div class="toggle-desc">检测到异常行为自动封禁 IP</div></div>
                <label class="toggle"><input type="checkbox" name="auto_ban" <?= empty($config['auto_ban']) ? '' : 'checked' ?>><span class="slider"></span></label>
            </div>
            <div class="toggle-row">
                <div><div class="toggle-label">自动封禁越权用户</div><div class="toggle-desc">尝试越权访问的 IP 将自动被封禁</div></div>
                <label class="toggle"><input type="checkbox" name="auto_ban_unauthorized" <?= empty($config['auto_ban_unauthorized']) ? '' : 'checked' ?>><span class="slider"></span></label>
            </div>
            <div class="toggle-row" onclick="openRateLimitModal()" style="cursor:pointer">
                <div><div class="toggle-label">频率限制设置</div><div class="toggle-desc">登录/评论/注册频率上限</div></div>
                <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
            <div style="padding-top:16px;border-top:1px solid var(--border);margin-bottom:16px">
                <div class="card-title" style="margin-bottom:8px">
                    <svg viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
                    音乐播放器设置
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">音乐歌单 ID</label>
                <input class="form-input" name="music_playlist_id" value="<?= htmlspecialchars($config['music_playlist_id'] ?? '3778678') ?>" placeholder="3778678">
                <p class="form-hint">网易云音乐歌单 ID，默认 3778678 为热歌榜</p>
            </div>
            <div class="form-group">
                <label class="form-label">网易云 Cookies（可选）</label>
                <input class="form-input" name="music_cookies" value="<?= htmlspecialchars($config['music_cookies'] ?? '') ?>" placeholder="MUSIC_U=xxx; __csrf=xxx; ...">
                <p class="form-hint">配置后可播放 VIP 歌曲</p>
            </div>

            <div style="padding-top:16px;border-top:1px solid var(--border);margin-bottom:16px">
                <div class="card-title" style="margin-bottom:8px">
                    <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    自定义入口路径（L1 隐藏入口扩展）
                </div>
                <p class="form-hint" style="margin-bottom:12px">设置后，站长和写作者将通过自定义 URL 路径访问后台。留空则使用默认路径。</p>
            </div>
            <div class="form-group">
                <label class="form-label">站长后台路径</label>
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="color:var(--text-muted)">/</span>
                    <input class="form-input mono" name="station_path" value="<?= htmlspecialchars($config['station_path'] ?? 'station') ?>" placeholder="station" maxlength="30" pattern="[a-zA-Z0-9][a-zA-Z0-9-]{2,28}[a-zA-Z0-9]">
                    <span style="color:var(--text-muted)">/dashboard.php</span>
                </div>
                <p class="form-hint">4-30字符，字母/数字/连字符，首尾必须是字母或数字</p>
            </div>
            <div class="form-group">
                <label class="form-label">写作者后台路径</label>
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="color:var(--text-muted)">/</span>
                    <input class="form-input mono" name="author_path" value="<?= htmlspecialchars($config['author_path'] ?? 'author') ?>" placeholder="author" maxlength="30" pattern="[a-zA-Z0-9][a-zA-Z0-9-]{2,28}[a-zA-Z0-9]">
                    <span style="color:var(--text-muted)">/dashboard.php</span>
                </div>
                <p class="form-hint">不能与站长路径相同，不能使用系统保留关键字</p>
            </div>
            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" name="hide_default_paths" <?= empty($config['hide_default_paths']) ? '' : 'checked' ?>>
                    隐藏默认路径（自定义路径生效后，/station/ 和 /author/ 返回 404）
                </label>
            </div>
            <!-- 频率限制弹窗 -->
    <div class="modal-overlay" id="rateLimitModal">
        <div class="modal-box" style="max-width:420px;text-align:left">
            <div class="modal-head">
                <div class="modal-title">频率限制设置</div>
                <button class="modal-close" onclick="closeRateLimitModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:0.88em;color:var(--text-secondary);margin-bottom:16px;">设置各项操作的频率上限，超过后将记录日志（开启自动封禁则同时封禁 IP）</p>
                <div class="form-group">
                    <label class="form-label">频繁登录次数（次/小时）</label>
                    <input class="form-input" type="number" name="max_login_fails" value="<?= $config['max_login_fails'] ?? 10 ?>" min="3" max="100">
                </div>
                <div class="form-group">
                    <label class="form-label">频繁评论（条/分钟）</label>
                    <input class="form-input" type="number" name="max_comments_per_minute" value="<?= $config['max_comments_per_minute'] ?? 5 ?>" min="1" max="60">
                </div>
                <div class="form-group">
                    <label class="form-label">频繁注册（次/IP）</label>
                    <input class="form-input" type="number" name="max_registrations_per_ip" value="<?= $config['max_registrations_per_ip'] ?? 3 ?>" min="1" max="50">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeRateLimitModal()">取消</button>
                    <button type="submit" class="btn btn-primary">确定</button>
                </div>
            </div>
        </div>
    </div>
    <script>
    function openRateLimitModal() { document.getElementById('rateLimitModal').classList.add('active'); }
    function closeRateLimitModal() { document.getElementById('rateLimitModal').classList.remove('active'); }
    document.getElementById('rateLimitModal')?.addEventListener('click', function(e) { if (e.target === this) closeRateLimitModal(); });
    </script>
            <button type="submit" class="btn btn-primary">保存配置</button>
        </form>
    </div>

    <?php
    $typeLabels = ['register' => '注册', 'comment' => '评论', 'login' => '登录'];
    ?>
    <?php elseif ($tab === 'security'): ?>
    <?php
    $banTypes = loadBans();
    ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            安全监控
        </div>
        <div class="page-subtitle">IP 封禁管理、登录日志、操作日志与越权访问记录</div>
    </div>
    <?php if ($banMsg):
        $msgText = match($banMsg) {
            'cleared' => '操作日志已清空',
            'recovered' => '操作日志已从镜像恢复',
            default => $banMsg,
        };
    ?>
    <div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= htmlspecialchars($msgText) ?></div>
    <?php endif; ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            添加封禁
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="ban_action" value="add">
            <div class="form-row">
                <div class="form-group" style="flex:1">
                    <label class="form-label">IP 地址</label>
                    <input class="form-input mono" name="ip" placeholder="例如 1.2.3.4" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">封禁功能</label>
                <div style="display:flex;gap:14px;flex-wrap:wrap">
                    <label class="form-check"><input type="checkbox" name="types[]" value="register"> 注册</label>
                    <label class="form-check"><input type="checkbox" name="types[]" value="comment"> 评论</label>
                    <label class="form-check"><input type="checkbox" name="types[]" value="login"> 登录</label>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">原因（可选）</label>
                <input class="form-input" name="reason" placeholder="封禁原因">
            </div>
            <button type="submit" class="btn btn-primary">添加封禁</button>
        </form>
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
                <div class="ban-item-actions">
                    <form method="post" style="display:inline" onsubmit="return confirm('确定解除封禁 <?= htmlspecialchars($ban['ip']) ?>？')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                        <input type="hidden" name="ban_action" value="remove">
                        <input type="hidden" name="ip" value="<?= htmlspecialchars($ban['ip']) ?>">
                        <button type="submit" class="btn-link danger" title="解除封禁">解除封禁</button>
                    </form>
                    <button type="button" class="btn-link" onclick="openBanEdit('<?= htmlspecialchars($ban['ip']) ?>', '<?= htmlspecialchars(json_encode($ban['types'] ?? [])) ?>')" title="编辑">编辑</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <!-- 编辑封禁弹窗 -->
    <div class="modal-overlay" id="banEditModal">
        <div class="modal-box" style="max-width:400px">
            <div class="modal-head">
                <div class="modal-title">编辑封禁</div>
                <button class="modal-close" onclick="closeBanEdit()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="ban-modal-ip" id="banModalIp"></div>
                <form method="post" id="banEditForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                    <input type="hidden" name="ban_action" value="update_types">
                    <input type="hidden" name="ip" id="banModalIpInput">
                    <div class="toggle-row">
                        <div><div class="toggle-label">注册</div><div class="toggle-desc">禁止该 IP 注册新账号</div></div>
                        <label class="toggle"><input type="checkbox" name="types[]" value="register"><span class="slider"></span></label>
                    </div>
                    <div class="toggle-row">
                        <div><div class="toggle-label">评论</div><div class="toggle-desc">禁止该 IP 发表评论</div></div>
                        <label class="toggle"><input type="checkbox" name="types[]" value="comment"><span class="slider"></span></label>
                    </div>
                    <div class="toggle-row">
                        <div><div class="toggle-label">登录</div><div class="toggle-desc">禁止该 IP 登录账号</div></div>
                        <label class="toggle"><input type="checkbox" name="types[]" value="login"><span class="slider"></span></label>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline" onclick="closeBanEdit()">取消</button>
                        <button type="submit" class="btn btn-primary">保存</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
    function openBanEdit(ip, typesStr) {
        document.getElementById('banModalIp').textContent = ip;
        document.getElementById('banModalIpInput').value = ip;
        var types = JSON.parse(typesStr || '[]');
        document.querySelectorAll('#banEditForm input[name="types[]"]').forEach(function(cb) {
            cb.checked = types.indexOf(cb.value) !== -1;
        });
        document.getElementById('banEditModal').classList.add('active');
    }
    function closeBanEdit() {
        document.getElementById('banEditModal').classList.remove('active');
    }
    document.getElementById('banEditModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeBanEdit();
    });
    </script>
    <!-- 越权访问日志 -->
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            越权访问日志
        </div>
        <?php
        $unauthFile = __DIR__ . '/../data/.unauthorized.json';
        $unauthLogs = file_exists($unauthFile) ? json_decode(file_get_contents($unauthFile), true) : [];
        if (!is_array($unauthLogs)) $unauthLogs = [];
        usort($unauthLogs, fn($a, $b) => strcmp($b['time'] ?? '', $a['time'] ?? ''));
        ?>
        <div style="display:flex;justify-content:flex-end;margin-bottom:10px">
            <?php if (!empty($unauthLogs)): ?>
            <form method="post" onsubmit="return confirm('确定清空所有越权访问日志？')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <input type="hidden" name="clear_unauth" value="1">
                <button type="submit" class="btn btn-sm btn-outline" style="color:#ef4444;border-color:#fecaca">清空日志</button>
            </form>
            <?php endif; ?>
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

    <!-- 登录日志 -->
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            登录日志
        </div>
        <?php
        $loginLogs = loadLogsList();
        $loginLogs = array_reverse($loginLogs);
        ?>
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

    <!-- 操作日志（哈希链） -->
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            操作日志
        </div>
        <?php
        $chainResult = verifyAuditChain();
        $auditLogs = [];
        if (file_exists(AUDIT_LOG_FILE)) {
            $auditLogs = json_decode(file_get_contents(AUDIT_LOG_FILE), true) ?: [];
        }
        $auditLogs = array_reverse($auditLogs);
        ?>
        <div style="margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span style="font-size:0.82em;color:var(--text-muted)">哈希链状态：</span>
            <span class="chain-badge <?= $chainResult['valid'] ? 'chain-valid' : 'chain-invalid' ?>" style="font-size:0.82em">
                <?php if ($chainResult['valid']): ?>
                ● 完整（<?= $chainResult['count'] ?> 条记录）
                <?php else: ?>
                ✗ 断裂（第 <?= ($chainResult['broken_at'] ?? 0) + 1 ?> 条异常）
                <?php endif; ?>
            </span>
            <?php if (!$chainResult['valid'] && is_dir(AUDIT_MIRROR_DIR)): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('确定从镜像恢复操作日志？')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <input type="hidden" name="recover_audit" value="1">
                <button type="submit" class="btn btn-sm btn-outline" style="color:#f59e0b;border-color:#fcd34d">从镜像恢复</button>
            </form>
            <?php endif; ?>
            <form method="post" style="display:inline;margin-left:auto" onsubmit="return confirm('确定清空所有操作日志？')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <input type="hidden" name="clear_audit" value="1">
                <button type="submit" class="btn btn-sm btn-outline" style="color:#ef4444;border-color:#fecaca">清空日志</button>
            </form>
        </div>
        <?php if (empty($auditLogs)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <p>暂无操作日志</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th>时间</th><th>用户</th><th>角色</th><th>操作</th><th>目标</th><th>结果</th><th>哈希</th></tr>
            <?php foreach ($auditLogs as $log): ?>
            <tr>
                <td style="color:var(--text-muted);font-size:0.85em;white-space:nowrap"><?= htmlspecialchars($log['ts'] ?? '') ?></td>
                <td><?= htmlspecialchars($log['user_name'] ?? '') ?><br><span style="font-size:0.78em;color:var(--text-muted)"><?= htmlspecialchars($log['user_id'] ?? '') ?></span></td>
                <td><?= htmlspecialchars($log['role'] ?? '') ?></td>
                <td style="color:var(--text-secondary)"><?= htmlspecialchars($log['action'] ?? '') ?></td>
                <td style="font-size:0.85em;color:var(--text-muted)"><?= htmlspecialchars($log['target'] ?? '') ?></td>
                <td><span class="chain-badge <?= ($log['result'] ?? '') === 'success' ? 'chain-valid' : 'chain-invalid' ?>"><?= htmlspecialchars($log['result'] ?? '') ?></span></td>
                <td><code style="font-size:0.7em"><?= htmlspecialchars(substr($log['hash'] ?? '', 0, 12)) ?>…</code></td>
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
    $bgMsg = $_GET['msg'] ?? '';
    ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            网站背景
        </div>
        <div class="page-subtitle">自定义网站背景图片与效果</div>
    </div>
    <?php if ($bgMsg === 'saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>保存成功</div><?php endif; ?>
    <?php if ($bgMsg === 'uploaded'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>上传成功</div><?php endif; ?>
    <?php if ($bgMsg === 'upload_error'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>上传失败，请检查 data/bg/ 目录权限</div><?php endif; ?>
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

    <?php elseif ($tab === 'update'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            在线更新
        </div>
        <div class="page-subtitle">系统版本管理与更新</div>
    </div>

    <div class="update-status-bar" id="updateStatusBar" style="display:none"></div>

    <!-- 当前版本 -->
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>当前版本</div>
        <div class="version-display">
            <div>
                <div class="version-tag">v<?= htmlspecialchars(APP_VERSION) ?></div>
                <div class="version-label"><?= (($config['update_channel'] ?? 'stable') === 'beta') ? '测试版' : '正式版' ?></div>
            </div>
            <div>
                <button class="btn btn-primary" onclick="checkUpdate()" id="checkUpdateBtn">
                    <svg viewBox="0 0 24 24" width="14" height="14"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    检查更新
                </button>
            </div>
        </div>
        <div id="updateCheckResult" style="margin-top:12px;display:none"></div>
    </div>

    <!-- 更新通道 -->
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>更新通道</div>
        <div class="channel-tabs">
            <div class="channel-tab <?= ($config['update_channel'] ?? 'stable') === 'stable' ? 'active' : '' ?>" onclick="switchChannel('stable')"><svg viewBox="0 0 24 24" width="16" height="16"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> 正式版</div>
            <div class="channel-tab <?= ($config['update_channel'] ?? '') === 'beta' ? 'active' : '' ?>" onclick="switchChannel('beta')"><svg viewBox="0 0 24 24" width="16" height="16"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg> 测试版</div>
        </div>
    </div>

    <!-- 执行更新 -->
    <div class="card" id="updateActionCard" style="display:none">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>执行更新</div>
        <div id="updateActionContent">
            <p style="color:var(--text-secondary)">发现新版本 <strong id="newVersionText"></strong>，点击下方按钮开始更新流程。</p>
            <div style="margin-top:16px;display:flex;gap:12px;flex-wrap:wrap">
                <button class="btn btn-primary" onclick="showChallengeModal()" id="startUpdateBtn">
                    <svg viewBox="0 0 24 24" width="14" height="14"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    执行更新
                </button>
                <button class="btn btn-secondary" onclick="document.getElementById('uploadPkgInput').click()">
                    <svg viewBox="0 0 24 24" width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    上传更新包
                </button>
            </div>
            <input type="file" id="uploadPkgInput" accept=".zip,.gz,.tar.gz" style="display:none">
        </div>
        <div id="updateProgressContent" style="display:none">
            <div class="update-progress">
                <div class="update-progress-step" id="upStep1"><span class="step-icon">⏳</span> 等待 SSH 确认</div>
                <div class="update-progress-step" id="upStep2"><span class="step-icon">⏳</span> 备份中</div>
                <div class="update-progress-step" id="upStep3"><span class="step-icon">⏳</span> 更新文件中</div>
                <div class="update-progress-step" id="upStep4"><span class="step-icon">⏳</span> 完成</div>
            </div>
            <div style="margin-top:12px">
                <p style="color:var(--text-muted);font-size:0.85em" id="updateProgressHint">请在 SSH 中执行: <code id="sshCommandText">ym-admin apply-update</code></p>
            </div>
        </div>
    </div>

    <!-- 挑战码弹窗 -->
    <div class="modal-overlay" id="challengeModal" style="display:none" onclick="if(event.target===this)closeChallengeModal()">
        <div class="modal-box" style="max-width:420px">
            <div class="modal-head">
                <div class="modal-title">安全验证</div>
                <button class="modal-close" onclick="closeChallengeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="color:var(--text-secondary);margin-bottom:16px">请在 SSH 中执行以下命令生成确认码：</p>
                <div class="code-block">ym-admin challenge</div>
                <div style="margin-top:16px">
                    <label class="form-label">输入 6 位确认码</label>
                    <input class="form-input" type="text" id="challengeCodeInput" placeholder="例如: A3B9F2" maxlength="6" style="text-transform:uppercase;letter-spacing:4px;font-size:1.2em;text-align:center" autocomplete="off">
                </div>
                <div style="margin-top:8px;color:var(--text-muted);font-size:0.82em">确认码 300 秒有效，每次操作后自动失效</div>
                <div id="challengeError" style="color:var(--danger);margin-top:8px;display:none"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeChallengeModal()">取消</button>
                <button class="btn btn-primary" onclick="submitChallenge()" id="submitChallengeBtn">确认并更新</button>
            </div>
        </div>
    </div>

    <!-- 手动更新（始终可见） -->
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>手动更新</div>
        <p style="color:var(--text-secondary);margin-bottom:12px">上传 ZIP 或 tar.gz 更新包手动升级系统。</p>
        <div>
            <button class="btn btn-secondary" onclick="document.getElementById('uploadPkgInput').click()">
                <svg viewBox="0 0 24 24" width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                上传更新包
            </button>
            <input type="file" id="uploadPkgInput" accept=".zip,.gz,.tar.gz" style="display:none">
        </div>
        <div id="manualUpdateProgress" style="display:none;margin-top:12px">
            <div class="update-progress">
                <div class="update-progress-step" id="mUpStep1"><span class="step-icon">⏳</span> 上传中...</div>
                <div class="update-progress-step" id="mUpStep2"><span class="step-icon">⏳</span> 解压验证中</div>
                <div class="update-progress-step" id="mUpStep3"><span class="step-icon">⏳</span> 部署中</div>
                <div class="update-progress-step" id="mUpStep4"><span class="step-icon">⏳</span> 完成</div>
            </div>
            <div style="margin-top:8px;color:var(--text-muted);font-size:0.85em" id="manualUpdateHint"></div>
        </div>
    </div>

    <!-- 更新历史 -->
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>更新历史</div>
        <div class="backup-list" id="backupList">
            <p style="color:var(--text-muted);font-size:0.85em">加载中...</p>
        </div>
    </div>

    <script>
    var currentUpdateStatus = null;
    var updatePollTimer = null;
    var pendingUpdateVersion = '';
    var pendingUpdatePath = '';

    function switchChannel(ch) {
        var fd = new FormData();
        fd.append('ajax', 'save_channel');
        fd.append('channel', ch);
        fd.append('csrf_token', '<?= generateCsrfToken() ?>');
        fetch('<?= $_SERVER['SCRIPT_NAME'] ?>?tab=update', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    document.querySelectorAll('.channel-tab').forEach(function(t) {
                        t.classList.toggle('active', t.textContent.includes(ch === 'stable' ? '正式版' : '测试版'));
                    });
                    document.querySelector('.version-label').textContent = ch === 'beta' ? '测试版' : '正式版';
                }
            });
    }

    function checkUpdate() {
        var btn = document.getElementById('checkUpdateBtn');
        btn.disabled = true;
        btn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite">⟳</span> 检查中...';
        document.getElementById('updateCheckResult').style.display = 'none';
        fetch('<?= $_SERVER['SCRIPT_NAME'] ?>?tab=update&ajax=check_update')
            .then(function(r) { return r.json(); })
            .then(function(d) {
                btn.disabled = false;
                btn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> 检查更新';
                var resultDiv = document.getElementById('updateCheckResult');
                if (d.available) {
                    resultDiv.innerHTML = '<div class="msg msg-success" style="margin:0"><svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>发现新版本 <strong>v' + d.latest_version + '</strong>（当前 v' + d.current_version + '）</div>';
                    document.getElementById('updateActionCard').style.display = 'block';
                    document.getElementById('newVersionText').textContent = 'v' + d.latest_version;
                    pendingUpdateVersion = d.latest_version;
                } else {
                    resultDiv.innerHTML = '<div class="msg" style="margin:0;background:var(--accent-glass);color:var(--text)"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>当前已是最新版本 v' + d.current_version + '</div>';
                    document.getElementById('updateActionCard').style.display = 'none';
                }
                resultDiv.style.display = 'block';
            })
            .catch(function() {
                btn.disabled = false;
                btn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> 检查更新';
                var resultDiv = document.getElementById('updateCheckResult');
                resultDiv.innerHTML = '<div class="msg" style="margin:0;background:rgba(255,80,80,0.15);color:#ff6060"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>检查更新失败，请检查服务器网络连接</div>';
                resultDiv.style.display = 'block';
            });
    }

    function showChallengeModal() {
        document.getElementById('challengeModal').style.display = 'flex';
        document.getElementById('challengeCodeInput').value = '';
        document.getElementById('challengeError').style.display = 'none';
        setTimeout(function() { document.getElementById('challengeCodeInput').focus(); }, 100);
    }

    function closeChallengeModal() {
        document.getElementById('challengeModal').style.display = 'none';
    }

    function submitChallenge() {
        var code = document.getElementById('challengeCodeInput').value.trim().toUpperCase();
        if (!code || code.length < 4) {
            document.getElementById('challengeError').textContent = '请输入有效的确认码';
            document.getElementById('challengeError').style.display = 'block';
            return;
        }
        document.getElementById('submitChallengeBtn').disabled = true;
        document.getElementById('submitChallengeBtn').textContent = '验证中...';
        document.getElementById('challengeError').style.display = 'none';

        var formData = new FormData();
        formData.append('ajax', 'trigger_update');
        formData.append('csrf_token', '<?= generateCsrfToken() ?>');
        formData.append('challenge_code', code);
        formData.append('target_version', pendingUpdateVersion);
        formData.append('package_path', pendingUpdatePath);

        fetch('<?= $_SERVER['SCRIPT_NAME'] ?>', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            document.getElementById('submitChallengeBtn').disabled = false;
            document.getElementById('submitChallengeBtn').textContent = '确认并更新';
            if (d.success) {
                closeChallengeModal();
                startUpdateProgress(d.token);
            } else {
                document.getElementById('challengeError').textContent = d.error || '验证失败';
                document.getElementById('challengeError').style.display = 'block';
            }
        })
        .catch(function() {
            document.getElementById('submitChallengeBtn').disabled = false;
            document.getElementById('submitChallengeBtn').textContent = '确认并更新';
            document.getElementById('challengeError').textContent = '请求失败，请重试';
            document.getElementById('challengeError').style.display = 'block';
        });
    }

    function startUpdateProgress(token) {
        document.getElementById('updateActionContent').style.display = 'none';
        document.getElementById('updateProgressContent').style.display = 'block';
        document.getElementById('sshCommandText').textContent = 'ym-admin apply-update';
        document.getElementById('upStep1').className = 'update-progress-step active';
        document.getElementById('upStep1').innerHTML = '<span class="step-icon">⏳</span> 等待 SSH 确认';
        document.getElementById('upStep2').className = 'update-progress-step';
        document.getElementById('upStep3').className = 'update-progress-step';
        document.getElementById('upStep4').className = 'update-progress-step';

        // 开始轮询
        if (updatePollTimer) clearInterval(updatePollTimer);
        updatePollTimer = setInterval(function() {
            fetch('<?= $_SERVER['SCRIPT_NAME'] ?>?tab=update&ajax=update_status')
                .then(function(r) { return r.json(); })
                .then(function(s) {
                    currentUpdateStatus = s;
                    if (s.status === 'in_progress') {
                        document.getElementById('upStep1').className = 'update-progress-step completed';
                        document.getElementById('upStep1').innerHTML = '<span class="step-icon">✓</span> SSH 已确认';
                        document.getElementById('upStep2').className = 'update-progress-step active';
                        document.getElementById('upStep2').innerHTML = '<span class="step-icon">⏳</span> 备份中';
                        document.getElementById('upStep3').className = 'update-progress-step';
                        document.getElementById('upStep4').className = 'update-progress-step';
                        document.getElementById('updateProgressHint').textContent = 'CLI 正在执行更新...';
                    } else if (s.status === 'completed') {
                        document.getElementById('upStep1').className = 'update-progress-step completed';
                        document.getElementById('upStep1').innerHTML = '<span class="step-icon">✓</span> SSH 已确认';
                        document.getElementById('upStep2').className = 'update-progress-step completed';
                        document.getElementById('upStep2').innerHTML = '<span class="step-icon">✓</span> 备份完成';
                        document.getElementById('upStep3').className = 'update-progress-step completed';
                        document.getElementById('upStep3').innerHTML = '<span class="step-icon">✓</span> 更新完成';
                        document.getElementById('upStep4').className = 'update-progress-step completed';
                        document.getElementById('upStep4').innerHTML = '<span class="step-icon">✓</span> 完成';
                        document.getElementById('updateProgressHint').innerHTML = '✅ 更新成功！v' + s.from_version + ' → v' + s.to_version;
                        if (updatePollTimer) { clearInterval(updatePollTimer); updatePollTimer = null; }
                        setTimeout(function() { location.reload(); }, 3000);
                    } else if (s.status === 'failed') {
                        document.getElementById('updateProgressHint').innerHTML = '❌ 更新失败：' + (s.error || '未知错误');
                        if (updatePollTimer) { clearInterval(updatePollTimer); updatePollTimer = null; }
                    }
                });
        }, 3000);
    }

    // 上传更新包（手动更新区域）
    document.addEventListener('DOMContentLoaded', function() {
        var uploadInput = document.getElementById('uploadPkgInput');
        if (uploadInput) {
            uploadInput.addEventListener('change', function() {
                if (!this.files || !this.files[0]) return;
                var file = this.files[0];
                var formData = new FormData();
                formData.append('ajax', 'upload_package');
                formData.append('csrf_token', '<?= generateCsrfToken() ?>');
                formData.append('update_package', file);

                // 显示手动更新进度
                var progress = document.getElementById('manualUpdateProgress');
                progress.style.display = 'block';
                document.getElementById('mUpStep1').className = 'update-progress-step active';
                document.getElementById('manualUpdateHint').textContent = '正在上传 ' + file.name + '...';

                fetch('<?= $_SERVER['SCRIPT_NAME'] ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success) {
                        document.getElementById('mUpStep1').className = 'update-progress-step completed';
                        document.getElementById('mUpStep2').className = 'update-progress-step active';
                        document.getElementById('manualUpdateHint').textContent = '解压验证中...';
                        setTimeout(function() {
                            document.getElementById('mUpStep2').className = 'update-progress-step completed';
                            document.getElementById('mUpStep3').className = 'update-progress-step active';
                            document.getElementById('manualUpdateHint').textContent = '更新包已上传，请通过 SSH 执行: ym-admin apply-update';
                            // 也显示到执行更新卡片
                            var verText = d.target_version ? 'v' + d.target_version : '未知版本';
                            document.getElementById('updateActionCard').style.display = 'block';
                            document.getElementById('newVersionText').textContent = verText;
                            pendingUpdateVersion = d.target_version || 'uploaded';
                        }, 800);
                    } else {
                        document.getElementById('mUpStep1').className = 'update-progress-step failed';
                        document.getElementById('manualUpdateHint').textContent = '上传失败：' + (d.error || '未知错误');
                    }
                })
                .catch(function() {
                    document.getElementById('mUpStep1').className = 'update-progress-step failed';
                    document.getElementById('manualUpdateHint').textContent = '上传失败：网络错误';
                });
            });
        }

        // 加载更新历史
        loadBackupHistory();
    });

    function loadBackupHistory() {
        var list = document.getElementById('backupList');
        fetch('<?= $_SERVER['SCRIPT_NAME'] ?>?tab=update&ajax=update_status')
            .then(function(r) { return r.json(); })
            .then(function(s) {
                var html = '';
                if (s.status !== 'idle') {
                    html += '<div class="backup-item"><div class="backup-info"><span class="backup-version">更新 ' + s.from_version + ' → ' + s.to_version + '</span><span class="backup-time">' + (s.completed_at ? new Date(s.completed_at * 1000).toLocaleString() : '进行中') + '</span></div><span class="backup-status ' + s.status + '">' + ({'pending':'等待中','in_progress':'进行中','completed':'已完成','failed':'失败'}[s.status] || s.status) + '</span></div>';
                } else {
                    html = '<p style="color:var(--text-muted);font-size:0.85em">暂无更新记录</p>';
                }
                list.innerHTML = html;
            })
            .catch(function() {
                list.innerHTML = '<p style="color:var(--text-muted);font-size:0.85em">加载失败</p>';
            });
    }
    </script>

    <?php elseif ($tab === 'guard'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
            守护进程
        </div>
        <div class="page-subtitle">文件完整性保护与自动恢复</div>
    </div>
    <?php
    $guardStatus = 'unknown';
    $guardPid = false;
    exec('systemctl is-active ym-guard 2>/dev/null', $output, $code);
    $guardStatus = $code === 0 ? 'active' : 'inactive';
    $guardPid = trim(shell_exec('systemctl show ym-guard -p MainPID --value 2>/dev/null') ?? '');
    ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            守护进程状态
        </div>
        <table>
            <tr><td style="color:var(--text-muted)">状态</td>
                <td>
                    <span class="chain-badge <?= $guardStatus === 'active' ? 'chain-valid' : 'chain-invalid' ?>">
                        <?= $guardStatus === 'active' ? '● 运行中' : '● 未运行' ?>
                    </span>
                </td>
            </tr>
            <tr><td style="color:var(--text-muted)">PID</td><td><code><?= $guardPid ?: 'N/A' ?></code></td></tr>
        </table>
    </div>

    <?php elseif ($tab === 'hfish'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="3" y1="3" x2="21" y2="21"/></svg>
            蜜罐安全
        </div>
        <div class="page-subtitle">HFish 蜜罐攻击日志与自动封禁（攻击≥阈值自动封禁登录/注册/评论）</div>
    </div>
    <?php
    $hfishSnapshot = [];
    $hfishSnapFile = __DIR__ . '/../data/.hfish_snapshot.json';
    if (file_exists($hfishSnapFile)) {
        $hfishSnapshot = json_decode(file_get_contents($hfishSnapFile), true) ?: [];
    }
    $hfishAttacks = $hfishSnapshot['attacks'] ?? [];
    $hfishThreshold = $hfishSnapshot['threshold'] ?? 3;
    $hfishUpdated = $hfishSnapshot['updated_at'] ?? '从未同步';
    $hfishError = $hfishSnapshot['error'] ?? '';
    $hfishBannedCount = count(array_filter($hfishAttacks, fn($a) => !empty($a['banned'])));
    ?>
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>蜜罐状态</div>
        <div class="table-wrap"><table>
            <tr><td style="color:var(--text-muted)">最近同步时间</td><td><?= htmlspecialchars($hfishUpdated) ?></td></tr>
            <tr><td style="color:var(--text-muted)">自动封禁阈值</td><td>攻击次数 ≥ <strong><?= intval($hfishThreshold) ?></strong> 次 → 封禁（登录/注册/评论）</td></tr>
            <tr><td style="color:var(--text-muted)">攻击 IP 记录</td><td><?= count($hfishAttacks) ?> 条（已封禁 <?= $hfishBannedCount ?> 条）</td></tr>
            <?php if ($hfishError): ?>
            <tr><td style="color:var(--text-muted)">数据源状态</td><td><span style="color:#ef4444"><?= htmlspecialchars($hfishError) ?></span></td></tr>
            <?php endif; ?>
        </table></div>
        <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
            <button class="btn btn-primary" onclick="hfishSync()" id="hfishSyncBtn">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                立即同步
            </button>
            <span id="hfishSyncMsg" style="font-size:0.85em;color:var(--text-muted)"></span>
        </div>
        <p style="font-size:0.82em;color:var(--text-muted);margin-top:10px">守护进程每 5 分钟自动同步一次；点击「立即同步」可即时拉取蜜罐攻击记录并执行封禁检查。</p>
    </div>

    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>攻击 IP 列表（<?= count($hfishAttacks) ?>）</div>
        <?php if (empty($hfishAttacks)): ?>
        <div class="empty-state"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg><p>暂无攻击记录（或蜜罐尚未同步）</p></div>
        <?php else: ?>
        <div class="table-wrap"><table>
            <tr><th>IP</th><th>攻击次数</th><th>攻击行为</th><th>命中蜜罐</th><th>UA</th><th>最近时间</th><th>状态</th></tr>
            <?php foreach ($hfishAttacks as $a): ?>
            <tr>
                <td><code><?= htmlspecialchars($a['ip'] ?? '') ?></code></td>
                <td><strong><?= intval($a['attack_cnt'] ?? 0) ?></strong><?= intval($a['attack_cnt'] ?? 0) >= $hfishThreshold ? ' <span class="chain-badge chain-invalid">已达阈值</span>' : '' ?></td>
                <td style="font-size:0.85em"><?= htmlspecialchars(implode(', ', array_keys($a['styles'] ?? [])) ?: '-') ?></td>
                <td style="font-size:0.85em"><?= htmlspecialchars(implode(', ', array_keys($a['honeypots'] ?? [])) ?: '-') ?></td>
                <td style="font-size:0.82em;color:var(--text-muted)"><?= htmlspecialchars(implode(', ', array_keys($a['uas'] ?? [])) ?: '-') ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($a['date'] ?? '') ?></td>
                <td><?= !empty($a['banned']) ? '<span class="chain-badge chain-invalid">已封禁</span>' : '<span class="chain-badge chain-valid">未封禁</span>' ?></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
    </div>
    <script>
    function hfishSync() {
        var btn = document.getElementById('hfishSyncBtn');
        var msg = document.getElementById('hfishSyncMsg');
        btn.disabled = true;
        msg.textContent = '同步中...';
        var fd = new FormData();
        fd.append('ajax', 'hfish_sync');
        fd.append('csrf_token', '<?= generateCsrfToken() ?>');
        fetch('<?= $_SERVER['SCRIPT_NAME'] ?>?tab=hfish', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                btn.disabled = false;
                if (d.success) { msg.textContent = '同步成功'; setTimeout(function() { location.reload(); }, 800); }
                else { msg.textContent = '同步失败：' + (d.output || d.error || '未知错误'); }
            })
            .catch(function() { btn.disabled = false; msg.textContent = '请求失败'; });
    }
    </script>
    <?php endif; ?>
</div>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}
</script>
<?php if (isset($_GET['logout'])): ?>
<?php
auditLog('logout', getCurrentUserId(), '超管登出');
session_unset();
session_destroy();
header('Location: /');
exit;
?>
<?php endif; ?>
</body>
</html>