<?php
/**
 * 个人详情页（v2.10.0）。
 * 评论区点击头像/昵称进入；展示头像/昵称/签名/注册时间。
 * 排除超管：超管无公开详情页（404）；用户不存在同样 404。
 */
session_start();
require_once __DIR__ . '/utils.php';

$id = trim($_GET['id'] ?? '');
$u = get_public_user($id);
$siteConfig = loadSiteConfig();
$siteTitle = $siteConfig['site_title'] ?? 'You Super Markdown';

if (!$u) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>404 - ' . htmlspecialchars($siteTitle) . '</title><link rel="stylesheet" href="css/style.css?v=' . @filemtime(__DIR__ . '/css/style.css') . '"></head>';
    echo '<body style="background:var(--bg)"><div style="min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;padding:24px"><div style="font-size:64px;font-weight:800;color:var(--accent)">404</div><div style="color:var(--text-muted)">用户不存在或无权查看</div><a href="./" style="color:var(--accent);text-decoration:none">← 返回主页</a></div></body></html>';
    exit;
}

$avatarSrc = ($u['avatar'] !== '' && strpos($u['avatar'], 'data/') === 0)
    ? $u['avatar']
    : ($u['avatar'] !== '' ? $u['avatar'] : 'api.php?action=avatar&qq=' . urlencode($u['qq']));
$roleLabels = [ROLE_USER => '普通用户', ROLE_AUTHOR => '写作者', ROLE_STATION_ADMIN => '站长', ROLE_GUEST => '访客'];
$roleLabel = $roleLabels[$u['role']] ?? '用户';
$roleBadgeClass = $u['role'] === ROLE_STATION_ADMIN ? 'up-badge-station'
    : ($u['role'] === ROLE_AUTHOR ? 'up-badge-author' : '');
$initial = mb_substr($u['nickname'] ?: '?', 0, 1, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($u['nickname']) ?> - 个人主页 - <?= htmlspecialchars($siteTitle) ?></title>
    <link rel="stylesheet" href="css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>">
    <style>
        body { background: var(--bg); }
        .up-wrap { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px; }
        .up-card {
            width: 100%; max-width: 440px;
            background: var(--card-bg, #fff);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 36px 32px;
            text-align: center;
            box-shadow: 0 12px 40px rgba(0,0,0,.08);
        }
        .up-avatar {
            width: 96px; height: 96px;
            margin: 0 auto 16px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--accent-light);
            border: 3px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            font-size: 38px; font-weight: 700; color: var(--accent);
        }
        .up-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .up-name { font-size: 22px; font-weight: 700; color: var(--text); display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap; }
        .up-badge {
            display: inline-block; padding: 2px 10px; border-radius: 999px;
            font-size: 12px; font-weight: 600; color: #fff; background: var(--accent);
        }
        .up-badge-author { background: #3b82f6; }
        .up-badge-station { background: #f59e0b; }
        .up-sign {
            margin: 14px 0 18px; color: var(--text-muted); font-size: 14px;
            min-height: 20px; line-height: 1.6;
        }
        .up-meta {
            border-top: 1px dashed var(--border); padding-top: 16px;
            display: flex; justify-content: center; gap: 24px;
            color: var(--text-muted); font-size: 13px;
        }
        .up-meta b { color: var(--text); font-weight: 600; }
        .up-back { display: inline-block; margin-top: 20px; color: var(--accent); text-decoration: none; font-size: 14px; }
        .up-back:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="up-wrap">
    <div class="up-card">
        <div class="up-avatar">
            <?php if ($avatarSrc): ?><img src="<?= htmlspecialchars($avatarSrc) ?>" alt="" onerror="this.style.display='none';this.parentNode.textContent='<?= htmlspecialchars($initial) ?>'"><?php endif; ?>
        </div>
        <div class="up-name"><?= htmlspecialchars($u['nickname']) ?><span class="up-badge <?= $roleBadgeClass ?>"><?= htmlspecialchars($roleLabel) ?></span></div>
        <div class="up-sign"><?= htmlspecialchars($u['signature'] ?: '这个人很懒，还没有留下签名~') ?></div>
        <div class="up-meta">
            <div><b><?= htmlspecialchars(substr($u['qq'] ?: '--', 0, 3)) ?>***</b><br>QQ</div>
            <div><b><?= htmlspecialchars($u['created'] ?: '--') ?></b><br>注册时间</div>
        </div>
        <a class="up-back" href="./">← 返回主页</a>
    </div>
</div>
</body>
</html>
