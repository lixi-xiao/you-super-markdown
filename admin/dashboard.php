<?php
require_once __DIR__ . '/../utils.php';
// v6.0.0：语言启停列表（ysmAllLangs / ysmSupportedLangs）
require_once __DIR__ . '/../lib/i18n.php';
// v6.1.0：服务端输出层 i18n（后台同样按当前语言输出）
secureSessionStart();

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

// v4.5.0：升级即全量重登 + 并发踢旧——旧会话（无 cmt_fp/cmt_tv）或 token_version 不匹配（被新登录踢出）→ 强制重登
if (empty($_SESSION['cmt_fp']) || !isset($_SESSION['cmt_tv']) || (int)$_SESSION['cmt_tv'] !== getUserTV($_SESSION['cmt_user']['id'] ?? '')) {
    session_unset();
    session_destroy();
    header('Location: /?admin_login=1&env=1');
    exit;
}

// v4.5.0：后台写操作环境校验——登录环境变化（换浏览器/设备/隐私模式/被踢）时拒绝所有后台写操作。
// 后台页面为原生表单 POST（无法带自定义头），采用「JS 上报校验」：页面加载后前端把环境指纹上报
// api.php?action=fp_report，服务端比对会话绑定指纹，一致才置 $_SESSION['cmt_fp_ok']=1。
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

// v4.5.0：除登出外的所有后台 POST 均需环境校验（登录环境变化/被踢 → 拒绝写操作）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdminEnv();
}

$users = fetchAllUsers();
$config = loadSiteConfig();
$siteTitle = $config['site_title'] ?? 'You Super Markdown';
$msg = $_GET['msg'] ?? '';

// v5.3.0：更新通知可用性（超管邮箱 + SMTP 缺一不可）——不可用时在后台显著位置持续告警
$notifySmtp = getSmtpConfig();
$updateNotifyReady = trim((string)($config['admin_email'] ?? '')) !== ''
    && $notifySmtp['host'] !== '' && $notifySmtp['user'] !== '' && $notifySmtp['pass'] !== '';

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
    // v5.4.5：切换通道写入审计（与其它敏感操作一致）
    auditLog('update_channel', $ch, '切换更新通道为 ' . ($ch === 'beta' ? '测试版(beta)' : '正式版(stable)'), 'ok');
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
    // v3.3.5：上传触发备份 / 单篇篡改还原 开关
    $bkTrigger = !empty($_POST['trigger_backup']);
    $bkSingle = !empty($_POST['single_restore']);
    $bkOk = saveBackupConfig($bkInterval, $bkArtKeep, $bkManKeep, $bkTrigger, $bkSingle);
    auditLog('backup_config_update', 'backup.conf', "数据库备份间隔={$bkInterval}分钟/文章保留{$bkArtKeep}份/手动保留{$bkManKeep}份/上传触发备份=" . ($bkTrigger ? t('common.on') : t('common.off')) . "/单篇还原=" . ($bkSingle ? t('common.on') : t('common.off')), $bkOk ? 'success' : 'failed');
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
    $ch = normalizeUpdateChannel($config['update_channel'] ?? 'stable');
    $result = checkForUpdates($ch);
    // v5.3.0：发现比当前版本更新 → 通知超管邮箱（复用既有 SMTP + 邮件模板；去重，同一新版本只发一次）。
    // 通知失败不影响检查结果返回；无 SMTP/邮箱时由 notifyUpdateAvailable 落盘 alert.log 告警。
    if (!empty($result['available'])) {
        try { notifyUpdateAvailable($result, $ch); } catch (\Throwable $e) { /* 通知失败不阻断检查 */ }
    }
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
    $uhPerPage = (int)($_GET['per_page'] ?? 10);
    if (!in_array($uhPerPage, [10, 20, 50, 100], true)) $uhPerPage = 10;
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
    // 防注入：目标版本仅允许「字母数字 . _ -」——与 apply-update 同一白名单
    // v5.3.0：放开连字符以支持 beta 语义化版本（如 5.3.1-beta.2）；既有稳定版号不受影响
    if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]*$/', $targetVersion)) {
        echo json_encode(['success' => false, 'error' => '目标版本格式非法'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // v5.3.0：跨通道升级/降级规则早判（与 apply-update 共用 utils.php:ysmCanUpdate，唯一真源）——
    //   此处先挡「方向性」错误（禁止降级 / 稳定通道不收预发布）；包类型（全量/增量）留待 apply-update
    //   按真实包识别后再判（故此处按"全量可用"预判，只判方向不断言包类型）。
    $ruleCheck = ysmCanUpdate(APP_VERSION, $targetVersion, 'full', $config['update_channel'] ?? 'stable');
    if (empty($ruleCheck['allowed'])) {
        echo json_encode(['success' => false, 'error' => $ruleCheck['reason']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 读取上传的更新包路径（仅接受已上传到固定目录的路径，防止任意路径注入）
    $pkgPath = trim($_POST['package_path'] ?? '');
    if ($pkgPath !== '' && strpos($pkgPath, '/tmp/ysm-update-packages/') !== 0) {
        echo json_encode(['success' => false, 'error' => '更新包路径非法'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // v3.2.0：在线更新选择的仓库包下载地址（仅允许 https，防 SSRF/任意协议注入）
    $packageUrl = trim($_POST['package_url'] ?? '');
    if ($packageUrl !== '' && strpos($packageUrl, 'https://') !== 0) {
        echo json_encode(['success' => false, 'error' => '更新包下载地址非法'], JSON_UNESCAPED_UNICODE);
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
        'package_url' => $packageUrl,
        'status' => 'pending',
        'created' => time(),
        'expires' => time() + 600,
        'challenge_verified' => true,
        'started_at' => null,
        'completed_at' => null,
        'error' => '',
    ];
    saveUpdateRequest($updateRequest);
    // v5.0.0 P1-4：更新请求文件不再世界可写（0666→0660；目录 /opt/you-super-markdown/run root:www-data 0770）
    @chmod(UPDATE_REQUEST_FILE, 0660);
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
    $pkgDir = '/tmp/ysm-update-packages';
    if (!is_dir($pkgDir)) mkdir($pkgDir, 0755, true);
    $pkgPath = $pkgDir . '/update-package.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $pkgPath)) {
        echo json_encode(['success' => false, 'error' => '保存失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 尝试读取版本信息
    $targetVersion = '';
    $tmpDir = tempnam(sys_get_temp_dir(), 'ysm');
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
    $syncScript = __DIR__ . '/../ysm-hfish-sync.py';
    $outArr = [];
    $rc = 1;
    if (file_exists($syncScript)) {
        exec('python3 ' . escapeshellarg($syncScript) . ' 2>&1', $outArr, $rc);
    } else {
        $outArr[] = '同步脚本不存在: ysm-hfish-sync.py';
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
        $newUsername = ysmUsernameNormalize($_POST['username'] ?? ($_POST['account'] ?? ''));
        $newPwd = trim($_POST['password'] ?? '');
        if ($newNick && $newUsername && $newPwd) {
            $vp = validatePassword($newPwd);
            if ($vp !== true) {
                $msg = 'pw_weak';
            } else {
                // v6.2.0：用户名唯一性检查（大小写不敏感）
                $nameChk = ysmUsernameValidate($newUsername);
                $accountExists = ($nameChk !== true) || ysmUsernameTaken($newUsername);
                if ($accountExists) {
                    $msg = 'account_duplicate';
                } else {
                    $users[] = [
                        'id' => bin2hex(random_bytes(8)),
                        'account' => $newUsername, 'username' => $newUsername, 'avatar' => ysmAvatarUrl($newUsername, 100),
                        'nickname' => $newNick,
                        'password' => password_hash($newPwd, PASSWORD_DEFAULT),
                        'role' => $newRole,
                        'created' => date('Y-m-d H:i:s'),
                        'created_by' => getCurrentUserId(),
                    ];
                    replaceAllUsers($users);
                    auditLog('user_create', $newAccount, "创建用户: {$newNick}, 角色: {$newRole}");
                    $msg = 'user_created';
                }
            }
        }
    } elseif ($_POST['action'] === 'delete_user') {
        $delId = $_POST['user_id'] ?? '';
        foreach ($users as $i => $u) {
            if ($u['id'] === $delId && ($u['role'] ?? '') !== ROLE_SUPER_ADMIN) {
                auditLog('user_delete', $u['account'] ?? $delId, "删除用户: {$u['nickname']}");
                array_splice($users, $i, 1);
                replaceAllUsers($users);
                // v5.4.1：清理该用户会话/设备残留（refresh_tokens、device_fps；comments/audit/unauthorized 保留）
                purgeUserResiduals($delId);
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
                    auditLog('user_role_change', $uu['account'] ?? $uid, "修改权限: {$uu['nickname']} {$oldRole} → {$newRole}");
                    $msg = 'role_changed';
                    break;
                }
            }
            unset($uu);
            if ($msg !== 'role_changed') $msg = 'role_invalid';
            replaceAllUsers($users);
        }
    } elseif ($_POST['action'] === 'set_user_disabled') {
        // v2.11.4：禁用/启用账号（禁用后无法登录/评论/进后台，已登录会话立即失效）
        $uid = $_POST['user_id'] ?? '';
        $disabled = (int)($_POST['disabled'] ?? 0);
        foreach ($users as &$uu) {
            if ($uu['id'] === $uid && ($uu['role'] ?? '') !== ROLE_SUPER_ADMIN) {
                $uu['disabled'] = $disabled ? 1 : 0;
                auditLog('user_disable', $uu['account'] ?? $uid, ($disabled ? '禁用账号: ' : '启用账号: ') . ($uu['nickname'] ?? ''));
                $msg = $disabled ? 'user_disabled' : 'user_enabled';
                break;
            }
        }
        unset($uu);
        replaceAllUsers($users);
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
                    auditLog('user_station_change', $uu['account'] ?? $uid, "设定归属站长: {$uu['nickname']} → {$stationId}");
                    $found = true;
                    break;
                }
            }
            unset($uu);
            if (!$found) { $msg = 'station_invalid'; }
            else { replaceAllUsers($users); $msg = 'station_changed'; }
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
                auditLog('user_reset_pwd', $uu['account'] ?? $uid, "重置密码: {$uu['nickname']}");
                $newAccountForFlash = $uu['account'] ?? '';
                $found = true;
                break;
            }
        }
        unset($uu);
        if (!$found) {
            $msg = 'reset_fail';
        } else {
            replaceAllUsers($users);
            // 新密码通过 session flash 传递（避免明文进 URL）
            $_SESSION['flash_reset_pwd'] = ['account' => $newAccountForFlash ?? '', 'pwd' => $newPwd];
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
    // v4.1.18：站点外观/功能项（标题/注册/评论/音乐）已移入「主界面及功能设置」，本表单仅保留安全项
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
    $config['hide_default_paths'] = !empty($_POST['hide_default_paths']);
    // v4.1.7：蜜罐攻击封禁阈值（1-100，ysm-hfish-sync.py 读取；写入 config 表供后台可配）
    $config['hfish_ban_threshold'] = min(100, max(1, intval($_POST['hfish_ban_threshold'] ?? 10)));
    // v4.4.0：注册蜜罐自动封禁（与上方 HFish 蜜罐独立，仅针对注册表单隐藏字段）——触发次数 + 封禁时长（15 分钟~永久）
    $config['honeypot_ban_count'] = min(50, max(1, intval($_POST['honeypot_ban_count'] ?? 3)));
    $hpDurOpts = [900, 1800, 3600, 7200, 21600, 43200, 86400, 604800, 0];
    $hpDur = intval($_POST['honeypot_ban_duration'] ?? 3600);
    $config['honeypot_ban_duration'] = in_array($hpDur, $hpDurOpts, true) ? $hpDur : 3600;
    saveSiteConfig($config);
    auditLog('config_update', 'site_config', '修改安全设置');
    // v4.1.8：安全配置（含蜜罐阈值）变更后立即刷新蜜罐快照——界面阈值/封禁状态即时生效，无需等 5 分钟轮询
    if (is_file(__DIR__ . '/../ysm-hfish-sync.py')) {
        exec('python3 ' . escapeshellarg(__DIR__ . '/../ysm-hfish-sync.py') . ' > /dev/null 2>&1 &');
    }
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
            banIp($ip, $types, $reason);
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

// v5.4.8：从镜像恢复操作日志——Web 仅"发起请求"（CSRF + 挑战码 + 超管会话），
//   实际恢复由根守护进程执行（镜像目录已收紧为 root:www-data 750 + immutable，www-data 无写权限）。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recover_audit'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=security&bmsg=csrf_error');
        exit;
    }
    if (!verifyChallenge($_POST['challenge_code'] ?? '')) {
        header('Location: dashboard.php?tab=security&bmsg=challenge_error');
        exit;
    }
    $nonce = writeAuditRecoverRequest();
    auditLog('audit_recover_requested', '', '提交「从镜像恢复操作日志」请求（nonce ' . substr((string)$nonce, 0, 8) . '）', $nonce !== '' ? 'ok' : 'failed');
    header('Location: dashboard.php?tab=security&bmsg=recover_submitted');
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

// v4.7.0：移除已信任设备（敏感操作：挑战码）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_device'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=threat&msg=csrf_error');
        exit;
    }
    if (!verifyChallenge($_POST['challenge_code'] ?? '')) {
        header('Location: dashboard.php?tab=threat&msg=challenge_failed');
        exit;
    }
    $uid = trim((string)($_POST['device_uid'] ?? ''));
    $devId = trim((string)($_POST['device_id'] ?? ''));
    if ($uid !== '' && $devId !== '' && removeKnownDevice($uid, $devId)) {
        auditLog('device_removed', $uid, '移除已信任设备 ' . $devId);
        header('Location: dashboard.php?tab=threat&msg=device_removed');
    } else {
        header('Location: dashboard.php?tab=threat&msg=device_rm_fail');
    }
    exit;
}

// v4.7.0：解除联动封锁（敏感操作：挑战码）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_linked'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=threat&msg=csrf_error');
        exit;
    }
    if (!verifyChallenge($_POST['challenge_code'] ?? '')) {
        header('Location: dashboard.php?tab=threat&msg=challenge_failed');
        exit;
    }
    $dt = trim((string)($_POST['link_dim_type'] ?? ''));
    $dk = trim((string)($_POST['link_dim_key'] ?? ''));
    if (in_array($dt, ['ip', 'fp'], true) && $dk !== '') {
        clearLinkedBlock($dt, $dk);
        auditLog('linked_cleared', '', '解除联动封锁（' . $dt . ':' . $dk . '）');
        header('Location: dashboard.php?tab=threat&msg=linked_cleared');
    } else {
        header('Location: dashboard.php?tab=threat&msg=linked_clr_fail');
    }
    exit;
}

// v4.7.0：生成一次性重置码（超管协助找回，敏感操作：挑战码；30 分钟过期一次性）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gen_reset_code'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=threat&msg=csrf_error');
        exit;
    }
    if (!verifyChallenge($_POST['challenge_code'] ?? '')) {
        header('Location: dashboard.php?tab=threat&msg=challenge_failed');
        exit;
    }
    $resetAccount = ysmUsernameNormalize($_POST['reset_account'] ?? '');
    $target = null;
    foreach ($users as $u) {
        $uName = ysmUsernameNormalize($u['username'] ?? '');
        $uLegacy = strtolower(trim((string)($u['account'] ?? '')));
        if ($uName === $resetAccount || $uLegacy === $resetAccount) { $target = $u; break; }
    }
    if (!$target) {
        header('Location: dashboard.php?tab=threat&msg=reset_user_not_found');
        exit;
    }
    [$ok, $code] = genAdminResetCode($target['id'], $target['email'] ?? '');
    if ($ok) {
        auditLog('admin_reset_code', $target['id'], '生成一次性重置码（超管协助找回）');
        header('Location: dashboard.php?tab=threat&msg=' . urlencode('重置码已生成：' . $code . '（30分钟内一次性有效，请转交用户）'));
    } else {
        header('Location: dashboard.php?tab=threat&msg=' . urlencode($code));
    }
    exit;
}

