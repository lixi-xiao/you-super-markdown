<?php
require_once __DIR__ . '/utils.php';
secureSessionStart();

$siteCfg = loadSiteConfig();
$title = $siteCfg['site_title'] ?? 'You Super Markdown';
$pid = $_GET['pid'] ?? '';
$email = $_GET['email'] ?? '';
$row = get_pending_author_by_id($pid);
$err = '';
$done = false;

if (!$row || $row['status'] !== 'verify_pending' || ($row['email'] ?? '') !== $email) {
    $err = '验证链接无效或已失效，请联系站长重新发起';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $err = '请求已过期，请刷新后重试';
    } else {
        $code = trim($_POST['code'] ?? '');
        [$ok, $verr] = email_code_verify($email, $code, 'author_verify');
        if (!$ok) {
            $err = $verr;
        } else {
            // 验证通过：生成超管确认 token，状态转 pending（待超管确认），发确认邮件
            $token = bin2hex(random_bytes(16));
            db_exec("UPDATE pending_author_creates SET status = 'pending', verify_code_id = ?, confirm_token = ? WHERE id = ?", [$verr['id'], $token, $pid]);
            auditLog('author_verify_pass', $row['qq'] ?? '', "写作者验证邮箱: {$row['nickname']}");
            sendAdminConfirmMail($pid, $token, $row['nickname'], $row['qq'], $email);
            $done = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
<title>邮箱验证 - <?= htmlspecialchars($title) ?></title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
        background: linear-gradient(160deg, #f0f4ff 0%, #e6ecf7 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        color: #1e293b;
    }
    .card {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 8px 30px rgba(31, 58, 95, 0.12);
        width: 100%;
        max-width: 420px;
        padding: 36px 30px;
        text-align: center;
    }
    .brand {
        font-size: 20px;
        font-weight: 700;
        color: #1f3a5f;
        margin-bottom: 6px;
    }
    .icon {
        width: 56px; height: 56px;
        margin: 0 auto 16px;
        border-radius: 50%;
        background: #1f3a5f;
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 26px;
    }
    h1 { font-size: 18px; margin-bottom: 8px; color: #1e293b; }
    p.desc { font-size: 13px; color: #64748b; margin-bottom: 22px; line-height: 1.6; }
    .email-tag {
        display: inline-block;
        background: #f0f4ff;
        color: #1f3a5f;
        border-radius: 8px;
        padding: 4px 12px;
        font-size: 13px;
        margin-bottom: 18px;
        word-break: break-all;
    }
    input[type="text"] {
        width: 100%;
        height: 46px;
        border: 1px solid #dce7f5;
        border-radius: 10px;
        padding: 0 14px;
        font-size: 16px;
        text-align: center;
        letter-spacing: 8px;
        outline: none;
        transition: border-color 0.2s;
    }
    input[type="text"]:focus { border-color: #1f3a5f; }
    button {
        width: 100%;
        height: 46px;
        margin-top: 14px;
        border: none;
        border-radius: 10px;
        background: #1f3a5f;
        color: #fff;
        font-size: 15px;
        cursor: pointer;
        transition: background 0.2s;
    }
    button:hover { background: #2a4a75; }
    button:disabled { opacity: 0.6; cursor: not-allowed; }
    .err { color: #c0392b; font-size: 13px; margin-top: 12px; }
    .ok { color: #1e8449; font-size: 14px; margin-top: 14px; font-weight: 600; }
    .back { display: inline-block; margin-top: 18px; color: #64748b; font-size: 13px; text-decoration: none; }
</style>
</head>
<body>
<div class="card">
    <div class="icon">✉</div>
    <div class="brand"><?= htmlspecialchars($title) ?></div>
    <h1>写作者邮箱验证</h1>
    <?php if ($done): ?>
        <p class="desc">邮箱验证通过！我们已通知超管进行最终确认，超管点击确认邮件后，您的写作者账号即可登录。</p>
        <div class="ok">✓ 验证成功，等待超管确认</div>
    <?php elseif ($err !== ''): ?>
        <p class="desc"><?= htmlspecialchars($err) ?></p>
        <a class="back" href="./">返回首页</a>
    <?php else: ?>
        <p class="desc">站长正在为您创建写作者账号，请完成邮箱验证：</p>
        <div class="email-tag"><?= htmlspecialchars($email) ?></div>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
            <input type="text" name="code" maxlength="6" placeholder="6 位验证码" required>
            <button type="submit" id="submitBtn">验证</button>
        </form>
        <div class="err" id="errBox"></div>
    <?php endif; ?>
</div>
<script>
    var btn = document.getElementById('submitBtn');
    var form = btn ? btn.form : null;
    if (form) form.addEventListener('submit', function() {
        btn.disabled = true;
        btn.textContent = '验证中...';
    });
</script>
</body>
</html>
