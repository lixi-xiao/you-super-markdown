<?php
require_once __DIR__ . '/../utils.php';
secureSessionStart();

// v3.0.8 统一安全入口：扫描器 UA 黑名单检测（命中返回 403 + 记录 + 封禁来源 IP）
security_check();

// 获取 URL 中的 entry_token（如 /admin/entry/a3Bf9xQ2mZ1k）
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);
$parts = explode('/', trim($path, '/'));
$entryToken = end($parts);

// 不是有效 token → 404，不暴露入口是否存在
if (!$entryToken || strlen($entryToken) !== 12 || !preg_match('/^[a-zA-Z0-9]+$/', $entryToken)) {
    http_response_code(404);
    exit('Not Found');
}

$entries = loadEntries();

// 查找匹配的 entry
$found = null;
foreach ($entries as $e) {
    if (($e['token'] ?? '') === $entryToken && empty($e['used']) && ($e['expires'] ?? 0) > time()) {
        $found = $e;
        break;
    }
}

if (!$found) {
    http_response_code(404);
    exit('Not Found');
}

$error = '';
$needDeviceVerify = false;
$deviceEmail = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Forbidden');
    }
    $reqFp = trim((string)($_POST['fingerprint'] ?? ''));
    if ($reqFp !== '' && preg_match('/^[a-f0-9]{16,64}$/i', $reqFp)) $reqFp = strtolower($reqFp);
    else $reqFp = '';

    // v4.7.0：设备验证码提交分支（OTP 通过但为陌生设备时出现的验证码表单）
    if (isset($_POST['device_code'])) {
        $pending = $_SESSION['cmt_pending_dev'] ?? null;
        if (!is_array($pending) || empty($pending['uid']) || empty($pending['email'])) {
            $error = '验证会话已失效，请重新验证';
        } else {
            [$devOk, $devErr] = email_code_verify($pending['email'], trim((string)($_POST['device_code_input'] ?? '')), 'device_login');
            if ($devOk) {
                $users = loadUsers();
                $superAdmin = null;
                foreach ($users as $u) { if ($u['id'] === $pending['uid']) { $superAdmin = $u; break; } }
                if (!$superAdmin || !empty($superAdmin['disabled'])) {
                    unset($_SESSION['cmt_pending_dev']);
                    $error = '账号不可用，请联系管理员';
                } else {
                    recordDevice($superAdmin['id'], $pending['fp_hash'], $_SERVER['HTTP_USER_AGENT'] ?? '');
                    unset($_SESSION['cmt_pending_dev']);
                    $_SESSION['otp_fails'] = 0;
                    $newTV = bumpUserTV($superAdmin['id']);
                    $_SESSION['cmt_fp'] = computeSessionFp($pending['fp'] ?? $reqFp);
                    $_SESSION['cmt_tv'] = $newTV;
                    $_SESSION['cmt_login_ts'] = time();
                    clearRefreshCookie(); // v4.6.0：超管无 refresh——清掉旧 ym_rt，杜绝续期绕过 30 分钟限制
                    $_SESSION['cmt_user'] = [
                        'id' => $superAdmin['id'],
                        'qq' => $superAdmin['qq'] ?? '',
                        'nickname' => $superAdmin['nickname'] ?? '高级管理员',
                        'role' => ROLE_SUPER_ADMIN,
                        'pw_hash' => $superAdmin['password'] ?? '',
                        'jwt' => generateJWT($superAdmin['id'], ROLE_SUPER_ADMIN, 1800),
                        'login_time' => time(),
                    ];
                    auditLog('login_otp', $superAdmin['id'], 'OTP 动态入口登录成功（新设备验证通过）');
                    notifyLoginEvent($superAdmin, getClientIP());
                    header('Location: /admin/dashboard.php');
                    exit;
                }
            } else {
                $error = $devErr;
                auditLog('login_otp_failed', '', '设备验证码错误');
            }
        }
    } else {
        $otp = $_POST['otp'] ?? '';
        // OTP 尝试限次：连续 3 次失败即销毁入口（防暴力枚举）
        $otpFails = (int)($_SESSION['otp_fails'] ?? 0);
        if ($otpFails >= 3) {
            db_exec('UPDATE entries SET used = 1 WHERE token = ?', [$entryToken]);
            http_response_code(404);
            exit('Not Found');
        }
        if (password_verify($otp, $found['otp_hash'])) {
            $_SESSION['otp_fails'] = 0;
            // 原子消费：标记 used=1
            db_exec('UPDATE entries SET used = 1 WHERE token = ?', [$entryToken]);

            // 加载用户
            $users = loadUsers();
            $superAdmin = null;
            foreach ($users as $u) {
                if (($u['role'] ?? '') === ROLE_SUPER_ADMIN) { $superAdmin = $u; break; }
            }
            if (!$superAdmin) {
                $error = '系统错误：未找到高级管理员账号';
            } else {
                // v4.7.0：陌生设备 → 邮件二次验证（OTP 通过后仍需验证码确认设备才完成登录）
                $fpHash = computeSessionFp($reqFp);
                if (!isKnownDevice($superAdmin['id'], $fpHash)) {
                    $_SESSION['cmt_pending_dev'] = [
                        'uid' => $superAdmin['id'], 'fp_hash' => $fpHash, 'fp' => $reqFp,
                        'email' => $superAdmin['email'] ?? '', 'role' => ROLE_SUPER_ADMIN,
                    ];
                    [$devOk, $devErr] = email_code_send($superAdmin['email'] ?? '', 'device_login', '陌生设备登录验证', ROLE_SUPER_ADMIN);
                    if ($devOk) {
                        $needDeviceVerify = true;
                        $deviceEmail = maskEmailAddr($superAdmin['email'] ?? '');
                    } else {
                        $error = $devErr;
                    }
                } else {
                    // v4.5.0：OTP 登录同样绑定环境指纹 + token_version（并发踢旧）
                    // v4.6.0：超管严格 30 分钟会话——不签发 refresh（30 分钟后必须重新 OTP 登录）
                    $newTV = bumpUserTV($superAdmin['id']);
                    $_SESSION['cmt_fp'] = computeSessionFp($reqFp);
                    $_SESSION['cmt_tv'] = $newTV;
                    $_SESSION['cmt_login_ts'] = time();
                    clearRefreshCookie(); // v4.6.0：超管无 refresh——清掉旧 ym_rt，杜绝续期绕过 30 分钟限制
                    $_SESSION['cmt_user'] = [
                        'id' => $superAdmin['id'],
                        'qq' => $superAdmin['qq'] ?? '',
                        'nickname' => $superAdmin['nickname'] ?? '高级管理员',
                        'role' => ROLE_SUPER_ADMIN,
                        'pw_hash' => $superAdmin['password'] ?? '',
                        'jwt' => generateJWT($superAdmin['id'], ROLE_SUPER_ADMIN, 1800),
                        'login_time' => time(),
                    ];
                    auditLog('login_otp', $superAdmin['id'], 'OTP 动态入口登录成功');
                    // v4.1.11：超管登录邮件通知（与站长/写作者登录通知一致，发往管理员邮箱）
                    notifyLoginEvent($superAdmin, getClientIP());
                    header('Location: /admin/dashboard.php');
                    exit;
                }
            }
        } else {
            $_SESSION['otp_fails'] = $otpFails + 1;
            $error = '密码错误';
            auditLog('login_otp_failed', '', 'OTP 验证失败');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-admin="super">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>管理员验证 - You Markdown</title>
<link rel="stylesheet" href="/css/admin.css?v=<?= @filemtime(__DIR__ . '/../css/admin.css') ?>">
</head>
<body class="entry-page">
    <div class="entry-card">
        <div class="entry-card-inner">
            <!-- 图标 — 盾牌锁 -->
            <div class="entry-icon-wrap">
                <div class="entry-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        <circle cx="12" cy="16" r="1.2" fill="currentColor" stroke="none"/>
                    </svg>
                </div>
            </div>

            <div class="entry-title"><?= !empty($needDeviceVerify) ? '设备验证' : '管理员验证' ?></div>
            <div class="entry-sub"><?= !empty($needDeviceVerify) ? ('验证码已发送至 <strong>' . htmlspecialchars($deviceEmail) . '</strong>，请输入以确认此设备') : '输入一次性密码以验证身份' ?></div>

            <?php if ($error): ?>
            <div class="entry-error">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($needDeviceVerify)): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <input type="hidden" name="device_code" value="1">
                <div class="entry-input-wrap">
                    <input class="entry-input" type="text" name="device_code_input" placeholder="• • • • • •" maxlength="6" autocomplete="off" autofocus>
                    <div class="entry-input-label">邮箱验证码</div>
                </div>
                <button type="submit" class="entry-btn">
                    <span class="entry-btn-text">确认设备</span>
                    <span class="entry-btn-arrow">
                        <svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </span>
                </button>
            </form>
            <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <input type="hidden" name="fingerprint" id="fpInput">
                <div class="entry-input-wrap">
                    <input class="entry-input" type="text" name="otp" placeholder="• • • • • •" maxlength="12" autocomplete="off" autofocus>
                    <div class="entry-input-label">一次性密码</div>
                </div>
                <button type="submit" class="entry-btn">
                    <span class="entry-btn-text">验证身份</span>
                    <span class="entry-btn-arrow">
                        <svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </span>
                </button>
            </form>
            <?php endif; ?>

            <div class="entry-expire">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                此入口将于 <strong><?= date('H:i:s', $found['expires']) ?></strong> 过期
            </div>
        </div>
    </div>
    <script>
    // v4.5.0：OTP 登录环境指纹——提交前计算并写入隐藏字段（与服务端绑定比对，换环境即失效）
    (function() {
        function fpFnv(s) { var h = 0x811c9dc5; for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 0x01000193); } return ('00000000' + (h >>> 0).toString(16)).slice(-8); }
        function fpCanvas() { try { var c = document.createElement('canvas'); c.width = 200; c.height = 40; var x = c.getContext('2d'); x.textBaseline = 'top'; x.font = '14px Arial'; x.fillStyle = '#f60'; x.fillRect(0, 0, 200, 40); x.fillStyle = '#069'; x.fillText('YouSuperMarkdown\u2620' + navigator.userAgent.length, 5, 12); var d = c.toDataURL(); return d.length + ':' + d.slice(-64); } catch (e) { return ''; } }
        var parts = [navigator.language || '', new Date().getTimezoneOffset(), (screen.width || 0) + 'x' + (screen.height || 0), fpCanvas(), navigator.userAgent];
        document.getElementById('fpInput').value = fpFnv(parts.join('|')) + fpFnv(parts.join('~')) + fpFnv(navigator.userAgent);
    })();
    </script>
</body>
</html>