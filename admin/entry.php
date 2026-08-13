<?php
session_start();
require_once __DIR__ . '/../utils.php';

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

$entriesFile = __DIR__ . '/../data/.entries.json';
$entries = file_exists($entriesFile) ? json_decode(file_get_contents($entriesFile), true) : [];
if (!is_array($entries)) $entries = [];

// 查找匹配的 entry
$found = null;
$foundIdx = null;
foreach ($entries as $i => $e) {
    if (($e['token'] ?? '') === $entryToken && empty($e['used']) && ($e['expires'] ?? 0) > time()) {
        $found = $e;
        $foundIdx = $i;
        break;
    }
}

if (!$found) {
    http_response_code(404);
    exit('Not Found');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = $_POST['otp'] ?? '';
    if (password_verify($otp, $found['otp_hash'])) {
        // 原子消费：标记 used=1
        $entries[$foundIdx]['used'] = 1;
        file_put_contents($entriesFile, json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

        // 加载用户
        $users = loadUsers();
        $superAdmin = null;
        foreach ($users as $u) {
            if (($u['role'] ?? '') === ROLE_SUPER_ADMIN) { $superAdmin = $u; break; }
        }
        if (!$superAdmin) {
            $error = '系统错误：未找到高级管理员账号';
        } else {
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
            header('Location: /admin/dashboard.php');
            exit;
        }
    } else {
        $error = '密码错误';
        auditLog('login_otp_failed', '', 'OTP 验证失败');
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-admin="super">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>管理员验证 - You Markdown</title>
<link rel="stylesheet" href="/css/admin.css">
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

            <div class="entry-title">管理员验证</div>
            <div class="entry-sub">输入一次性密码以验证身份</div>

            <?php if ($error): ?>
            <div class="entry-error">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="post">
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

            <div class="entry-expire">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                此入口将于 <strong><?= date('H:i:s', $found['expires']) ?></strong> 过期
            </div>
        </div>
    </div>
</body>
</html>