// 主界面及功能设置表单（v4.1.18：背景+音乐+站点外观，非敏感操作，仅 CSRF 无需挑战码）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_FILES['bg_image']) || isset($_POST['_ui_save']))) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=ui&msg=csrf_error');
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
                header('Location: dashboard.php?tab=ui&msg=uploaded');
                exit;
            }
        }
        header('Location: dashboard.php?tab=ui&msg=upload_error');
        exit;
    }
    // 保存主界面配置
    if (isset($_POST['_ui_save'])) {
        // 背景
        $config['bg_type'] = in_array($_POST['bg_type']??'', ['none','image','api']) ? $_POST['bg_type'] : 'none';
        // bg_image 仅允许站内 data/bg/ 路径或 http(s) URL，防止 CSS 值注入
        $bgImage = trim($_POST['bg_image']??'');
        if ($bgImage !== '' && strpos($bgImage, 'data/bg/') !== 0 && !preg_match('#^https?://#i', $bgImage)) $bgImage = '';
        $config['bg_image'] = $bgImage;
        // v4.2.0：API 背景固定默认源（留空保存自动回退固定 16:9 图片 API）
        $config['bg_api_url'] = trim($_POST['bg_api_url']??'');
        if ($config['bg_api_url'] === '') $config['bg_api_url'] = FIXED_IMG_API;
        // v4.2.4：首页卡片封面图片源（用户自定义则优先，留空回退 FIXED_IMG_API，走同一池化缓存策略）
        $config['card_cover_api'] = trim($_POST['card_cover_api']??'');
        $config['bg_blur_enabled'] = !empty($_POST['bg_blur_enabled']);
        $config['bg_blur_level'] = max(0, min(50, intval($_POST['bg_blur_level']??0)));
        $config['bg_card_opacity'] = max(20, min(100, intval($_POST['bg_card_opacity']??100)));
        // 站点外观/功能（v4.1.18 从系统配置移入，非敏感项不触发挑战码）
        $config['site_title'] = trim($_POST['site_title'] ?? 'You Super Markdown');
        $config['registration_enabled'] = !empty($_POST['registration_enabled']);
        $config['guest_comments_enabled'] = !empty($_POST['guest_comments_enabled']);
        // v2.6.5：超管主页评论开关（默认关=超管不参与前台评论/回复）
        $config['super_admin_comment'] = !empty($_POST['super_admin_comment']);
        // v4.0.0：评论邮件订阅通知
        $config['comment_notify_enabled'] = !empty($_POST['comment_notify_enabled']);
        $config['comment_notify_email'] = trim($_POST['comment_notify_email'] ?? '');
        // 音乐播放器（v4.4.2 起仅保留网易云）
        $config['music_playlist_id'] = trim($_POST['music_playlist_id'] ?? '3778678');
        $config['music_cookies'] = trim($_POST['music_cookies'] ?? '');
        // v4.4.0：默认播放歌曲关键词（留空则不自动播放）
        $config['music_auto_play'] = mb_substr(trim($_POST['music_auto_play'] ?? ''), 0, 60, 'UTF-8');
        // v4.5.0：本地背景音乐开关 + 上传替换（<100MB 常见音频 → 服务器 ffmpeg 自动转码 96kbps mp3）
        $config['bg_music_enabled'] = !empty($_POST['bg_music_enabled']);
        if (!empty($_FILES['bg_music_file']['name'])) {
            $upR = saveBgMusicFile($_FILES['bg_music_file']['tmp_name'], $_FILES['bg_music_file']['name']);
            if ($upR[0]) auditLog('bg_music_upload', 'bg_music', $upR[1]);
            else auditLog('bg_music_upload_fail', 'bg_music', $upR[1]);
        }
        saveSiteConfig($config);
        auditLog('ui_config', 'site_config', '修改主界面及功能设置');
        header('Location: dashboard.php?tab=ui&msg=saved');
        exit;
    }
}
// ===== v6.0.0：语言与字体设置（首页默认语言 / 语言启停 / 自定义字体开关） =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_lang_save'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=ui&msg=csrf_error');
        exit;
    }
    $allLangCodes = function_exists('ysmAllLangs') ? array_keys(ysmAllLangs()) : ['zh-CN'];
    // 语言启停：表单提交「启用的语言」；zh-CN 恒启用；dis = 全部 − 启用
    $enabled = [];
    foreach ((array)($_POST['enabled_langs'] ?? []) as $el) {
        $el = (string)$el;
        if (in_array($el, $allLangCodes, true)) $enabled[] = $el;
    }
    $enabled[] = 'zh-CN';
    $enabled = array_values(array_unique($enabled));
    $config['disabled_langs'] = array_values(array_diff($allLangCodes, $enabled));
    // 首页默认语言（必须是已启用语言；否则回落 zh-CN）
    $dftLang = trim($_POST['default_lang'] ?? '');
    $config['default_lang'] = in_array($dftLang, $enabled, true) ? $dftLang : 'zh-CN';
    // 自定义字体开关（关闭不清文件，便于随时重新启用）
    $config['custom_font_enabled'] = !empty($_POST['custom_font_enabled']);
    saveSiteConfig($config);
    auditLog('lang_font_config', 'site_config', '修改首页默认语言/语言启停/字体开关');
    header('Location: dashboard.php?tab=ui&msg=saved');
    exit;
}
// ===== v6.0.0：自定义字体上传 / 移除（替换内置萝莉体） =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['_font_remove']) || isset($_FILES['custom_font']))) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: dashboard.php?tab=ui&msg=csrf_error');
        exit;
    }
    $fontDir = __DIR__ . '/../data/fonts/';
    if (!is_dir($fontDir)) @mkdir($fontDir, 0755, true);
    // 移除
    if (!empty($_POST['_font_remove'])) {
        $old = (string)($config['custom_font_name'] ?? '');
        if ($old !== '' && strpos($old, 'data/fonts/') === 0) {
            $p = __DIR__ . '/../' . $old;
            if (is_file($p)) @unlink($p);
        }
        $config['custom_font_name'] = '';
        $config['custom_font_enabled'] = false;
        saveSiteConfig($config);
        auditLog('custom_font_remove', 'custom_font', '移除自定义字体');
        header('Location: dashboard.php?tab=ui&msg=saved');
        exit;
    }
    // 上传
    $file = $_FILES['custom_font'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        header('Location: dashboard.php?tab=ui&msg=upload_error');
        exit;
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 20 * 1024 * 1024) {
        header('Location: dashboard.php?tab=ui&msg=upload_error');
        exit;
    }
    $extMap = ['ttf', 'otf', 'woff', 'woff2'];
    $origExt = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($origExt, $extMap, true)) {
        header('Location: dashboard.php?tab=ui&msg=upload_error');
        exit;
    }
    // 魔数校验（防伪装文件）：wOFF / wOF2 / TrueType(\x00\x01\x00\x00) / OpenType(OTTO) / Apple true
    $fh = @fopen($file['tmp_name'], 'rb');
    $magic = $fh ? fread($fh, 4) : '';
    if ($fh) fclose($fh);
    $magicOk = ($origExt === 'woff' && $magic === 'wOFF')
        || ($origExt === 'woff2' && $magic === 'wOF2')
        || (in_array($origExt, ['ttf', 'otf'], true) && in_array($magic, ["\x00\x01\x00\x00", 'OTTO', 'true'], true));
    if (!$magicOk) {
        auditLog('custom_font_upload_fail', (string)$file['name'], '字体文件头不合法');
        header('Location: dashboard.php?tab=ui&msg=upload_error');
        exit;
    }
    // MIME 复核（finfo 可用时；octet-stream 常见于字体，放行）
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$fi->file($file['tmp_name']);
        if ($mime !== '' && stripos($mime, 'font') === false
            && !in_array($mime, ['application/octet-stream', 'application/vnd.ms-opentype', 'application/font-sfnt'], true)) {
            auditLog('custom_font_upload_fail', (string)$file['name'], 'MIME 不合法：' . $mime);
            header('Location: dashboard.php?tab=ui&msg=upload_error');
            exit;
        }
    }
    $fname = 'font_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $origExt;
    if (!move_uploaded_file($file['tmp_name'], $fontDir . $fname)) {
        header('Location: dashboard.php?tab=ui&msg=upload_error');
        exit;
    }
    // 删旧文件（仅限 data/fonts/ 内）
    $old = (string)($config['custom_font_name'] ?? '');
    if ($old !== '' && strpos($old, 'data/fonts/') === 0) {
        $p = __DIR__ . '/../' . $old;
        if (is_file($p) && basename($p) !== $fname) @unlink($p);
    }
    $config['custom_font_name'] = 'data/fonts/' . $fname;
    $config['custom_font_enabled'] = true;
    saveSiteConfig($config);
    auditLog('custom_font_upload', $config['custom_font_name'], '上传自定义字体');
    header('Location: dashboard.php?tab=ui&msg=uploaded');
    exit;
}
// 封禁相关函数（全局可用，代理到 utils.php 的 SQLite 实现）
function loadBans() {
    return loadBansList();
}
function saveBans($bans) {
    return replaceAllBans($bans);
}
$banMsg = $_GET['bmsg'] ?? '';
// v6.0.0：语言与字体卡片数据（全部语言含停用项；新增 lang/*.php 自动出现）
$allLangsAdmin = function_exists('ysmAllLangs') ? ysmAllLangs() : ['zh-CN' => ['name' => '简体中文', 'code' => 'zh-CN']];
$disabledLangs = (array)($config['disabled_langs'] ?? []);
$customFontName = (string)($config['custom_font_name'] ?? '');
?>
<!DOCTYPE html>
<html lang="<?= ysmLocale() ?>" data-admin="super">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('admin.571') ?> - <?= htmlspecialchars($siteTitle) ?></title>
<link rel="stylesheet" href="../css/admin.css?v=<?= @filemtime(__DIR__ . '/../css/admin.css') ?>">
<!-- v6.1.0：后台 JS 动态文案（弹窗/提示/异步渲染）运行时翻译 -->
<script>window.I18N = <?= json_encode(ysmClientI18n('admin'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="../js/i18n.js?v=<?= @filemtime(__DIR__ . '/../js/i18n.js') ?>"></script>
</head>
<body>

<!-- v3.1.5：移动端顶栏（菜单按钮融入顶栏，与后台统一视觉） -->
<div class="mobile-topbar" id="mobileTopbar">
    <button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleSidebar()" aria-label="<?= t('admin.001') ?>">
        <svg viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="mobile-topbar-title"><?= t('admin.002') ?></div>
    <div class="mobile-topbar-user">
        <span class="mobile-topbar-name"><?= t('admin.003') ?></span>
    </div>
</div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <div class="sidebar-title"><span><?= t('admin.003') ?></span><?= t('admin.004') ?></div>
    </div>
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="sidebar-link <?= (!isset($_GET['tab']) || $_GET['tab'] === 'overview') ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <?= t('admin.465') ?>
        </a>
        <div class="sidebar-group"><?= t('admin.482') ?></div>
        <a href="dashboard.php?tab=users" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'users' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <?= t('admin.466') ?>
        </a>
        <div class="sidebar-group"><?= t('admin.481') ?></div>
        <a href="dashboard.php?tab=ui" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'ui' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            <?= t('admin.470') ?>
        </a>
        <a href="dashboard.php?tab=links" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'links' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            <?= t('admin.471') ?>
        </a>
        <a href="dashboard.php?tab=data" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'data' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
            <?= t('admin.474') ?>
        </a>
        <div class="sidebar-group"><?= t('admin.483') ?></div>
        <a href="dashboard.php?tab=config" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'config' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <?= t('admin.468') ?>
        </a>
        <a href="dashboard.php?tab=security" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'security' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <?= t('admin.469') ?>
        </a>
        <a href="dashboard.php?tab=threat" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'threat' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z"/><path d="M9 12l2 2 4-4"/></svg>
            <?= t('admin.478') ?>
        </a>
        <a href="dashboard.php?tab=hfish" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'hfish' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="3" y1="3" x2="21" y2="21"/></svg>
            <?= t('admin.475') ?>
        </a>
        <a href="dashboard.php?tab=verify" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'verify' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/><circle cx="12" cy="13" r="1"/></svg>
            <?= t('admin.477') ?>
        </a>
        <a href="dashboard.php?tab=logs" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'logs' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <?= t('admin.467') ?>
        </a>
        <div class="sidebar-group"><?= t('admin.484') ?></div>
        <a href="dashboard.php?tab=update" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'update' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            <?= t('admin.472') ?>
        </a>
        <a href="dashboard.php?tab=guard" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'guard' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
            <?= t('admin.473') ?>
        </a>
        <a href="dashboard.php?tab=mail" class="sidebar-link <?= ($_GET['tab'] ?? '') === 'mail' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <?= t('admin.476') ?>
        </a>
        <a href="#" onclick="bindLogoutSubmit(event)" class="sidebar-link danger">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <?= t('admin.480') ?>
        </a>
    </nav>
</div>

<div class="main">
    <?php if ($msg === 'saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('admin.369') ?></div><?php endif; ?>
    <?php if ($msg === 'user_created'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('admin.370') ?></div><?php endif; ?>
    <?php if ($msg === 'user_deleted'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('admin.371') ?></div><?php endif; ?>
    <?php if ($msg === 'user_disabled'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('admin.372') ?></div><?php endif; ?>
    <?php if ($msg === 'user_enabled'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('admin.373') ?></div><?php endif; ?>
    <?php if ($msg === 'role_changed'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('admin.374') ?></div><?php endif; ?>
    <?php if ($msg === 'role_invalid'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('admin.375') ?></div><?php endif; ?>
    <?php if ($msg === 'station_changed'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('admin.376') ?></div><?php endif; ?>
    <?php if ($msg === 'station_invalid'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('admin.377') ?></div><?php endif; ?>
    <?php if ($msg === 'pwd_reset'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('admin.378') ?></div><?php endif; ?>
    <?php if ($msg === 'reset_fail'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('admin.379') ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['flash_reset_pwd'])): ?>
    <?php $flashPwd = $_SESSION['flash_reset_pwd']; unset($_SESSION['flash_reset_pwd']); ?>
    <div class="msg msg-warn" style="border:1px dashed var(--accent);background:var(--accent-light,#f0f5ff)">
        <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <?= t('admin.560') ?> <strong><?= htmlspecialchars($flashPwd['account'] ?? '') ?></strong><?= t('admin.380') ?><code style="font-size:1.1em;font-weight:700"><?= htmlspecialchars($flashPwd['pwd'] ?? '') ?></code>
        <?= t('admin.583') ?>
    </div>
    <?php endif; ?>
    <?php if ($msg === 'account_duplicate'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('admin.381') ?></div><?php endif; ?>
    <?php if ($msg === 'pw_weak'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('admin.382') ?></div><?php endif; ?>
    <?php if ($msg === 'challenge_error'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('admin.250') ?></div><?php endif; ?>

    <?php if (!$updateNotifyReady): ?>
    <div class="msg msg-warning">
        <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <strong><?= t('admin.005') ?></strong><?= t('admin.006') ?><code>sudo ysm-admin check</code><?= t('admin.572') ?>
    </div>
    <?php endif; ?>

    <?php
    $tab = $_GET['tab'] ?? 'overview';
    // v5.0.0 P2：tab 白名单校验（对齐 station/author 后台），非法值回落 overview
    if (!in_array($tab, ['overview', 'users', 'logs', 'config', 'security', 'ui', 'update', 'guard', 'data', 'hfish', 'mail', 'verify', 'threat', 'links'], true)) $tab = 'overview';
    $superCount = count(array_filter($users, fn($u) => ($u['role'] ?? '') === ROLE_SUPER_ADMIN));
    $stationCount = count(array_filter($users, fn($u) => ($u['role'] ?? '') === ROLE_STATION_ADMIN));
    $authorCount = count(array_filter($users, fn($u) => ($u['role'] ?? '') === ROLE_AUTHOR));
    $userCount = count(array_filter($users, fn($u) => ($u['role'] ?? 'user') === ROLE_USER));

    // v2.6.6：日志分页控件渲染（v3.3.9 起 renderPager 抽为 utils.php 公用函数，超管/站长后台复用；GET 无副作用，无需 CSRF）
    ?> 

    <?php if ($tab === 'overview'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <?= t('admin.485') ?>
        </div>
        <div class="page-subtitle"><?= t('admin.007') ?></div>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-number"><?= $superCount ?></div><div class="stat-label"><?= t('admin.383') ?></div></div>
        <div class="stat-card"><div class="stat-number"><?= $stationCount ?></div><div class="stat-label"><?= t('station.003') ?></div></div>
        <div class="stat-card"><div class="stat-number"><?= $authorCount ?></div><div class="stat-label"><?= t('author.003') ?></div></div>
        <div class="stat-card"><div class="stat-number"><?= $userCount ?></div><div class="stat-label"><?= t('admin.384') ?></div></div>
    </div>

    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <?= t('admin.486') ?>
        </div>
        <div class="table-wrap">
        <table>
            <tr><td style="color:var(--text-muted)"><?= t('admin.385') ?></td><td><code><?= APP_VERSION ?></code></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.386') ?></td><td><?= empty($config['registration_enabled']) ? '关闭' : '开启' ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.387') ?></td><td><?= empty($config['guest_comments_enabled']) ? '关闭' : '开启' ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.388') ?></td><td><?= empty($config['super_admin_comment']) ? '关闭' : '开启' ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.041') ?></td><td><?= htmlspecialchars($config['update_channel'] ?? 'stable') ?></td></tr>
        </table>
        </div>
    </div>

    <?php elseif ($tab === 'users'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <?= t('admin.466') ?>
        </div>
        <div class="page-subtitle"><?= t('admin.008') ?></div>
    </div>
    <?php
    // v4.6.0：用户管理搜索与分页参数（需在搜索框渲染前解析）
    // v4.7.5：超管表同样纳入搜索与分页（此前仅三个角色组过滤，超管表恒全量显示，搜索观感"不起作用"）
    $usersQ = trim((string)($_GET['users_q'] ?? ''));
    $saPerPage = (int)($_GET['sa_per_page'] ?? 10); if (!in_array($saPerPage, [10, 20, 50, 100], true)) $saPerPage = 10;
    $stPerPage = (int)($_GET['st_per_page'] ?? 10); if (!in_array($stPerPage, [10, 20, 50, 100], true)) $stPerPage = 10;
    $auPerPage = (int)($_GET['au_per_page'] ?? 10); if (!in_array($auPerPage, [10, 20, 50, 100], true)) $auPerPage = 10;
    $usPerPage = (int)($_GET['us_per_page'] ?? 10); if (!in_array($usPerPage, [10, 20, 50, 100], true)) $usPerPage = 10;
    ?>
    <form method="get" style="margin-bottom:14px" id="userSearchForm">
        <input type="hidden" name="tab" value="users">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input class="form-input" type="text" name="users_q" value="<?= htmlspecialchars($usersQ) ?>" placeholder="<?= t('admin.389') ?>" style="max-width:340px">
            <button type="submit" class="btn"><?= t('admin.009') ?></button>
            <?php if ($usersQ !== ''): ?><a class="btn btn-ghost" href="dashboard.php?tab=users"><?= t('admin.193') ?></a><?php endif; ?>
        </div>
    </form>
    <?php
    $users = fetchAllUsers();
    ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
            <?= t('admin.488') ?>
        </div>
        <form method="post" class="need-challenge">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_user">
            <input type="hidden" name="challenge_code">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label"><?= t('admin.010') ?></label>
                    <input class="form-input" name="nickname" placeholder="<?= t('admin.011') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('admin.012') ?></label>
                    <input class="form-input" name="username" placeholder="<?= t('admin.013') ?>" pattern="[a-zA-Z0-9][a-zA-Z0-9_-]{2,19}">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('admin.014') ?></label>
                    <input class="form-input" name="password" type="password" placeholder="******">
                </div>
                <div class="form-group" style="min-width:120px">
                    <label class="form-label"><?= t('admin.015') ?></label>
                    <select class="form-select" name="role">
                        <option value="<?= ROLE_STATION_ADMIN ?>"><?= t('station.003') ?></option>
                        <option value="<?= ROLE_AUTHOR ?>"><?= t('author.003') ?></option>
                        <option value="<?= ROLE_USER ?>"><?= t('admin.355') ?></option>
                    </select>
                </div>
                <div class="form-group" style="flex:0">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary"><?= t('admin.016') ?></button>
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
    foreach ($users as $su) { if (($su['role'] ?? '') === ROLE_STATION_ADMIN) $stationNames[$su['id']] = ($su['nickname'] ?: $su['account']); }
    $superAdmins = []; $groupStations = []; $groupAuthors = []; $groupUsers = [];
    foreach ($users as $uu) {
        $r = $uu['role'] ?? 'user';
        // v4.7.5：为站长/写作者行附加 _station（归属站长名，超管为顶级归属）作为可搜索字段
        if ($r === ROLE_SUPER_ADMIN) {
            $superAdmins[] = $uu;
        } elseif ($r === ROLE_STATION_ADMIN) {
            $uu['_station'] = '超管'; $groupStations[] = $uu;
        } elseif ($r === ROLE_AUTHOR) {
            $uu['_station'] = $stationNames[$uu['station_id'] ?? ''] ?? '超管'; $groupAuthors[] = $uu;
        } else {
            $groupUsers[] = $uu;
        }
    }
    // v4.7.5：用户管理分页——四张表（超管/站长/写作者/普通用户）各自分页，均参与搜索；默认每页 10 条
    // 搜索字段统一为昵称/UID/QQ/邮箱/归属站长（_station）；页码与每页条数互相独立、互不干扰
    $saPaged = paginateList($superAdmins, ['nickname', 'username', 'account', 'id', 'email', '_station'], $usersQ, (int)($_GET['sa_page'] ?? 1), $saPerPage);
    $stPaged = paginateList($groupStations, ['nickname', 'username', 'account', 'id', 'email', '_station'], $usersQ, (int)($_GET['st_page'] ?? 1), $stPerPage);
    $auPaged = paginateList($groupAuthors, ['nickname', 'username', 'account', 'id', 'email', '_station'], $usersQ, (int)($_GET['au_page'] ?? 1), $auPerPage);
    $usPaged = paginateList($groupUsers, ['nickname', 'username', 'account', 'id', 'email', '_station'], $usersQ, (int)($_GET['us_page'] ?? 1), $usPerPage);
    // v4.7.5：分页链接需保留其他三张表的页码/每页条数，切换任一表页码不重置其余表
    $saPgExtra = ['tab' => 'users', 'users_q' => $usersQ, 'st_page' => (int)($_GET['st_page'] ?? 1), 'au_page' => (int)($_GET['au_page'] ?? 1), 'us_page' => (int)($_GET['us_page'] ?? 1), 'st_per_page' => $stPerPage, 'au_per_page' => $auPerPage, 'us_per_page' => $usPerPage];
    $stPgExtra = ['tab' => 'users', 'users_q' => $usersQ, 'sa_page' => (int)($_GET['sa_page'] ?? 1), 'au_page' => (int)($_GET['au_page'] ?? 1), 'us_page' => (int)($_GET['us_page'] ?? 1), 'sa_per_page' => $saPerPage, 'au_per_page' => $auPerPage, 'us_per_page' => $usPerPage];
    $auPgExtra = ['tab' => 'users', 'users_q' => $usersQ, 'sa_page' => (int)($_GET['sa_page'] ?? 1), 'st_page' => (int)($_GET['st_page'] ?? 1), 'us_page' => (int)($_GET['us_page'] ?? 1), 'sa_per_page' => $saPerPage, 'st_per_page' => $stPerPage, 'us_per_page' => $usPerPage];
    $usPgExtra = ['tab' => 'users', 'users_q' => $usersQ, 'sa_page' => (int)($_GET['sa_page'] ?? 1), 'st_page' => (int)($_GET['st_page'] ?? 1), 'au_page' => (int)($_GET['au_page'] ?? 1), 'sa_per_page' => $saPerPage, 'st_per_page' => $stPerPage, 'au_per_page' => $auPerPage];
    function ysmUserDetail($u, $stationNames, $statComments, $statArticles) {
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
            'nickname' => $u['nickname'] ?? '', 'username' => $u['username'] ?? ($u['account'] ?? ''), 'account' => $u['account'] ?? '',
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
    function ysmStatusBadge($u) {
        if (!empty($u['disabled'])) return '<span class="role-badge" style="background:rgba(229,72,77,.12);color:var(--danger,#e5484d)">' . t('admin.017') . '</span>';
        return '<span class="role-badge" style="background:rgba(52,199,89,.12);color:#34c759">' . t('admin.018') . '</span>';
    }
    function ysmOpMenu($u, $stationNames, $detail) {
        if (($u['role'] ?? '') === ROLE_SUPER_ADMIN) return '<span style="color:var(--text-muted);font-size:0.82em">—</span>';
        $uid = htmlspecialchars($u['id']);
        $isAuthor = ($u['role'] ?? '') === ROLE_AUTHOR;
        $name = htmlspecialchars($u['nickname'] ?? '');
        $account = htmlspecialchars($u['username'] ?? ($u['account'] ?? ''));
        $roleKey = (string)($u['role'] ?? 'user');
        $roleLabel = ['station_admin' => t('admin.558'), 'author' => t('admin.557'), 'user' => t('admin.559')][$roleKey] ?? t('admin.559');
        $initial = htmlspecialchars(mb_strlen($u['nickname'] ?? '') ? mb_substr((string)$u['nickname'], 0, 1) : '?');
        $statusTxt = !empty($u['disabled']) ? '已禁用' : '正常';
        $statusColor = !empty($u['disabled']) ? 'var(--danger)' : 'var(--text-muted)';
        $tok = htmlspecialchars(generateCsrfToken());
        // v3.0.3~v3.0.5：⋯ 按钮（SVG 竖排三点）+ 隐藏 template（内容注入单例 userOpModal 独立弹窗展示）
        $h = '<button type="button" class="user-op-btn" data-op-target="uop-' . $uid . '" title="' . t('admin.019') . '"><svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button>';
        $h .= '<template id="uop-' . $uid . '">';
        // 用户信息头（头像按角色色光环）
        $h .= '<div class="user-op-modal-user"><div class="user-op-avatar role-' . htmlspecialchars($roleKey) . '">' . $initial . '</div><div><div class="user-op-user-name">' . $name . '</div><div class="user-op-user-meta"><span>用户名: ' . $account . '</span><span class="role-badge role-' . htmlspecialchars($roleKey) . '">' . $roleLabel . '</span><span style="color:' . $statusColor . '">' . $statusTxt . '</span></div></div></div>';
        // 查看详情（只读，无需挑战码）
        $h .= '<button type="button" class="user-op-item" data-detail=\'' . htmlspecialchars(json_encode($detail, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '\' onclick="ysmOpenUserDetail(this)"><span class="user-op-ic"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span><span class="user-op-txt">' . t('admin.020') . '</span></button>';
        // 权限设置
        $h .= '<div class="user-op-sep"></div><div class="user-op-head">' . t('admin.021') . '</div>';
        $h .= '<form method="post" class="need-challenge"><input type="hidden" name="csrf_token" value="' . $tok . '"><input type="hidden" name="action" value="set_user_role"><input type="hidden" name="user_id" value="' . $uid . '"><input type="hidden" name="challenge_code">';
        $h .= '<div class="user-op-form"><div class="user-op-row"><span class="user-op-label">' . t('admin.015') . '</span><select class="form-select" name="role">';
        foreach ([ROLE_STATION_ADMIN => t('admin.558'), ROLE_AUTHOR => t('admin.557'), ROLE_USER => t('admin.559')] as $rv => $rl) {
            $sel = ($u['role'] ?? '') === $rv ? ' selected' : '';
            $h .= '<option value="' . $rv . '"' . $sel . '>' . $rl . '</option>';
        }
        $h .= '</select><button type="submit" class="user-op-save">' . t('admin.022') . '</button></div></div></form>';
        // 归属设置（仅写作者）
        if ($isAuthor) {
            $h .= '<div class="user-op-sep"></div><div class="user-op-head">' . t('admin.023') . '</div>';
            $h .= '<form method="post" class="need-challenge"><input type="hidden" name="csrf_token" value="' . $tok . '"><input type="hidden" name="action" value="set_user_station"><input type="hidden" name="user_id" value="' . $uid . '"><input type="hidden" name="challenge_code">';
            $h .= '<div class="user-op-form"><div class="user-op-row"><span class="user-op-label">' . t('admin.024') . '</span><select class="form-select" name="station_id">';
            $h .= '<option value="">' . t('admin.003') . '</option>';
            foreach ($stationNames as $sid => $sname) {
                $sel = ($u['station_id'] ?? '') === $sid ? ' selected' : '';
                $h .= '<option value="' . htmlspecialchars($sid) . '"' . $sel . '>' . htmlspecialchars($sname) . '</option>';
            }
            $h .= '</select><button type="submit" class="user-op-save">' . t('admin.022') . '</button></div></div></form>';
        }
        // 禁用/启用
        $confirm = !empty($u['disabled']) ? t('admin.cf_enable', ['name' => $name]) : t('admin.cf_disable', ['name' => $name]);
        $h .= '<div class="user-op-sep"></div>';
        $h .= '<form method="post" class="need-challenge" data-confirm="' . htmlspecialchars($confirm, ENT_QUOTES) . '"><input type="hidden" name="csrf_token" value="' . $tok . '"><input type="hidden" name="action" value="set_user_disabled"><input type="hidden" name="user_id" value="' . $uid . '"><input type="hidden" name="disabled" value="' . (!empty($u['disabled']) ? '0' : '1') . '"><input type="hidden" name="challenge_code">';
        $h .= '<button type="submit" class="user-op-item">' . (!empty($u['disabled']) ? '<span class="user-op-ic"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg></span><span class="user-op-txt">' . t('admin.025') . '</span>' : '<span class="user-op-ic"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="18" y1="8" x2="23" y2="13"/><line x1="23" y1="8" x2="18" y2="13"/></svg></span><span class="user-op-txt">' . t('admin.026') . '</span>') . '</button></form>';
        // 重置密码
        $h .= '<form method="post" class="need-challenge" data-confirm="' . t('admin.cf_reset', ['name' => $name]) . '"><input type="hidden" name="csrf_token" value="' . $tok . '"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="' . $uid . '"><input type="hidden" name="challenge_code">';
        $h .= '<button type="submit" class="user-op-item"><span class="user-op-ic"><svg viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg></span><span class="user-op-txt">' . t('admin.027') . '</span></button></form>';
        // 删除
        $h .= '<div class="user-op-sep"></div>';
        $h .= '<form method="post" class="need-challenge" data-confirm="' . t('admin.cf_delete', ['name' => $name]) . '"><input type="hidden" name="csrf_token" value="' . $tok . '"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="' . $uid . '"><input type="hidden" name="challenge_code">';
        $h .= '<button type="submit" class="user-op-item danger"><span class="user-op-ic"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg></span><span class="user-op-txt">' . t('admin.028') . '</span></button></form>';
        $h .= '</template>';
        return $h;
    }
    ?>

    <!-- v2.11.5：系统管理员（只读，不可操作；v4.7.5 起同样参与搜索与分页） -->
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <?= t('admin.487') ?><span class="card-badge"><?= $saPaged['total'] ?></span>
        </div>
        <div class="table-wrap"><table>
            <tr><th><?= t('admin.010') ?></th><th><?= t('admin.029') ?></th><th><?= t('admin.015') ?></th><th><?= t('admin.030') ?></th><th><?= t('admin.031') ?></th></tr>
            <?php foreach ($saPaged['items'] as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['nickname'] ?? '') ?></td>
                <td style="color:var(--text-muted)"><code><?= htmlspecialchars($u['username'] ?? ($u['account'] ?? '')) ?></code></td>
                <td><span class="role-badge role-super_admin"><?= htmlspecialchars($u['role'] ?? '') ?></span></td>
                <td><?= ysmStatusBadge($u) ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($u['created'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$saPaged['items']): ?><tr><td colspan="5" class="table-empty"><?= $usersQ !== '' ? t('admin.601') : t('admin.600') ?></td></tr><?php endif; ?>
        </table></div>
        <?= renderPager($saPaged, 'sa_page', $saPgExtra, 'sa_per_page') ?>
    </div>

    <!-- 站长 -->
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            <?= t('admin.558') ?><span class="card-badge"><?= $stPaged['total'] ?></span>
        </div>
        <div class="table-wrap"><table>
            <tr><th><?= t('admin.010') ?></th><th><?= t('admin.029') ?></th><th><?= t('admin.030') ?></th><th><?= t('admin.031') ?></th><th><?= t('admin.032') ?></th></tr>
            <?php foreach ($stPaged['items'] as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['nickname'] ?? '') ?></td>
                <td style="color:var(--text-muted)"><code><?= htmlspecialchars($u['username'] ?? ($u['account'] ?? '')) ?></code></td>
                <td><?= ysmStatusBadge($u) ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($u['created'] ?? '') ?></td>
                <td><?= ysmOpMenu($u, $stationNames, ysmUserDetail($u, $stationNames, $statComments, $statArticles)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$stPaged['items']): ?><tr><td colspan="5" class="table-empty"><?= $usersQ !== '' ? '无匹配用户' : '暂无数据' ?></td></tr><?php endif; ?>
        </table></div>
        <?= renderPager($stPaged, 'st_page', $stPgExtra, 'st_per_page') ?>
    </div>

    <!-- 写作者（含归属站长列与归属管理） -->
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
            <?= t('admin.557') ?><span class="card-badge"><?= $auPaged['total'] ?></span>
        </div>
        <div class="table-wrap"><table>
            <tr><th><?= t('admin.010') ?></th><th><?= t('admin.029') ?></th><th><?= t('admin.033') ?></th><th><?= t('admin.030') ?></th><th><?= t('admin.031') ?></th><th><?= t('admin.032') ?></th></tr>
            <?php foreach ($auPaged['items'] as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['nickname'] ?? '') ?></td>
                <td style="color:var(--text-muted)"><code><?= htmlspecialchars($u['username'] ?? ($u['account'] ?? '')) ?></code></td>
                <td style="color:var(--text-secondary)"><?= htmlspecialchars($stationNames[$u['station_id'] ?? ''] ?? '超管') ?></td>
                <td><?= ysmStatusBadge($u) ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($u['created'] ?? '') ?></td>
                <td><?= ysmOpMenu($u, $stationNames, ysmUserDetail($u, $stationNames, $statComments, $statArticles)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$auPaged['items']): ?><tr><td colspan="6" class="table-empty"><?= $usersQ !== '' ? '无匹配用户' : '暂无数据' ?></td></tr><?php endif; ?>
        </table></div>
        <?= renderPager($auPaged, 'au_page', $auPgExtra, 'au_per_page') ?>
    </div>

    <!-- 普通用户 -->
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            <?= t('admin.559') ?><span class="card-badge"><?= $usPaged['total'] ?></span>
        </div>
        <div class="table-wrap"><table>
            <tr><th><?= t('admin.010') ?></th><th><?= t('admin.029') ?></th><th><?= t('admin.030') ?></th><th><?= t('admin.031') ?></th><th><?= t('admin.032') ?></th></tr>
            <?php foreach ($usPaged['items'] as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['nickname'] ?? '') ?></td>
                <td style="color:var(--text-muted)"><code><?= htmlspecialchars($u['username'] ?? ($u['account'] ?? '')) ?></code></td>
                <td><?= ysmStatusBadge($u) ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($u['created'] ?? '') ?></td>
                <td><?= ysmOpMenu($u, $stationNames, ysmUserDetail($u, $stationNames, $statComments, $statArticles)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$usPaged['items']): ?><tr><td colspan="5" class="table-empty"><?= $usersQ !== '' ? '无匹配用户' : '暂无数据' ?></td></tr><?php endif; ?>
        </table></div>
        <?= renderPager($usPaged, 'us_page', $usPgExtra, 'us_per_page') ?>
    </div>

    <?php elseif ($tab === 'logs'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <?= t('admin.467') ?>
        </div>
        <div class="page-subtitle"><?= t('admin.034') ?></div>
    </div>
    <?php
    $q = trim((string)($_GET['q'] ?? ''));
    $perPage = (int)($_GET['per_page'] ?? 10);
    if (!in_array($perPage, [10, 20, 50, 100], true)) $perPage = 10;
    $pageData = paginateList(loadAuditLogs(), ['ts', 'user_name', 'user_id', 'role', 'action', 'target', 'detail', 'ip'], $q, (int)($_GET['page'] ?? 1), $perPage);
    $chainResult = verifyAuditChain();
    ?>
    <?php if (!$chainResult['valid']): ?>
    <div class="card card-danger">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= t('admin.614') ?>
        </div>
        <p style="color:#fca5a5;font-size:0.9em"><?= t('admin.chain_broken_at', ['n' => $chainResult['broken_at']]) ?></p>
    </div>
    <?php else: ?>
    <div class="card card-success">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?= t('admin.561', ['n' => $chainResult['count']]) ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <?= t('admin.562', ['n' => $pageData['total']]) ?>
        </div>
        <div class="log-toolbar">
            <form class="log-toolbar-search" method="get" action="dashboard.php">
                <input type="hidden" name="tab" value="logs">
                <input type="text" class="form-input" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="<?= t('admin.390') ?>" autocomplete="off">
                <button type="submit" class="btn btn-sm btn-primary"><?= t('admin.009') ?></button>
                <?php if ($q !== ''): ?><a class="btn btn-sm btn-outline" href="dashboard.php?tab=logs"><?= t('admin.193') ?></a><?php endif; ?>
            </form>
            <span class="toolbar-spacer"></span>
            <?php if ($q !== ''): ?><span class="result-count"><?= t('common.match_count', ['n' => $pageData['total']]) ?></span><?php endif; ?>
        </div>
        <div class="table-wrap">
        <table>
            <tr><th><?= t('admin.035') ?></th><th><?= t('admin.036') ?></th><th><?= t('admin.015') ?></th><th><?= t('admin.032') ?></th><th><?= t('admin.037') ?></th><th><?= t('admin.038') ?></th><th>IP</th></tr>
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
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <?= t('admin.468') ?>
        </div>
        <div class="page-subtitle"><?= t('admin.039') ?></div>
    </div>
    <?php ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <?= t('admin.468') ?>
        </div>
        <form method="post" class="need-challenge">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="_save_config" value="1">
            <input type="hidden" name="challenge_code">
            <div class="form-group">
                <label class="form-label"><?= t('admin.040') ?></label>
                <input class="form-input" name="admin_email" value="<?= htmlspecialchars($config['admin_email'] ?? '') ?>" placeholder="admin@example.com">
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('admin.041') ?></label>
                <select class="form-select" name="update_channel" style="max-width:200px">
                    <option value="stable" <?= ($config['update_channel'] ?? 'stable') === 'stable' ? 'selected' : '' ?>><?= t('admin.391') ?></option>
                    <option value="beta" <?= ($config['update_channel'] ?? '') === 'beta' ? 'selected' : '' ?>><?= t('admin.200') ?></option>
                </select>
            </div>
            <div style="padding-top:16px;border-top:1px solid var(--border);margin-bottom:16px">
                <div class="card-title" style="margin-bottom:8px">
                    <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <?= t('admin.516') ?>
                </div>
            </div>
            <div class="toggle-row">
                <div><div class="toggle-label"><?= t('admin.042') ?></div><div class="toggle-desc"><?= t('admin.043') ?></div></div>
                <label class="toggle"><input type="checkbox" name="auto_ban" <?= empty($config['auto_ban']) ? '' : 'checked' ?>><span class="slider"></span></label>
            </div>
            <div class="toggle-row">
                <div><div class="toggle-label"><?= t('admin.044') ?></div><div class="toggle-desc"><?= t('admin.045') ?></div></div>
                <label class="toggle"><input type="checkbox" name="auto_ban_unauthorized" <?= empty($config['auto_ban_unauthorized']) ? '' : 'checked' ?>><span class="slider"></span></label>
            </div>
            <!-- v4.1.7：蜜罐攻击封禁阈值（后台可配，ysm-hfish-sync.py 读取） -->
            <div class="toggle-row" style="align-items:center">
                <div><div class="toggle-label"><?= t('admin.392') ?></div><div class="toggle-desc"><?= t('admin.393') ?><span style="color:var(--accent);font-weight:600"><?= (int)($config['hfish_ban_threshold'] ?? 10) ?></span><?= t('admin.394') ?></div></div>
                <input type="number" class="form-input" name="hfish_ban_threshold" value="<?= (int)($config['hfish_ban_threshold'] ?? 10) ?>" min="1" max="100" style="width:88px;flex-shrink:0" title="<?= t('admin.395') ?>">
            </div>
            <!-- v4.4.0：注册蜜罐自动封禁（独立于上方 HFish 蜜罐，仅针对注册表单隐藏蜜罐字段，静默拒绝） -->
            <div class="toggle-row" style="align-items:center">
                <div><div class="toggle-label"><?= t('admin.396') ?></div><div class="toggle-desc"><?= t('admin.397') ?><span style="color:var(--accent);font-weight:600"><?= (int)($config['honeypot_ban_count'] ?? 3) ?></span><?= t('admin.398') ?><span style="color:var(--accent);font-weight:600"><?= $config['honeypot_ban_duration'] > 0 ? (int)($config['honeypot_ban_duration'] / 60) . ' 分钟' : '永久' ?></span>）</div></div>
                <input type="number" class="form-input" name="honeypot_ban_count" value="<?= (int)($config['honeypot_ban_count'] ?? 3) ?>" min="1" max="50" style="width:70px;flex-shrink:0" title="<?= t('admin.399') ?>">
                <select class="form-input" name="honeypot_ban_duration" style="width:120px;flex-shrink:0" title="<?= t('admin.046') ?>">
                    <?php
                    $hpDurNow = (int)($config['honeypot_ban_duration'] ?? 3600);
                    $hpOpts = [['900', '15 分钟'], ['1800', '30 分钟'], ['3600', '1 小时'], ['7200', '2 小时'], ['21600', '6 小时'], ['43200', '12 小时'], ['86400', '24 小时'], ['604800', '7 天'], ['0', '永久']];
                    foreach ($hpOpts as $ho) {
                        $sel = $hpDurNow === (int)$ho[0] ? ' selected' : '';
                        echo '<option value="' . $ho[0] . '"' . $sel . '>' . $ho[1] . '</option>';
                    }
                    ?>
                </select>
            </div>
            <div class="toggle-row" onclick="openRateLimitModal()" style="cursor:pointer">
                <div><div class="toggle-label"><?= t('admin.047') ?></div><div class="toggle-desc"><?= t('admin.048') ?></div></div>
                <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
            <div style="padding-top:16px;border-top:1px solid var(--border);margin-bottom:16px">
                <div class="card-title" style="margin-bottom:8px">
                    <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <?= t('admin.610') ?>
                </div>
                <p class="form-hint" style="margin-bottom:12px"><?= t('admin.049') ?></p>
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('admin.050') ?></label>
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="color:var(--text-muted)">/</span>
                    <input class="form-input mono" name="station_path" value="<?= htmlspecialchars($config['station_path'] ?? 'station') ?>" placeholder="station" maxlength="30" pattern="[a-zA-Z0-9][a-zA-Z0-9-]{2,28}[a-zA-Z0-9]">
                    <span style="color:var(--text-muted)">/dashboard.php</span>
                </div>
                <p class="form-hint"><?= t('admin.051') ?></p>
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('admin.052') ?></label>
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="color:var(--text-muted)">/</span>
                    <input class="form-input mono" name="author_path" value="<?= htmlspecialchars($config['author_path'] ?? 'author') ?>" placeholder="author" maxlength="30" pattern="[a-zA-Z0-9][a-zA-Z0-9-]{2,28}[a-zA-Z0-9]">
                    <span style="color:var(--text-muted)">/dashboard.php</span>
                </div>
                <p class="form-hint"><?= t('admin.053') ?></p>
            </div>
            <div class="form-group">
                <div style="display:flex;align-items:center;gap:10px">
                    <span style="font-size:13px"><?= t('admin.054') ?></span>
                    <label class="toggle" style="flex-shrink:0;margin-left:auto"><input type="checkbox" name="hide_default_paths" <?= empty($config['hide_default_paths']) ? '' : 'checked' ?>><span class="slider"></span></label>
                </div>
            </div>
            <!-- 频率限制弹窗 -->
    <div class="modal-overlay" id="rateLimitModal">
        <div class="modal-box" style="max-width:420px;text-align:left">
            <div class="modal-head">
                <div class="modal-title"><?= t('admin.047') ?></div>
                <button class="modal-close" onclick="closeRateLimitModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:0.88em;color:var(--text-secondary);margin-bottom:16px;"><?= t('admin.055') ?></p>
                <div class="form-group">
                    <label class="form-label"><?= t('admin.056') ?></label>
                    <input class="form-input" type="number" name="max_login_fails" value="<?= $config['max_login_fails'] ?? 10 ?>" min="3" max="100">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('admin.057') ?></label>
                    <input class="form-input" type="number" name="max_comments_per_minute" value="<?= $config['max_comments_per_minute'] ?? 5 ?>" min="1" max="60">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('admin.058') ?></label>
                    <input class="form-input" type="number" name="max_registrations_per_ip" value="<?= $config['max_registrations_per_ip'] ?? 3 ?>" min="1" max="50">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeRateLimitModal()"><?= t('admin.059') ?></button>
                    <button type="submit" class="btn btn-primary"><?= t('admin.060') ?></button>
                </div>
            </div>
        </div>
    </div>
    <script>
    function openRateLimitModal() { document.getElementById('rateLimitModal').classList.add('active'); }
    function closeRateLimitModal() { document.getElementById('rateLimitModal').classList.remove('active'); }
    document.getElementById('rateLimitModal')?.addEventListener('click', function(e) { if (e.target === this) closeRateLimitModal(); });
    </script>
            <button type="submit" class="btn btn-primary"><?= t('admin.061') ?></button>
        </form>
    </div>

    <?php
    $typeLabels = ['register' => t('admin.602'), 'comment' => t('admin.603'), 'login' => t('admin.604')];
    ?>
    <?php elseif ($tab === 'security'): ?>
    <?php
    $banTypes = loadBans();
    // v4.1.8：封禁列表分页（与日志分页同模式）
    $banPerPage = (int)($_GET['ban_per_page'] ?? 10);
    if (!in_array($banPerPage, [10, 20, 50, 100], true)) $banPerPage = 10;
    $banQ = trim((string)($_GET['ban_q'] ?? ''));
    $banData = paginateList($banTypes, ['ip', 'reason', 'time'], $banQ, (int)($_GET['ban_page'] ?? 1), $banPerPage);
    ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <?= t('admin.469') ?>
        </div>
        <div class="page-subtitle"><?= t('admin.062') ?></div>
    </div>
    <?php if ($banMsg):
        $msgText = match($banMsg) {
            'cleared' => t('admin.546'),
            'recovered' => t('admin.545'),
            default => $banMsg,
        };
    ?>
    <div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= htmlspecialchars($msgText) ?></div>
    <?php endif; ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?= t('admin.492') ?>
        </div>
        <form method="post" class="need-challenge">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="ban_action" value="add">
            <input type="hidden" name="challenge_code">
            <div class="form-row">
                <div class="form-group" style="flex:1">
                    <label class="form-label"><?= t('admin.063') ?></label>
                    <input class="form-input mono" name="ip" placeholder="<?= t('admin.064') ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('admin.065') ?></label>
                <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">
                    <span style="font-size:13px"><?= t('admin.066') ?></span><label class="toggle" style="flex-shrink:0"><input type="checkbox" name="types[]" value="register"><span class="slider"></span></label>
                    <span style="font-size:13px"><?= t('admin.067') ?></span><label class="toggle" style="flex-shrink:0"><input type="checkbox" name="types[]" value="comment"><span class="slider"></span></label>
                    <span style="font-size:13px"><?= t('admin.068') ?></span><label class="toggle" style="flex-shrink:0"><input type="checkbox" name="types[]" value="login"><span class="slider"></span></label>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('admin.069') ?></label>
                <input class="form-input" name="reason" placeholder="<?= t('admin.070') ?>">
            </div>
            <button type="submit" class="btn btn-primary"><?= t('admin.071') ?></button>
        </form>
    </div>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            <?= t('admin.563', ['n' => $banData['total']]) ?>
        </div>
        <?php if (empty($banData['items'])): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            <p><?= t('admin.072') ?></p>
        </div>
        <?php else: ?>
        <div class="ban-list">
            <?php foreach ($banData['items'] as $ban): ?>
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
                <div style="font-size:0.82em;color:var(--text-muted);margin-bottom:8px"><?= t('common.reason') ?><?= htmlspecialchars($ban['reason']) ?></div>
                <?php endif; ?>
                <div class="ban-item-actions">
                    <form method="post" style="display:inline" class="need-challenge" data-confirm="<?= t('admin.cf_unban', ['ip' => $ban['ip']]) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                        <input type="hidden" name="ban_action" value="remove">
                        <input type="hidden" name="ip" value="<?= htmlspecialchars($ban['ip']) ?>">
                        <input type="hidden" name="challenge_code">
                        <button type="submit" class="btn-link danger" title="<?= t('admin.073') ?>"><?= t('admin.073') ?></button>
                    </form>
                    <button type="button" class="btn-link" onclick="openBanEdit('<?= htmlspecialchars($ban['ip']) ?>', '<?= htmlspecialchars(json_encode($ban['types'] ?? [])) ?>')" title="<?= t('links.011') ?>"><?= t('links.011') ?></button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?= renderPager($banData, 'ban_page', ['tab' => 'security', 'ban_q' => $banQ], 'ban_per_page') ?>
    </div>
    <!-- 编辑封禁弹窗 -->
    <div class="modal-overlay" id="banEditModal">
        <div class="modal-box" style="max-width:400px">
            <div class="modal-head">
                <div class="modal-title"><?= t('admin.074') ?></div>
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
                        <div><div class="toggle-label"><?= t('admin.066') ?></div><div class="toggle-desc"><?= t('admin.075') ?></div></div>
                        <label class="toggle"><input type="checkbox" name="types[]" value="register"><span class="slider"></span></label>
                    </div>
                    <div class="toggle-row">
                        <div><div class="toggle-label"><?= t('admin.067') ?></div><div class="toggle-desc"><?= t('admin.076') ?></div></div>
                        <label class="toggle"><input type="checkbox" name="types[]" value="comment"><span class="slider"></span></label>
                    </div>
                    <div class="toggle-row">
                        <div><div class="toggle-label"><?= t('admin.068') ?></div><div class="toggle-desc"><?= t('admin.077') ?></div></div>
                        <label class="toggle"><input type="checkbox" name="types[]" value="login"><span class="slider"></span></label>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-outline" onclick="closeBanEdit()"><?= t('admin.059') ?></button>
                        <button type="submit" class="btn btn-primary"><?= t('admin.022') ?></button>
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
    $unauthPerPage = (int)($_GET['unauth_per_page'] ?? 10);
    $loginPerPage = (int)($_GET['login_per_page'] ?? 10);
    $auditPerPage = (int)($_GET['audit_per_page'] ?? 10);
    if (!in_array($unauthPerPage, [10, 20, 50, 100], true)) $unauthPerPage = 10;
    if (!in_array($loginPerPage, [10, 20, 50, 100], true)) $loginPerPage = 10;
    if (!in_array($auditPerPage, [10, 20, 50, 100], true)) $auditPerPage = 10;
    $unauthQ = trim((string)($_GET['unauth_q'] ?? ''));
    $unauthData = paginateList(db_all('SELECT * FROM unauthorized ORDER BY time DESC'), ['time', 'ip', 'action', 'user', 'user_id', 'ua'], $unauthQ, (int)($_GET['unauth_page'] ?? 1), $unauthPerPage);
    ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <?= t('admin.564', ['n' => $unauthData['total']]) ?>
        </div>
        <div class="log-toolbar">
            <form method="get" action="dashboard.php" class="log-toolbar-search">
                <input type="hidden" name="tab" value="security">
                <input type="text" class="form-input" name="unauth_q" value="<?= htmlspecialchars($unauthQ) ?>" placeholder="<?= t('admin.400') ?>" autocomplete="off">
                <button type="submit" class="btn btn-sm btn-primary"><?= t('admin.009') ?></button>
                <?php if ($unauthQ !== ''): ?><a class="btn btn-sm btn-outline" href="dashboard.php?tab=security"><?= t('admin.193') ?></a><?php endif; ?>
            </form>
            <span class="toolbar-spacer"></span>
            <?php if ($unauthQ !== ''): ?><span class="result-count"><?= t('common.match_count', ['n' => $unauthData['total']]) ?></span><?php endif; ?>
            <?php if ($unauthData['total'] > 0): ?>
            <form method="post" data-confirm="<?= htmlspecialchars(t('admin.078'), ENT_QUOTES) ?>" onsubmit="return ysmConfirm(this)">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <input type="hidden" name="clear_unauth" value="1">
                <button type="submit" class="btn btn-sm btn-outline" style="color:#ef4444;border-color:#fecaca"><?= t('admin.079') ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?php if (empty($unauthData['items'])): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <p><?= $unauthQ !== '' ? t('admin.570', ['q' => $unauthQ]) : t('admin.567') ?></p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th><?= t('admin.035') ?></th><th>IP</th><th><?= t('admin.032') ?></th><th><?= t('admin.036') ?></th></tr>
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
            <?= t('admin.565', ['n' => $loginData['total']]) ?>
        </div>
        <div class="log-toolbar">
            <form class="log-toolbar-search" method="get" action="dashboard.php">
                <input type="hidden" name="tab" value="security">
                <input type="text" class="form-input" name="login_q" value="<?= htmlspecialchars($loginQ) ?>" placeholder="<?= t('admin.401') ?>" autocomplete="off">
                <button type="submit" class="btn btn-sm btn-primary"><?= t('admin.009') ?></button>
                <?php if ($loginQ !== ''): ?><a class="btn btn-sm btn-outline" href="dashboard.php?tab=security"><?= t('admin.193') ?></a><?php endif; ?>
            </form>
            <span class="toolbar-spacer"></span>
            <?php if ($loginQ !== ''): ?><span class="result-count"><?= t('common.match_count', ['n' => $loginData['total']]) ?></span><?php endif; ?>
        </div>
        <?php if (empty($loginData['items'])): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            <p><?= $loginQ !== '' ? t('admin.570', ['q' => $loginQ]) : t('admin.568') ?></p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th><?= t('admin.035') ?></th><th>IP</th><th><?= t('admin.032') ?></th></tr>
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
            <?= t('admin.566', ['n' => $auditData['total']]) ?>
        </div>
        <div style="margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span style="font-size:0.82em;color:var(--text-muted)"><?= t('admin.080') ?></span>
            <span class="chain-badge <?= $chainResult['valid'] ? 'chain-valid' : 'chain-invalid' ?>" style="font-size:0.82em">
                <?php if ($chainResult['valid']): ?>
                <?= t('admin.615', ['n' => $chainResult['count']]) ?>
                <?php else: ?>
                <?= t('admin.616', ['n' => ($chainResult['broken_at'] ?? 0) + 1]) ?>
                <?php endif; ?>
            </span>
            <?php $__mir = auditMirrorStatus(); $__rr = readAuditRecoverResult(); ?>
            <?php if (($_GET['bmsg'] ?? '') === 'recover_submitted'): ?>
            <div class="msg msg-success" style="margin:8px 0 0"><?= t('admin.081') ?></div>
            <?php endif; ?>
            <?php if (!$chainResult['valid'] && $__mir['present']): ?>
            <form method="post" style="display:inline" class="need-challenge" data-confirm="<?= t('admin.cf_restore_audit') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <input type="hidden" name="recover_audit" value="1">
                <input type="hidden" name="challenge_code">
                <button type="submit" class="btn btn-sm btn-outline" style="color:#f59e0b;border-color:#fcd34d"><?= t('admin.082') ?></button>
            </form>
            <?php elseif (!$chainResult['valid']): ?>
            <span style="color:var(--text-muted);font-size:12px"><?= t('admin.083') ?><code>sudo ysm-admin audit-recover</code></span>
            <?php endif; ?>
            <?php if (!empty($__rr['status'])): ?>
            <div style="color:var(--text-muted);font-size:12px;margin-top:4px"><?= t('admin.last_restore') ?><?= htmlspecialchars(($__rr['status'] ?? '') . ' · ' . ($__rr['message'] ?? '') . ' · ' . ($__rr['time'] ?? '')) ?></div>
            <?php endif; ?>
            <form method="post" style="display:inline;margin-left:auto" class="need-challenge" data-confirm="<?= t('admin.cf_clear_audit') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <input type="hidden" name="clear_audit" value="1">
                <input type="hidden" name="challenge_code">
                <button type="submit" class="btn btn-sm btn-outline" style="color:#ef4444;border-color:#fecaca"><?= t('admin.079') ?></button>
            </form>
        </div>
        <div class="log-toolbar">
            <form class="log-toolbar-search" method="get" action="dashboard.php">
                <input type="hidden" name="tab" value="security">
                <input type="text" class="form-input" name="audit_q" value="<?= htmlspecialchars($auditQ) ?>" placeholder="<?= t('admin.390') ?>" autocomplete="off">
                <button type="submit" class="btn btn-sm btn-primary"><?= t('admin.009') ?></button>
                <?php if ($auditQ !== ''): ?><a class="btn btn-sm btn-outline" href="dashboard.php?tab=security"><?= t('admin.193') ?></a><?php endif; ?>
            </form>
            <span class="toolbar-spacer"></span>
            <?php if ($auditQ !== ''): ?><span class="result-count"><?= t('common.match_count', ['n' => $auditData['total']]) ?></span><?php endif; ?>
        </div>
        <?php if (empty($auditData['items'])): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <p><?= $auditQ !== '' ? t('admin.570', ['q' => $auditQ]) : t('admin.569') ?></p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <tr><th><?= t('admin.035') ?></th><th><?= t('admin.036') ?></th><th><?= t('admin.015') ?></th><th><?= t('admin.032') ?></th><th><?= t('admin.037') ?></th><th><?= t('admin.038') ?></th><th><?= t('admin.084') ?></th></tr>
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

    <?php elseif ($tab === 'ui'): ?>
    <?php
    $bgType = $config['bg_type'] ?? 'none';
    $bgImage = $config['bg_image'] ?? '';
    $bgApiUrl = $config['bg_api_url'] ?? '';
    $cardCoverApi = $config['card_cover_api'] ?? '';
    $bgBlurEnabled = !empty($config['bg_blur_enabled']);
    $bgBlurLevel = $config['bg_blur_level'] ?? 0;
    $bgCardOpacity = $config['bg_card_opacity'] ?? 100;
    $bgMsg = $_GET['msg'] ?? '';
    ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            <?= t('admin.470') ?>
        </div>
        <div class="page-subtitle"><?= t('admin.085') ?></div>
    </div>
    <?php if ($bgMsg === 'saved'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('admin.402') ?></div><?php endif; ?>
    <?php if ($bgMsg === 'uploaded'): ?><div class="msg msg-success"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('admin.403') ?></div><?php endif; ?>
    <?php if ($bgMsg === 'upload_error'): ?><div class="msg msg-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><?= t('admin.404') ?></div><?php endif; ?>
    <!-- v4.1.18：站点外观（从安全设置移入，非敏感项无需挑战码） -->
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <?= t('admin.573') ?>
        </div>
        <div class="form-group">
            <label class="form-label"><?= t('admin.086') ?></label>
            <input class="form-input" type="text" id="siteTitleInput" value="<?= htmlspecialchars($config['site_title'] ?? '') ?>" maxlength="60">
        </div>
        <div class="form-row" style="margin-bottom:16px;align-items:center">
            <div style="display:flex;align-items:center;gap:8px"><span style="font-size:13px"><?= t('admin.405') ?></span><label class="toggle" style="flex-shrink:0"><input type="checkbox" id="regToggle" <?= empty($config['registration_enabled']) ? '' : 'checked' ?>><span class="slider"></span></label></div>
            <div style="display:flex;align-items:center;gap:8px"><span style="font-size:13px"><?= t('admin.406') ?></span><label class="toggle" style="flex-shrink:0"><input type="checkbox" id="guestToggle" <?= empty($config['guest_comments_enabled']) ? '' : 'checked' ?>><span class="slider"></span></label></div>
            <div style="display:flex;align-items:center;gap:8px"><span style="font-size:13px"><?= t('admin.407') ?></span><label class="toggle" style="flex-shrink:0"><input type="checkbox" id="superCommentToggle" <?= empty($config['super_admin_comment']) ? '' : 'checked' ?>><span class="slider"></span></label></div>
        </div>
        <div style="padding:12px 16px;border:1px solid var(--border);border-radius:12px;background:var(--surface)">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                <div>
                    <div style="font-weight:600"><?= t('admin.087') ?></div>
                    <div style="font-size:0.82em;color:var(--text-muted)"><?= t('admin.088') ?></div>
                </div>
                <label class="toggle" style="flex-shrink:0"><input type="checkbox" id="notifyToggle" <?= empty($config['comment_notify_enabled']) ? '' : 'checked' ?>><span class="slider"></span></label>
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label"><?= t('admin.089') ?></label>
                <input class="form-input" type="email" id="notifyEmailInput" value="<?= htmlspecialchars($config['comment_notify_email'] ?? '') ?>" placeholder="<?= t('admin.408') ?>">
            </div>
        </div>
    </div>
    <!-- v4.1.18：音乐播放器（从安全设置移入，非敏感项无需挑战码） -->
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
            <?= t('admin.515') ?>
        </div>
        <!-- v5.3.0：音乐为「预设通道」——歌单 ID / 默认播放歌曲 / Cookies 三项已固定，后台不再展示。
             采用 display:none 隐藏（可回退：删除本包装 <div> 的 style 即恢复显示）。输入元素保留在 DOM 中，
             既不影响 bgForm 提交时的取值逻辑（见下方 submit 处理器），也不丢失既有数据（前台按预设正常工作）。 -->
        <div style="display:none">
        <div class="form-group">
            <label class="form-label"><?= t('admin.090') ?></label>
            <input class="form-input" type="text" id="musicNeteaseInput" value="<?= htmlspecialchars($config['music_playlist_id'] ?? '3778678') ?>" placeholder="3778678">
            <p class="form-hint"><?= t('admin.091') ?></p>
        </div>
        <!-- v4.4.0：默认播放歌曲——打开歌单无历史播放时自动定位播放该歌（歌曲名关键词） -->
        <div class="form-group">
            <label class="form-label"><?= t('admin.092') ?></label>
            <input class="form-input" type="text" id="musicAutoPlayInput" value="<?= htmlspecialchars($config['music_auto_play'] ?? '') ?>" placeholder="<?= t('admin.409') ?>" maxlength="60">
            <p class="form-hint"><?= t('admin.093') ?></p>
        </div>
        <div class="form-group">
            <label class="form-label"><?= t('admin.094') ?></label>
            <input class="form-input" type="text" id="musicNetCookieInput" value="<?= htmlspecialchars($config['music_cookies'] ?? '') ?>" placeholder="MUSIC_U=xxx; __csrf=xxx; ...">
            <p class="form-hint"><?= t('admin.095') ?></p>
        </div>
        </div>
        <!-- v4.5.0：本地背景音乐——超管/站长后台上传单曲作默认背景音，前台只能开/关不能选曲；单曲循环，浏览器缓存不重复消耗服务器流量；<100MB 常见音频自动转码压缩 -->
        <div class="form-group">
            <div class="toggle-row">
                <label class="toggle-label"><?= t('admin.096') ?></label>
                <label class="toggle" style="flex-shrink:0"><input type="checkbox" id="bgMusicToggle" <?= !empty($config['bg_music_enabled']) ? 'checked' : '' ?>><span class="slider"></span></label>
                <div class="toggle-desc"><?= t('admin.097') ?></div>
            </div>
            <input class="form-input" type="file" id="bgMusicFileInput" name="bg_music_file" accept=".mp3,.wav,.flac,.m4a,.aac,.ogg,.opus,.wma,audio/*" style="margin-top:8px">
            <p class="form-hint" id="bgMusicStatus"><?php $bmSize = bgMusicSize(); echo $bmSize > 0 ? ('当前背景音：' . round($bmSize / 1024 / 1024, 1) . ' MB（上传新文件可替换）') : '尚未上传背景音乐（mp3/wav/flac/m4a 等 ≤100MB，服务器自动转码压缩为 96kbps mp3）'; ?></p>
        </div>
    </div>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            <?= t('admin.512') ?>
        </div>
        <div class="bg-type-grid bg-vis-grid">
            <label class="bg-type-card <?= $bgType==='none'?'active':'' ?>" data-type="none"><input type="radio" name="bg_type" value="none" <?= $bgType==='none'?'checked':'' ?>><div class="type-icon none"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></div><div class="type-name"><?= t('station.044') ?></div></label>
            <label class="bg-type-card <?= $bgType==='image'?'active':'' ?>" data-type="image"><input type="radio" name="bg_type" value="image" <?= $bgType==='image'?'checked':'' ?>><div class="type-icon upload"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></div><div class="type-name"><?= t('admin.410') ?></div></label>
            <label class="bg-type-card <?= $bgType==='api'?'active':'' ?>" data-type="api"><input type="radio" name="bg_type" value="api" <?= $bgType==='api'?'checked':'' ?>><div class="type-icon api"><svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></div><div class="type-name"><?= t('admin.411') ?></div></label>
        </div>
    </div>
    <div class="card" id="imageSection" style="display:<?= $bgType==='image'?'block':'none' ?>">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg><?= t('admin.098') ?></div>
        <?php if ($bgImage && $bgType==='image'): ?>
        <div class="img-preview-thumb"><img src="../<?= htmlspecialchars($bgImage) ?>" alt="<?= t('admin.412') ?>"><button class="remove-img" onclick="removeBgImage()" title="<?= t('admin.339') ?>">&times;</button></div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <div class="upload-area">
                <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <div class="upload-text"><?= t('admin.099') ?></div>
                <div class="upload-hint"><?= t('admin.100') ?></div>
                <input type="file" name="bg_image" accept="image/jpeg,image/png,image/gif,image/webp" onchange="if(this.files.length)this.form.submit()">
            </div>
        </form>
        <input type="hidden" id="bgImagePath" value="<?= htmlspecialchars($bgImage) ?>">
    </div>
    <div class="card" id="apiSection" style="display:<?= $bgType==='api'?'block':'none' ?>">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg><?= t('admin.101') ?></div>
        <div class="form-group">
            <label class="form-label"><?= t('admin.102') ?></label>
            <div class="api-url-group">
                <input class="form-input" type="url" id="bgApiUrl" value="<?= htmlspecialchars($bgApiUrl) ?>" placeholder="<?= htmlspecialchars(FIXED_IMG_API) ?>">
                <button class="btn btn-sm btn-outline" type="button" onclick="testApiUrl()"><?= t('admin.103') ?></button>
            </div>
        </div>
        <div id="apiTestResult" style="margin-top:8px"></div>
    </div>
    <!-- v4.2.4：首页卡片封面图片源（用户自定义则优先，留空回退固定源，走同一池化缓存策略） -->
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg><?= t('admin.104') ?></div>
        <div class="form-group">
            <label class="form-label"><?= t('admin.105') ?></label>
            <div class="api-url-group">
                <input class="form-input" type="url" id="cardCoverApiInput" value="<?= htmlspecialchars($cardCoverApi) ?>" placeholder="<?= htmlspecialchars(FIXED_IMG_API) ?>">
                <button class="btn btn-sm btn-outline" type="button" onclick="testCardCoverApi()"><?= t('admin.103') ?></button>
            </div>
            <p class="form-hint"><?= t('admin.106') ?></p>
        </div>
        <div id="cardCoverApiTestResult" style="margin-top:8px"></div>
    </div>
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?= t('admin.107') ?></div>
        <div class="toggle-row" id="bgBlurRow" style="display:<?= $bgType!=='none'?'flex':'none' ?>">
            <div><div class="toggle-label"><?= t('admin.108') ?></div><div class="toggle-desc"><?= t('admin.109') ?></div></div>
            <label class="toggle"><input type="checkbox" id="blurToggle" <?= $bgBlurEnabled?'checked':'' ?>><span class="slider"></span></label>
        </div>
        <div id="blurLevelWrap" style="display:<?= ($bgBlurEnabled && $bgType!=='none')?'block':'none' ?>;padding-top:8px">
            <div class="slider-group">
                <div class="slider-header"><label><?= t('admin.413') ?></label><span class="slider-val" id="blurVal"><?= $bgBlurLevel ?>px</span></div>
                <div class="slider-row"><span class="slider-label"><?= t('admin.414') ?></span><input type="range" min="0" max="50" value="<?= $bgBlurLevel ?>" step="2" id="blurSlider"><span class="slider-label"><?= t('admin.415') ?></span></div>
            </div>
        </div>
        <div class="slider-group" style="margin-top:12px">
            <div class="slider-header"><label><?= t('admin.416') ?></label><span class="slider-val" id="opacityVal"><?= $bgCardOpacity ?>%</span></div>
            <div class="slider-row"><span class="slider-label"><?= t('admin.417') ?></span><input type="range" min="20" max="100" value="<?= $bgCardOpacity ?>" step="5" id="opacitySlider"><span class="slider-label"><?= t('admin.418') ?></span></div>
        </div>
    </div>
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><?= t('admin.110') ?></div>
        <div class="preview-box" id="previewBox">
            <div class="preview-blur" id="previewBlur"></div>
            <div class="preview-card-sim" id="previewCard"><div class="sim-title"><?= t('admin.111') ?></div><div class="sim-line" style="width:80%"></div><div class="sim-line" style="width:100%"></div><div class="sim-line"></div></div>
            <div class="preview-overlay"><span id="previewBgLabel"><?= t('admin.112') ?></span><span id="previewBlurLabel"></span></div>
        </div>
    </div>
    <form method="post" id="bgForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
        <input type="hidden" name="_ui_save" value="1">
        <input type="hidden" name="bg_type" id="formBgType" value="<?= htmlspecialchars($bgType) ?>">
        <input type="hidden" name="bg_image" id="formBgImage" value="<?= htmlspecialchars($bgImage) ?>">
        <input type="hidden" name="bg_api_url" id="formBgApiUrl" value="<?= htmlspecialchars($bgApiUrl) ?>">
        <input type="hidden" name="card_cover_api" id="formCardCoverApi" value="<?= htmlspecialchars($cardCoverApi) ?>">
        <input type="hidden" name="bg_blur_enabled" id="formBlurEnabled" value="<?= $bgBlurEnabled?'1':'' ?>">
        <input type="hidden" name="bg_blur_level" id="formBlurLevel" value="<?= $bgBlurLevel ?>">
        <input type="hidden" name="bg_card_opacity" id="formCardOpacity" value="<?= $bgCardOpacity ?>">
        <input type="hidden" name="site_title" id="formSiteTitle" value="<?= htmlspecialchars($config['site_title'] ?? '') ?>">
        <input type="hidden" name="registration_enabled" id="formRegEnabled" value="<?= !empty($config['registration_enabled'])?'1':'' ?>">
        <input type="hidden" name="guest_comments_enabled" id="formGuestComments" value="<?= !empty($config['guest_comments_enabled'])?'1':'' ?>">
        <input type="hidden" name="super_admin_comment" id="formSuperComment" value="<?= !empty($config['super_admin_comment'])?'1':'' ?>">
        <input type="hidden" name="comment_notify_enabled" id="formNotifyEnabled" value="<?= !empty($config['comment_notify_enabled'])?'1':'' ?>">
        <input type="hidden" name="comment_notify_email" id="formNotifyEmail" value="<?= htmlspecialchars($config['comment_notify_email'] ?? '') ?>">
        <input type="hidden" name="music_playlist_id" id="formMusicNetease" value="<?= htmlspecialchars($config['music_playlist_id'] ?? '3778678') ?>">
        <input type="hidden" name="music_auto_play" id="formMusicAutoPlay" value="<?= htmlspecialchars($config['music_auto_play'] ?? '') ?>">
        <input type="hidden" name="music_cookies" id="formMusicNetCookie" value="<?= htmlspecialchars($config['music_cookies'] ?? '') ?>">
        <input type="hidden" name="bg_music_enabled" id="formBgMusicEnabled" value="<?= !empty($config['bg_music_enabled']) ? '1' : '' ?>">
        <div style="display:flex;justify-content:flex-end;gap:10px">
            <button type="button" class="btn btn-outline" onclick="resetBg()"><?= t('admin.113') ?></button>
            <button type="submit" class="btn btn-primary"><?= t('admin.061') ?></button>
        </div>
    </form>

    <!-- ===== v6.0.0：语言与字体（首页默认语言 / 语言启停 / 自定义字体） ===== -->
    <form method="post" class="card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
        <input type="hidden" name="_lang_save" value="1">
        <div class="card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            <?= t('admin.514') ?>
        </div>
        <div class="form-group">
            <label class="form-label"><?= t('admin.114') ?></label>
            <select class="form-input" name="default_lang">
                <?php foreach ($allLangsAdmin as $lc => $info): $isDis = in_array($lc, $disabledLangs, true); ?>
                <option value="<?= htmlspecialchars($lc) ?>" <?= ($config['default_lang'] ?? 'zh-CN') === $lc ? 'selected' : '' ?> <?= $isDis ? 'disabled' : '' ?>><?= htmlspecialchars($info['name']) ?>（<?= htmlspecialchars($lc) ?>）<?= $isDis ? ' · 已停用' : '' ?></option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint"><?= t('admin.115') ?></p>
        </div>
        <div class="form-group">
            <label class="form-label"><?= t('admin.116') ?></label>
            <div style="display:flex;flex-direction:column;gap:8px;margin-top:4px">
                <?php foreach ($allLangsAdmin as $lc => $info): $on = !in_array($lc, $disabledLangs, true); ?>
                <label class="toggle-row" style="gap:12px;align-items:center">
                    <span style="flex:1;min-width:0;word-break:break-word"><?= htmlspecialchars($info['name']) ?> <code style="opacity:.6;font-size:12px"><?= htmlspecialchars($lc) ?></code></span>
                    <span class="toggle"><input type="checkbox" name="enabled_langs[]" value="<?= htmlspecialchars($lc) ?>" <?= $on ? 'checked' : '' ?> <?= $lc === 'zh-CN' ? 'disabled checked' : '' ?>><span class="slider"></span></span>
                </label>
                <?php endforeach; ?>
            </div>
            <p class="form-hint"><?= t('admin.117') ?><code>lang/*.php</code><?= t('admin.118') ?></p>
        </div>
        <div class="toggle-row">
            <div><div class="toggle-label"><?= t('admin.119') ?></div><div class="toggle-desc"><?= t('admin.120') ?></div></div>
            <label class="toggle"><input type="checkbox" name="custom_font_enabled" <?= !empty($config['custom_font_enabled']) ? 'checked' : '' ?>><span class="slider"></span></label>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:12px">
            <button type="submit" class="btn btn-primary"><?= t('admin.121') ?></button>
        </div>
    </form>

    <form method="post" enctype="multipart/form-data" class="card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
        <input type="hidden" name="_font_upload" value="1">
        <div class="card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7V4h16v3"/><line x1="12" y1="4" x2="12" y2="20"/><line x1="9" y1="20" x2="15" y2="20"/></svg>
            <?= t('admin.513') ?>
        </div>
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:10px;word-break:break-all">
            <?= t('admin.font_current') ?><?= $customFontName !== '' ? htmlspecialchars($customFontName) : t('admin.font_none') ?>
            <?= !empty($config['custom_font_enabled']) ? ' · <span style="color:#16a34a">' . t('admin.font_enabled') . '</span>' : ' · <span style="color:var(--text-muted)">' . t('admin.font_disabled') . '</span>' ?>
        </p>
        <div class="upload-area">
            <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <div class="upload-text"><?= t('admin.122') ?></div>
            <div class="upload-hint"><?= t('admin.123') ?></div>
            <input type="file" name="custom_font" accept=".ttf,.otf,.woff,.woff2" onchange="if(this.files.length)this.form.submit()">
        </div>
        <?php if ($customFontName !== ''): ?>
        <div style="margin-top:10px">
            <button type="submit" class="btn btn-outline" name="_font_remove" value="1" data-confirm="<?= htmlspecialchars(t('admin.124'), ENT_QUOTES) ?>" onclick="return ysmConfirm(this)"><?= t('admin.125') ?></button>
        </div>
        <?php endif; ?>
    </form>
    <script>
    (function() {
        var typeCards = document.querySelectorAll('.bg-type-card[data-type]');
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
        // v4.2.4：卡片封面 API 测试（仅探测可加载性，实际抓取走服务端池化代理）
        window.testCardCoverApi = function() { var input = document.getElementById('cardCoverApiInput'); var url = input.value.trim(); if (!url) return; var result = document.getElementById('cardCoverApiTestResult'); result.innerHTML = '<span style="color:var(--text-muted);font-size:13px">测试中...</span>'; var img = new Image(); img.onload = function() { result.innerHTML = '<div class="img-preview-thumb"><img src="'+url+'" style="max-width:200px;max-height:120px"></div><div style="font-size:12px;color:#16a34a;margin-top:4px">✓ 图片可访问</div>'; }; img.onerror = function() { result.innerHTML = '<div style="font-size:12px;color:#dc2626">✗ 无法加载图片</div>'; }; img.src = url + (url.indexOf('?')>=0?'&':'?') + '_t=' + Date.now(); };
        bgApiUrl.addEventListener('input', function() { previewApiSrc = ''; updatePreview(); });
        blurToggle.addEventListener('change', function() { blurLevelWrap.style.display = blurToggle.checked ? 'block' : 'none'; updatePreview(); });
        blurSlider.addEventListener('input', function() { blurVal.textContent = blurSlider.value + 'px'; updatePreview(); });
        opacitySlider.addEventListener('input', function() { opacityVal.textContent = opacitySlider.value + '%'; updatePreview(); });
        function updatePreview() {
            var blur = blurToggle.checked ? parseInt(blurSlider.value) : 0;
            var opacity = parseInt(opacitySlider.value) / 100;
            if (currentType === 'none') { previewBox.style.backgroundImage = 'none'; previewBox.style.backgroundColor = 'var(--bg)'; previewBgLabel.textContent = TK('admin.112'); }
            else if (currentType === 'image' && bgImagePath.value) { previewBox.style.backgroundImage = 'url(../' + bgImagePath.value + ')'; previewBox.style.backgroundColor = ''; previewBgLabel.textContent = TK('admin.126'); }
            else if (currentType === 'api') { if (previewApiSrc) { previewBox.style.backgroundImage = 'url(' + previewApiSrc + ')'; previewBox.style.backgroundColor = ''; previewBgLabel.textContent = TK('admin.127'); } else { previewBox.style.backgroundImage = 'none'; previewBox.style.backgroundColor = 'var(--bg)'; previewBgLabel.textContent = bgApiUrl.value.trim() ? TK('admin.128') : TK('admin.129'); } }
            else { previewBox.style.backgroundImage = 'none'; previewBox.style.backgroundColor = 'var(--bg)'; previewBgLabel.textContent = TK('admin.129'); }
            previewBox.style.backgroundSize = 'cover'; previewBox.style.backgroundPosition = 'center';
            previewBlur.style.backdropFilter = 'blur(' + blur + 'px)'; previewBlur.style.webkitBackdropFilter = 'blur(' + blur + 'px)';
            previewCard.style.background = 'rgba(255,255,255,' + opacity + ')';
            var labels = []; if (blur > 0) labels.push(TK('admin.130').replace('{n}', blur)); labels.push(TK('admin.131').replace('{n}', Math.round(opacity*100))); previewBlurLabel.textContent = labels.join(' · ');
        }
        window.removeBgImage = function() { if (confirm(TK('admin.132'))) { bgImagePath.value = ''; document.getElementById('formBgImage').value = ''; document.getElementById('formBgType').value = 'none'; currentType = 'none'; typeCards.forEach(function(c) { c.classList.remove('active'); }); typeCards[0].classList.add('active'); imageSection.style.display = 'none'; bgBlurRow.style.display = 'none'; updatePreview(); } };
        window.resetBg = function() { currentType = 'none'; typeCards.forEach(function(c) { c.classList.remove('active'); }); typeCards[0].classList.add('active'); imageSection.style.display = 'none'; apiSection.style.display = 'none'; bgImagePath.value = ''; bgApiUrl.value = ''; previewApiSrc = ''; blurToggle.checked = false; blurSlider.value = 0; blurVal.textContent = '0px'; opacitySlider.value = 100; opacityVal.textContent = '100%'; blurLevelWrap.style.display = 'none'; bgBlurRow.style.display = 'none'; updatePreview(); };
        document.getElementById('bgForm').addEventListener('submit', function() { document.getElementById('formBgType').value = currentType; document.getElementById('formBgImage').value = bgImagePath.value; document.getElementById('formBgApiUrl').value = bgApiUrl.value.trim(); document.getElementById('formCardCoverApi').value = document.getElementById('cardCoverApiInput').value.trim(); document.getElementById('formBlurEnabled').value = blurToggle.checked ? '1' : ''; document.getElementById('formBlurLevel').value = blurSlider.value; document.getElementById('formCardOpacity').value = opacitySlider.value; document.getElementById('formSiteTitle').value = document.getElementById('siteTitleInput').value.trim(); document.getElementById('formRegEnabled').value = document.getElementById('regToggle').checked ? '1' : ''; document.getElementById('formGuestComments').value = document.getElementById('guestToggle').checked ? '1' : ''; document.getElementById('formSuperComment').value = document.getElementById('superCommentToggle').checked ? '1' : ''; document.getElementById('formNotifyEnabled').value = document.getElementById('notifyToggle').checked ? '1' : ''; document.getElementById('formNotifyEmail').value = document.getElementById('notifyEmailInput').value.trim(); document.getElementById('formMusicNetease').value = document.getElementById('musicNeteaseInput').value.trim(); document.getElementById('formMusicAutoPlay').value = document.getElementById('musicAutoPlayInput').value.trim(); document.getElementById('formMusicNetCookie').value = document.getElementById('musicNetCookieInput').value.trim(); document.getElementById('formBgMusicEnabled').value = document.getElementById('bgMusicToggle').checked ? '1' : ''; });
        <?php if ($bgType === 'api' && $bgApiUrl): ?>
        (function() { var u=<?= json_encode($bgApiUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; var img=new Image(); img.onload=function(){previewApiSrc=u;updatePreview();}; img.src=u; })();
        <?php endif; ?>
        updatePreview();
    })();
    </script>

    <?php elseif ($tab === 'links'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            <?= t('admin.471') ?>
        </div>
        <div class="page-subtitle"><?= t('admin.133') ?></div>
    </div>
    <div id="linksAdmin" data-csrf="<?= htmlspecialchars(generateCsrfToken()) ?>">
        <div class="card">
            <div class="card-title" id="linkFormTitle"><?= t('admin.134') ?></div>
            <div class="form-group">
                <label class="form-label"><?= t('admin.135') ?></label>
                <input class="form-input" id="linkName" maxlength="60" placeholder="<?= t('admin.136') ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('admin.137') ?></label>
                <div class="api-url-group">
                    <input class="form-input" id="linkUrl" placeholder="https://example.com">
                    <button class="btn btn-sm btn-outline" type="button" id="linkFetchBtn"><?= t('admin.138') ?></button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('admin.139') ?></label>
                <input class="form-input" id="linkDesc" maxlength="200" placeholder="<?= t('admin.140') ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('admin.141') ?></label>
                <input class="form-input" id="linkFavicon" placeholder="https://example.com/favicon.ico">
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('admin.142') ?></label>
                <input class="form-input" id="linkSort" type="number" value="0" style="max-width:160px">
            </div>
            <div id="linkFormMsg" class="form-hint"></div>
            <div style="display:flex;justify-content:flex-end;gap:10px">
                <button type="button" class="btn btn-outline" id="linkCancelBtn" style="display:none"><?= t('admin.143') ?></button>
                <button type="button" class="btn btn-primary" id="linkSaveBtn"><?= t('admin.022') ?></button>
            </div>
            <input type="hidden" id="linkId" value="">
        </div>
        <div class="card">
            <div class="card-title"><?= t('admin.144') ?></div>
            <div class="table-wrap">
                <table id="linksTable">
                    <tr><th><?= t('admin.145') ?></th><th><?= t('admin.030') ?></th><th><?= t('admin.146') ?></th><th><?= t('admin.147') ?></th><th><?= t('admin.032') ?></th></tr>
                </table>
            </div>
        </div>
    </div>
    <script src="../js/fp.js?v=<?= @filemtime(__DIR__ . '/../js/fp.js') ?>"></script>
    <script src="../js/links-admin.js?v=<?= @filemtime(__DIR__ . '/../js/links-admin.js') ?>"></script>

    <?php elseif ($tab === 'update'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            <?= t('admin.472') ?>
        </div>
        <div class="page-subtitle"><?= t('admin.148') ?></div>
    </div>

    <div class="update-status-bar" id="updateStatusBar" style="display:none"></div>

    <!-- 当前版本 -->
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?= t('admin.149') ?></div>
        <div class="version-display">
            <div>
                <div class="version-tag">v<?= htmlspecialchars(APP_VERSION) ?></div>
                <div class="version-label"><?= (($config['update_channel'] ?? 'stable') === 'beta') ? '测试版' : '正式版' ?></div>
            </div>
            <div>
                <button class="btn btn-primary" onclick="checkUpdate()" id="checkUpdateBtn">
                    <svg viewBox="0 0 24 24" width="14" height="14"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    <?= t('admin.491') ?>
                </button>
            </div>
        </div>
        <div id="updateCheckResult" style="margin-top:12px;display:none"></div>
    </div>

    <!-- 更新通道 -->
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg><?= t('admin.041') ?></div>
        <div class="channel-tabs">
            <div class="channel-tab <?= ($config['update_channel'] ?? 'stable') === 'stable' ? 'active' : '' ?>" data-channel="stable" onclick="switchChannel('stable')"><svg viewBox="0 0 24 24" width="16" height="16"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg><?= t('admin.201') ?></div>
            <div class="channel-tab <?= ($config['update_channel'] ?? '') === 'beta' ? 'active' : '' ?>" data-channel="beta" onclick="switchChannel('beta')"><svg viewBox="0 0 24 24" width="16" height="16"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg><?= t('admin.200') ?></div>
        </div>
        <!-- v3.2.4：更新方式与更新包类型详细说明（面向小白，三种通道一目了然） -->
        <div style="margin-top:14px;border-top:1px solid var(--border);padding-top:12px;font-size:0.88em;color:var(--text-secondary);line-height:1.8">
            <div style="font-weight:600;color:var(--text);margin-bottom:6px"><?= t('admin.150') ?></div>
            <div style="display:flex;flex-direction:column;gap:8px">
                <div><span style="font-weight:600;color:var(--accent)"><?= t('admin.151') ?></span><br>
                <?= t('admin.609') ?><code>sudo ysm-admin apply-update</code><?= t('admin.152') ?></div>
                <div><span style="font-weight:600;color:var(--accent)"><?= t('admin.153') ?></span><br>
                <?= t('admin.576') ?><code>ZIP / tar.gz</code><?= t('admin.154') ?><code>sudo ysm-admin apply-update</code><?= t('admin.155') ?></div>
                <div><span style="font-weight:600;color:var(--accent)"><?= t('admin.156') ?></span><br>
                <?= t('admin.577') ?><code>sudo ysm-admin apply-update</code>。</div>
            </div>
            <div style="font-weight:600;color:var(--text);margin:10px 0 6px"><?= t('admin.157') ?></div>
            <div style="display:flex;flex-direction:column;gap:8px">
                <div><span style="font-weight:600;color:var(--accent)"><?= t('admin.158') ?></span>（<code>-full.tar.gz</code><?= t('admin.159') ?><b><?= t('admin.160') ?></b><?= t('admin.161') ?><b><?= t('admin.162') ?></b><?= t('admin.163') ?></div>
                <div><span style="font-weight:600;color:var(--accent)"><?= t('admin.164') ?></span>（<code>-to-*-inc.tar.gz</code><?= t('admin.165') ?><b><?= t('admin.166') ?></b><?= t('admin.167') ?></div>
                <div><span style="font-weight:600;color:var(--accent)"><?= t('admin.168') ?></span>（<code>-install.tar.gz</code><?= t('admin.169') ?><b><?= t('admin.170') ?></b><?= t('admin.171') ?></div>
            </div>
            <div style="margin-top:10px;color:var(--text-muted)"><?= t('admin.channel_now') ?><?= ($config['update_channel'] ?? 'stable') === 'beta' ? t('admin.channel_beta_desc') : t('admin.channel_stable_desc') ?></div>
        </div>
    </div>

    <!-- 执行更新 -->
    <div class="card" id="updateActionCard" style="display:none">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg><?= t('admin.172') ?></div>
        <div id="updateActionContent">
            <!-- v3.2.2：跨版本更新必须使用全量包提示 -->
            <div id="crossVerHint" class="msg" style="display:none;margin:0 0 12px;background:rgba(245,158,11,0.14);color:#b45309">
                <svg viewBox="0 0 24 24" width="16" height="16" style="vertical-align:-3px;margin-right:4px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <strong><?= t('admin.173') ?></strong><?= t('admin.174') ?><b><?= t('admin.158') ?></b><?= t('admin.578') ?>
            </div>
            <p style="color:var(--text-secondary)"><?= t('admin.175') ?><strong id="newVersionText"></strong><?= t('admin.176') ?></p>
            <!-- v3.2.0：在线更新自动识别仓库发布的全量包 / 增量包，由站长选择 -->
            <div id="pkgSelectArea" style="margin-top:14px;display:none">
                <div class="bg-type-grid" style="max-width:640px">
                    <label class="bg-type-card" id="pkgFullCard">
                        <input type="radio" name="pkg_type" value="full">
                        <div class="type-icon full"><svg viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/><path d="M6 3l3 3-3 3M18 15l-3 3 3 3"/></svg></div>
                        <div class="type-name"><?= t('admin.177') ?></div>
                        <div class="type-desc" style="font-size:0.74em"><?= t('admin.178') ?></div>
                    </label>
                    <label class="bg-type-card" id="pkgIncCard">
                        <input type="radio" name="pkg_type" value="inc">
                        <div class="type-icon inc"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></div>
                        <div class="type-name"><?= t('admin.179') ?></div>
                        <div class="type-desc" style="font-size:0.74em"><?= t('admin.180') ?></div>
                    </label>
                </div>
                <p id="pkgHint" style="color:var(--text-muted);font-size:0.82em;margin-top:10px"></p>
            </div>
            <div style="margin-top:16px;display:flex;gap:12px;flex-wrap:wrap">
                <button class="btn btn-primary" onclick="showChallengeModal()" id="startUpdateBtn">
                    <svg viewBox="0 0 24 24" width="14" height="14"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    <?= t('admin.490') ?>
                </button>
                <button class="btn btn-secondary" onclick="document.getElementById('uploadPkgInput').click()">
                    <svg viewBox="0 0 24 24" width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <?= t('admin.494') ?>
                </button>
            </div>
            <input type="file" id="uploadPkgInput" accept=".zip,.gz,.tar.gz" style="display:none">
        </div>
        <div id="updateProgressContent" style="display:none">
            <div class="update-progress">
                <div class="update-progress-step" id="upStep1"><span class="step-icon">⏳</span><?= t('admin.181') ?></div>
                <div class="update-progress-step" id="upStep2"><span class="step-icon">⏳</span><?= t('admin.182') ?></div>
                <div class="update-progress-step" id="upStep3"><span class="step-icon">⏳</span><?= t('admin.183') ?></div>
                <div class="update-progress-step" id="upStep4"><span class="step-icon">⏳</span><?= t('admin.184') ?></div>
            </div>
            <div style="margin-top:12px">
                <p style="color:var(--text-muted);font-size:0.85em" id="updateProgressHint"><?= t('admin.185') ?><code id="sshCommandText">ysm-admin apply-update</code></p>
            </div>
        </div>
    </div>

    <!-- 手动更新（始终可见） -->
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg><?= t('admin.186') ?></div>
        <p style="color:var(--text-secondary);margin-bottom:12px"><?= t('admin.187') ?></p>
        <div>
            <button class="btn btn-secondary" onclick="document.getElementById('uploadPkgInput').click()">
                <svg viewBox="0 0 24 24" width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <?= t('admin.494') ?>
            </button>
            <input type="file" id="uploadPkgInput" accept=".zip,.gz,.tar.gz" style="display:none">
        </div>
        <div id="manualUpdateProgress" style="display:none;margin-top:12px">
            <div class="update-progress">
                <div class="update-progress-step" id="mUpStep1"><span class="step-icon">⏳</span><?= t('admin.188') ?></div>
                <div class="update-progress-step" id="mUpStep2"><span class="step-icon">⏳</span><?= t('admin.189') ?></div>
                <div class="update-progress-step" id="mUpStep3"><span class="step-icon">⏳</span><?= t('admin.190') ?></div>
                <div class="update-progress-step" id="mUpStep4"><span class="step-icon">⏳</span><?= t('admin.184') ?></div>
            </div>
            <div style="margin-top:8px;color:var(--text-muted);font-size:0.85em" id="manualUpdateHint"></div>
        </div>
    </div>

    <!-- 更新历史 -->
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?= t('admin.191') ?></div>
        <form class="log-toolbar" onsubmit="return loadBackupHistory(1, document.getElementById('backupSearchInput').value)">
            <input type="text" class="form-input" id="backupSearchInput" placeholder="<?= t('admin.192') ?>" autocomplete="off">
            <button type="submit" class="btn btn-sm btn-primary"><?= t('admin.009') ?></button>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('backupSearchInput').value='';loadBackupHistory(1,'')"><?= t('admin.193') ?></button>
            <span class="toolbar-spacer"></span>
            <span class="page-info" id="backupPageInfo"></span>
        </form>
        <div class="backup-list" id="backupList">
            <p style="color:var(--text-muted);font-size:0.85em"><?= t('admin.194') ?></p>
        </div>
        <div id="backupPager"></div>
    </div>

    <script>
    var currentUpdateStatus = null;
    var updatePollTimer = null;
    var pendingUpdateVersion = '';
    var pendingUpdatePath = '';
    var pendingUpdateUrl = ''; // v3.2.0：在线更新选中的仓库包下载地址（全量/增量包）

    function switchChannel(ch) {
        var code = prompt(TK('admin.195'));
        if (!code) return;
        var fd = new FormData();
        fd.append('ajax', 'save_channel');
        fd.append('channel', ch);
        fd.append('csrf_token', '<?= generateCsrfToken() ?>');
        fd.append('challenge_code', code.trim().toUpperCase());
        fetch('<?= $_SERVER['SCRIPT_NAME'] ?>?tab=update', { method: 'POST', body: fd })
            .then(function(r) { return r.json().catch(function() { return { success: false, error: TK('admin.196') }; }); })
            .then(function(d) {
                if (!d || !d.success) {
                    // v5.4.5：失败必须有明确反馈（此前静默无反应，看起来像"功能不可用"）
                    alert(TK('admin.197') + ((d && d.error) || TK('admin.198')) + '\n' + TK('admin.199'));
                    return;
                }
                document.querySelectorAll('.channel-tab').forEach(function(t) {
                    t.classList.toggle('active', t.getAttribute('data-channel') === ch);
                });
                var vl = document.querySelector('.version-label');
                if (vl) vl.textContent = ch === 'beta' ? TK('admin.200') : TK('admin.201');
                alert(TK('admin.202') + (ch === 'beta' ? TK('admin.203') : TK('admin.204')) + TK('admin.205'));
                setTimeout(function() { location.reload(); }, 300);
            })
            .catch(function(e) { alert(TK('admin.206') + e); });
    }

    function checkUpdate() {
        var btn = document.getElementById('checkUpdateBtn');
        btn.disabled = true;
        btn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite">⟳</span> ' + TK('admin.207');
        document.getElementById('updateCheckResult').style.display = 'none';
        fetch('<?= $_SERVER['SCRIPT_NAME'] ?>?tab=update&ajax=check_update')
            .then(function(r) { return r.json(); })
            .then(function(d) {
                btn.disabled = false;
                btn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> ' + TK('admin.208');
                var resultDiv = document.getElementById('updateCheckResult');
                if (d.available) {
                    // v3.2.2：更新通道标识（稳定版/测试版）+ 跨版本检测（主版本号变化必须全量包）
                    // v5.3.0：后端 update_decision.force_full（预发布→正式版）同样强制全量包（与 apply-update 同一判定）
                    var chLabel = TK('<?= ($config['update_channel'] ?? 'stable') === 'beta' ? 'admin.ch_beta' : 'admin.ch_stable' ?>');
                    var cross = d.latest_version && d.current_version && String(d.latest_version).split('.')[0] !== String(d.current_version).split('.')[0];
                    var forceFullByRule = !!(d.update_decision && d.update_decision.force_full);
                    var forceFull = !!cross || forceFullByRule;
                    var ruleHint = cross
                        ? TK('admin.209').replace('{title}', '<strong>' + TK('admin.210') + '</strong>').replace('{full}', '<strong>' + TK('admin.158') + '</strong>')
                        : (forceFullByRule ? TK('admin.211').replace('{title}', '<strong>' + TK('admin.212') + '</strong>').replace('{full}', '<strong>' + TK('admin.158') + '</strong>') : '');
                    resultDiv.innerHTML = '<div class="msg msg-success" style="margin:0"><svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>' + TK('admin.213').replace('{latest}', '<strong>v' + d.latest_version + '</strong>').replace('{current}', 'v' + d.current_version).replace('{channel}', chLabel) + '</div>' +
                        (ruleHint ? '<div class="msg" style="margin:8px 0 0;background:rgba(245,158,11,0.14);color:#b45309"><svg viewBox="0 0 24 24" width="16" height="16" style="vertical-align:-3px;margin-right:4px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' + ruleHint + '</div>' : '');
                    document.getElementById('updateActionCard').style.display = 'block';
                    document.getElementById('newVersionText').textContent = 'v' + d.latest_version;
                    pendingUpdateVersion = d.latest_version;
                    // v3.2.0：仓库发布含全量/增量包 → 展示选择；否则回退 archive 全量下载
                    if (d.packages && d.packages.length > 0) {
                        renderPkgSelect(d.packages, forceFull);
                    } else {
                        document.getElementById('pkgSelectArea').style.display = 'none';
                        document.getElementById('crossVerHint').style.display = cross ? 'block' : 'none';
                        pendingUpdateUrl = '';
                        pendingUpdatePath = '';
                    }
                } else {
                    resultDiv.innerHTML = '<div class="msg" style="margin:0;background:var(--accent-glass);color:var(--text)"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' + TK('admin.214').replace('{n}', d.current_version) + '</div>';
                    document.getElementById('updateActionCard').style.display = 'none';
                }
                // v5.4.7：测试通道——同时列出「最新正式版」与「最新测试版」，由使用者自选安装哪一个
                if (d.options && d.options.length) {
                    window.__ysmOpts = d.options;
                    var oh = '<div style="margin:8px 0 0;padding:10px 12px;border:1px solid var(--border);border-radius:10px">' +
                             '<div style="font-weight:600;margin-bottom:6px">' + TK('admin.215') + '</div>';
                    d.options.forEach(function(o, i) {
                        oh += '<div style="display:flex;align-items:center;gap:10px;padding:6px 0">' +
                              '<span style="flex:1">' + o.option_label + ' · <strong>v' + o.latest_version + '</strong>' +
                              (String(o.latest_version) === String(d.current_version) ? ' <span style="color:var(--text-muted)">' + TK('admin.216') + '</span>' : '') +
                              '</span><button class="btn btn-secondary" type="button" onclick="pickOption(' + i + ')">' + TK('admin.217') + '</button></div>';
                    });
                    oh += '</div>';
                    resultDiv.innerHTML += oh;
                }
                resultDiv.style.display = 'block';
            })
            .catch(function() {
                btn.disabled = false;
                btn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> ' + TK('admin.208');
                var resultDiv = document.getElementById('updateCheckResult');
                resultDiv.innerHTML = '<div class="msg" style="margin:0;background:rgba(255,80,80,0.15);color:#ff6060"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>' + TK('admin.218') + '</div>';
                resultDiv.style.display = 'block';
            });
    }

    // v5.4.7：选定「正式版 / 测试版」候选后，带入该版本的包并进入既有更新流程（挑战码 → apply-update）
    function pickOption(i) {
        var o = (window.__ysmOpts || [])[i];
        if (!o) return;
        var pkgs = o.packages || [];
        var full = null;
        for (var k = 0; k < pkgs.length; k++) { if (pkgs[k].type === 'full' && pkgs[k].url) { full = pkgs[k]; break; } }
        pendingUpdateVersion = o.latest_version;
        pendingUpdatePath = '';
        pendingUpdateUrl = full ? full.url : '';
        document.getElementById('newVersionText').textContent = 'v' + o.latest_version;
        document.getElementById('updateActionCard').style.display = 'block';
        if (pkgs.length) {
            renderPkgSelect(pkgs, true);   // 同号跨通道/预发布→正式版均要求全量包
        } else {
            document.getElementById('pkgSelectArea').style.display = 'none';
        }
        var ac = document.getElementById('updateActionCard');
        if (ac && ac.scrollIntoView) ac.scrollIntoView({ behavior: 'smooth', block: 'center' });
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

    // v3.2.0：渲染全量/增量包选择（仓库 Releases assets 自动识别）
    // v3.2.2：跨版本更新（forceFull）时默认选中全量包并显示强制提示
    function renderPkgSelect(pkgs, forceFull) {
        var full = null, inc = null;
        pkgs.forEach(function(p) { if (p.type === 'full') full = p; else if (p.type === 'inc') inc = p; });
        var area = document.getElementById('pkgSelectArea');
        var fCard = document.getElementById('pkgFullCard');
        var iCard = document.getElementById('pkgIncCard');
        var hint = document.getElementById('pkgHint');
        var crossHint = document.getElementById('crossVerHint');
        fCard.style.display = full ? '' : 'none';
        iCard.style.display = inc ? '' : 'none';
        if (full) {
            fCard.querySelector('.type-desc').textContent = TK('admin.upd_full_desc').replace('{name}', full.name).replace('{size}', fmtSize(full.size));
        }
        if (inc) {
            iCard.querySelector('.type-desc').textContent = TK('admin.upd_inc_desc').replace('{name}', inc.name).replace('{size}', fmtSize(inc.size));
        }
        if (crossHint) crossHint.style.display = forceFull ? 'block' : 'none';
        // 默认选中：跨版本强制全量包；否则优先增量包（体积小）
        var sel = (inc && !forceFull) ? 'inc' : (full ? 'full' : 'inc');
        var radios = document.querySelectorAll('input[name="pkg_type"]');
        radios.forEach(function(r) { r.checked = (r.value === sel); });
        var fullRadio = fCard.querySelector('input');
        var incRadio = iCard.querySelector('input');
        fullRadio.onchange = function() { if (this.checked) { pendingUpdateUrl = full ? full.url : ''; pendingUpdatePath = ''; syncPkgCardActive(); } };
        incRadio.onchange = function() { if (this.checked) { pendingUpdateUrl = inc ? inc.url : ''; pendingUpdatePath = ''; syncPkgCardActive(); } };
        if (sel === 'inc' && inc) { pendingUpdateUrl = inc.url; } else if (full) { pendingUpdateUrl = full.url; }
        pendingUpdatePath = '';
        area.style.display = 'block';
        syncPkgCardActive();
        hint.textContent = sel === 'inc'
            ? TK('admin.219').replace('{ver}', pendingUpdateVersion)
            : TK('admin.220').replace('{ver}', pendingUpdateVersion);
    }

    function syncPkgCardActive() {
        var radios = document.querySelectorAll('input[name="pkg_type"]');
        radios.forEach(function(r) {
            var card = r.closest('.bg-type-card');
            if (card) card.classList.toggle('active', r.checked);
        });
    }

    function fmtSize(n) {
        n = Number(n) || 0;
        if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
        if (n >= 1024) return (n / 1024).toFixed(1) + ' KB';
        return n + ' B';
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
        formData.append('package_url', pendingUpdateUrl); // v3.2.0：在线更新选中的仓库包下载地址

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
        document.getElementById('sshCommandText').textContent = 'ysm-admin apply-update';
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
                document.getElementById('manualUpdateHint').textContent = TK('admin.221').replace('{name}', file.name);

                fetch('<?= $_SERVER['SCRIPT_NAME'] ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success) {
                        document.getElementById('mUpStep1').className = 'update-progress-step completed';
                        document.getElementById('mUpStep2').className = 'update-progress-step active';
                        document.getElementById('manualUpdateHint').textContent = TK('admin.222');
                        setTimeout(function() {
                            document.getElementById('mUpStep2').className = 'update-progress-step completed';
                            document.getElementById('mUpStep3').className = 'update-progress-step active';
                            document.getElementById('manualUpdateHint').textContent = TK('admin.223');
                            // 也显示到执行更新卡片
                            var verText = d.target_version ? 'v' + d.target_version : TK('admin.224');
                            document.getElementById('updateActionCard').style.display = 'block';
                            document.getElementById('newVersionText').textContent = verText;
                            pendingUpdateVersion = d.target_version || 'uploaded';
                            // 上传包路径交给 trigger_update（踩坑 #3：不提交则走仓库分支）；清空在线包选择
                            pendingUpdatePath = d.package_path || '';
                            pendingUpdateUrl = '';
                        }, 800);
                    } else {
                        document.getElementById('mUpStep1').className = 'update-progress-step failed';
                        document.getElementById('manualUpdateHint').textContent = TK('admin.225') + (d.error || TK('admin.198'));
                    }
                })
                .catch(function() {
                    document.getElementById('mUpStep1').className = 'update-progress-step failed';
                    document.getElementById('manualUpdateHint').textContent = TK('admin.225') + TK('admin.226');
                });
            });
        }

        // 加载更新历史（初始页码/每页条数/搜索词由下方 IIFE 从 URL 恢复）
        loadBackupHistory(backupInitPage);
    });

    var backupSearchQ = '';
    var backupPerPage = 10;
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
        list.innerHTML = '<p style="color:var(--text-muted);font-size:0.85em">' + TK('admin.194') + '</p>';
        fetch('<?= $_SERVER['SCRIPT_NAME'] ?>?tab=update&ajax=update_history&page=' + encodeURIComponent(page) + '&q=' + encodeURIComponent(backupSearchQ) + '&per_page=' + encodeURIComponent(backupPerPage))
            .then(function(r) { return r.json(); })
            .then(function(d) {
                var hist = (d && d.history) || [];
                var html = '';
                // v5.0.0：搜索词拼接进 innerHTML 前转义（防 HTML 注入）
                var __qEsc = String(backupSearchQ || '').replace(/[&<>"']/g, function(c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; });
                if (hist.length === 0) {
                    html = '<p style="color:var(--text-muted);font-size:0.85em">' + (backupSearchQ ? TK('admin.227').replace('{q}', __qEsc) : TK('admin.228')) + '</p>';
                } else {
                    for (var i = 0; i < hist.length; i++) {
                        var h = hist[i];
                        var stText = ({'completed': TK('admin.229'), 'failed': TK('admin.230'), 'in_progress': TK('admin.231')}[h.status] || h.status);
                        html += '<div class="backup-item">'
                            + '<div class="backup-info"><span class="backup-version">' + TK('admin.232').replace('{from}', h.from_version).replace('{to}', h.to_version) + '</span>'
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
                list.innerHTML = '<p style="color:var(--text-muted);font-size:0.85em">' + TK('admin.233') + '</p>';
            });
        return false;
    }

    // v2.6.6：更新历史分页控件（三段式：总数+每页条数 / 页码按钮 / 页码跳转；事件委托防注入）
    function renderBackupPager(d) {
        var info = document.getElementById('backupPageInfo');
        if (info) info.textContent = (d && d.total > 0) ? TK('admin.234').replace('{a}', d.total).replace('{b}', d.page).replace('{c}', d.pages) : '';
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
        html += '<div class="pagination-info"><span class="page-info">' + TK('admin.235').replace('{n}', '<strong>' + d.total + '</strong>') + '</span>'
              + '<label class="per-page">' + TK('admin.236') + ' <select data-pp-url="' + ppTpl + '">';
        [10, 20, 50, 100].forEach(function(opt) {
            html += '<option value="' + opt + '"' + (opt === perPage ? ' selected' : '') + '>' + opt + '</option>';
        });
        html += '</select> ' + TK('admin.237') + '</label></div>';
        // 中：页码按钮（仅多页时显示）
        if (showNav) {
            html += '<div class="pagination-btns">';
            var mkBtn = function(pg, label, title, current) {
                if (current) return '<span class="page-btn current" title="' + title + '">' + label + '</span>';
                return '<a class="page-btn" data-page="' + pg + '" href="javascript:void(0)" title="' + title + '">' + label + '</a>';
            };
            html += mkBtn(1, '« ' + TK('admin.238'), TK('admin.238'), false);
            html += mkBtn(Math.max(1, page - 1), '‹ ' + TK('admin.239'), TK('admin.239'), false);
            var prev = 0;
            for (var i = 0; i < seq.length; i++) {
                var pg = seq[i];
                if (pg - prev > 1) html += '<span class="page-btn page-ellipsis">…</span>';
                html += mkBtn(pg, pg, TK('admin.240').replace('{n}', pg), pg === page);
                prev = pg;
            }
            html += mkBtn(Math.min(pages, page + 1), TK('admin.241') + ' ›', TK('admin.241'), false);
            html += mkBtn(pages, TK('admin.242') + ' »', TK('admin.242'), false);
            html += '</div>';
        }
        // 右：页码信息 + 跳转（仅多页时显示）
        if (showNav) {
            html += '<div class="pagination-jump"><span class="page-info">' + TK('admin.243').replace('{a}', page).replace('{b}', pages) + '</span>'
                  + '<input type="number" class="page-jump" min="1" max="' + pages + '" placeholder="' + TK('admin.244') + '" data-pg-url="' + pgTpl + '">'
                  + '<button type="button" class="page-btn">' + TK('admin.245') + '</button></div>';
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
            <?= t('admin.473') ?>
        </div>
        <div class="page-subtitle"><?= t('admin.246') ?></div>
    </div>
    <?php
    $guardStatus = 'unknown';
    $guardPid = false;
    exec('systemctl is-active ysm-guard 2>/dev/null', $output, $code);
    $guardStatus = $code === 0 ? 'active' : 'inactive';
    $guardPid = trim(shell_exec('systemctl show ysm-guard -p MainPID --value 2>/dev/null') ?? '');
    ?>
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <?= t('admin.574') ?>
        </div>
        <table>
            <tr><td style="color:var(--text-muted)"><?= t('admin.030') ?></td>
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
            <?= t('admin.474') ?>
        </div>
        <div class="page-subtitle"><?= t('admin.247') ?></div>
    </div>
    <?php if ($dMsg === 'saved'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(46,204,113,0.15);color:#2ecc71"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('admin.248') ?></div>
    <?php elseif ($dMsg === 'save_failed'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060"><?= t('admin.249') ?></div>
    <?php elseif ($dMsg === 'challenge_failed'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060"><?= t('admin.250') ?></div>
    <?php elseif ($dMsg === 'csrf_error'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060"><?= t('admin.251') ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg><?= t('admin.252') ?></div>
        <table>
            <tr><td style="color:var(--text-muted)"><?= t('admin.419') ?></td><td><?= (int)($gState['backup_interval_min'] ?? $bCfg['interval_min']) ?><?= t('admin.interval_unit') ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.420') ?></td><td><?= htmlspecialchars($gState['last_db_backup'] ?: '尚未生成') ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.421') ?></td><td><?= htmlspecialchars($gState['next_db_backup'] ?: '—') ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.422') ?></td><td><?= $gState['db_backup_size'] ? number_format((float)$gState['db_backup_size'] / 1024, 1) . ' KB' : '—' ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.423') ?></td><td><?= t('admin.keep_article_line', ['n' => (int)($gState['article_backup_keep'] ?? $bCfg['article_keep']), 'last' => htmlspecialchars($gState['last_articles_backup'] ?: t('common.not_generated')), 'c' => (int)($gState['articles_backup_count'] ?? 0)]) ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.424') ?></td><td><?= !empty($bCfg['trigger_backup']) ? t('common.on') : t('common.off') ?><?= t('admin.last_trigger') ?><?= htmlspecialchars($gState['last_upload_backup'] ?? t('common.not_recorded')) ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.260') ?></td><td><?= !empty($bCfg['single_restore']) ? t('common.on') : t('common.off') ?><?= t('admin.last_single_restore') ?><?= htmlspecialchars($gState['last_single_restore'] ?? t('common.not_recorded')) ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.425') ?></td><td><?= (int)($gState['manual_backup_keep'] ?? $bCfg['manual_keep']) ?><?= t('admin.manual_keep_unit') ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.426') ?></td><td><?= htmlspecialchars($gState['last_restore'] ?: t('common.not_recorded')) ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.253') ?></td><td><?= t('admin.254') ?></td></tr>
        </table>
    </div>

    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg><?= t('admin.255') ?></div>
        <form method="post" class="need-challenge" data-confirm="<?= t('admin.cf_backup') ?>">
            <input type="hidden" name="save_backup_config" value="1">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <table>
                <tr>
                    <td style="color:var(--text-muted)"><?= t('admin.256') ?></td>
                    <td><input class="form-input" style="width:160px" type="number" min="5" max="1440" name="interval_min" value="<?= (int)$bCfg['interval_min'] ?>" required></td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted)"><?= t('admin.257') ?></td>
                    <td><input class="form-input" style="width:160px" type="number" min="1" max="90" name="article_keep" value="<?= (int)$bCfg['article_keep'] ?>" required></td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted)"><?= t('admin.258') ?></td>
                    <td><input class="form-input" style="width:160px" type="number" min="1" max="30" name="manual_keep" value="<?= (int)$bCfg['manual_keep'] ?>" required></td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted)"><?= t('admin.259') ?></td>
                    <td><div style="display:flex;align-items:center;gap:8px"><span style="font-size:13px"><?= t('admin.427') ?></span><label class="toggle" style="flex-shrink:0"><input type="checkbox" name="trigger_backup" value="1" <?= !empty($bCfg['trigger_backup']) ? 'checked' : '' ?>><span class="slider"></span></label></div></td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted)"><?= t('admin.260') ?></td>
                    <td><div style="display:flex;align-items:center;gap:8px"><span style="font-size:13px"><?= t('admin.428') ?></span><label class="toggle" style="flex-shrink:0"><input type="checkbox" name="single_restore" value="1" <?= !empty($bCfg['single_restore']) ? 'checked' : '' ?>><span class="slider"></span></label></div></td>
                </tr>
            </table>
            <div style="margin-top:12px">
                <button class="btn btn-primary" type="submit">
                    <svg viewBox="0 0 24 24" width="14" height="14"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <?= t('admin.489') ?>
                </button>
                <span style="color:var(--text-muted);font-size:0.82em;margin-left:8px"><?= t('admin.261') ?></span>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?= t('admin.262') ?></div>
        <?php if (!$bList): ?>
            <p style="color:var(--text-muted);font-size:0.85em"><?= t('admin.263') ?></p>
        <?php else: ?>
        <table>
            <thead><tr><th><?= t('admin.264') ?></th><th><?= t('admin.265') ?></th><th><?= t('admin.035') ?></th><th><?= t('admin.266') ?></th></tr></thead>
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
        <p style="color:var(--text-muted);font-size:0.82em;margin-top:8px"><?= t('admin.267') ?><code>sudo ysm-admin backup</code>。</p>
    </div>

    <?php elseif ($tab === 'hfish'): ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="3" y1="3" x2="21" y2="21"/></svg>
            <?= t('admin.475') ?>
        </div>
        <div class="page-subtitle"><?= t('admin.268') ?></div>
    </div>
    <?php
    $hfishSnapshot = [];
    $hfishSnapFile = __DIR__ . '/../data/.hfish_snapshot.json';
    if (file_exists($hfishSnapFile)) {
        $hfishSnapshot = json_decode(file_get_contents($hfishSnapFile), true) ?: [];
    }
    $hfishAttacks = $hfishSnapshot['attacks'] ?? [];
    // v4.1.8：阈值显示以后台最新配置为准（保存后即时生效，无需等快照轮询）；快照阈值兜底
    $hfishThreshold = (int)($config['hfish_ban_threshold'] ?? ($hfishSnapshot['threshold'] ?? 10));
    $hfishUpdated = $hfishSnapshot['updated_at'] ?? '从未同步';
    $hfishError = $hfishSnapshot['error'] ?? '';
    $hfishBannedCount = count(array_filter($hfishAttacks, fn($a) => !empty($a['banned'])));
    // v4.1.8：蜜罐攻击 IP 列表分页
    $hfishPerPage = (int)($_GET['hfish_per_page'] ?? 10);
    if (!in_array($hfishPerPage, [10, 20, 50, 100], true)) $hfishPerPage = 10;
    $hfishData = paginateList($hfishAttacks, ['ip', 'date'], '', (int)($_GET['hfish_page'] ?? 1), $hfishPerPage);
    ?>
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?= t('admin.269') ?></div>
        <div class="table-wrap"><table>
            <tr><td style="color:var(--text-muted)"><?= t('admin.429') ?></td><td><?= htmlspecialchars($hfishUpdated) ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.430') ?></td><td><?= t('admin.431') ?><strong><?= intval($hfishThreshold) ?></strong><?= t('admin.432') ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.433') ?></td><td><?= t('admin.hfish_count', ['n' => count($hfishAttacks), 'm' => $hfishBannedCount]) ?></td></tr>
            <?php if ($hfishError): ?>
            <tr><td style="color:var(--text-muted)"><?= t('admin.434') ?></td><td><span style="color:#ef4444"><?= htmlspecialchars($hfishError) ?></span></td></tr>
            <?php endif; ?>
        </table></div>
        <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
            <button class="btn btn-primary" onclick="hfishSync()" id="hfishSyncBtn">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                <?= t('admin.493') ?>
            </button>
            <span id="hfishSyncMsg" style="font-size:0.85em;color:var(--text-muted)"></span>
        </div>
        <p style="font-size:0.82em;color:var(--text-muted);margin-top:10px"><?= t('admin.270') ?></p>
    </div>

    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg><?= t('admin.hfish_title', ['n' => $hfishData['total']]) ?></div>
        <?php if (empty($hfishData['items'])): ?>
        <div class="empty-state"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg><p><?= t('admin.271') ?></p></div>
        <?php else: ?>
        <div class="table-wrap"><table>
            <tr><th>IP</th><th><?= t('admin.272') ?></th><th><?= t('admin.273') ?></th><th><?= t('admin.274') ?></th><th>UA</th><th><?= t('admin.275') ?></th><th><?= t('admin.030') ?></th></tr>
            <?php foreach ($hfishData['items'] as $a): ?>
            <tr>
                <td><code><?= htmlspecialchars($a['ip'] ?? '') ?></code></td>
                <td><strong><?= intval($a['attack_cnt'] ?? 0) ?></strong><?= intval($a['attack_cnt'] ?? 0) >= $hfishThreshold ? ' <span class="chain-badge chain-invalid">' . t('admin.reach_threshold') . '</span>' : '' ?></td>
                <td style="font-size:0.85em"><?= htmlspecialchars(implode(', ', array_keys($a['styles'] ?? [])) ?: '-') ?></td>
                <td style="font-size:0.85em"><?= htmlspecialchars(implode(', ', array_keys($a['honeypots'] ?? [])) ?: '-') ?></td>
                <td style="font-size:0.82em;color:var(--text-muted)"><?= htmlspecialchars(implode(', ', array_keys($a['uas'] ?? [])) ?: '-') ?></td>
                <td style="color:var(--text-muted);font-size:0.85em"><?= htmlspecialchars($a['date'] ?? '') ?></td>
                <td><?php if (!empty($a['banned'])): ?><span class="chain-badge chain-invalid"><?= t('admin.435') ?></span><?php elseif (!empty($a['skip'])): ?><span class="chain-badge" style="background:rgba(251,191,36,.15);color:#d97706" title="<?= htmlspecialchars($a['skip_reason'] ?? t('admin.587')) ?>"><?= t('admin.436') ?></span><?php else: ?><span class="chain-badge chain-valid"><?= t('admin.437') ?></span><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
        <?= renderPager($hfishData, 'hfish_page', ['tab' => 'hfish'], 'hfish_per_page') ?>
    </div>
    <script>
    function hfishSync() {
        var btn = document.getElementById('hfishSyncBtn');
        var msg = document.getElementById('hfishSyncMsg');
        btn.disabled = true;
        msg.textContent = TK('admin.276');
        var fd = new FormData();
        fd.append('ajax', 'hfish_sync');
        fd.append('csrf_token', '<?= generateCsrfToken() ?>');
        fetch('<?= $_SERVER['SCRIPT_NAME'] ?>?tab=hfish', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                btn.disabled = false;
                if (d.success) { msg.textContent = TK('admin.277'); setTimeout(function() { location.reload(); }, 800); }
                else { msg.textContent = TK('admin.278') + (d.output || d.error || TK('admin.198')); }
            })
            .catch(function() { btn.disabled = false; msg.textContent = TK('admin.279'); });
    }
    </script>
    <?php elseif ($tab === 'mail'): ?>
    <?php
    $smtpCfg = getSmtpConfig();
    $smtpAdminEmail = $config['admin_email'] ?? '';
    $smtpMsg = $_GET['msg'] ?? '';
    $alertLogTail = [];
    if (is_file(ALERT_LOG)) {
        // v4.1.9：逐行 trim，去除文件里行首/行尾多余空白，避免 pre-wrap 显示错位
        $lines = array_slice(array_filter(array_map('trim', explode("\n", (string)@file_get_contents(ALERT_LOG)))), -6);
        $alertLogTail = array_reverse($lines);
    }
    ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <?= t('admin.476') ?>
        </div>
        <div class="page-subtitle"><?= t('admin.280') ?></div>
    </div>
    <?php if ($smtpMsg === 'saved'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(46,204,113,0.15);color:#2ecc71"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('admin.281') ?></div>
    <?php elseif ($smtpMsg === 'save_failed'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060"><?= t('admin.282') ?></div>
    <?php elseif ($smtpMsg === 'challenge_failed'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060"><?= t('admin.250') ?></div>
    <?php elseif ($smtpMsg === 'csrf_error'): ?>
        <div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060"><?= t('admin.251') ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg><?= t('admin.283') ?></div>
        <?php if ($smtpAdminEmail === ''): ?>
        <div class="msg" style="margin:0 0 12px;background:rgba(255,80,80,0.15);color:#ff6060"><?= t('admin.284') ?></div>
        <?php else: ?>
        <div class="form-hint" style="margin-bottom:12px"><?= t('admin.438') ?><b><?= htmlspecialchars($smtpAdminEmail) ?></b><?= t('admin.439') ?></div>
        <?php endif; ?>
        <table>
            <tr><td style="color:var(--text-muted)"><?= t('admin.440') ?></td><td><?= htmlspecialchars($smtpAdminEmail ?: '(未设置，请到「系统配置」填写 admin_email)') ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.441') ?></td><td><?= $smtpCfg['host'] ? htmlspecialchars($smtpCfg['host']) . ':' . (int)$smtpCfg['port'] . '（' . htmlspecialchars($smtpCfg['enc']) . '）' : '未配置' ?></td></tr>
            <tr><td style="color:var(--text-muted)"><?= t('admin.442') ?></td><td><?= htmlspecialchars($smtpCfg['user'] ?: '未配置') ?></td></tr>
        </table>
        <form method="post" class="need-challenge" data-confirm="<?= t('admin.cf_smtp') ?>">
            <input type="hidden" name="save_smtp_config" value="1">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <table style="margin-top:8px">
                <tr><td style="color:var(--text-muted)"><?= t('admin.285') ?></td>
                    <td><input class="form-input" style="width:220px" type="text" name="smtp_host" value="<?= htmlspecialchars($smtpCfg['host']) ?>" placeholder="<?= t('admin.443') ?>" required></td></tr>
                <tr><td style="color:var(--text-muted)"><?= t('admin.286') ?></td>
                    <td><input class="form-input" style="width:120px" type="number" min="1" max="65535" name="smtp_port" value="<?= (int)$smtpCfg['port'] ?>" required></td></tr>
                <tr><td style="color:var(--text-muted)"><?= t('admin.287') ?></td>
                    <td><select class="form-input" style="width:160px" name="smtp_enc">
                        <option value="ssl" <?= $smtpCfg['enc'] === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
                        <option value="tls" <?= $smtpCfg['enc'] === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
                        <option value="plain" <?= $smtpCfg['enc'] === 'plain' ? 'selected' : '' ?>><?= t('admin.444') ?></option>
                    </select></td></tr>
                <tr><td style="color:var(--text-muted)"><?= t('admin.288') ?></td>
                    <td><input class="form-input" style="width:220px" type="text" name="smtp_user" value="<?= htmlspecialchars($smtpCfg['user']) ?>" placeholder="<?= t('admin.445') ?>" required></td></tr>
                <tr><td style="color:var(--text-muted)"><?= t('admin.289') ?></td>
                    <td><?php $smtpPassSrc = smtpPassSource(); ?>
                        <?php if ($smtpPassSrc === 'env'): ?>
                        <span style="color:#34c759;font-size:0.85em"><?= t('admin.290') ?><code>YSM_SMTP_PASS</code><?= t('admin.291') ?><code>/etc/php/*/fpm/pool.d/zz-ysm-secret.conf</code><?= t('admin.292') ?><code>sudo ysm-admin set-smtp-pass</code>）</span>
                        <?php elseif ($smtpPassSrc === 'config'): ?>
                        <span style="color:#f59e0b;font-size:0.85em"><?= t('admin.293') ?><code>sudo ysm-admin set-smtp-pass</code><?= t('admin.294') ?><code>zz-ysm-secret.conf</code><?= t('admin.295') ?></span>
                        <?php elseif ($smtpPassSrc === 'file'): ?>
                        <span style="color:#34c759;font-size:0.85em"><?= t('admin.296') ?><code>/opt/you-super-markdown/secrets/smtp_pass</code><?= t('admin.297') ?></span>
                        <?php else: ?>
                        <span style="color:#ef4444;font-size:0.85em"><?= t('admin.298') ?><code>sudo ysm-admin set-smtp-pass</code><?= t('admin.294') ?><code>zz-ysm-secret.conf</code><?= t('admin.299') ?></span>
                        <?php endif; ?></td></tr>
                <tr><td style="color:var(--text-muted)"><?= t('admin.575') ?></td>
                    <td><input class="form-input" style="width:220px" type="text" name="smtp_from" value="<?= htmlspecialchars($smtpCfg['from']) ?>" placeholder="<?= t('admin.446') ?>"></td></tr>
            </table>
            <div style="margin-top:12px">
                <button class="btn btn-primary" type="submit">
                    <svg viewBox="0 0 24 24" width="14" height="14"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <?= t('admin.489') ?>
                </button>
                <span style="color:var(--text-muted);font-size:0.82em;margin-left:8px"><?= t('admin.261') ?></span>
            </div>
        </form>
        <div style="margin-top:14px">
            <button class="btn btn-secondary" onclick="testSmtpMail()"><?= t('admin.300') ?></button>
            <span style="color:var(--text-muted);font-size:0.82em;margin-left:8px"><?= t('admin.301') ?></span>
        </div>
        <div id="smtpTestResult" style="display:none;margin-top:10px;padding:10px 14px;border-radius:8px;font-size:0.9em"></div>
    </div>

    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?= t('admin.302') ?></div>
        <?php if (!$alertLogTail): ?>
            <p style="color:var(--text-muted);font-size:0.85em"><?= t('admin.303') ?></p>
        <?php else: ?>
        <div style="font-family:monospace;font-size:0.82em;line-height:1.7;white-space:pre-wrap;word-break:break-all">
            <?php foreach ($alertLogTail as $line): ?><?= htmlspecialchars($line) ?><?= PHP_EOL ?><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <p style="color:var(--text-muted);font-size:0.82em;margin-top:8px"><?= t('admin.304') ?></p>
    </div>

    <script>
    function testSmtpMail() {
        var code = prompt(TK('admin.305'));
        if (!code) return;
        var fd = new FormData();
        fd.append('ajax', 'test_smtp');
        fd.append('csrf_token', '<?= generateCsrfToken() ?>');
        fd.append('challenge_code', code.trim().toUpperCase());
        var el = document.getElementById('smtpTestResult');
        el.style.display = 'block';
        el.textContent = TK('admin.306');
        el.style.color = 'var(--text-secondary)';
        fetch('<?= $_SERVER['SCRIPT_NAME'] ?>', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    el.style.background = 'rgba(46,204,113,0.15)';
                    el.style.color = '#2ecc71';
                    el.textContent = '✅ ' + t('admin.307').replace('{email}', '<?= htmlspecialchars($smtpAdminEmail ?: "") ?>');
                } else {
                    el.style.background = 'rgba(255,80,80,0.15)';
                    el.style.color = '#ff6060';
                    el.textContent = '❌ ' + (d.error || TK('admin.308'));
                }
            })
            .catch(function() {
                el.style.background = 'rgba(255,80,80,0.15)';
                el.style.color = '#ff6060';
                el.textContent = '❌ ' + TK('admin.309');
            });
    }
    </script>
    <?php elseif ($tab === 'verify'): ?>
    <?php
    $verifyMsg = $_GET['msg'] ?? '';
    $codeRows = db_all('SELECT * FROM email_codes ORDER BY created DESC');
    $codePage = max(1, (int)($_GET['vp'] ?? 1));
    $codesPaged = paginateList($codeRows, ['email', 'purpose', 'code', 'ip'], trim($_GET['vq'] ?? ''), $codePage, 10);
    $pendRows = db_all('SELECT * FROM pending_author_creates ORDER BY created DESC');
    $pendPage = max(1, (int)($_GET['pp'] ?? 1));
    $pendsPaged = paginateList($pendRows, ['email', 'nickname', 'account', 'status'], trim($_GET['pq'] ?? ''), $pendPage, 10);
    $purposeLabel = fn($p) => ['register' => '注册', 'author_verify' => '写作者验证', 'author_confirm' => '超管确认'][$p] ?? $p;
    $statusLabel = fn($s) => ['verify_pending' => '等写作者验证', 'pending' => '待超管确认', 'confirmed' => '已确认', 'rejected' => '已拒绝', 'expired' => '已过期'][$s] ?? $s;
    ?>
    <div class="page-header">
        <div class="page-title">
            <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?= t('admin.477') ?>
        </div>
        <div class="page-subtitle"><?= t('admin.310') ?></div>
    </div>
    <?php if ($verifyMsg === 'saved'): ?><div class="msg" style="margin:0 0 16px;background:rgba(46,204,113,0.15);color:#2ecc71"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?= t('admin.369') ?></div><?php endif; ?>
    <?php if ($verifyMsg === 'csrf_error'): ?><div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060"><?= t('admin.447') ?></div><?php endif; ?>
    <?php if ($verifyMsg === 'challenge_failed'): ?><div class="msg" style="margin:0 0 16px;background:rgba(255,80,80,0.15);color:#ff6060"><?= t('admin.448') ?></div><?php endif; ?>
    <div class="card">
        <div class="card-title"><?= t('admin.311') ?></div>
        <form method="post" class="need-challenge" data-confirm="<?= t('admin.cf_verify') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="save_verify_config" value="1">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label"><?= t('admin.312') ?></label>
                    <div style="display:flex;align-items:center;gap:10px">
                        <span style="font-size:13px"><?= t('admin.313') ?></span>
                        <label class="toggle" style="flex-shrink:0;margin-left:auto"><input type="checkbox" name="email_verify_enabled" id="ev_email" value="1" <?= !empty($config['email_verify_enabled']) ? 'checked' : '' ?>><span class="slider"></span></label>
                    </div>
                </div>
                <div class="form-group">
                    <!-- v2.11.0：滑块人机验证已彻底移除（原 captcha_enabled 开关删除） -->
                    <label class="form-label"><?= t('admin.314') ?></label>
                    <div style="display:flex;align-items:center;gap:10px">
                        <span style="font-size:13px"><?= t('admin.315') ?></span>
                        <label class="toggle" style="flex-shrink:0;margin-left:auto"><input type="checkbox" name="author_dual_verify_enabled" id="ev_dual" value="1" <?= !empty($config['author_dual_verify_enabled']) ? 'checked' : '' ?>><span class="slider"></span></label>
                    </div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label"><?= t('admin.316') ?></label>
                    <input class="form-input" type="number" name="verify_code_ttl" min="60" value="<?= (int)($config['verify_code_ttl'] ?? 300) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('admin.317') ?></label>
                    <input class="form-input" type="number" name="confirm_link_ttl" min="300" value="<?= (int)($config['confirm_link_ttl'] ?? 86400) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('admin.318') ?></label>
                    <input class="form-input" type="number" name="resend_cooldown" min="10" value="<?= (int)($config['resend_cooldown'] ?? 60) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="flex:0">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary"><?= t('admin.061') ?></button>
                </div>
            </div>
            <div class="form-hint" style="margin-top:8px;font-size:12px;color:var(--text-muted)"><?= t('admin.319') ?><code>sudo ysm-admin challenge</code><?= t('admin.320') ?></div>
        </form>
    </div>
    <div class="card">
        <div class="card-title"><?= t('admin.code_rows', ['n' => count($codeRows)]) ?></div>
        <form method="get" class="search-bar" style="margin-bottom:12px;display:flex;gap:8px">
            <input type="hidden" name="tab" value="verify">
            <input class="form-input" type="text" name="vq" value="<?= htmlspecialchars($_GET['vq'] ?? '') ?>" placeholder="<?= t('admin.449') ?>" style="max-width:320px">
            <button class="btn btn-secondary" type="submit"><?= t('admin.009') ?></button>
        </form>
        <div class="table-wrap">
        <table>
            <tr><th><?= t('admin.035') ?></th><th><?= t('admin.321') ?></th><th><?= t('admin.322') ?></th><th><?= t('admin.323') ?></th><th>IP</th><th><?= t('admin.324') ?></th><th><?= t('admin.030') ?></th></tr>
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
            <?php if (empty($codesPaged['rows'])): ?><tr><td colspan="7" style="text-align:center;color:var(--text-muted)"><?= t('admin.450') ?></td></tr><?php endif; ?>
        </table>
        </div>
        <?= renderPager($codesPaged, 'vp', ['tab' => 'verify', 'vq' => $_GET['vq'] ?? ''], 'vpp') ?>
    </div>
    <div class="card">
        <div class="card-title"><?= t('admin.pending_rows', ['n' => count($pendRows)]) ?></div>
        <form method="get" class="search-bar" style="margin-bottom:12px;display:flex;gap:8px">
            <input type="hidden" name="tab" value="verify">
            <input class="form-input" type="text" name="pq" value="<?= htmlspecialchars($_GET['pq'] ?? '') ?>" placeholder="<?= t('admin.451') ?>" style="max-width:320px">
            <button class="btn btn-secondary" type="submit"><?= t('admin.009') ?></button>
        </form>
        <div class="table-wrap">
        <table>
            <tr><th><?= t('admin.325') ?></th><th><?= t('admin.321') ?></th><th><?= t('admin.010') ?></th><th><?= t('admin.029') ?></th><th><?= t('admin.030') ?></th><th><?= t('admin.326') ?></th></tr>
            <?php foreach ($pendsPaged['rows'] as $pd): ?>
            <tr>
                <td><?= date('Y-m-d H:i:s', (int)$pd['created']) ?></td>
                <td><?= htmlspecialchars($pd['email']) ?></td>
                <td><?= htmlspecialchars($pd['nickname']) ?></td>
                <td><?= htmlspecialchars($pd['account']) ?></td>
                <td><?= $statusLabel($pd['status']) ?></td>
                <td><?= $pd['confirmed_at'] ? htmlspecialchars($pd['confirmed_at']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($pendsPaged['rows'])): ?><tr><td colspan="6" style="text-align:center;color:var(--text-muted)"><?= t('admin.450') ?></td></tr><?php endif; ?>
        </table>
        </div>
        <?= renderPager($pendsPaged, 'pp', ['tab' => 'verify', 'pq' => $_GET['pq'] ?? ''], 'ppp') ?>
    </div>
    <?php elseif ($tab === 'threat'): ?>
    <?php if ($msg === 'challenge_failed'): ?><div class="msg msg-error"><?= t('admin.452') ?></div><?php endif; ?>
    <?php if ($msg === 'csrf_error'): ?><div class="msg msg-error"><?= t('admin.453') ?></div><?php endif; ?>
    <?php if ($msg === 'device_removed'): ?><div class="msg msg-success"><?= t('admin.454') ?></div><?php endif; ?>
    <?php if ($msg === 'device_rm_fail'): ?><div class="msg msg-error"><?= t('admin.455') ?></div><?php endif; ?>
    <?php if ($msg === 'linked_cleared'): ?><div class="msg msg-success"><?= t('admin.456') ?></div><?php endif; ?>
    <?php if ($msg === 'linked_clr_fail'): ?><div class="msg msg-error"><?= t('admin.457') ?></div><?php endif; ?>
    <?php if ($msg === 'reset_user_not_found'): ?><div class="msg msg-error"><?= t('admin.458') ?></div><?php endif; ?>
    <?php if (strpos((string)$msg, '重置码') !== false): ?><div class="msg msg-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <?php
    $tp = max(1, (int)($_GET['tp'] ?? 1));
    $tpp = 20;
    [$threatRows, $threatTotal] = threatDims($tp, $tpp);
    $threatPages = max(1, (int)ceil($threatTotal / $tpp));
    if ($tp > $threatPages) { $tp = $threatPages; [$threatRows, $threatTotal] = threatDims($tp, $tpp); }
    ?>
    <div class="card">
        <div class="card-title"><?= t('admin.327') ?></div>
        <div class="table-wrap">
        <table>
            <tr><th><?= t('admin.328') ?></th><th><?= t('admin.329') ?></th><th><?= t('admin.330') ?></th><th><?= t('admin.331') ?></th><th><?= t('admin.332') ?></th><th><?= t('admin.032') ?></th></tr>
            <?php foreach ($threatRows as $tr): $locked = (int)$tr['locked_left']; ?>
            <tr>
                <td><code><?= htmlspecialchars($tr['dim_type'] === 'ip' ? 'IP: ' . $tr['dim_key'] : '指纹: ' . substr($tr['dim_key'], 0, 16) . '…') ?></code></td>
                <td><b><?= $tr['score'] ?></b></td>
                <td><?= $tr['cnt'] ?></td>
                <td><?= date('m-d H:i', (int)$tr['last_ts']) ?></td>
                <td>
                    <?php if ($locked > 0): ?><span style="color:#e5484d;font-weight:600"><?= t('common.locked_remain', ['n' => ceil($locked / 60)]) ?></span>
                    <?php elseif ($tr['score'] >= 200): ?><span style="color:#e5484d;font-weight:600"><?= t('admin.459') ?></span>
                    <?php elseif ($tr['score'] >= 100): ?><span style="color:#f5a623;font-weight:600"><?= t('admin.460') ?></span>
                    <?php elseif ($tr['score'] >= 50): ?><span style="color:#f5a623"><?= t('admin.461') ?></span>
                    <?php else: ?><span style="color:var(--text-muted)"><?= t('station.136') ?></span><?php endif; ?>
                </td>
                <td>
                    <?php if ($locked > 0 || $tr['score'] >= 50): ?>
                    <form method="post" class="need-challenge" style="display:inline" data-confirm="<?= htmlspecialchars(t('admin.cf_unlink'), ENT_QUOTES) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                        <input type="hidden" name="clear_linked" value="1">
                        <input type="hidden" name="link_dim_type" value="<?= htmlspecialchars($tr['dim_type']) ?>">
                        <input type="hidden" name="link_dim_key" value="<?= htmlspecialchars($tr['dim_key']) ?>">
                        <input type="hidden" name="challenge_code">
                        <button class="btn btn-sm btn-outline"><?= t('admin.333') ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($threatRows)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-muted)"><?= t('admin.462') ?></td></tr><?php endif; ?>
        </table>
        </div>
        <?= renderPager(['page' => $tp, 'pages' => $threatPages, 'per_page' => $tpp, 'total' => $threatTotal], 'tp', ['tab' => 'threat'], 'tpp') ?>
    </div>

    <div class="card">
        <div class="card-title"><?= t('admin.334') ?></div>
        <div class="table-wrap">
        <?php
        $dp = max(1, (int)($_GET['dp'] ?? 1));
        $dpp = 20;
        [$devRows, $devTotal] = getDevicesPaginated($dp, $dpp);
        $devPages = max(1, (int)ceil($devTotal / $dpp));
        if ($dp > $devPages) { $dp = $devPages; [$devRows, $devTotal] = getDevicesPaginated($dp, $dpp); }
        ?>
        <table>
            <tr><th><?= t('admin.335') ?></th><th><?= t('admin.015') ?></th><th><?= t('admin.336') ?></th><th>UA</th><th><?= t('admin.337') ?></th><th><?= t('admin.338') ?></th><th><?= t('admin.032') ?></th></tr>
            <?php foreach ($devRows as $d): ?>
            <tr>
                <td><?= htmlspecialchars($d['account'] ?? '') ?></td>
                <td><?= htmlspecialchars($d['role'] ?? '') ?></td>
                <td><code><?= htmlspecialchars(substr($d['fp_hash'], 0, 16)) ?>…</code></td>
                <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($d['ua'] ?? '') ?>"><?= htmlspecialchars(mb_substr($d['ua'] ?? '', 0, 40, 'UTF-8')) ?></td>
                <td><?= date('Y-m-d', (int)$d['first_seen']) ?></td>
                <td><?= date('Y-m-d', (int)$d['last_seen']) ?></td>
                <td>
                    <form method="post" class="need-challenge" style="display:inline" data-confirm="<?= htmlspecialchars(t('admin.cf_device'), ENT_QUOTES) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                        <input type="hidden" name="remove_device" value="1">
                        <input type="hidden" name="device_uid" value="<?= htmlspecialchars($d['user_id']) ?>">
                        <input type="hidden" name="device_id" value="<?= htmlspecialchars($d['id']) ?>">
                        <input type="hidden" name="challenge_code">
                        <button class="btn btn-sm btn-outline"><?= t('admin.339') ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($devRows)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-muted)"><?= t('admin.463') ?></td></tr><?php endif; ?>
        </table>
        </div>
        <?= renderPager(['page' => $dp, 'pages' => $devPages, 'per_page' => $dpp, 'total' => $devTotal], 'dp', ['tab' => 'threat'], 'dpp') ?>
    </div>

    <div class="card">
        <div class="card-title"><?= t('admin.340') ?></div>
        <form method="post" class="need-challenge">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="hidden" name="gen_reset_code" value="1">
            <input type="hidden" name="challenge_code">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label"><?= t('admin.029') ?></label>
                    <input class="form-input" type="text" name="reset_account" placeholder="<?= t('admin.341') ?>" maxlength="20" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="flex:0">
                    <button type="submit" class="btn btn-primary"><?= t('admin.342') ?></button>
                </div>
            </div>
            <div class="form-hint"><?= t('admin.343') ?></div>
        </form>
    </div>
    <?php endif; ?>
</div>

    <!-- 挑战码弹窗（全局：tab 链外，所有 tab 的敏感操作均可弹出） -->
    <div class="modal-overlay" id="challengeModal" style="display:none" onclick="if(event.target===this)closeChallengeModal()">
        <div class="modal-box" style="max-width:420px">
            <div class="modal-head">
                <div class="modal-title"><?= t('admin.344') ?></div>
                <button class="modal-close" onclick="closeChallengeModal()" aria-label="<?= t('admin.345') ?>">&times;</button>
            </div>
            <div class="modal-body">
                <p style="color:var(--text-secondary);margin-bottom:16px"><?= t('admin.346') ?></p>
                <div class="code-block">ysm-admin challenge</div>
                <div style="margin-top:16px">
                    <label class="form-label"><?= t('admin.347') ?></label>
                    <input class="form-input" type="text" id="challengeCodeInput" placeholder="<?= t('admin.348') ?>" maxlength="6" style="text-transform:uppercase;letter-spacing:4px;font-size:1.2em;text-align:center" autocomplete="off">
                </div>
                <div style="margin-top:8px;color:var(--text-muted);font-size:0.82em"><?= t('admin.349') ?></div>
                <div id="challengeError" style="color:var(--danger);margin-top:8px;display:none"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeChallengeModal()"><?= t('admin.059') ?></button>
                <button class="btn btn-primary" onclick="submitChallenge()" id="submitChallengeBtn"><?= t('admin.350') ?></button>
            </div>
        </div>
    </div>

    <!-- v2.11.5：用户详情弹窗（只读，展示完整资料 + 关联统计） -->
    <div class="modal-overlay" id="userDetailModal" style="display:none" onclick="if(event.target===this)this.style.display='none'">
        <div class="modal-box user-detail-box">
            <div class="modal-head">
                <div class="modal-title" id="udTitle"><?= t('admin.351') ?></div>
                <button class="modal-close" onclick="document.getElementById('userDetailModal').style.display='none'">&times;</button>
            </div>
            <div class="modal-body" id="udBody"></div>
        </div>
    </div>

    <!-- v3.0.3：用户操作独立菜单弹窗（⋯ → 单例 modal，与弹窗体系统一） -->
    <div class="modal-overlay" id="userOpModal" style="display:none" onclick="if(event.target===this)this.style.display='none'">
        <div class="modal-box user-op-modal-box">
            <div class="modal-head">
                <div class="modal-title"><?= t('admin.352') ?></div>
                <button class="modal-close" onclick="document.getElementById('userOpModal').style.display='none'" aria-label="<?= t('admin.345') ?>">&times;</button>
            </div>
            <div class="modal-body" id="userOpBody"></div>
        </div>
    </div>

<script>
// v6.3.1：统一确认——文案由服务端 t() 写进 data-confirm，避免在 JS 里调 PHP 的 t() 导致 ReferenceError（确认被跳过）
function ysmConfirm(el) {
    var m = (el && el.getAttribute) ? (el.getAttribute('data-confirm') || '') : '';
    return m === '' ? true : confirm(m);
}
// 敏感操作挑战码统一处理：提交前弹出确认码输入（SSH: sudo ysm-admin challenge）
// v3.0.3：改为事件委托（document submit），支持动态注入的 need-challenge 表单（用户操作弹窗）
document.addEventListener('submit', function(e) {
    var f = e.target;
    if (!f || !f.classList || !f.classList.contains('need-challenge')) return;
    e.preventDefault();
    if (f.dataset.confirm && !confirm(f.dataset.confirm)) return;
    var code = prompt(TK('admin.305'));
    if (!code) return;
    var hid = f.querySelector('input[name="challenge_code"]');
    if (!hid) { hid = document.createElement('input'); hid.type = 'hidden'; hid.name = 'challenge_code'; f.appendChild(hid); }
    hid.value = code.trim().toUpperCase();
    f.submit();
});
// 登出走 POST + CSRF（防 GET 登出被 CSRF 利用）
function bindLogoutSubmit(e) {
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
function ysmEsc(s) {
    var el = document.createElement('span');
    el.textContent = (s === null || s === undefined) ? '' : String(s);
    return el.innerHTML;
}
function ysmOpenUserDetail(btn) {
    var d = {};
    try { d = JSON.parse(btn.getAttribute('data-detail') || '{}'); } catch (e) { return; }
    // v3.0.5：详情弹窗在二级菜单之上打开——先收起操作菜单，避免被其遮罩挡住
    var opModal = document.getElementById('userOpModal');
    if (opModal) opModal.style.display = 'none';
    var roleMap = { 'super_admin': TK('admin.353'), 'station_admin': TK('admin.024'), 'author': TK('admin.354'), 'user': TK('admin.355') };
    var base = [
        [TK('admin.010'), d.nickname], [TK('admin.029'), d.username || d.account], [TK('admin.321'), d.email],
        [TK('admin.015'), roleMap[d.role] || d.role], [TK('admin.033'), d.station || '—'],
        [TK('admin.030'), d.disabled ? TK('admin.017') : TK('admin.018')], [TK('admin.031'), d.created], [TK('admin.356'), d.created_by || '—'],
        [TK('admin.357'), d.signature || '—'], [TK('admin.358'), d.last_login]
    ];
    var stats = [[TK('admin.359'), d.login_count], [TK('admin.360'), d.comments], [TK('admin.361'), d.articles]];
    var h = '<div class="user-detail-grid">';
    base.forEach(function(r) {
        h += '<div class="user-detail-item"><span class="user-detail-label">' + r[0] + '</span><span class="user-detail-value">' + ysmEsc(r[1]) + '</span></div>';
    });
    h += '</div><div class="user-detail-sec">' + TK('admin.362') + '</div><div class="user-detail-grid">';
    stats.forEach(function(r) {
        h += '<div class="user-detail-item"><span class="user-detail-label">' + r[0] + '</span><span class="user-detail-value">' + ysmEsc(r[1]) + '</span></div>';
    });
    h += '</div>';
    document.getElementById('udTitle').textContent = TK('admin.363') + ' ' + (d.nickname || d.username || d.account || '');
    document.getElementById('udBody').innerHTML = h;
    document.getElementById('userDetailModal').style.display = 'flex';
}
</script>
<script>
// v4.5.0：环境指纹上报校验——页面加载后上报当前环境指纹（与登录时一致的算法），服务端比对会话绑定指纹
(function() {
    function fpFnv(s) { var h = 0x811c9dc5; for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 0x01000193); } return ('00000000' + (h >>> 0).toString(16)).slice(-8); }
    function fpCanvas() { try { var c = document.createElement('canvas'); c.width = 200; c.height = 40; var x = c.getContext('2d'); x.textBaseline = 'top'; x.font = '14px Arial'; x.fillStyle = '#f60'; x.fillRect(0, 0, 200, 40); x.fillStyle = '#069'; x.fillText('YouSuperMarkdown\u2620' + navigator.userAgent.length, 5, 12); var d = c.toDataURL(); return d.length + ':' + d.slice(-64); } catch (e) { return ''; } }
    var parts = [navigator.language || '', new Date().getTimezoneOffset(), (screen.width || 0) + 'x' + (screen.height || 0), fpCanvas(), navigator.userAgent];
    var fp = fpFnv(parts.join('|')) + fpFnv(parts.join('~')) + fpFnv(navigator.userAgent);
    fetch('/api.php?action=csrf').then(r => r.json()).then(function(d) {
            if (!d.success || !d.csrf_token) return;
            // v4.6.1：fp_report 结果回显——环境不匹配时立即明确提示（否则保存等写操作被静默拦截，用户误以为"保存不生效"）
            fetch('/api.php?action=fp_report', { method: 'POST', headers: { 'X-Fp': fp, 'X-CSRF-Token': d.csrf_token } })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res && res.success === false) {
                        var _b = document.createElement('div');
                        _b.style.cssText = 'position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:99999;background:#dc2626;color:#fff;padding:10px 18px;border-radius:10px;font-size:13px;box-shadow:0 4px 16px rgba(0,0,0,.25);max-width:92vw';
                        _b.textContent = TK('admin.364');
                        document.body.appendChild(_b);
                        setTimeout(function() { if (_b.parentNode) _b.parentNode.removeChild(_b); }, 7000);
                    }
                })
                .catch(function() {});
        }).catch(function() {});
})();
</script>
</body>
</html>