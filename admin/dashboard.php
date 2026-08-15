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

// v2.10.0-fix：登出处理前置（HTML 输出前，避免 header 失效导致退不出去）。
// 消费型 CSRF token 被页面内其他表单消耗后，同源请求走 Referer/Origin 兜底强制销毁；
// 同时吊销 JWT jti（入黑名单）+ 清除 PHPSESSID cookie，杜绝 session 残留导致超管会话复活。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    $tokenOk = verifyCsrfToken($_POST['csrf_token'] ?? '');
    $ref = $_SERVER['HTTP_REFERER'] ?? $_SERVER['HTTP_ORIGIN'] ?? '';
    $sameOrigin = ($ref !== '' && parse_url($ref, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? ''));
    if ($tokenOk || $sameOrigin) {
        auditLog('logout', getCurrentUserId(), '超管登出' . ($tokenOk ? '' : '（CSRF token 已失效，同源兜底销毁）'));
        revokeCurrentJWT();
        session_unset();
        session_destroy();
        if (ini_get('session.use_cookies')) {
            $cp = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $cp['path'], $cp['domain'], $cp['secure'], $cp['httponly']);
        }
    }
    header('Location: /?logged_out=1');
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
    // 切换更新通道属敏感操作，需服务器挑战码
    if (!verifyChallenge($_POST['challenge_code'] ?? '')) {
        echo json_encode(['success' => false, 'error' => '挑战码无效或已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ch = ($_POST['channel'] ?? 'stable') === 'beta' ? 'beta' : 'stable';
    $config['update_channel'] = $ch;
    saveSiteConfig($config);
    echo json_encode(['success' => true, 'channel' => $ch], JSON_UNESCAPED_UNICODE);
    exit;
}

// POST 处理：保存自动备份配置（敏感操作：CSRF + 挑战码 + 审计）
if (isset($_POST['save_backup_config'])) {
    if (!checkCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=data&msg=csrf_error');
        exit;
    }
    if (!verifyChallenge($_POST['challenge_code'] ?? '')) {
        header('Location: dashboard.php?tab=data&msg=challenge_failed');
        exit;
    }
    $bkInterval = (int)($_POST['interval_min'] ?? 0);
    $bkArtKeep = (int)($_POST['article_keep'] ?? 0);
    $bkManKeep = (int)($_POST['manual_keep'] ?? 0);
    $bkOk = saveBackupConfig($bkInterval, $bkArtKeep, $bkManKeep);
    auditLog('backup_config_update', 'backup.conf', "数据库备份间隔={$bkInterval}分钟/文章保留{$bkArtKeep}份/手动保留{$bkManKeep}份", $bkOk ? 'success' : 'failed');
    header('Location: dashboard.php?tab=data&msg=' . ($bkOk ? 'saved' : 'save_failed'));
    exit;
}

// POST 处理：保存 SMTP 邮件配置（敏感操作：CSRF + 挑战码 + 审计）
if (isset($_POST['save_smtp_config'])) {
    if (!checkCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=mail&msg=csrf_error');
        exit;
    }
    if (!verifyChallenge($_POST['challenge_code'] ?? '')) {
        header('Location: dashboard.php?tab=mail&msg=challenge_failed');
        exit;
    }
    $smtpOk = saveSmtpConfig(
        $_POST['smtp_host'] ?? '',
        (int)($_POST['smtp_port'] ?? 465),
        $_POST['smtp_user'] ?? '',
        $_POST['smtp_pass'] ?? '',
        $_POST['smtp_from'] ?? '',
        $_POST['smtp_enc'] ?? 'ssl'
    );
    auditLog('smtp_config_update', 'config(smtp)', 'SMTP 邮件配置更新', $smtpOk ? 'success' : 'failed');
    header('Location: dashboard.php?tab=mail&msg=' . ($smtpOk ? 'saved' : 'save_failed'));
    exit;
}

// POST 处理：保存注册验证配置（v2.9.0，敏感操作：CSRF + 挑战码 + 审计）
if (isset($_POST['save_verify_config'])) {
    if (!checkCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=verify&msg=csrf_error');
        exit;
    }
    if (!verifyChallenge($_POST['challenge_code'] ?? '')) {
        header('Location: dashboard.php?tab=verify&msg=challenge_failed');
        exit;
    }
    $config['email_verify_enabled'] = !empty($_POST['email_verify_enabled']);
    // v2.11.0：滑块人机验证已彻底移除（captcha_enabled 配置删除）
    $config['author_dual_verify_enabled'] = !empty($_POST['author_dual_verify_enabled']);
    $config['verify_code_ttl'] = max(60, (int)($_POST['verify_code_ttl'] ?? 300));
    $config['confirm_link_ttl'] = max(300, (int)($_POST['confirm_link_ttl'] ?? 86400));
    $config['resend_cooldown'] = max(10, (int)($_POST['resend_cooldown'] ?? 60));
    saveSiteConfig($config);
    auditLog('verify_config_update', 'config', '注册验证与双重确认配置更新');
    header('Location: dashboard.php?tab=verify&msg=saved');
    exit;
}

// AJAX 处理：发送测试邮件（敏感操作：CSRF + 挑战码）
if (isset($_POST['ajax']) && $_POST['ajax'] === 'test_smtp') {
    header('Content-Type: application/json; charset=utf-8');
    if (!checkCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'csrf_error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!verifyChallenge($_POST['challenge_code'] ?? '')) {
        echo json_encode(['success' => false, 'error' => '挑战码无效或已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $mailTo = $config['admin_email'] ?? '';
    if ($mailTo === '') {
        echo json_encode(['success' => false, 'error' => '请先在系统配置中设置管理员邮箱（admin_email）'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $site = $config['site_title'] ?? 'You Super Markdown';
    $mailBody = "这是一封来自 {$site} 的邮件配置测试。\n"
        . "如果你收到此邮件，说明 SMTP 配置正确，告警邮件可以正常发送。\n"
        . "时间：" . date('Y-m-d H:i:s') . "\n";
    $mailHtml = renderMailHtml($site, '邮件配置测试', $mailBody);
    [$mailOk, $mailErr] = sendSmtpMail($mailTo, "[{$site} 邮件配置测试]", $mailBody, $mailHtml);
    auditLog('smtp_test', $mailTo, '发送测试邮件', $mailOk ? 'success' : 'failed');
    echo json_encode(['success' => $mailOk, 'error' => $mailOk ? '' : $mailErr], JSON_UNESCAPED_UNICODE);
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

// AJAX 处理：获取更新历史（v2.6.6 支持分页 + 搜索 + 每页条数自定义；从审计日志读取）
if (isset($_GET['ajax']) && $_GET['ajax'] === 'update_history') {
    header('Content-Type: application/json; charset=utf-8');
    $uhQ = trim((string)($_GET['q'] ?? ''));
    $uhPerPage = (int)($_GET['per_page'] ?? 20);
    if (!in_array($uhPerPage, [10, 20, 50, 100], true)) $uhPerPage = 20;
    $uhData = paginateList(getUpdateHistory(100), ['from_version', 'to_version', 'completed_at', 'status'], $uhQ, (int)($_GET['page'] ?? 1), $uhPerPage);
    echo json_encode([
        'history' => $uhData['items'],
        'total' => $uhData['total'],
        'page' => $uhData['page'],
        'pages' => $uhData['pages'],
        'per_page' => $uhPerPage,
    ], JSON_UNESCAPED_UNICODE);
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
    // 防注入：目标版本仅允许数字与点（避免写入 utils.php 时破坏/注入代码）
    if (!preg_match('/^[\d.]+$/', $targetVersion)) {
        echo json_encode(['success' => false, 'error' => '目标版本格式非法'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 读取上传的更新包路径（仅接受已上传到固定目录的路径，防止任意路径注入）
    $pkgPath = trim($_POST['package_path'] ?? '');
    if ($pkgPath !== '' && strpos($pkgPath, '/tmp/ym-update-packages/') !== 0) {
        echo json_encode(['success' => false, 'error' => '更新包路径非法'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 挑战码尝试限次（防暴力枚举：连续 5 次失败锁定 60 秒）
    $nowTs = time();
    if ($nowTs < (int)($_SESSION['challenge_lock_until'] ?? 0)) {
        echo json_encode(['success' => false, 'error' => '尝试过于频繁，请 60 秒后重试'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!verifyChallenge($challengeCode)) {
        $_SESSION['challenge_fails'] = (int)($_SESSION['challenge_fails'] ?? 0) + 1;
        if ($_SESSION['challenge_fails'] >= 5) {
            $_SESSION['challenge_lock_until'] = time() + 60;
            $_SESSION['challenge_fails'] = 0;
        }
        echo json_encode(['success' => false, 'error' => '挑战码无效或已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION['challenge_fails'] = 0;
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
    } elseif (!verifyChallenge($_POST['challenge_code'] ?? '')) {
        $msg = 'challenge_error';
    } elseif ($_POST['action'] === 'create_user') {
        $newRole = $_POST['role'] ?? ROLE_USER;
        if (!in_array($newRole, [ROLE_STATION_ADMIN, ROLE_AUTHOR, ROLE_USER])) $newRole = ROLE_USER;
        $newNick = trim($_POST['nickname'] ?? '');
        $newQQ = trim($_POST['qq'] ?? '');
        $newPwd = trim($_POST['password'] ?? '');
        if ($newNick && $newQQ && $newPwd) {
            $vp = validatePassword($newPwd);
            if ($vp !== true) {
                $msg = 'pw_weak';
            } else {
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
                        'role' => $newRole,
                        'created' => date('Y-m-d H:i:s'),
                        'created_by' => getCurrentUserId(),
                    ];
                    saveUsers($users);
                    auditLog('user_create', $newQQ, "创建用户: {$newNick}, 角色: {$newRole}");
                    $msg = 'user_created';
                }
            }
        }
    } elseif ($_POST['action'] === 'delete_user') {
        $delId = $_POST['user_id'] ?? '';
        foreach ($users as $i => $u) {
            if ($u['id'] === $delId && ($u['role'] ?? '') !== ROLE_SUPER_ADMIN) {
                auditLog('user_delete', $u['qq'] ?? $delId, "删除用户: {$u['nickname']}");
                array_splice($users, $i, 1);
                saveUsers($users);
                $msg = 'user_deleted';
                break;
            }
        }
    } elseif ($_POST['action'] === 'set_user_role') {
        // v2.11.4：修改用户权限（站长/写作者/普通用户；不可操作超管，不可升级为超管）
        $uid = $_POST['user_id'] ?? '';
        $newRole = $_POST['role'] ?? '';
        if (!in_array($newRole, [ROLE_STATION_ADMIN, ROLE_AUTHOR, ROLE_USER], true)) {
            $msg = 'role_invalid';
        } else {
            foreach ($users as &$uu) {
                if ($uu['id'] === $uid && ($uu['role'] ?? '') !== ROLE_SUPER_ADMIN) {
                    $oldRole = $uu['role'] ?? ROLE_USER;
                    $uu['role'] = $newRole;
                    // 从站长角色降级/离开站长体系时清空归属关系；进入写作者时不强行分配站长
                    if (($uu['station_id'] ?? '') !== '' && $oldRole === ROLE_STATION_ADMIN && $newRole !== ROLE_STATION_ADMIN) {
                        $uu['station_id'] = '';
                    }
                    auditLog('user_role_change', $uu['qq'] ?? $uid, "修改权限: {$uu['nickname']} {$oldRole} → {$newRole}");
                    $msg = 'role_changed';
                    break;
                }
            }
            unset($uu);
            if ($msg !== 'role_changed') $msg = 'role_invalid';
            saveUsers($users);
        }
    } elseif ($_POST['action'] === 'set_user_disabled') {
        // v2.11.4：禁用/启用账号（禁用后无法登录/评论/进后台，已登录会话立即失效）
        $uid = $_POST['user_id'] ?? '';
        $disabled = (int)($_POST['disabled'] ?? 0);
        foreach ($users as &$uu) {
            if ($uu['id'] === $uid && ($uu['role'] ?? '') !== ROLE_SUPER_ADMIN) {
                $uu['disabled'] = $disabled ? 1 : 0;
                auditLog('user_disable', $uu['qq'] ?? $uid, ($disabled ? '禁用账号: ' : '启用账号: ') . ($uu['nickname'] ?? ''));
                $msg = $disabled ? 'user_disabled' : 'user_enabled';
                break;
            }
        }
        unset($uu);
        saveUsers($users);
    } elseif ($_POST['action'] === 'set_user_station') {
        // v2.11.5：超管设定写作者归属站长
        $uid = $_POST['user_id'] ?? '';
        $stationId = $_POST['station_id'] ?? '';
        $stationValid = false;
        foreach ($users as $su) {
            if ($su['id'] === $stationId && ($su['role'] ?? '') === ROLE_STATION_ADMIN) { $stationValid = true; break; }
        }
        if (!$stationValid) {
            $msg = 'station_invalid';
        } else {
            $found = false;
            foreach ($users as &$uu) {
                if ($uu['id'] === $uid && ($uu['role'] ?? '') === ROLE_AUTHOR) {
                    $uu['station_id'] = $stationId;
                    auditLog('user_station_change', $uu['qq'] ?? $uid, "设定归属站长: {$uu['nickname']} → {$stationId}");
                    $found = true;
                    break;
                }
            }
            unset($uu);
            if (!$found) { $msg = 'station_invalid'; }
            else { saveUsers($users); $msg = 'station_changed'; }
        }
    } elseif ($_POST['action'] === 'reset_password') {
        // v2.11.5：重置用户密码（生成随机强密码，仅本次显示给超管）
        $uid = $_POST['user_id'] ?? '';
        $newPwd = randomPassword();
        $newHash = password_hash($newPwd, PASSWORD_DEFAULT);
        $found = false;
        foreach ($users as &$uu) {
            if ($uu['id'] === $uid && ($uu['role'] ?? '') !== ROLE_SUPER_ADMIN) {
                $uu['password'] = $newHash;
                auditLog('user_reset_pwd', $uu['qq'] ?? $uid, "重置密码: {$uu['nickname']}");
                $newQQForFlash = $uu['qq'] ?? '';
                $found = true;
                break;
            }
        }
        unset($uu);
        if (!$found) {
            $msg = 'reset_fail';
        } else {
            saveUsers($users);
            // 新密码通过 session flash 传递（避免明文进 URL）
            $_SESSION['flash_reset_pwd'] = ['qq' => $newQQForFlash ?? '', 'pwd' => $newPwd];
            $msg = 'pwd_reset';
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
    if (!verifyChallenge($_POST['challenge_code'] ?? '')) {
        header('Location: dashboard.php?tab=config&msg=challenge_error');
        exit;
    }
    $config['site_title'] = trim($_POST['site_title'] ?? 'You Super Markdown');
    $config['registration_enabled'] = !empty($_POST['registration_enabled']);
    $config['guest_comments_enabled'] = !empty($_POST['guest_comments_enabled']);
    // v2.6.5：超管主页评论开关（默认关=超管不参与前台评论/回复）
    $config['super_admin_comment'] = !empty($_POST['super_admin_comment']);
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
    $config['music_playlist_id_qq'] = trim($_POST['music_playlist_id_qq'] ?? '');
    $config['music_cookies'] = trim($_POST['music_cookies'] ?? '');
    $config['music_cookies_qq'] = trim($_POST['music_cookies_qq'] ?? '');
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
    if (!verifyChallenge($_POST['challenge_code'] ?? '')) {
        header('Location: dashboard.php?tab=security&bmsg=challenge_error');
        exit;
    }
    $act = $_POST['ban_action'];
    if ($act === 'add') {
        $ip = trim($_POST['ip'] ?? '');
        $types = $_POST['types'] ?? [];
        $reason = trim($_POST['reason'] ?? '');
        if ($ip && !empty($types)) {
            addBan($ip, $types, $reason);
            header('Location: dashboard.php?tab=security&bmsg=' . urlencode('封禁已添加'));
            exit;
        }
    } elseif ($act === 'remove') {
        $ip = trim($_POST['ip'] ?? '');
        db_exec('DELETE FROM bans WHERE ip = ?', [$ip]);
        header('Location: dashboard.php?tab=security&bmsg=' . urlencode('已解除封禁'));
        exit;
    } elseif ($act === 'update_types') {
        $ip = trim($_POST['ip'] ?? '');
        $types = $_POST['types'] ?? [];
        db_exec('UPDATE bans SET types_json = ? WHERE ip = ?', [json_encode($types, JSON_UNESCAPED_UNICODE), $ip]);
        header('Location: dashboard.php?tab=security&bmsg=' . urlencode('权限已更新'));
        exit;
    }
    header('Location: dashboard.php?tab=security&bmsg=error');
    exit;
}

// 清空操作日志（敏感操作：需挑战码确认；清空后同步镜像并留下审计痕迹）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_audit'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=security&bmsg=csrf_error');
        exit;
    }
    if (!verifyChallenge($_POST['challenge_code'] ?? '')) {
        header('Location: dashboard.php?tab=security&bmsg=challenge_error');
        exit;
    }
    clearAuditLogs();
    auditLog('audit_cleared', '', '清空全部操作日志（已挑战码确认）');
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
    db_exec('DELETE FROM unauthorized');
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
        // bg_image 仅允许站内 data/bg/ 路径或 http(s) URL，防止 CSS 值注入
        $bgImage = trim($_POST['bg_image']??'');
        if ($bgImage !== '' && strpos($bgImage, 'data/bg/') !== 0 && !preg_match('#^https?://#i', $bgImage)) $bgImage = '';
        $config['bg_image'] = $bgImage;
        $config['bg_api_url'] = trim($_POST['bg_api_url']??'');
        $config['bg_blur_enabled'] = !empty($_POST['bg_blur_enabled']);
        $config['bg_blur_level'] = max(0, min(50, intval($_POST['bg_blur_level']??0)));
        $config['bg_card_opacity'] = max(20, min(100, intval($_POST['bg_card_opacity']??100)));
        saveSiteConfig($config);
        header('Location: dashboard.php?tab=background&msg=saved');
        exit;
    }
}
// 封禁相关函数（全局可用，代理到 utils.php 的 SQLite 实现）
function loadBans() {
    return loadBansList();
}
function saveBans($bans) {
    return saveBansList($bans);
}
$banMsg = $_GET['bmsg'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-admin="super">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>高级管理员后台 - <?= htmlspecialchars($siteTitle) ?></title>
<link rel="stylesheet" href="../css/admin.css?v=<?= @filemtime(__DIR__ . '/../css/admin.css') ?>">
</head>
<body>

<!-- v3.1.5：移动端顶栏（菜单按钮融入顶栏，与后台统一视觉） -->
<div class="mobile-topbar" id="mobileTopbar">
    <button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleSidebar()" aria-label="打开菜单">
        <svg viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="mobile-topbar-title">超管后台</div>
    <div class="mobile-topbar-user">
        <span class="mobile-topbar-name">超管</span>
    </div>
</div>
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
        <a href="dashboard.php?tab=data" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'data' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
            数据管理
        </a>
        <a href="dashboard.php?tab=hfish" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'hfish' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="3" y1="3" x2="21" y2="21"/></svg>
            蜜罐安全
        </a>
        <a href="dashboard.php?tab=mail" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'mail' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            邮件设置
        </a>
        <a href="dashboard.php?tab=verify" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'verify' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/><circle cx="12" cy="13" r="1"/></svg>
            注册验证
        </a>
        <a href="#" onclick="logoutSubmit(event)" class="sidebar-link danger">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            退出登录
        </a>
    </nav>
</div>

<div class="main">
    <?php if ($msg === 'saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>配置已保存</div><?php endif; ?>
    <?php if ($msg === 'user_created'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>用户已创建</div><?php endif; ?>
    <?php if ($msg === 'user_deleted'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>用户已删除</div><?php endif; ?>
    <?php if ($msg === 'user_disabled'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>账号已禁用，该用户已无法登录/评论</div><?php endif; ?>
    <?php if ($msg === 'user_enabled'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>账号已启用</div><?php endif; ?>
    <?php if ($msg === 'role_changed'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>用户权限已修改</div><?php endif; ?>
    <?php if ($msg === 'role_invalid'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>权限修改无效（无法操作超管）</div><?php endif; ?>
    <?php if ($msg === 'station_changed'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>写作者归属站长已更新</div><?php endif; ?>
    <?php if ($msg === 'station_invalid'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>归属设置无效（仅写作者可归属站长）</div><?php endif; ?>
    <?php if ($msg === 'pwd_reset'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>密码已重置，请立即保存并告知用户</div><?php endif; ?>
    <?php if ($msg === 'reset_fail'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>重置失败（无法操作超管）</div><?php endif; ?>
    <?php if (!empty($_SESSION['flash_reset_pwd'])): ?>
    <?php $flashPwd = $_SESSION['flash_reset_pwd']; unset($_SESSION['flash_reset_pwd']); ?>
    <div class="msg msg-warn" style="border:1px dashed var(--accent);background:var(--accent-light,#f0f5ff)">
        <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        用户 <strong><?= htmlspecialchars($flashPwd['qq'] ?? '') ?></strong> 的新密码：<code style="font-size:1.1em;font-weight:700"><?= htmlspecialchars($flashPwd['pwd'] ?? '') ?></code>
        （仅本次显示，请立即复制并安全告知用户）
    </div>
    <?php endif; ?>
    <?php if ($msg === 'qq_duplicate'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>该账号已存在，请更换</div><?php endif; ?>
    <?php if ($msg === 'pw_weak'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>密码至少 8 位，且必须包含大写字母、小写字母与数字</div><?php endif; ?>
    <?php if ($msg === 'challenge_error'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>挑战码无效或已过期，请重新生成</div><?php endif; ?>

    <?php
    $tab = $_GET['tab'] ?? 'overview';
    $superCount = count(array_filter($users, fn($u) => ($u['role'] ?? '') === ROLE_SUPER_ADMIN));
    $stationCount = count(array_filter($users, fn($u) => ($u['role'] ?? '') === ROLE_STATION_ADMIN));
    $authorCount = count(array_filter($users, fn($u) => ($u['role'] ?? '') === ROLE_AUTHOR));
    $userCount = count(array_filter($users, fn($u) => ($u['role'] ?? 'user') === ROLE_USER));

    // v2.6.6：日志分页控件渲染（GET 无副作用，无需 CSRF）
    // $p: paginateList() 结果；$pageParam: 页码参数名；$extra: 需保持的额外查询参数（如 tab/q，不含分页参数）
    // $perPageParam: 每页条数参数名。分页栏 = 总数+每页条数选择 + 页码按钮 + 页码跳转（每页自定义 v2.6.6 同次更新）
    function renderPager(array $p, string $pageParam, array $extra = [], string $perPageParam = 'per_page'): string {
        $page = $p['page'];
        $pages = $p['pages'];
        $perPage = (int)($p['per_page'] ?? 50);
        $enc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        // 页码按钮 URL 模板：固定 per_page，page 用 __PAGE__ 占位
        $urlTpl = 'dashboard.php?' . http_build_query(array_merge($extra, [$perPageParam => $perPage, $pageParam => '__PAGE__']));
        $link = fn($pg) => $enc(str_replace('__PAGE__', (string)$pg, $urlTpl));
        // 每页条数切换 URL 模板：per_page 用 __PP__ 占位，页码回第 1 页
        $ppTpl = 'dashboard.php?' . http_build_query(array_merge($extra, [$perPageParam => '__PP__', $pageParam => 1]));
        // 页码跳转 URL 模板：page 用 __PG__ 占位
        $pgTpl = 'dashboard.php?' . http_build_query(array_merge($extra, [$perPageParam => $perPage, $pageParam => '__PG__']));
        // 单页时仅保留「总数 + 每页条数选择器」，隐藏页码按钮与跳转区（避免分页栏整体消失）
        $showNav = $pages > 1;
        // 页码序列：页数少全显示，多则首末+当前±1+省略号
        $seq = [];
        if ($pages <= 7) {
            $seq = range(1, $pages);
        } else {
            $seq = [1];
            for ($i = max(2, $page - 1); $i <= min($pages - 1, $page + 1); $i++) $seq[] = $i;
            $seq[] = $pages;
            $seq = array_values(array_unique($seq));
        }
        $html = '<nav class="pagination">';
        // 左：总数 + 每页条数（始终显示）
        $html .= '<div class="pagination-info"><span class="page-info">共 <strong>' . $p['total'] . '</strong> 条</span>'
               . '<label class="per-page">每页 <select data-pp-url="' . $enc($ppTpl) . '" onchange="if(this.dataset.ppUrl)location.href=this.dataset.ppUrl.replace(\'__PP__\',this.value)">';
        foreach ([10, 20, 50, 100] as $opt) {
            $html .= '<option value="' . $opt . '"' . ($opt === $perPage ? ' selected' : '') . '>' . $opt . '</option>';
        }
        $html .= '</select> 条</label></div>';
        // 中：页码按钮（仅多页时显示）
        if ($showNav) {
            $html .= '<div class="pagination-btns">';
            $html .= '<a class="page-btn" href="' . $link(1) . '" title="首页">« 首页</a>';
            $html .= '<a class="page-btn" href="' . $link(max(1, $page - 1)) . '" title="上一页">‹ 上一页</a>';
            $prev = 0;
            foreach ($seq as $pg) {
                if ($pg - $prev > 1) $html .= '<span class="page-btn page-ellipsis">…</span>';
                $html .= ($pg === $page)
                    ? '<span class="page-btn current">' . $pg . '</span>'
                    : '<a class="page-btn" href="' . $link($pg) . '">' . $pg . '</a>';
                $prev = $pg;
            }
            $html .= '<a class="page-btn" href="' . $link(min($pages, $page + 1)) . '" title="下一页">下一页 ›</a>';
            $html .= '<a class="page-btn" href="' . $link($pages) . '" title="末页">末页 »</a>';
            $html .= '</div>';
        }
        // 右：页码信息 + 跳转（仅多页时显示）
        if ($showNav) {
            $html .= '<div class="pagination-jump"><span class="page-info">第 ' . $page . ' / ' . $pages . ' 页</span>'
                   . '<input type="number" class="page-jump" min="1" max="' . $pages . '" placeholder="页码" data-pg-url="' . $enc($pgTpl) . '" '
                   . 'onchange="if(this.value&&this.dataset.pgUrl)location.href=this.dataset.pgUrl.replace(\'__PG__\',this.value)" '
                   . 'onkeydown="if(event.key===\'Enter\')this.onchange()">'
                   . '<button type="button" class="page-btn" onclick="var i=this.previousElementSibling;if(i&&i.value&&i.dataset.pgUrl)location.href=i.dataset.pgUrl.replace(\'__PG__\',i.value)">跳转</button></div>';
        }
        return $html . '</nav>';
    }
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
            <tr><td style="color:var(--text-muted)">超管主页评论</td><td><?= empty($config['super_admin_comment']) ? '关闭' : '开启' ?></td></tr>
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
        <div class="page-subtitle">管理站长 / 写作者 / 普通用户：查看详情、修改权限、归属设置、禁用启用、重置密码与删除（敏感操作需 SSH 挑战码）</div>
    </div>
    <?php
    $users = loadUsers();
    ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
            创建用户
        </div>
        <form method="post" class="need-challenge">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_user">
            <input type="hidden" name="challenge_code">
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
                    <label class="form-label">密码（至少 8 位，含大小写字母与数字）</label>
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

    <?php
    // ===== v2.11.5：用户分组 + 关联统计（评论/文章/登录） =====
    $statComments = [];
    foreach (db_all('SELECT user_id, COUNT(*) c FROM comments GROUP BY user_id') as $r) $statComments[$r['user_id']] = (int)$r['c'];
    $statArticles = [];
    if (is_dir(__DIR__ . '/../data/articles')) {
        foreach (glob(__DIR__ . '/../data/articles/*.md') as $af) {
            $raw = @file_get_contents($af);
            if ($raw && preg_match('/<!--META(.*?)-->/s', $raw, $am)) {
                $meta = json_decode(trim($am[1]), true) ?: [];
                $aid = $meta['author_id'] ?? '';
                if ($aid !== '') $statArticles[$aid] = ($statArticles[$aid] ?? 0) + 1;
            }
        }
    }
    $stationNames = [];
    foreach ($users as $su) { if (($su['role'] ?? '') === ROLE_STATION_ADMIN) $stationNames[$su['id']] = ($su['nickname'] ?: $su['qq']); }
    $superAdmins = []; $groupStations = []; $groupAuthors = []; $groupUsers = [];
    foreach ($users as $uu) {
        $r = $uu['role'] ?? 'user';
        if ($r === ROLE_SUPER_ADMIN) $superAdmins[] = $uu;
        elseif ($r === ROLE_STATION_ADMIN) $groupStations[] = $uu;
        elseif ($r === ROLE_AUTHOR) $groupAuthors[] = $uu;
        else $groupUsers[] = $uu;
    }
    function ymUserDetail($u, $stationNames, $statComments, $statArticles) {
        // v3.0.5：归属统一以超管为顶级——站长归属=超管；写作者未指定站长时归属=超管
        $role = $u['role'] ?? 'user';
        if ($role === ROLE_STATION_ADMIN) {
            $stationLabel = '超管';
        } elseif ($role === ROLE_AUTHOR) {
            $stationLabel = $stationNames[$u['station_id'] ?? ''] ?? '超管';
        } else {
            $stationLabel = '';
        }
        return [
            'nickname' => $u['nickname'] ?? '', 'qq' => $u['qq'] ?? '',
            'email' => $u['email'] ?? '未绑定', 'role' => $role,
            'station' => $stationLabel,
            'disabled' => !empty($u['disabled']),
            'created' => $u['created'] ?? '', 'created_by' => $u['created_by'] ?? '',
            'signature' => $u['signature'] ?? '',
            'last_login' => $u['last_login'] ?? '从未登录',
            'login_count' => (int)($u['login_count'] ?? 0),
            'comments' => $statComments[$u['id']] ?? 0,
            'articles' => $statArticles[$u['id']] ?? 0,
        ];
    }
    function ymStatusBadge($u) {
        if (!empty($u['disabled'])) return '<span class="role-badge" style="background:rgba(229,72,77,.12);color:var(--danger,#e5484d)">已禁用</span>';
        return '<span class="role-badge" style="background:rgba(52,199,89,.12);color:#34c759">正常</span>';
    }
    function ymOpMenu($u, $stationNames, $detail) {
        if (($u['role'] ?? '') === ROLE_SUPER_ADMIN) return '<span style="color:var(--text-muted);font-size:0.82em">—</span>';
        $uid = htmlspecialchars($u['id']);
        $isAuthor = ($u['role'] ?? '') === ROLE_AUTHOR;
        $name = htmlspecialchars($u['nickname'] ?? '');
        $qq = htmlspecialchars($u['qq'] ?? '');
        $roleKey = (string)($u['role'] ?? 'user');
        $roleLabel = ['station_admin' => '站长', 'author' => '写作者', 'user' => '普通用户'][$roleKey] ?? '普通用户';
        $initial = htmlspecialchars(mb_strlen($u['nickname'] ?? '') ? mb_substr((string)$u['nickname'], 0, 1) : '?');
        $statusTxt = !empty($u['disabled']) ? '已禁用' : '正常';
        $statusColor = !empty($u['disabled']) ? 'var(--danger)' : 'var(--text-muted)';
        $tok = htmlspecialchars(generateCsrfToken());
        // v3.0.3~v3.0.5：⋯ 按钮（SVG 竖排三点）+ 隐藏 template（内容注入单例 userOpModal 独立弹窗展示）
        $h = '<button type="button" class="user-op-btn" data-op-target="uop-' . $uid . '" title="管理用户"><svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button>';
        $h .= '<template id="uop-' . $uid . '">';
        // 用户信息头（头像按角色色光环）
        $h .= '<div class="user-op-modal-user"><div class="user-op-avatar role-' . htmlspecialchars($roleKey) . '">' . $initial . '</div><div><div class="user-op-user-name">' . $name . '</div><div class="user-op-user-meta"><span>UID: ' . $qq . '</span><span class="role-badge role-' . htmlspecialchars($roleKey) . '">' . $roleLabel . '</span><span style="color:' . $statusColor . '">' . $statusTxt . '</span></div></div></div>';
        // 查看详情（只读，无需挑战码）
        $h .= '<button type="button" class="user-op-item" data-detail=\'' . htmlspecialchars(json_encode($detail, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '\' onclick="ymOpenUserDetail(this)"><span class="user-op-ic"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span><span class="user-op-txt">查看详情</span></button>';
        // 权限设置
        $h .= '<div class="user-op-sep"></div><div class="user-op-head">权限设置</div>';
        $h .= '<form method="post" class="need-challenge"><input type="hidden" name="csrf_token" value="' . $tok . '"><input type="hidden" name="action" value="set_user_role"><input type="hidden" name="user_id" value="' . $uid . '"><input type="hidden" name="challenge_code">';
        $h .= '<div class="user-op-form"><div class="user-op-row"><span class="user-op-label">角色</span><select class="form-select" name="role">';
        foreach ([ROLE_STATION_ADMIN => '站长', ROLE_AUTHOR => '写作者', ROLE_USER => '普通用户'] as $rv => $rl) {
            $sel = ($u['role'] ?? '') === $rv ? ' selected' : '';
            $h .= '<option value="' . $rv . '"' . $sel . '>' . $rl . '</option>';
        }
        $h .= '</select><button type="submit" class="user-op-save">保存</button></div></div></form>';
        // 归属设置（仅写作者）
        if ($isAuthor) {
            $h .= '<div class="user-op-sep"></div><div class="user-op-head">归属设置</div>';
            $h .= '<form method="post" class="need-challenge"><input type="hidden" name="csrf_token" value="' . $tok . '"><input type="hidden" name="action" value="set_user_station"><input type="hidden" name="user_id" value="' . $uid . '"><input type="hidden" name="challenge_code">';
            $h .= '<div class="user-op-form"><div class="user-op-row"><span class="user-op-label">站长</span><select class="form-select" name="station_id">';
            $h .= '<option value="">超管</option>';
            foreach ($stationNames as $sid => $sname) {
                $sel = ($u['station_id'] ?? '') === $sid ? ' selected' : '';
                $h .= '<option value="' . htmlspecialchars($sid) . '"' . $sel . '>' . htmlspecialchars($sname) . '</option>';
            }
            $h .= '</select><button type="submit" class="user-op-save">保存</button></div></div></form>';
        }
        // 禁用/启用
        $confirm = !empty($u['disabled']) ? '确认启用 ' . $name . ' ？' : '确认禁用 ' . $name . ' ？禁用后该用户无法登录/评论，已登录会话立即失效';
        $h .= '<div class="user-op-sep"></div>';
        $h .= '<form method="post" class="need-challenge" data-confirm="' . htmlspecialchars($confirm, ENT_QUOTES) . '"><input type="hidden" name="csrf_token" value="' . $tok . '"><input type="hidden" name="action" value="set_user_disabled"><input type="hidden" name="user_id" value="' . $uid . '"><input type="hidden" name="disabled" value="' . (!empty($u['disabled']) ? '0' : '1') . '"><input type="hidden" name="challenge_code">';
        $h .= '<button type="submit" class="user-op-item">' . (!empty($u['disabled']) ? '<span class="user-op-ic"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg></span><span class="user-op-txt">启用账号</span>' : '<span class="user-op-ic"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="18" y1="8" x2="23" y2="13"/><line x1="23" y1="8" x2="18" y2="13"/></svg></span><span class="user-op-txt">禁用账号</span>') . '</button></form>';
        // 重置密码
        $h .= '<form method="post" class="need-challenge" data-confirm="确认重置 ' . $name . ' 的密码？将生成随机强密码并仅显示一次"><input type="hidden" name="csrf_token" value="' . $tok . '"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="' . $uid . '"><input type="hidden" name="challenge_code">';
        $h .= '<button type="submit" class="user-op-item"><span class="user-op-ic"><svg viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg></span><span class="user-op-txt">重置密码</span></button></form>';
        // 删除
        $h .= '<div class="user-op-sep"></div>';
        $h .= '<form method="post" class="need-challenge" data-confirm="确定删除 ' . $name . '？"><input type="hidden" name="csrf_token" value="' . $tok . '"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="' . $uid . '"><input type="hidden" name="challenge_code">';
        $h .= '<button type="submit" class="user-op-item danger"><span class="user-op-ic"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg></span><span class="user-op-txt">删除用户</span></button></form>';
        $h .= '</template>';
        return $h;
    }
    ?>

    <!-- v2.11.5：系统管理员（只读，不可操作） -->
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            系统管理员<span class="card-badge"><?= count($superAdmins) ?></span>
        </div>
        <div class="table-wrap"><table>
            <tr><th>昵称</th><th>UID（QQ）</th><th>角色</th><th>状态</th><th>创建时间</th></tr>
            <?php foreach ($superAdmins as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['nickname'] ?? '') ?></td>
                <td style="color:var(--text-muted)"><code><?= htmlspecialchars($u['qq'] ?? '') ?></code></td>
                <td><span class="role-badge role-super_admin"><?= htmlspecialchars($u['role'] ?? '') ?></span></td>
                <td><?= ymStatusBadge($u) ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($u['created'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
    </div>

    <!-- 站长 -->
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            站长<span class="card-badge"><?= count($groupStations) ?></span>
        </div>
        <div class="table-wrap"><table>
            <tr><th>昵称</th><th>UID（QQ）</th><th>状态</th><th>创建时间</th><th>操作</th></tr>
            <?php foreach ($groupStations as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['nickname'] ?? '') ?></td>
                <td style="color:var(--text-muted)"><code><?= htmlspecialchars($u['qq'] ?? '') ?></code></td>
                <td><?= ymStatusBadge($u) ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($u['created'] ?? '') ?></td>
                <td><?= ymOpMenu($u, $stationNames, ymUserDetail($u, $stationNames, $statComments, $statArticles)) ?></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
    </div>

    <!-- 写作者（含归属站长列与归属管理） -->
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
            写作者<span class="card-badge"><?= count($groupAuthors) ?></span>
        </div>
        <div class="table-wrap"><table>
            <tr><th>昵称</th><th>UID（QQ）</th><th>归属站长</th><th>状态</th><th>创建时间</th><th>操作</th></tr>
            <?php foreach ($groupAuthors as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['nickname'] ?? '') ?></td>
                <td style="color:var(--text-muted)"><code><?= htmlspecialchars($u['qq'] ?? '') ?></code></td>
                <td style="color:var(--text-secondary)"><?= htmlspecialchars($stationNames[$u['station_id'] ?? ''] ?? '超管') ?></td>
                <td><?= ymStatusBadge($u) ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($u['created'] ?? '') ?></td>
                <td><?= ymOpMenu($u, $stationNames, ymUserDetail($u, $stationNames, $statComments, $statArticles)) ?></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
    </div>

    <!-- 普通用户 -->
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            普通用户<span class="card-badge"><?= count($groupUsers) ?></span>
        </div>
        <div class="table-wrap"><table>
            <tr><th>昵称</th><th>UID（QQ）</th><th>状态</th><th>创建时间</th><th>操作</th></tr>
            <?php foreach ($groupUsers as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['nickname'] ?? '') ?></td>
                <td style="color:var(--text-muted)"><code><?= htmlspecialchars($u['qq'] ?? '') ?></code></td>
                <td><?= ymStatusBadge($u) ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($u['created'] ?? '') ?></td>
                <td><?= ymOpMenu($u, $stationNames, ymUserDetail($u, $stationNames, $statComments, $statArticles)) ?></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
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
    $q = trim((string)($_GET['q'] ?? ''));
    $perPage = (int)($_GET['per_page'] ?? 50);
    if (!in_array($perPage, [10, 20, 50, 100], true)) $perPage = 50;
    $pageData = paginateList(loadAuditLogs(), ['ts', 'user_name', 'user_id', 'role', 'action', 'target', 'detail', 'ip'], $q, (int)($_GET['page'] ?? 1), $perPage);
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
            最近操作记录（共 <?= $pageData['total'] ?> 条）
        </div>
        <div class="log-toolbar">
            <form class="log-toolbar-search" method="get" action="dashboard.php">
                <input type="hidden" name="tab" value="logs">
                <input type="text" class="form-input" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="搜索 时间/用户/角色/操作/目标/IP..." autocomplete="off">
                <button type="submit" class="btn btn-sm btn-primary">搜索</button>
                <?php if ($q !== ''): ?><a class="btn btn-sm btn-outline" href="dashboard.php?tab=logs">清除</a><?php endif; ?>
            </form>
            <span class="toolbar-spacer"></span>
            <?php if ($q !== ''): ?><span class="result-count">匹配 <?= $pageData['total'] ?> 条</span><?php endif; ?>
        </div>
        <div class="table-wrap">
        <table>
            <tr><th>时间</th><th>用户</th><th>角色</th><th>操作</th><th>目标</th><th>结果</th><th>IP</th></tr>
            <?php if (empty($pageData['items'])): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:28px"><?= $q !== '' ? '未找到匹配「' . htmlspecialchars($q) . '」的记录' : '暂无审计日志' ?></td></tr>
            <?php else: ?>
            <?php foreach ($pageData['items'] as $log): ?>
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
            <?php endif; ?>
        </table>
        </div>
        <?= renderPager($pageData, 'page', ['tab' => 'logs', 'q' => $q]) ?>
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
        <form method="post" class="need-challenge">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="_save_config" value="1">
            <input type="hidden" name="challenge_code">
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
                <label class="form-check"><input type="checkbox" name="super_admin_comment" <?= empty($config['super_admin_comment']) ? '' : 'checked' ?>> 允许超管主页评论（默认关闭，超管以访客身份浏览不参与前台评论）</label>
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
        <form method="post" class="need-challenge">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="ban_action" value="add">
            <input type="hidden" name="challenge_code">
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
                    <form method="post" style="display:inline" class="need-challenge" data-confirm="确定解除封禁 <?= htmlspecialchars($ban['ip']) ?>？">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                        <input type="hidden" name="ban_action" value="remove">
                        <input type="hidden" name="ip" value="<?= htmlspecialchars($ban['ip']) ?>">
                        <input type="hidden" name="challenge_code">
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
                <form method="post" id="banEditForm" class="need-challenge">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                    <input type="hidden" name="ban_action" value="update_types">
                    <input type="hidden" name="ip" id="banModalIpInput">
                    <input type="hidden" name="challenge_code">
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
    <?php
    // v2.6.6：三个日志区域的每页条数需互相保留（切换任一区域时其它区域不回退默认值）
    $unauthPerPage = (int)($_GET['unauth_per_page'] ?? 50);
    $loginPerPage = (int)($_GET['login_per_page'] ?? 50);
    $auditPerPage = (int)($_GET['audit_per_page'] ?? 50);
    if (!in_array($unauthPerPage, [10, 20, 50, 100], true)) $unauthPerPage = 50;
    if (!in_array($loginPerPage, [10, 20, 50, 100], true)) $loginPerPage = 50;
    if (!in_array($auditPerPage, [10, 20, 50, 100], true)) $auditPerPage = 50;
    $unauthQ = trim((string)($_GET['unauth_q'] ?? ''));
    $unauthData = paginateList(db_all('SELECT * FROM unauthorized ORDER BY time DESC'), ['time', 'ip', 'action', 'user', 'user_id', 'ua'], $unauthQ, (int)($_GET['unauth_page'] ?? 1), $unauthPerPage);
    ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            越权访问日志（共 <?= $unauthData['total'] ?> 条）
        </div>
        <div class="log-toolbar">
            <form method="get" action="dashboard.php" class="log-toolbar-search">
                <input type="hidden" name="tab" value="security">
                <input type="text" class="form-input" name="unauth_q" value="<?= htmlspecialchars($unauthQ) ?>" placeholder="搜索 时间/IP/操作/用户..." autocomplete="off">
                <button type="submit" class="btn btn-sm btn-primary">搜索</button>
                <?php if ($unauthQ !== ''): ?><a class="btn btn-sm btn-outline" href="dashboard.php?tab=security">清除</a><?php endif; ?>
            </form>
            <span class="toolbar-spacer"></span>
            <?php if ($unauthQ !== ''): ?><span class="result-count">匹配 <?= $unauthData['total'] ?> 条</span><?php endif; ?>
            <?php if ($unauthData['total'] > 0): ?>
            <form method="post" onsubmit="return confirm('确定清空所有越权访问日志？')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <input type="hidden" name="clear_unauth" value="1">
                <button type="submit" class="btn btn-sm btn-outline" style="color:#ef4444;border-color:#fecaca">清空日志</button>
            </form>
            <?php endif; ?>
        </div>
        <?php if (empty($unauthData['items'])): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <p><?= $unauthQ !== '' ? '未找到匹配「' . htmlspecialchars($unauthQ) . '」的记录' : '暂无越权访问记录' ?></p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th>时间</th><th>IP</th><th>操作</th><th>用户</th></tr>
            <?php foreach ($unauthData['items'] as $log): ?>
            <tr>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($log['time'] ?? '') ?></td>
                <td><code><?= htmlspecialchars($log['ip'] ?? '') ?></code></td>
                <td style="color:var(--text-secondary)"><?= htmlspecialchars($log['action'] ?? '') ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($log['user'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?= renderPager($unauthData, 'unauth_page', ['tab' => 'security', 'unauth_q' => $unauthQ, 'login_per_page' => $loginPerPage, 'audit_per_page' => $auditPerPage], 'unauth_per_page') ?>
        <?php endif; ?>
    </div>

    <!-- 登录日志 -->
    <?php
    $loginQ = trim((string)($_GET['login_q'] ?? ''));
    $loginData = paginateList(array_reverse(loadLogsList()), ['time', 'ip', 'action'], $loginQ, (int)($_GET['login_page'] ?? 1), $loginPerPage);
    ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            登录日志（共 <?= $loginData['total'] ?> 条）
        </div>
        <div class="log-toolbar">
            <form class="log-toolbar-search" method="get" action="dashboard.php">
                <input type="hidden" name="tab" value="security">
                <input type="text" class="form-input" name="login_q" value="<?= htmlspecialchars($loginQ) ?>" placeholder="搜索 时间/IP/操作..." autocomplete="off">
                <button type="submit" class="btn btn-sm btn-primary">搜索</button>
                <?php if ($loginQ !== ''): ?><a class="btn btn-sm btn-outline" href="dashboard.php?tab=security">清除</a><?php endif; ?>
            </form>
            <span class="toolbar-spacer"></span>
            <?php if ($loginQ !== ''): ?><span class="result-count">匹配 <?= $loginData['total'] ?> 条</span><?php endif; ?>
        </div>
        <?php if (empty($loginData['items'])): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            <p><?= $loginQ !== '' ? '未找到匹配「' . htmlspecialchars($loginQ) . '」的记录' : '暂无登录日志' ?></p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th>时间</th><th>IP</th><th>操作</th></tr>
            <?php foreach ($loginData['items'] as $log): ?>
            <tr>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($log['time'] ?? '') ?></td>
                <td><code><?= htmlspecialchars($log['ip'] ?? '') ?></code></td>
                <td style="color:var(--text-secondary)"><?= htmlspecialchars($log['action'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?= renderPager($loginData, 'login_page', ['tab' => 'security', 'login_q' => $loginQ, 'unauth_per_page' => $unauthPerPage, 'audit_per_page' => $auditPerPage], 'login_per_page') ?>
        <?php endif; ?>
    </div>

    <!-- 操作日志（哈希链） -->
    <?php
    $auditQ = trim((string)($_GET['audit_q'] ?? ''));
    $auditData = paginateList(loadAuditLogs(), ['ts', 'user_name', 'user_id', 'role', 'ip', 'action', 'target', 'detail'], $auditQ, (int)($_GET['audit_page'] ?? 1), $auditPerPage);
    $chainResult = verifyAuditChain();
    ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            操作日志（共 <?= $auditData['total'] ?> 条）
        </div>
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
            <form method="post" style="display:inline;margin-left:auto" class="need-challenge" data-confirm="确定清空所有操作日志？">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <input type="hidden" name="clear_audit" value="1">
                <input type="hidden" name="challenge_code">
                <button type="submit" class="btn btn-sm btn-outline" style="color:#ef4444;border-color:#fecaca">清空日志</button>
            </form>
        </div>
        <div class="log-toolbar">
            <form class="log-toolbar-search" method="get" action="dashboard.php">
                <input type="hidden" name="tab" value="security">
                <input type="text" class="form-input" name="audit_q" value="<?= htmlspecialchars($auditQ) ?>" placeholder="搜索 时间/用户/角色/操作/目标/IP..." autocomplete="off">
                <button type="submit" class="btn btn-sm btn-primary">搜索</button>
                <?php if ($auditQ !== ''): ?><a class="btn btn-sm btn-outline" href="dashboard.php?tab=security">清除</a><?php endif; ?>
            </form>
            <span class="toolbar-spacer"></span>
            <?php if ($auditQ !== ''): ?><span class="result-count">匹配 <?= $auditData['total'] ?> 条</span><?php endif; ?>
        </div>
        <?php if (empty($auditData['items'])): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <p><?= $auditQ !== '' ? '未找到匹配「' . htmlspecialchars($auditQ) . '」的记录' : '暂无操作日志' ?></p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th>时间</th><th>用户</th><th>角色</th><th>操作</th><th>目标</th><th>结果</th><th>哈希</th></tr>
            <?php foreach ($auditData['items'] as $log): ?>
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
        <?= renderPager($auditData, 'audit_page', ['tab' => 'security', 'audit_q' => $auditQ, 'unauth_per_page' => $unauthPerPage, 'login_per_page' => $loginPerPage], 'audit_per_page') ?>
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
        <form class="log-toolbar" onsubmit="return loadBackupHistory(1, document.getElementById('backupSearchInput').value)">
            <input type="text" class="form-input" id="backupSearchInput" placeholder="搜索 版本/时间/状态..." autocomplete="off">
            <button type="submit" class="btn btn-sm btn-primary">搜索</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('backupSearchInput').value='';loadBackupHistory(1,'')">清除</button>
            <span class="toolbar-spacer"></span>
            <span class="page-info" id="backupPageInfo"></span>
        </form>
        <div class="backup-list" id="backupList">
            <p style="color:var(--text-muted);font-size:0.85em">加载中...</p>
        </div>
        <div id="backupPager"></div>
    </div>

    <script>
    var currentUpdateStatus = null;
    var updatePollTimer = null;
    var pendingUpdateVersion = '';
    var pendingUpdatePath = '';

    function switchChannel(ch) {
        var code = prompt('切换更新通道为敏感操作，请在 SSH 中执行 sudo ym-admin challenge 获取确认码后输入：');
        if (!code) return;
        var fd = new FormData();
        fd.append('ajax', 'save_channel');
        fd.append('channel', ch);
        fd.append('csrf_token', '<?= generateCsrfToken() ?>');
        fd.append('challenge_code', code.trim().toUpperCase());
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

        // 加载更新历史（初始页码/每页条数/搜索词由下方 IIFE 从 URL 恢复）
        loadBackupHistory(backupInitPage);
    });

    var backupSearchQ = '';
    var backupPerPage = 20;
    var backupInitPage = 1;
    // v2.6.6 同次更新：初始每页条数/页码/搜索词从 URL 读取，修复"选择每页条数后刷新被重置为 20"的 bug
    (function() {
        var qs = location.search || '';
        var mPer = qs.match(/[?&]per_page=(\d+)/);
        if (mPer) {
            var v = parseInt(mPer[1], 10);
            if (v === 10 || v === 20 || v === 50 || v === 100) backupPerPage = v;
        }
        var mPg = qs.match(/[?&]page=(\d+)/);
        if (mPg) {
            var p = parseInt(mPg[1], 10);
            if (p > 0) backupInitPage = p;
        }
        var mQ = qs.match(/[?&]q=([^&]*)/);
        if (mQ) {
            backupSearchQ = decodeURIComponent(mQ[1].replace(/\+/g, ' '));
            var inp = document.getElementById('backupSearchInput');
            if (inp) inp.value = backupSearchQ;
        }
    })();
    function loadBackupHistory(page, q, perPage) {
        page = page || 1;
        if (q !== undefined) backupSearchQ = q;
        if (perPage !== undefined) backupPerPage = perPage;
        var list = document.getElementById('backupList');
        list.innerHTML = '<p style="color:var(--text-muted);font-size:0.85em">加载中...</p>';
        fetch('<?= $_SERVER['SCRIPT_NAME'] ?>?tab=update&ajax=update_history&page=' + encodeURIComponent(page) + '&q=' + encodeURIComponent(backupSearchQ) + '&per_page=' + encodeURIComponent(backupPerPage))
            .then(function(r) { return r.json(); })
            .then(function(d) {
                var hist = (d && d.history) || [];
                var html = '';
                if (hist.length === 0) {
                    html = '<p style="color:var(--text-muted);font-size:0.85em">' + (backupSearchQ ? '未找到匹配「' + backupSearchQ + '」的记录' : '暂无更新记录') + '</p>';
                } else {
                    for (var i = 0; i < hist.length; i++) {
                        var h = hist[i];
                        var stText = ({'completed':'已完成','failed':'失败','in_progress':'进行中'}[h.status] || h.status);
                        html += '<div class="backup-item">'
                            + '<div class="backup-info"><span class="backup-version">更新 ' + h.from_version + ' → ' + h.to_version + '</span>'
                            + '<span class="backup-time">' + (h.completed_at || '') + '</span></div>'
                            + '<span class="backup-status ' + h.status + '">' + stText + '</span></div>';
                    }
                }
                list.innerHTML = html;
                renderBackupPager(d);
                // 同步 URL（replaceState 不触发跳转），刷新/分享后可恢复当前页码/条数/搜索词
                if (history.replaceState) {
                    var stateQs = 'tab=update&page=' + encodeURIComponent(page) + '&per_page=' + encodeURIComponent(backupPerPage);
                    if (backupSearchQ !== '') stateQs += '&q=' + encodeURIComponent(backupSearchQ);
                    history.replaceState(null, '', '<?= $_SERVER['SCRIPT_NAME'] ?>?' + stateQs);
                }
            })
            .catch(function() {
                list.innerHTML = '<p style="color:var(--text-muted);font-size:0.85em">加载失败</p>';
            });
        return false;
    }

    // v2.6.6：更新历史分页控件（三段式：总数+每页条数 / 页码按钮 / 页码跳转；事件委托防注入）
    function renderBackupPager(d) {
        var info = document.getElementById('backupPageInfo');
        if (info) info.textContent = (d && d.total > 0) ? ('共 ' + d.total + ' 条 · 第 ' + d.page + '/' + d.pages + ' 页') : '';
        var pager = document.getElementById('backupPager');
        if (!d || !d.total || !d.pages) { pager.innerHTML = ''; return; }
        var page = d.page, pages = d.pages, perPage = d.per_page || backupPerPage;
        // 单页时仍保留「共 N 条 + 每页条数选择器」，仅隐藏页码按钮与跳转区（与 PHP renderPager 逻辑对齐）
        var showNav = pages > 1;
        // 安全基础 URL：搜索词经 encodeURIComponent，插入 data 属性无注入风险
        var baseUrl = '<?= $_SERVER['SCRIPT_NAME'] ?>?tab=update&ajax=update_history&q=' + encodeURIComponent(backupSearchQ);
        var ppTpl = baseUrl + '&per_page=__PP__&page=1';
        var pgTpl = baseUrl + '&per_page=' + perPage + '&page=__PG__';
        var seq = [];
        if (pages <= 7) { for (var i = 1; i <= pages; i++) seq.push(i); }
        else {
            seq.push(1);
            for (var i = Math.max(2, page - 1); i <= Math.min(pages - 1, page + 1); i++) seq.push(i);
            seq.push(pages);
            seq = seq.filter(function(v, idx, a) { return a.indexOf(v) === idx; });
        }
        var html = '<nav class="pagination">';
        // 左：总数 + 每页条数
        html += '<div class="pagination-info"><span class="page-info">共 <strong>' + d.total + '</strong> 条</span>'
              + '<label class="per-page">每页 <select data-pp-url="' + ppTpl + '">';
        [10, 20, 50, 100].forEach(function(opt) {
            html += '<option value="' + opt + '"' + (opt === perPage ? ' selected' : '') + '>' + opt + '</option>';
        });
        html += '</select> 条</label></div>';
        // 中：页码按钮（仅多页时显示）
        if (showNav) {
            html += '<div class="pagination-btns">';
            var mkBtn = function(pg, label, title, current) {
                if (current) return '<span class="page-btn current" title="' + title + '">' + label + '</span>';
                return '<a class="page-btn" data-page="' + pg + '" href="javascript:void(0)" title="' + title + '">' + label + '</a>';
            };
            html += mkBtn(1, '« 首页', '首页', false);
            html += mkBtn(Math.max(1, page - 1), '‹ 上一页', '上一页', false);
            var prev = 0;
            for (var i = 0; i < seq.length; i++) {
                var pg = seq[i];
                if (pg - prev > 1) html += '<span class="page-btn page-ellipsis">…</span>';
                html += mkBtn(pg, pg, '第 ' + pg + ' 页', pg === page);
                prev = pg;
            }
            html += mkBtn(Math.min(pages, page + 1), '下一页 ›', '下一页', false);
            html += mkBtn(pages, '末页 »', '末页', false);
            html += '</div>';
        }
        // 右：页码信息 + 跳转（仅多页时显示）
        if (showNav) {
            html += '<div class="pagination-jump"><span class="page-info">第 ' + page + ' / ' + pages + ' 页</span>'
                  + '<input type="number" class="page-jump" min="1" max="' + pages + '" placeholder="页码" data-pg-url="' + pgTpl + '">'
                  + '<button type="button" class="page-btn">跳转</button></div>';
        }
        pager.innerHTML = html + '</nav>';
        // 事件委托（click: 页码按钮/跳转按钮；change: 每页条数/页码输入）
        pager.onclick = function(e) {
            var target = e.target;
            while (target && target !== pager && !(target.getAttribute && target.getAttribute('data-page'))) target = target.parentNode;
            if (target && target !== pager && target.getAttribute('data-page')) {
                loadBackupHistory(parseInt(target.getAttribute('data-page'), 10));
                return;
            }
            var btn = e.target;
            if (btn && btn.type === 'button') {
                var inp = btn.previousElementSibling;
                if (inp && inp.dataset && inp.dataset.pgUrl && inp.value) {
                    loadBackupHistory(parseInt(inp.value, 10));
                }
            }
        };
        pager.onchange = function(e) {
            var t = e.target;
            if (t && t.dataset && t.dataset.ppUrl) {
                // 每页条数切换：AJAX 重新加载（禁止整页跳转到 ajax=update_history 接口，否则浏览器显示原始 JSON）
                loadBackupHistory(1, undefined, parseInt(t.value, 10));
            } else if (t && t.dataset && t.dataset.pgUrl && t.value) {
                loadBackupHistory(parseInt(t.value, 10));
            }
        };
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

    <?php elseif ($tab === 'data'): ?>
    <?php
    $gState = getGuardState();
    $bCfg = getBackupConfig();
    $bList = getBackupList();
    $dMsg = $_GET['msg'] ?? '';
    ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
            数据管理
        </div>
        <div class="page-subtitle">数据库 / 文章自动备份、整库恢复与保留策略</div>
    </div>
    <?php if ($dMsg === 'saved'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(46,204,113,0.15);color:#2ecc71"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>自动备份配置已保存，守护进程下个周期生效</div>
    <?php elseif ($dMsg === 'save_failed'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060">配置写入失败，请检查 /opt/you-markdown/backup.conf 权限</div>
    <?php elseif ($dMsg === 'challenge_failed'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060">挑战码无效或已过期，请重新生成</div>
    <?php elseif ($dMsg === 'csrf_error'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060">CSRF 校验失败，请刷新页面重试</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>自动备份状态</div>
        <table>
            <tr><td style="color:var(--text-muted)">数据库备份间隔</td><td><?= (int)($gState['backup_interval_min'] ?? $bCfg['interval_min']) ?> 分钟（5~1440 可配）</td></tr>
            <tr><td style="color:var(--text-muted)">上次数据库备份</td><td><?= htmlspecialchars($gState['last_db_backup'] ?: '尚未生成') ?></td></tr>
            <tr><td style="color:var(--text-muted)">下次数据库备份</td><td><?= htmlspecialchars($gState['next_db_backup'] ?: '—') ?></td></tr>
            <tr><td style="color:var(--text-muted)">数据库备份大小</td><td><?= $gState['db_backup_size'] ? number_format((float)$gState['db_backup_size'] / 1024, 1) . ' KB' : '—' ?></td></tr>
            <tr><td style="color:var(--text-muted)">文章每日备份</td><td>保留 <?= (int)($gState['article_backup_keep'] ?? $bCfg['article_keep']) ?> 份；上次：<?= htmlspecialchars($gState['last_articles_backup'] ?: '尚未生成') ?>（当前 <?= (int)($gState['articles_backup_count'] ?? 0) ?> 份）</td></tr>
            <tr><td style="color:var(--text-muted)">手动备份保留</td><td><?= (int)($gState['manual_backup_keep'] ?? $bCfg['manual_keep']) ?> 份（超时自动清除）</td></tr>
            <tr><td style="color:var(--text-muted)">最近自动恢复</td><td><?= htmlspecialchars($gState['last_restore'] ?: '无记录') ?></td></tr>
            <tr><td style="color:var(--text-muted)">备份锁定</td><td>backups/db、backups/articles、logs 目录 chattr +i 锁定（root），PHP 权限不可篡改</td></tr>
        </table>
    </div>

    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>自动备份配置</div>
        <form method="post" class="need-challenge" data-confirm="保存自动备份配置？">
            <input type="hidden" name="save_backup_config" value="1">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <table>
                <tr>
                    <td style="color:var(--text-muted)">数据库备份间隔（分钟）</td>
                    <td><input class="form-input" style="width:160px" type="number" min="5" max="1440" name="interval_min" value="<?= (int)$bCfg['interval_min'] ?>" required></td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted)">文章每日备份保留份数</td>
                    <td><input class="form-input" style="width:160px" type="number" min="1" max="90" name="article_keep" value="<?= (int)$bCfg['article_keep'] ?>" required></td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted)">手动备份保留份数</td>
                    <td><input class="form-input" style="width:160px" type="number" min="1" max="30" name="manual_keep" value="<?= (int)$bCfg['manual_keep'] ?>" required></td>
                </tr>
            </table>
            <div style="margin-top:12px">
                <button class="btn btn-primary" type="submit">
                    <svg viewBox="0 0 24 24" width="14" height="14"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    保存配置
                </button>
                <span style="color:var(--text-muted);font-size:0.82em;margin-left:8px">保存需 SSH 生成挑战码（sudo ym-admin challenge），300 秒有效</span>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>备份列表</div>
        <?php if (!$bList): ?>
            <p style="color:var(--text-muted);font-size:0.85em">暂无备份记录（自动备份将在守护进程下个周期生成）</p>
        <?php else: ?>
        <table>
            <thead><tr><th>类型</th><th>文件</th><th>时间</th><th>大小</th></tr></thead>
            <tbody>
            <?php foreach ($bList as $b): ?>
                <tr>
                    <td><span class="chain-badge chain-valid"><?= htmlspecialchars($b['version']) ?></span></td>
                    <td><code><?= htmlspecialchars($b['file']) ?></code></td>
                    <td><?= date('Y-m-d H:i:s', $b['timestamp']) ?></td>
                    <td><?= number_format($b['size'] / 1024, 1) ?> KB</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <p style="color:var(--text-muted);font-size:0.82em;margin-top:8px">数据库备份固定 1 份滚动；文章备份按保留份数自动清除；手动/更新备份超时自动清除。手动整站备份：SSH 执行 <code>sudo ym-admin backup</code>。</p>
    </div>

    <?php elseif ($tab === 'hfish'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="3" y1="3" x2="21" y2="21"/></svg>
            蜜罐安全
        </div>
        <div class="page-subtitle">HFish 蜜罐攻击日志与自动封禁（攻击≥阈值自动封禁登录/注册/评论；内网 IP 豁免）</div>
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
            <tr><td style="color:var(--text-muted)">自动封禁阈值</td><td>攻击次数 ≥ <strong><?= intval($hfishThreshold) ?></strong> 次 → 封禁（登录/注册/评论）；内网/私有 IP（10/8、172.16/12、192.168/16 等）豁免自动封禁</td></tr>
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
    <?php elseif ($tab === 'mail'): ?>
    <?php
    $smtpCfg = getSmtpConfig();
    $smtpAdminEmail = $config['admin_email'] ?? '';
    $smtpMsg = $_GET['msg'] ?? '';
    $alertLogTail = [];
    if (is_file(ALERT_LOG)) {
        $lines = array_slice(array_filter(explode("\n", (string)@file_get_contents(ALERT_LOG))), -6);
        $alertLogTail = array_reverse($lines);
    }
    ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            邮件设置
        </div>
        <div class="page-subtitle">SMTP 告警邮件配置（直连发送，无 MTA 依赖）</div>
    </div>
    <?php if ($smtpMsg === 'saved'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(46,204,113,0.15);color:#2ecc71"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>SMTP 配置已保存</div>
    <?php elseif ($smtpMsg === 'save_failed'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060">配置保存失败，请检查 config 表权限</div>
    <?php elseif ($smtpMsg === 'challenge_failed'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060">挑战码无效或已过期，请重新生成</div>
    <?php elseif ($smtpMsg === 'csrf_error'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060">CSRF 校验失败，请刷新页面重试</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>SMTP 配置</div>
        <table>
            <tr><td style="color:var(--text-muted)">管理员邮箱（收件人）</td><td><?= htmlspecialchars($smtpAdminEmail ?: '(未设置，请到「系统配置」填写 admin_email)') ?></td></tr>
            <tr><td style="color:var(--text-muted)">当前 SMTP 服务器</td><td><?= $smtpCfg['host'] ? htmlspecialchars($smtpCfg['host']) . ':' . (int)$smtpCfg['port'] . '（' . htmlspecialchars($smtpCfg['enc']) . '）' : '未配置' ?></td></tr>
            <tr><td style="color:var(--text-muted)">当前发信账号</td><td><?= htmlspecialchars($smtpCfg['user'] ?: '未配置') ?></td></tr>
        </table>
        <form method="post" class="need-challenge" data-confirm="保存 SMTP 配置？授权码将写入数据库 config 表">
            <input type="hidden" name="save_smtp_config" value="1">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <table style="margin-top:8px">
                <tr><td style="color:var(--text-muted)">SMTP 服务器</td>
                    <td><input class="form-input" style="width:220px" type="text" name="smtp_host" value="<?= htmlspecialchars($smtpCfg['host']) ?>" placeholder="如 smtp.163.com" required></td></tr>
                <tr><td style="color:var(--text-muted)">端口</td>
                    <td><input class="form-input" style="width:120px" type="number" min="1" max="65535" name="smtp_port" value="<?= (int)$smtpCfg['port'] ?>" required></td></tr>
                <tr><td style="color:var(--text-muted)">加密方式</td>
                    <td><select class="form-input" style="width:160px" name="smtp_enc">
                        <option value="ssl" <?= $smtpCfg['enc'] === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
                        <option value="tls" <?= $smtpCfg['enc'] === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
                        <option value="plain" <?= $smtpCfg['enc'] === 'plain' ? 'selected' : '' ?>>无加密</option>
                    </select></td></tr>
                <tr><td style="color:var(--text-muted)">发信账号</td>
                    <td><input class="form-input" style="width:220px" type="text" name="smtp_user" value="<?= htmlspecialchars($smtpCfg['user']) ?>" placeholder="如 xxx@163.com" required></td></tr>
                <tr><td style="color:var(--text-muted)">授权码</td>
                    <td><?php $smtpPassSrc = smtpPassSource(); ?>
                        <?php if ($smtpPassSrc === 'env'): ?>
                        <span style="color:#34c759;font-size:0.85em">✅ 已通过服务器环境变量 <code>YM_SMTP_PASS</code> 注入（php-fpm 配置，Web 端不可见；修改需在服务器操作）</span>
                        <?php elseif ($smtpPassSrc === 'config'): ?>
                        <span style="color:#f59e0b;font-size:0.85em">⚠️ 使用 config 表密文兜底。建议迁移到环境变量：在 php-fpm pool 配置添加 <code>env[YM_SMTP_PASS] = "授权码"</code> 后 <code>systemctl reload php8.3-fpm</code></span>
                        <?php else: ?>
                        <span style="color:#ef4444;font-size:0.85em">❌ 未配置密码。请在服务器 php-fpm pool 配置添加 <code>env[YM_SMTP_PASS] = "授权码"</code>（独立专用发信账号），并 <code>systemctl reload php8.3-fpm</code></span>
                        <?php endif; ?></td></tr>
                <tr><td style="color:var(--text-muted)">发件人（可空=账号）</td>
                    <td><input class="form-input" style="width:220px" type="text" name="smtp_from" value="<?= htmlspecialchars($smtpCfg['from']) ?>" placeholder="留空则用发信账号"></td></tr>
            </table>
            <div style="margin-top:12px">
                <button class="btn btn-primary" type="submit">
                    <svg viewBox="0 0 24 24" width="14" height="14"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    保存配置
                </button>
                <span style="color:var(--text-muted);font-size:0.82em;margin-left:8px">保存需 SSH 生成挑战码（sudo ym-admin challenge），300 秒有效</span>
            </div>
        </form>
        <div style="margin-top:14px">
            <button class="btn btn-secondary" onclick="testSmtpMail()">发送测试邮件</button>
            <span style="color:var(--text-muted);font-size:0.82em;margin-left:8px">向管理员邮箱发送一封测试邮件，验证配置是否正确（需挑战码）</span>
        </div>
        <div id="smtpTestResult" style="display:none;margin-top:10px;padding:10px 14px;border-radius:8px;font-size:0.9em"></div>
    </div>

    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>最近告警发送记录（alert.log）</div>
        <?php if (!$alertLogTail): ?>
            <p style="color:var(--text-muted);font-size:0.85em">暂无失败记录（发送成功不会出现在此）</p>
        <?php else: ?>
        <div style="font-family:monospace;font-size:0.82em;line-height:1.7;white-space:pre-wrap;word-break:break-all">
            <?php foreach ($alertLogTail as $line): ?><?= htmlspecialchars($line) ?><?= PHP_EOL ?><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <p style="color:var(--text-muted);font-size:0.82em;margin-top:8px">说明：邮件发送成功无记录；失败会在此列出（SMTP 认证失败/连接失败/mail 命令不可用等），便于追溯「告警没发出去」。</p>
    </div>

    <script>
    function testSmtpMail() {
        var code = prompt('请在 SSH 中执行 sudo ym-admin challenge 获取 6 位确认码后输入：');
        if (!code) return;
        var fd = new FormData();
        fd.append('ajax', 'test_smtp');
        fd.append('csrf_token', '<?= generateCsrfToken() ?>');
        fd.append('challenge_code', code.trim().toUpperCase());
        var el = document.getElementById('smtpTestResult');
        el.style.display = 'block';
        el.textContent = '发送中...';
        el.style.color = 'var(--text-secondary)';
        fetch('<?= $_SERVER['SCRIPT_NAME'] ?>', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    el.style.background = 'rgba(46,204,113,0.15)';
                    el.style.color = '#2ecc71';
                    el.textContent = '✅ 测试邮件发送成功，请查收 <?= htmlspecialchars($smtpAdminEmail ?: "") ?>';
                } else {
                    el.style.background = 'rgba(255,80,80,0.15)';
                    el.style.color = '#ff6060';
                    el.textContent = '❌ ' + (d.error || '发送失败');
                }
            })
            .catch(function() {
                el.style.background = 'rgba(255,80,80,0.15)';
                el.style.color = '#ff6060';
                el.textContent = '❌ 请求失败，请重试';
            });
    }
    </script>
    <?php elseif ($tab === 'verify'): ?>
    <?php
    $verifyMsg = $_GET['msg'] ?? '';
    $codeRows = db_all('SELECT * FROM email_codes ORDER BY created DESC');
    $codePage = max(1, (int)($_GET['vp'] ?? 1));
    $codesPaged = paginateList($codeRows, ['email', 'purpose', 'code', 'ip'], trim($_GET['vq'] ?? ''), $codePage, 15);
    $pendRows = db_all('SELECT * FROM pending_author_creates ORDER BY created DESC');
    $pendPage = max(1, (int)($_GET['pp'] ?? 1));
    $pendsPaged = paginateList($pendRows, ['email', 'nickname', 'qq', 'status'], trim($_GET['pq'] ?? ''), $pendPage, 15);
    $purposeLabel = fn($p) => ['register' => '注册', 'author_verify' => '写作者验证', 'author_confirm' => '超管确认'][$p] ?? $p;
    $statusLabel = fn($s) => ['verify_pending' => '等写作者验证', 'pending' => '待超管确认', 'confirmed' => '已确认', 'rejected' => '已拒绝', 'expired' => '已过期'][$s] ?? $s;
    ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            注册验证
        </div>
        <div class="page-subtitle">注册邮箱验证码 + 滑块人机验证 + 写作者双重确认（v2.9.0，正式版默认启用 / 测试版默认禁用）</div>
    </div>
    <?php if ($verifyMsg === 'saved'): ?><div class="msg" style="margin:0 0 16px;background:rgba(46,204,113,0.15);color:#2ecc71"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>配置已保存</div><?php endif; ?>
    <?php if ($verifyMsg === 'csrf_error'): ?><div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060">请求已过期，请重试</div><?php endif; ?>
    <?php if ($verifyMsg === 'challenge_failed'): ?><div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060">挑战码无效或已过期</div><?php endif; ?>
    <div class="card">
        <div class="card-title">功能开关与参数</div>
        <form method="post" class="need-challenge" data-confirm="确认保存注册验证配置？">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="save_verify_config" value="1">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">注册邮箱验证码（总开关）</label>
                    <div class="form-check">
                        <input type="checkbox" name="email_verify_enabled" id="ev_email" value="1" <?= !empty($config['email_verify_enabled']) ? 'checked' : '' ?>>
                        <label for="ev_email">启用注册邮箱验证码（一个邮箱只能注册一个账户）</label>
                    </div>
                </div>
                <div class="form-group">
                    <!-- v2.11.0：滑块人机验证已彻底移除（原 captcha_enabled 开关删除） -->
                    <label class="form-label">写作者双重确认</label>
                    <div class="form-check">
                        <input type="checkbox" name="author_dual_verify_enabled" id="ev_dual" value="1" <?= !empty($config['author_dual_verify_enabled']) ? 'checked' : '' ?>>
                        <label for="ev_dual">站长创建写作者需邮箱验证 + 超管邮件确认</label>
                    </div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">验证码有效期（秒）</label>
                    <input class="form-input" type="number" name="verify_code_ttl" min="60" value="<?= (int)($config['verify_code_ttl'] ?? 300) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">超管确认链接有效期（秒）</label>
                    <input class="form-input" type="number" name="confirm_link_ttl" min="300" value="<?= (int)($config['confirm_link_ttl'] ?? 86400) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">验证码重发冷却（秒，超管后台操作不受限）</label>
                    <input class="form-input" type="number" name="resend_cooldown" min="10" value="<?= (int)($config['resend_cooldown'] ?? 60) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="flex:0">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">保存配置</button>
                </div>
            </div>
            <div class="form-hint" style="margin-top:8px;font-size:12px;color:var(--text-muted)">保存为敏感操作：需 SSH 执行 <code>sudo ym-admin challenge</code> 获取 6 位确认码</div>
        </form>
    </div>
    <div class="card">
        <div class="card-title">验证码发送记录（共 <?= count($codeRows) ?> 条）</div>
        <form method="get" class="search-bar" style="margin-bottom:12px;display:flex;gap:8px">
            <input type="hidden" name="tab" value="verify">
            <input class="form-input" type="text" name="vq" value="<?= htmlspecialchars($_GET['vq'] ?? '') ?>" placeholder="搜索邮箱 / 用途 / 验证码 / IP" style="max-width:320px">
            <button class="btn btn-secondary" type="submit">搜索</button>
        </form>
        <div class="table-wrap">
        <table>
            <tr><th>时间</th><th>邮箱</th><th>用途</th><th>验证码</th><th>IP</th><th>操作者</th><th>状态</th></tr>
            <?php foreach ($codesPaged['rows'] as $c): ?>
            <tr>
                <td><?= date('Y-m-d H:i:s', (int)$c['created']) ?></td>
                <td><?= htmlspecialchars($c['email']) ?></td>
                <td><?= $purposeLabel($c['purpose']) ?></td>
                <td><code><?= htmlspecialchars($c['code']) ?></code></td>
                <td><?= htmlspecialchars($c['ip']) ?></td>
                <td><?= $c['operator_role'] ? htmlspecialchars($c['operator_role']) : '—' ?></td>
                <td><?= !empty($c['used']) ? '已使用' : ((int)$c['expires'] < time() ? '已过期' : '未使用') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($codesPaged['rows'])): ?><tr><td colspan="7" style="text-align:center;color:var(--text-muted)">暂无记录</td></tr><?php endif; ?>
        </table>
        </div>
        <?= renderPager($codesPaged, 'vp', ['tab' => 'verify', 'vq' => $_GET['vq'] ?? ''], 'vpp') ?>
    </div>
    <div class="card">
        <div class="card-title">写作者创建确认记录（共 <?= count($pendRows) ?> 条）</div>
        <form method="get" class="search-bar" style="margin-bottom:12px;display:flex;gap:8px">
            <input type="hidden" name="tab" value="verify">
            <input class="form-input" type="text" name="pq" value="<?= htmlspecialchars($_GET['pq'] ?? '') ?>" placeholder="搜索邮箱 / 昵称 / QQ / 状态" style="max-width:320px">
            <button class="btn btn-secondary" type="submit">搜索</button>
        </form>
        <div class="table-wrap">
        <table>
            <tr><th>发起时间</th><th>邮箱</th><th>昵称</th><th>QQ</th><th>状态</th><th>确认时间</th></tr>
            <?php foreach ($pendsPaged['rows'] as $pd): ?>
            <tr>
                <td><?= date('Y-m-d H:i:s', (int)$pd['created']) ?></td>
                <td><?= htmlspecialchars($pd['email']) ?></td>
                <td><?= htmlspecialchars($pd['nickname']) ?></td>
                <td><?= htmlspecialchars($pd['qq']) ?></td>
                <td><?= $statusLabel($pd['status']) ?></td>
                <td><?= $pd['confirmed_at'] ? htmlspecialchars($pd['confirmed_at']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($pendsPaged['rows'])): ?><tr><td colspan="6" style="text-align:center;color:var(--text-muted)">暂无记录</td></tr><?php endif; ?>
        </table>
        </div>
        <?= renderPager($pendsPaged, 'pp', ['tab' => 'verify', 'pq' => $_GET['pq'] ?? ''], 'ppp') ?>
    </div>
    <?php endif; ?>
</div>

    <!-- 挑战码弹窗（全局：tab 链外，所有 tab 的敏感操作均可弹出） -->
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

    <!-- v2.11.5：用户详情弹窗（只读，展示完整资料 + 关联统计） -->
    <div class="modal-overlay" id="userDetailModal" style="display:none" onclick="if(event.target===this)this.style.display='none'">
        <div class="modal-box user-detail-box">
            <div class="modal-head">
                <div class="modal-title" id="udTitle">用户详情</div>
                <button class="modal-close" onclick="document.getElementById('userDetailModal').style.display='none'">&times;</button>
            </div>
            <div class="modal-body" id="udBody"></div>
        </div>
    </div>

    <!-- v3.0.3：用户操作独立菜单弹窗（⋯ → 单例 modal，与弹窗体系统一） -->
    <div class="modal-overlay" id="userOpModal" style="display:none" onclick="if(event.target===this)this.style.display='none'">
        <div class="modal-box user-op-modal-box">
            <div class="modal-head">
                <div class="modal-title">用户管理</div>
                <button class="modal-close" onclick="document.getElementById('userOpModal').style.display='none'">&times;</button>
            </div>
            <div class="modal-body" id="userOpBody"></div>
        </div>
    </div>

<script>
// 敏感操作挑战码统一处理：提交前弹出确认码输入（SSH: sudo ym-admin challenge）
// v3.0.3：改为事件委托（document submit），支持动态注入的 need-challenge 表单（用户操作弹窗）
document.addEventListener('submit', function(e) {
    var f = e.target;
    if (!f || !f.classList || !f.classList.contains('need-challenge')) return;
    e.preventDefault();
    if (f.dataset.confirm && !confirm(f.dataset.confirm)) return;
    var code = prompt('请在 SSH 中执行 sudo ym-admin challenge 获取 6 位确认码后输入：');
    if (!code) return;
    var hid = f.querySelector('input[name="challenge_code"]');
    if (!hid) { hid = document.createElement('input'); hid.type = 'hidden'; hid.name = 'challenge_code'; f.appendChild(hid); }
    hid.value = code.trim().toUpperCase();
    f.submit();
});
// 登出走 POST + CSRF（防 GET 登出被 CSRF 利用）
function logoutSubmit(e) {
    e.preventDefault();
    var fd = new FormData();
    fd.append('logout', '1');
    fd.append('csrf_token', '<?= generateCsrfToken() ?>');
    fetch(window.location.href.split('?')[0], { method: 'POST', body: fd }).then(function() { location.href = '/'; });
}
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}
// v3.0.3：用户操作独立菜单弹窗（⋯ → 单例 userOpModal，注入 template 内容）
(function() {
    var opModal = document.getElementById('userOpModal');
    var opBody = document.getElementById('userOpBody');
    if (!opModal || !opBody) return;
    document.addEventListener('click', function(e) {
        var btn = e.target.closest ? e.target.closest('[data-op-target]') : null;
        if (!btn) return;
        e.stopPropagation();
        var tpl = document.getElementById(btn.getAttribute('data-op-target'));
        if (!tpl) return;
        opBody.innerHTML = tpl.innerHTML;
        opModal.style.display = 'flex';
    });
})();
// v2.11.5：用户详情弹窗（展示完整资料 + 关联统计）
function ymEsc(s) {
    var el = document.createElement('span');
    el.textContent = (s === null || s === undefined) ? '' : String(s);
    return el.innerHTML;
}
function ymOpenUserDetail(btn) {
    var d = {};
    try { d = JSON.parse(btn.getAttribute('data-detail') || '{}'); } catch (e) { return; }
    // v3.0.5：详情弹窗在二级菜单之上打开——先收起操作菜单，避免被其遮罩挡住
    var opModal = document.getElementById('userOpModal');
    if (opModal) opModal.style.display = 'none';
    var roleMap = { 'super_admin': '系统管理员', 'station_admin': '站长', 'author': '写作者', 'user': '普通用户' };
    var base = [
        ['昵称', d.nickname], ['UID（QQ）', d.qq], ['邮箱', d.email],
        ['角色', roleMap[d.role] || d.role], ['归属站长', d.station || '—'],
        ['状态', d.disabled ? '已禁用' : '正常'], ['创建时间', d.created], ['创建者', d.created_by || '—'],
        ['签名', d.signature || '—'], ['最后登录', d.last_login]
    ];
    var stats = [['登录次数', d.login_count], ['评论数', d.comments], ['文章数', d.articles]];
    var h = '<div class="user-detail-grid">';
    base.forEach(function(r) {
        h += '<div class="user-detail-item"><span class="user-detail-label">' + r[0] + '</span><span class="user-detail-value">' + ymEsc(r[1]) + '</span></div>';
    });
    h += '</div><div class="user-detail-sec">关联统计</div><div class="user-detail-grid">';
    stats.forEach(function(r) {
        h += '<div class="user-detail-item"><span class="user-detail-label">' + r[0] + '</span><span class="user-detail-value">' + ymEsc(r[1]) + '</span></div>';
    });
    h += '</div>';
    document.getElementById('udTitle').textContent = '用户详情 - ' + (d.nickname || d.qq || '');
    document.getElementById('udBody').innerHTML = h;
    document.getElementById('userDetailModal').style.display = 'flex';
}
</script>
</body>
</html>