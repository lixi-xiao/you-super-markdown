<?php
require_once __DIR__ . '/utils.php';

$siteCfg = loadSiteConfig();
$title = $siteCfg['site_title'] ?? 'You Super Markdown';
$token = $_GET['token'] ?? '';
$row = get_pending_author_by_token($token);
$result = '';
$okResult = false;

if (!$row) {
    $result = '该确认链接无效、已使用或已过期。';
} else {
    $ttl = max(300, (int)($siteCfg['confirm_link_ttl'] ?? 86400));
    if (time() - (int)$row['created'] > $ttl) {
        update_pending_author_status($row['id'], 'expired');
        $result = '该确认链接已过期（链接自发起后 ' . intdiv($ttl, 3600) . ' 小时内有效），请联系站长重新发起。';
    } else {
        if (create_author_from_pending($row)) {
            update_pending_author_status($row['id'], 'confirmed');
            auditLog('author_confirm', $row['qq'] ?? '', "超管确认创建写作者: {$row['nickname']}");
            $result = '确认成功！写作者账号已创建，站长可通知其登录。';
            $okResult = true;
        } else {
            update_pending_author_status($row['id'], 'rejected');
            $result = '创建失败：该 QQ 号或邮箱已被其他账号占用，请与站长联系处理。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>超管确认 - <?= htmlspecialchars($title) ?></title>
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
    .icon {
        width: 56px; height: 56px;
        margin: 0 auto 16px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 26px;
        color: #fff;
    }
    .icon.ok { background: #1e8449; }
    .icon.fail { background: #c0392b; }
    .brand { font-size: 20px; font-weight: 700; color: #1f3a5f; margin-bottom: 10px; }
    .result { font-size: 15px; line-height: 1.7; margin-bottom: 6px; }
    .result.ok { color: #1e8449; font-weight: 600; }
    .result.fail { color: #c0392b; }
    .back {
        display: inline-block;
        margin-top: 20px;
        padding: 10px 24px;
        border-radius: 10px;
        background: #1f3a5f;
        color: #fff;
        font-size: 14px;
        text-decoration: none;
    }
    .back:hover { background: #2a4a75; }
</style>
</head>
<body>
<div class="card">
    <div class="icon <?= $okResult ? 'ok' : 'fail' ?>"><?= $okResult ? '✓' : '!' ?></div>
    <div class="brand"><?= htmlspecialchars($title) ?></div>
    <div class="result <?= $okResult ? 'ok' : 'fail' ?>"><?= htmlspecialchars($result) ?></div>
    <a class="back" href="./">返回首页</a>
</div>
</body>
</html>
