<?php
require_once __DIR__ . '/utils.php';
secureSessionStart();
header('Content-Type: application/json; charset=utf-8');

// v3.0.8 统一安全入口：扫描器 UA 黑名单检测（命中返回 403 + 记录 + 封禁来源 IP）
security_check();

// v3.3.11：公告为单向通知，禁止评论——公告关联文章（当前即「更新历史.md」）的评论读写全部拦截
function isAnnouncementArticle($article) {
    static $set = null;
    if ($set === null) {
        $set = [];
        foreach (getAnnouncements() as $a) {
            if (!empty($a['article'])) $set[$a['article']] = true;
        }
    }
    return isset($set[$article]);
}

// 评论树组装：把评论表（parent_id 自关联）还原为嵌套结构（前端零改动）
// $sanitize=true 时对外脱敏：qq 置空、avatar 若为 QQ 头像 URL（含 qq 号）也置空，
// 防止 ?action=get&article=<任意> 批量枚举评论者的真实 QQ 号（v2.6.2）
function buildCommentTree($rows, $sanitize = false) {
    $map = [];
    foreach ($rows as $r) {
        $avatar = $r['avatar'] ?? '';
        if ($sanitize && strpos((string)$avatar, 'qlogo') !== false) $avatar = '';
        $map[$r['id']] = [
            'id' => $r['id'],
            'user_id' => $r['user_id'],
            'qq' => $sanitize ? '' : $r['qq'],
            'nickname' => $r['nickname'],
            'avatar' => $avatar,
            'signature' => $r['signature'],
            'content' => $r['content'],
            'likes' => (int)$r['likes'],
            'replies' => [],
            'created_at' => $r['created_at'],
        ];
    }
    $roots = [];
    foreach ($rows as $r) {
        if (!empty($r['parent_id']) && isset($map[$r['parent_id']])) {
            $map[$r['parent_id']]['replies'][] = $map[$r['id']];
        } else {
            $roots[] = $map[$r['id']];
        }
    }
    return $roots;
}

// 递归展开嵌套评论为扁平行（供写库）
function flattenComments($comments, $article, $parentId, &$out) {
    foreach ($comments as $c) {
        $out[] = [
            'id' => $c['id'] ?? bin2hex(random_bytes(8)),
            'article' => $article,
            'parent_id' => $parentId,
            'user_id' => $c['user_id'] ?? '',
            'qq' => $c['qq'] ?? '',
            'nickname' => $c['nickname'] ?? '',
            'avatar' => $c['avatar'] ?? '',
            'signature' => $c['signature'] ?? '',
            'content' => $c['content'] ?? '',
            'likes' => (int)($c['likes'] ?? 0),
            'created_at' => $c['created_at'] ?? '',
        ];
        if (!empty($c['replies']) && is_array($c['replies'])) {
            flattenComments($c['replies'], $article, $out[count($out) - 1]['id'], $out);
        }
    }
}

/**
 * v4.7.0：完成登录会话（login 与 device_verify 共用）。
 * 设置 session + tv+1 并发踢旧 + 环境指纹绑定 + 签发 refresh + 记录日志/通知。
 */
function completeLogin($u, $reqFp) {
    session_regenerate_id(true);
    $avatar = $u['avatar'] ?? getAvatarUrl($u['qq'] ?? '');
    $_SESSION['cmt_user'] = [
        'id' => $u['id'], 'qq' => $u['qq'],
        'nickname' => $u['nickname'] ?? '',
        'avatar' => $avatar,
        'signature' => $u['signature'] ?? '',
        'role' => $u['role'] ?? 'user',
        'email' => $u['email'] ?? '',
        'pw_hash' => $u['password'],
    ];
    $newTV = bumpUserTV($u['id']);
    $_SESSION['cmt_fp'] = computeSessionFp($reqFp);
    $_SESSION['cmt_tv'] = $newTV;
    $_SESSION['cmt_login_ts'] = time();
    if (($u['role'] ?? '') !== ROLE_SUPER_ADMIN) {
        issueRefreshToken($u['id'], $_SESSION['cmt_fp'], $newTV);
    }
    $clientIP = getClientIP();
    loginFailClear($clientIP, $u['qq'] ?? '');
    db_exec('UPDATE users SET last_login = ?, login_count = login_count + 1 WHERE id = ?', [date('Y-m-d H:i:s'), $u['id']]);
    notifyLoginEvent($u, $clientIP);
    $safeUser = sanitizeUserForClient($_SESSION['cmt_user']);
    return ['success' => true, 'user' => $safeUser,
        'isAdminFirstLogin' => in_array($u['role'] ?? '', [ROLE_SUPER_ADMIN, ROLE_STATION_ADMIN]),
        'env_bound' => true];
}

function loadComments($article, $sanitize = false) {
    $rows = db_all('SELECT * FROM comments WHERE article = ? ORDER BY rowid', [$article]);
    return buildCommentTree($rows, $sanitize);
}
function saveComments($article, $comments) {
    $flat = [];
    flattenComments($comments, $article, null, $flat);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_exec('DELETE FROM comments WHERE article = ?', [$article]);
        $st = $pdo->prepare('INSERT INTO comments (id, article, parent_id, user_id, qq, nickname, avatar, signature, content, likes, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($flat as $r) {
            $st->execute([$r['id'], $r['article'], $r['parent_id'], $r['user_id'], $r['qq'], $r['nickname'], $r['avatar'], $r['signature'], $r['content'], $r['likes'], $r['created_at']]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
function getUser() { return empty($_SESSION['cmt_user']) ? null : $_SESSION['cmt_user']; }
function validateSession() {
    if (empty($_SESSION['cmt_user'])) return null;
    $sess = $_SESSION['cmt_user'];
    $users = loadUsers();
    foreach ($users as $u) {
        if ($u['id'] === ($sess['id'] ?? '')) {
            if (($u['password'] ?? '') !== ($sess['pw_hash'] ?? '')) {
                session_unset();
                session_destroy();
                return null;
            }
            $_SESSION['cmt_user']['role'] = $u['role'] ?? 'user';
            return $_SESSION['cmt_user'];
        }
    }
    session_unset();
    session_destroy();
    return null;
}
// v2.6.1：主页视角的登录用户 —— 超管彻底分离（OTP 入口登录的系统级角色在主页隐身，
// 主页 check/user-status/评论一律按未登录处理；后台鉴权不受影响，超管后台仍走 validateSession/JWT）
function validateHomeUser() {
    $u = validateSession();
    if (!$u) return null;
    if (($u['role'] ?? '') === ROLE_SUPER_ADMIN) return null;
    // v2.11.4：被禁用账号主页按未登录处理（隐身 + 不能评论/绑定设备）
    foreach (loadUsers() as $lu) {
        if ($lu['id'] === ($u['id'] ?? '') && !empty($lu['disabled'])) return null;
    }
    return $u;
}
function jsonOut($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getAvatarUrl($qq) {
    return 'https://q1.qlogo.cn/g?b=qq&nk=' . urlencode($qq) . '&s=100';
}
function addReplyRecursive(&$replies, $parentId, $reply) {
    foreach ($replies as &$r) {
        if ($r['id'] === $parentId) {
            if (!isset($r['replies'])) $r['replies'] = [];
            $r['replies'][] = $reply;
            return true;
        }
        if (!empty($r['replies'])) {
            if (addReplyRecursive($r['replies'], $parentId, $reply)) return true;
        }
    }
    return false;
}
function delReplyRecursive(&$replies, $delId, $userId, $isAdmin) {
    foreach ($replies as $i => $r) {
        if ($r['id'] === $delId && ($isAdmin || $r['user_id'] === $userId)) {
            array_splice($replies, $i, 1);
            return true;
        }
        if (!empty($r['replies'])) {
            if (delReplyRecursive($r['replies'], $delId, $userId, $isAdmin)) return true;
        }
    }
    return false;
}
// v4.0.0：递归查找评论昵称（回复邮件通知带出被回复者；找不到返回 null）
function findCommentNickname($replies, $findId) {
    foreach ($replies as $r) {
        if ($r['id'] === $findId) return $r['nickname'] ?? '';
        if (!empty($r['replies'])) {
            $n = findCommentNickname($r['replies'], $findId);
            if ($n !== null) return $n;
        }
    }
    return null;
}
// CSRF 防护：所有 POST 请求需携带有效 X-CSRF-Token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        jsonOut(['success' => false, 'error' => 'CSRF 校验失败'], 403);
    }
}
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ===== v2.11.0：滑块人机验证已彻底移除（原 captcha_new 分支删除） =====

// ===== v2.11.1：动态 CSRF token 获取（修复「会话失败」根因） =====
// 登录/注册等匿名前置操作在提交前先取本接口的 token（同一请求链内 cookie 与 token
// 必然同 session），解决浏览器未携带 PHPSESSID / session 过期导致的 token 不匹配
if ($action === 'csrf') {
    jsonOut(['success' => true, 'csrf_token' => generateCsrfToken()]);
}

// v4.5.0：后台环境指纹上报校验——后台页面加载后 JS 调用（fetch 带 X-Fp），
// 服务端比对会话绑定指纹：一致置 cmt_fp_ok（后台原生表单 POST 据此放行写操作），不一致清除（换环境拦截）
if ($action === 'fp_report') {
    $fpOk = requireSessionEnv();
    if ($fpOk) {
        $_SESSION['cmt_fp_ok'] = 1;
    } else {
        unset($_SESSION['cmt_fp_ok']);
    }
    jsonOut(['success' => $fpOk]);
}

// ===== v2.9.0：注册邮箱验证码发送（60s 冷却，按邮箱；注册为匿名，不走超管豁免） =====
// v4.4.0：发送前必须先通过随机算术人机验证（点击「获取验证码」→ 弹窗答题 → 答对才真正发码）
if ($action === 'arith_challenge') {
    jsonOut(['success' => true, 'expression' => genArithChallenge()]);
}

if ($action === 'send_register_code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteCfg = loadSiteConfig();
    // v4.7.1：联动封锁拦截（与 login/找回一致，堵住绕过路径）
    $linkLeft = checkLinkedBlock();
    if ($linkLeft > 0) jsonOut(['success' => false, 'error' => '触发联动风控，请 ' . $linkLeft . ' 秒后再试', 'locked_seconds' => $linkLeft], 429);
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    // v4.4.0：算术人机验证——答错/未答一律拒绝发码（一次性消费，重放无效）
    [$arithOk, $arithErr] = verifyArithChallenge($input['arith_answer'] ?? '');
    if (!$arithOk) jsonOut(['success' => false, 'error' => $arithErr], 400);
    // v4.5.0：指纹+IP 双维发码限速（60 秒 ≥5 次，防换 IP/换邮箱轰炸）；无指纹请求按 no-fp 维度计
    $ipNow = getClientIP();
    $reqFp = getRequestFp();
    if (db_rate_count('reg_rates', $ipNow, 60, $reqFp) >= 5) {
        logThreat('reg_flood', $ipNow, $reqFp, 300);
        jsonOut(['success' => false, 'error' => '发送过于频繁，请稍后再试'], 429);
    }
    if (email_exists($email)) jsonOut(['success' => false, 'error' => '该邮箱已被注册'], 409);
    [$ok, $err] = email_code_send($email, 'register', $email);
    if (!$ok) jsonOut(['success' => false, 'error' => $err], 400);
    db_rate_add('reg_rates', $ipNow, $reqFp);
    jsonOut(['success' => true, 'ttl' => is_array($err) ? ($err['ttl'] ?? 300) : 300]);
}

if ($action === 'avatar') {
    // v2.6.3：收紧——仅登录用户可用，防止未登录批量探测 QQ 号（配合评论脱敏，前台已无匿名头像需求）
    if (!validateSession()) jsonOut(['success' => false, 'error' => '请先登录'], 403);
    $qq = trim($_GET['qq'] ?? '');
    if (empty($qq)) jsonOut(['success' => false, 'error' => '缺少QQ号'], 400);
    $url = getAvatarUrl($qq);
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'method' => 'GET']]);
    $img = @file_get_contents($url, false, $ctx);
    if ($img !== false && strlen($img) > 100) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        echo $img;
    } else {
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=86400');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="#8b95a5"/><text x="50" y="58" text-anchor="middle" fill="#fff" font-size="40" font-family="sans-serif">' . htmlspecialchars(mb_substr($qq, 0, 1, 'UTF-8')) . '</text></svg>';
    }
    exit;
}
// ===== v2.9.0：站长创建写作者——发送验证码到写作者邮箱（需站长登录 + CSRF；v2.11.0 起滑块已移除） =====
if ($action === 'send_author_code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkRole(ROLE_STATION_ADMIN)) jsonOut(['success' => false, 'error' => '无权限'], 403);
    if (!verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) jsonOut(['success' => false, 'error' => 'CSRF 校验失败'], 403);
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $cfg = loadSiteConfig();
    if (!email_valid($email)) jsonOut(['success' => false, 'error' => '邮箱格式不正确'], 400);
    $ipNow = getClientIP();
    $recent = db_one('SELECT COUNT(*) AS c FROM email_codes WHERE ip = ? AND created > ?', [$ipNow, time() - 60])['c'] ?? 0;
    if ($recent >= 5) jsonOut(['success' => false, 'error' => '发送过于频繁，请稍后再试'], 429);
    if (email_exists($email)) jsonOut(['success' => false, 'error' => '该邮箱已被使用'], 409);
    [$ok, $err] = email_code_send($email, 'author_verify', '站长创建写作者', ROLE_STATION_ADMIN);
    if (!$ok) jsonOut(['success' => false, 'error' => $err], 400);
    jsonOut(['success' => true, 'ttl' => is_array($err) ? ($err['ttl'] ?? 300) : 300]);
}

// ===== v2.10.0：更换绑定邮箱——发送验证码（登录 + CSRF；受 email_verify_enabled 开关控制，关闭即禁用） =====
if ($action === 'send_email_change_code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = validateHomeUser();
    if (!$u) jsonOut(['success' => false, 'error' => '请先登录'], 401);
    if (!checkCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) jsonOut(['success' => false, 'error' => 'CSRF 校验失败'], 403);
    // v4.5.0：环境校验（换环境/被踢 → 拒绝敏感操作）
    if (!requireSessionEnv()) jsonOut(['success' => false, 'error' => '登录环境已变化，请重新登录', 'env_invalid' => true], 401);
    $siteCfg = loadSiteConfig();
    if (empty($siteCfg['email_verify_enabled'])) jsonOut(['success' => false, 'error' => '邮箱验证已关闭'], 403);
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    if (!email_valid($email)) jsonOut(['success' => false, 'error' => '邮箱格式不正确'], 400);
    if (email_exists($email)) jsonOut(['success' => false, 'error' => '该邮箱已被其他账号使用'], 409);
    $ipNow = getClientIP();
    $recent = db_one('SELECT COUNT(*) AS c FROM email_codes WHERE ip = ? AND created > ?', [$ipNow, time() - 60])['c'] ?? 0;
    if ($recent >= 5) jsonOut(['success' => false, 'error' => '发送过于频繁，请稍后再试'], 429);
    [$ok, $err] = email_code_send($email, 'email_change', '更换绑定邮箱', $u['role'] ?? '');
    if (!$ok) jsonOut(['success' => false, 'error' => $err], 400);
    jsonOut(['success' => true, 'ttl' => is_array($err) ? ($err['ttl'] ?? 300) : 300]);
}

// ===== v2.10.0：更换绑定邮箱——验证码原子确认（登录 + CSRF） =====
if ($action === 'update_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = validateHomeUser();
    if (!$u) jsonOut(['success' => false, 'error' => '请先登录'], 401);
    if (!checkCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) jsonOut(['success' => false, 'error' => 'CSRF 校验失败'], 403);
    // v4.5.0：环境校验（换环境/被踢 → 拒绝敏感操作）
    if (!requireSessionEnv()) jsonOut(['success' => false, 'error' => '登录环境已变化，请重新登录', 'env_invalid' => true], 401);
    $siteCfg = loadSiteConfig();
    if (empty($siteCfg['email_verify_enabled'])) jsonOut(['success' => false, 'error' => '邮箱验证已关闭'], 403);
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $code = trim($input['code'] ?? '');
    if (!email_valid($email)) jsonOut(['success' => false, 'error' => '邮箱格式不正确'], 400);
    if (email_exists($email)) jsonOut(['success' => false, 'error' => '该邮箱已被其他账号使用'], 409);
    [$ok, $verr] = email_code_verify($email, $code, 'email_change');
    if (!$ok) jsonOut(['success' => false, 'error' => $verr], 400);
    $users = loadUsers();
    foreach ($users as &$usr) {
        if ($usr['id'] === $u['id']) { $usr['email'] = $email; break; }
    }
    unset($usr);
    saveUsers($users);
    $_SESSION['cmt_user']['email'] = $email;
    auditLog('email_change', $u['id'], '更换绑定邮箱为 ' . $email);
    jsonOut(['success' => true, 'email' => $email]);
}

// ===== v2.10.0：头像上传（登录 + CSRF；multipart/form-data，字段名 avatar） =====
if ($action === 'avatar_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = validateHomeUser();
    if (!$u) jsonOut(['success' => false, 'error' => '请先登录'], 401);
    if (!checkCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) jsonOut(['success' => false, 'error' => 'CSRF 校验失败'], 403);
    // v4.5.0：环境校验（换环境/被踢 → 拒绝敏感操作）
    if (!requireSessionEnv()) jsonOut(['success' => false, 'error' => '登录环境已变化，请重新登录', 'env_invalid' => true], 401);
    [$ok, $res] = avatar_upload($u['id'], $_FILES['avatar'] ?? null);
    if (!$ok) jsonOut(['success' => false, 'error' => $res], 400);
    auditLog('avatar_update', $u['id'], '更新头像');
    jsonOut(['success' => true, 'avatar' => $res]);
}

// ===== v3.1.6：文章图片上传（站长/写作者 + CSRF；multipart/form-data，字段名 image；≤5MB） =====
if ($action === 'article_image_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 写作者与站长均可上传文章配图
    if (!checkRole(ROLE_AUTHOR)) jsonOut(['success' => false, 'error' => '无权限'], 403);
    if (!checkCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) jsonOut(['success' => false, 'error' => 'CSRF 校验失败'], 403);
    $file = $_FILES['image'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonOut(['success' => false, 'error' => '未收到文件'], 400);
    }
    if ($file['size'] <= 0 || $file['size'] > 5 * 1024 * 1024) {
        jsonOut(['success' => false, 'error' => '图片大小需在 5MB 以内'], 400);
    }
    // MIME 白名单：getimagesize 实测 + 扩展名双重校验（防伪装文件）
    $extMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info['mime'], array_values($extMap), true)) {
        jsonOut(['success' => false, 'error' => '仅支持 JPG/PNG/GIF/WebP 图片'], 400);
    }
    $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($extMap[$origExt])) $origExt = array_search($info['mime'], $extMap) ?: 'png';
    // 内容必须为图片（getimagesize 返回宽高 > 0）
    if ($info[0] <= 0 || $info[1] <= 0) jsonOut(['success' => false, 'error' => '图片文件无效'], 400);
    $dir = __DIR__ . '/data/images/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fname = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $origExt;
    if (!move_uploaded_file($file['tmp_name'], $dir . $fname)) {
        jsonOut(['success' => false, 'error' => '保存失败，请检查 data/images/ 目录权限'], 500);
    }
    auditLog('article_image_upload', $fname, '上传文章图片');
    jsonOut(['success' => true, 'url' => 'data/images/' . $fname]);
}

// ===== v3.3.0：文章视频上传（站长/写作者 + CSRF；multipart/form-data，字段名 video；≤20MB） =====
// 合法性强制校验：扩展名白名单 → finfo MIME → 容器结构（ftyp box / EBML 魔数）三重校验
if ($action === 'article_video_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkRole(ROLE_AUTHOR)) jsonOut(['success' => false, 'error' => '无权限'], 403);
    if (!checkCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) jsonOut(['success' => false, 'error' => 'CSRF 校验失败'], 403);
    $file = $_FILES['video'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonOut(['success' => false, 'error' => '未收到文件'], 400);
    }
    if ($file['size'] <= 0 || $file['size'] > 20 * 1024 * 1024) {
        jsonOut(['success' => false, 'error' => '视频大小需在 20MB 以内'], 400);
    }
    $extMap = ['mp4' => 'video/mp4', 'webm' => 'video/webm'];
    $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($extMap[$origExt])) {
        jsonOut(['success' => false, 'error' => '仅支持 MP4/WebM 视频'], 400);
    }
    // 强制校验：内容与声明格式一致、容器结构合法（防伪装/损坏文件）
    if (!validateVideoFile($file['tmp_name'], $origExt)) {
        jsonOut(['success' => false, 'error' => '视频文件校验失败（内容与声明格式不符或文件损坏）'], 400);
    }
    $dir = __DIR__ . '/data/videos/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fname = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $origExt;
    if (!move_uploaded_file($file['tmp_name'], $dir . $fname)) {
        jsonOut(['success' => false, 'error' => '保存失败，请检查 data/videos/ 目录权限'], 500);
    }
    auditLog('article_video_upload', $fname, '上传文章视频');
    jsonOut(['success' => true, 'url' => 'data/videos/' . $fname]);
}

if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteCfg = loadSiteConfig();
    if (empty($siteCfg['registration_enabled'])) {
        jsonOut(['success' => false, 'error' => '注册已关闭'], 403);
    }
    $clientIP = getClientIP();
    if (isIPBanned($clientIP, 'register')) jsonOut(['success' => false, 'error' => '你的 IP 已被封禁，无法注册'], 403);
    // v4.7.1：联动封锁拦截（bans type=link + link:ip/fp 登录锁，堵住 register 绕过联动封锁路径）
    $linkLeft = checkLinkedBlock();
    if ($linkLeft > 0) jsonOut(['success' => false, 'error' => '触发联动风控，请 ' . $linkLeft . ' 秒后再试', 'locked_seconds' => $linkLeft], 429);
    $regLimit = max(1, intval($siteCfg['max_registrations_per_ip'] ?? $siteCfg['reg_limit_per_ip'] ?? 3));
    $ipRegs = db_rate_count('reg_rates', $clientIP, 2592000); // 30 天累计
    if ($ipRegs >= $regLimit) {
        logAbnormal($clientIP, '频繁注册（累计' . $ipRegs . '次，限制' . $regLimit . '次）');
        logThreat('reg_flood', $clientIP, getRequestFp(), 300);
        if ($siteCfg['auto_ban'] ?? false) addBan($clientIP, ['register'], '自动封禁：频繁注册');
        jsonOut(['success' => false, 'error' => '注册次数已达上限'], 429);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $reqFp = getRequestFp();
    // v4.4.0：蜜罐字段——隐藏输入框仅机器人会填；命中即判定机器人，静默拒绝（返回成功但不注册，浪费其时间）
    if (!empty($input['website'])) {
        auditLog('register_honeypot', substr((string)$input['website'], 0, 64), '注册蜜罐命中，静默拒绝（IP ' . $clientIP . '）');
        // v4.4.0/v4.5.0：短时间（10 分钟窗口）连续命中达阈值 → 自动封禁 IP（指纹+IP 双维计数）
        db_rate_add('honeypot_rates', $clientIP, $reqFp);
        logThreat('honeypot', $clientIP, $reqFp, 300);
        $hpCount = db_rate_count('honeypot_rates', $clientIP, 600, $reqFp);
        $hpThreshold = max(1, (int)($siteCfg['honeypot_ban_count'] ?? 3));
        if ($hpCount >= $hpThreshold && !isIPBanned($clientIP, 'register')) {
            $hpDuration = max(0, (int)($siteCfg['honeypot_ban_duration'] ?? 3600));
            addBan($clientIP, ['register'], '注册蜜罐连续命中自动封禁', $hpDuration);
            auditLog('honeypot_auto_ban', $clientIP, '注册蜜罐 ' . $hpCount . ' 次命中，自动封禁' . ($hpDuration > 0 ? ($hpDuration . ' 秒') : '（永久）'), 'banned');
        }
        jsonOut(['success' => true, 'user' => null]);
    }
    $qq = trim($input['qq'] ?? '');
    $nick = trim($input['nickname'] ?? '');
    $pw = $input['password'] ?? '';
    $email = trim($input['email'] ?? '');
    $code = $input['code'] ?? '';
    if (empty($qq) || empty($pw)) jsonOut(['success' => false, 'error' => 'QQ号和密码不能为空'], 400);
    $vp = validatePassword($pw);
    if ($vp !== true) jsonOut(['success' => false, 'error' => $vp], 400);
    if (empty($nick)) $nick = '用户' . substr($qq, -4);
    $nick = mb_substr($nick, 0, 20, 'UTF-8');
    // v2.11.0：滑块人机验证已彻底移除
    // v2.9.0 邮箱验证码（开关启用时：邮箱格式 + 唯一 + 验证码一次性校验）
    if (!empty($siteCfg['email_verify_enabled'])) {
        if (!email_valid($email)) jsonOut(['success' => false, 'error' => '邮箱格式不正确'], 400);
        if (email_exists($email)) jsonOut(['success' => false, 'error' => '该邮箱已被注册'], 409);
        [$ok, $verr] = email_code_verify($email, $code, 'register');
        if (!$ok) jsonOut(['success' => false, 'error' => $verr], 400);
    }
    $users = loadUsers();
    foreach ($users as $u) { if (($u['qq'] ?? '') === $qq) jsonOut(['success' => false, 'error' => '该QQ号已注册'], 409); }
    $avatarUrl = getAvatarUrl($qq);
    $new = [
        'id' => genId(), 'qq' => $qq, 'nickname' => $nick,
        'email' => $email,
        'password' => password_hash($pw, PASSWORD_DEFAULT),
        'avatar' => $avatarUrl, 'signature' => '', 'role' => 'user',
        'created' => date('Y-m-d H:i:s')
    ];
    $users[] = $new;
    saveUsers($users);
    db_rate_add('reg_rates', $clientIP, $reqFp);
    session_regenerate_id(true);
    // v4.5.0：注册即绑定环境指纹 + token_version（并发踢旧）+ 签发 refresh token
    $newTV = bumpUserTV($new['id']);
    $_SESSION['cmt_fp'] = computeSessionFp($reqFp);
    $_SESSION['cmt_tv'] = $newTV;
    // v4.6.0：记录登录时间（后台 24h 过期按登录时间算）
    $_SESSION['cmt_login_ts'] = time();
    issueRefreshToken($new['id'], $_SESSION['cmt_fp'], $newTV);
    $_SESSION['cmt_user'] = [
        'id' => $new['id'], 'qq' => $qq, 'nickname' => $nick,
        'avatar' => $avatarUrl, 'signature' => '', 'role' => 'user',
        'email' => $email,   // v2.10.0：注册即写邮箱，供个人设置展示/更换
        'pw_hash' => $new['password']
    ];
    $safeUser = sanitizeUserForClient($_SESSION['cmt_user']);
    jsonOut(['success' => true, 'user' => $safeUser, 'env_bound' => true]);
}
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientIP = getClientIP();
    if (isIPBanned($clientIP, 'login')) jsonOut(['success' => false, 'error' => '你的 IP 已被封禁，无法登录'], 403);
    // v4.7.0：联动威胁评分拦截（IP/指纹任一维度累计超阈值 → 联动封锁）
    $linkLeft = checkLinkedBlock();
    if ($linkLeft > 0) jsonOut(['success' => false, 'error' => '触发联动风控，请 ' . $linkLeft . ' 秒后再试', 'locked_seconds' => $linkLeft], 429);
    $input = json_decode(file_get_contents('php://input'), true);
    $qq = trim($input['qq'] ?? '');
    $pw = $input['password'] ?? '';
    // v4.5.0：登录环境指纹（前端上报，服务端与 UA 一起绑定签名）
    $reqFp = getRequestFp();
    if (empty($qq) || empty($pw)) jsonOut(['success' => false, 'error' => 'QQ号和密码不能为空'], 400);
    // v2.11.0：登录锁定检查（IP+账号双级；60 秒内失败 ≥3 次 → 锁 15 分钟）
    $lockLeft = loginLocked('ip:' . $clientIP);
    if ($lockLeft <= 0) $lockLeft = loginLocked('qq:' . $qq);
    if ($lockLeft > 0) {
        // v4.7.0：锁定期仍尝试 → 威胁计分（逐步升级联动封锁）
        logThreat('login_locked_try', $clientIP, $reqFp, 60);
        jsonOut(['success' => false, 'error' => '登录失败次数过多，请 ' . $lockLeft . ' 秒后重试', 'locked_seconds' => $lockLeft], 429);
    }
    $users = loadUsers();
    foreach ($users as $u) {
        if (($u['qq'] ?? '') === $qq && password_verify($pw, $u['password'])) {
            // v2.11.4：被禁用账号拒绝登录
            if (!empty($u['disabled'])) jsonOut(['success' => false, 'error' => '该账号已被禁用，请联系管理员'], 403);
            // v4.7.0：管理角色陌生设备 → 邮件二次验证（密码通过后仍需验证码才完成登录）
            $isMgmt = in_array($u['role'] ?? '', [ROLE_SUPER_ADMIN, ROLE_STATION_ADMIN, ROLE_AUTHOR], true);
            $fpHash = computeSessionFp($reqFp);
            if ($isMgmt && !isKnownDevice($u['id'], $fpHash)) {
                // 该设备正被陌生设备风控锁定 → 拒绝
                $fpLockLeft = loginLocked('fp:' . $fpHash);
                if ($fpLockLeft > 0) jsonOut(['success' => false, 'error' => '该设备触发风控，请 ' . $fpLockLeft . ' 秒后重试'], 429);
                $_SESSION['cmt_pending_dev'] = [
                    'uid' => $u['id'], 'fp_hash' => $fpHash, 'fp' => $reqFp,
                    'email' => $u['email'] ?? '', 'role' => $u['role'] ?? '',
                ];
                $_SESSION['dev_verify_fails'] = 0;
                [$devOk, $devErr] = email_code_send($u['email'] ?? '', 'device_login', '陌生设备登录验证', $u['role'] ?? '');
                if (!$devOk) jsonOut(['success' => false, 'error' => $devErr], 400);
                auditLog('login_device_pending', $u['id'], '陌生设备登录待二次验证（' . maskEmailAddr($u['email'] ?? '') . '）');
                jsonOut(['success' => false, 'need_device_verify' => true,
                    'masked_email' => maskEmailAddr($u['email'] ?? ''),
                    'ttl' => is_array($devErr) ? ($devErr['ttl'] ?? 300) : 300,
                    'error' => '新设备登录，验证码已发送至 ' . maskEmailAddr($u['email'] ?? '')], 200);
            }
            jsonOut(completeLogin($u, $reqFp));
        }
    }
    // v2.11.0/v4.5.0：失败计数（IP+账号+指纹三维，60 秒窗口 ≥3 → 锁 15 分钟）
    loginFailAdd($clientIP, $qq, $reqFp);
    // v4.7.0：失败威胁计分——管理角色账号按「陌生设备失败」高权重（3 次即触发 15min 设备风控，逐次升级）
    $failUser = null;
    foreach ($users as $u) { if (($u['qq'] ?? '') === $qq) { $failUser = $u; break; } }
    $isMgmtTarget = $failUser !== null && in_array($failUser['role'] ?? '', [ROLE_SUPER_ADMIN, ROLE_STATION_ADMIN, ROLE_AUTHOR], true);
    if ($isMgmtTarget) {
        logThreat('device_login_fail', $clientIP, $reqFp, 30);
        if ($reqFp !== '') fpRiskLock(computeSessionFp($reqFp));
    } else {
        logThreat('login_fail', $clientIP, $reqFp, 30);
    }
    $ipFails = loginFailCount($clientIP, $qq, 60, $reqFp);
    $loginCfg = loadSiteConfig();
    if ($ipFails >= 3) {
        lockLogin('ip:' . $clientIP, 900);
        lockLogin('qq:' . $qq, 900);
        logThreat('login_lock', $clientIP, $reqFp, 60);
        logAbnormal($clientIP, '频繁错误登录（60秒内' . $ipFails . '次，已锁定15分钟）');
        if ($loginCfg['auto_ban'] ?? false) addBan($clientIP, ['login'], '自动封禁：频繁错误登录');
        loginFailClear($clientIP, $qq);
        jsonOut(['success' => false, 'error' => '登录失败次数过多，请 900 秒后重试', 'locked_seconds' => 900], 429);
    }
    jsonOut(['success' => false, 'error' => 'QQ号或密码错误'], 401);
}

// ===== v4.7.0：陌生设备登录邮件二次验证（验证码原子消费 → 记录设备 → 完成登录） =====
if ($action === 'device_verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $linkLeft = checkLinkedBlock();
    if ($linkLeft > 0) jsonOut(['success' => false, 'error' => '触发联动风控，请 ' . $linkLeft . ' 秒后再试'], 429);
    $input = json_decode(file_get_contents('php://input'), true);
    $code = trim($input['code'] ?? '');
    $pending = $_SESSION['cmt_pending_dev'] ?? null;
    if (!is_array($pending) || empty($pending['uid']) || empty($pending['email'])) jsonOut(['success' => false, 'error' => '验证会话已失效，请重新登录'], 400);
    // 重新加载用户（防等待验证码期间被禁用/删除）
    $users = loadUsers();
    $u = null;
    foreach ($users as $uu) { if ($uu['id'] === $pending['uid']) { $u = $uu; break; } }
    if (!$u) { unset($_SESSION['cmt_pending_dev']); jsonOut(['success' => false, 'error' => '账号不存在或已删除'], 400); }
    if (!empty($u['disabled'])) { unset($_SESSION['cmt_pending_dev']); jsonOut(['success' => false, 'error' => '该账号已被禁用'], 403); }
    [$ok, $verr] = email_code_verify($pending['email'], $code, 'device_login');
    if (!$ok) {
        // 验证码输错 → 威胁计分；连续错过多直接清 pending 防爆破
        logThreat('device_code_fail', getClientIP(), $pending['fp'] ?? '', 30);
        $fails = (int)($_SESSION['dev_verify_fails'] ?? 0) + 1;
        $_SESSION['dev_verify_fails'] = $fails;
        if ($fails >= 5) unset($_SESSION['cmt_pending_dev'], $_SESSION['dev_verify_fails']);
        jsonOut(['success' => false, 'error' => $verr, 'fails' => $fails], 400);
    }
    unset($_SESSION['dev_verify_fails']);
    // 验证通过：记录设备为已信任 → 完成登录
    recordDevice($u['id'], $pending['fp_hash'], $_SERVER['HTTP_USER_AGENT'] ?? '');
    unset($_SESSION['cmt_pending_dev']);
    auditLog('login_device_verified', $u['id'], '陌生设备验证通过，已加入信任设备');
    jsonOut(completeLogin($u, $pending['fp'] ?? ''));
}

// ===== v4.7.0：找回密码（首页弹窗；管理角色仅限常用设备自助找回，陌生设备联系超管；超管协助走 admin_reset 一次性重置码） =====
if ($action === 'password_reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $mode = $input['mode'] ?? '';
    $qq = trim($input['qq'] ?? '');
    $email = trim($input['email'] ?? '');
    $code = trim($input['code'] ?? '');
    $newPw = $input['new_password'] ?? '';
    $reqFp = getRequestFp();
    $clientIP = getClientIP();

    if ($mode === 'send_code') {
        $linkLeft = checkLinkedBlock();
        if ($linkLeft > 0) jsonOut(['success' => false, 'error' => '触发联动风控，请 ' . $linkLeft . ' 秒后再试'], 429);
        if (empty($qq) || empty($email)) jsonOut(['success' => false, 'error' => '请填写账号与绑定邮箱'], 400);
        // 发码限速（IP 60 秒 ≥5 次 → 计分）
        $recent = db_one('SELECT COUNT(*) AS c FROM email_codes WHERE ip = ? AND created > ?', [$clientIP, time() - 60])['c'] ?? 0;
        if ($recent >= 5) { logThreat('reset_flood', $clientIP, $reqFp, 300); jsonOut(['success' => false, 'error' => '发送过于频繁，请稍后再试'], 429); }
        // 账号+邮箱双重匹配（不暴露账号是否存在，统一提示）
        $users = loadUsers();
        $target = null;
        foreach ($users as $u) {
            if (($u['qq'] ?? '') === $qq && strcasecmp((string)($u['email'] ?? ''), $email) === 0) { $target = $u; break; }
        }
        if (!$target) jsonOut(['success' => false, 'error' => '账号与邮箱不匹配'], 400);
        // 管理角色：仅常用设备可自助找回；陌生设备 → 联系超管（不发送验证码）
        $isMgmt = in_array($target['role'] ?? '', [ROLE_SUPER_ADMIN, ROLE_STATION_ADMIN, ROLE_AUTHOR], true);
        if ($isMgmt) {
            $fpHash = computeSessionFp($reqFp);
            if (!isKnownDevice($target['id'], $fpHash)) {
                jsonOut(['success' => false, 'error' => '当前设备非常用设备，请使用常用设备找回，或联系站点管理员协助'], 403);
            }
        }
        [$ok, $err] = email_code_send($email, 'password_reset', '找回密码', $target['role'] ?? '');
        if (!$ok) jsonOut(['success' => false, 'error' => $err], 400);
        auditLog('password_reset_send', $target['id'], '找回密码验证码已发送');
        jsonOut(['success' => true, 'masked_email' => maskEmailAddr($email), 'ttl' => is_array($err) ? ($err['ttl'] ?? 300) : 300]);
    }

    if ($mode === 'do_reset') {
        $linkLeft = checkLinkedBlock();
        if ($linkLeft > 0) jsonOut(['success' => false, 'error' => '触发联动风控，请 ' . $linkLeft . ' 秒后再试'], 429);
        if (empty($qq) || empty($code) || empty($newPw)) jsonOut(['success' => false, 'error' => '请完整填写账号、验证码与新密码'], 400);
        // v4.7.0：同账号验证码失败锁定——3 次失败锁 15 分钟（防 6 位验证码暴力枚举）
        $rstKey = 'rst:' . hash('sha256', 'qq|' . strtolower($qq));
        $rstLock = loginLocked($rstKey);
        if ($rstLock > 0) {
            logThreat('login_locked_try', $clientIP, $reqFp, 60);
            jsonOut(['success' => false, 'error' => '验证失败次数过多，请 ' . $rstLock . ' 秒后重试', 'locked_seconds' => $rstLock], 429);
        }
        $users = loadUsers();
        $target = null;
        foreach ($users as $u) { if (($u['qq'] ?? '') === $qq) { $target = $u; break; } }
        // 两种凭证：邮箱验证码（自助找回）或超管一次性重置码（协助找回）；
        // 账号不存在也走同一失败路径（统一提示，防存在性探测）
        [$ok1, $v1] = $target ? email_code_verify($target['email'] ?? '', $code, 'password_reset') : [false, ''];
        [$ok2, $v2] = $target ? email_code_verify($target['email'] ?? '', $code, 'admin_reset') : [false, ''];
        if (!$ok1 && !$ok2) {
            logThreat('code_fail', $clientIP, $reqFp, 30);
            db_exec('INSERT INTO login_fails (ip, t, acc, fp) VALUES (?,?,?,?)', [$clientIP, time(), 'rst:' . strtolower($qq), '']);
            $rstFails = (int)(db_one('SELECT COUNT(*) AS c FROM login_fails WHERE acc = ? AND t > ?', ['rst:' . strtolower($qq), time() - 900])['c'] ?? 0);
            if ($rstFails >= 3) {
                lockLogin($rstKey, 900);
                logThreat('login_lock', $clientIP, $reqFp, 60);
                jsonOut(['success' => false, 'error' => '验证失败次数过多，请 900 秒后重试', 'locked_seconds' => 900], 429);
            }
            jsonOut(['success' => false, 'error' => '验证码不正确或已过期'], 400);
        }
        $vp = validatePassword($newPw);
        if ($vp !== true) jsonOut(['success' => false, 'error' => $vp], 400);
        $newHash = password_hash($newPw, PASSWORD_DEFAULT);
        $updated = false;
        foreach ($users as &$uu) { if ($uu['id'] === $target['id']) { $uu['password'] = $newHash; $updated = true; break; } }
        unset($uu);
        if (!$updated) jsonOut(['success' => false, 'error' => '账号状态异常，请联系管理员'], 400);
        saveUsers($users);
        db_exec('DELETE FROM login_fails WHERE acc = ?', ['rst:' . strtolower($qq)]); // 重置成功清除失败计数
        // 密码已变：tv+1 踢全部旧会话 + 吊销 refresh token
        bumpUserTV($target['id']);
        revokeUserRefreshTokens($target['id']);
        // 若重置的是当前登录会话 → 强制退出
        if (($_SESSION['cmt_user']['id'] ?? '') === $target['id']) unset($_SESSION['cmt_user']);
        auditLog('password_reset', $target['id'], '通过验证码找回重置密码');
        // v4.7.2：密码重置邮件告警（区分自助找回 / 超管协助重置码）
        notifyPasswordReset($target, $clientIP, $ok2 ? 'admin_reset' : 'self_reset');
        jsonOut(['success' => true]);
    }
    jsonOut(['success' => false, 'error' => '未知操作'], 400);
}


if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // v2.10.2/v4.5.0：超管也可在主页退出——吊销 JWT（jti 黑名单）+ 吊销 refresh token + 销毁会话 + 清 cookie
    $uid = $_SESSION['cmt_user']['id'] ?? '';
    if ($uid !== '') revokeUserRefreshTokens($uid);
    revokeCurrentJWT();
    clearRefreshCookie();
    unset($_SESSION['cmt_user']);
    session_unset();
    session_destroy();
    if (ini_get('session.use_cookies')) {
        $cp = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $cp['path'], $cp['domain'], $cp['secure'], $cp['httponly']);
    }
    jsonOut(['success' => true]);
}
// v4.5.0：refresh 接口——登录态过期后，用 httpOnly ym_rt + 当前环境指纹自动续期（换环境则失败，需重新登录）
if ($action === 'refresh' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rt = $_COOKIE['ym_rt'] ?? '';
    $reqFp = getRequestFp();
    $row = consumeRefreshToken($rt, $reqFp, -1); // 先按记录自身 tv 校验，再取用户当前 tv
    if (!$row) {
        clearRefreshCookie();
        jsonOut(['success' => false, 'error' => '登录已过期或登录环境已变化，请重新登录'], 401);
    }
    $curTV = getUserTV($row['user_id']);
    if ((int)$row['tv'] !== $curTV) {
        revokeUserRefreshTokens($row['user_id']);
        clearRefreshCookie();
        jsonOut(['success' => false, 'error' => '账号已在其他设备登录，当前会话已失效'], 401);
    }
    // 校验通过：重建登录态（保持同一 tv，不踢旧）
    session_regenerate_id(true);
    $found = null;
    foreach (loadUsers() as $uu) {
        if ($uu['id'] === $row['user_id']) { $found = $uu; break; }
    }
    if (!$found) { clearRefreshCookie(); jsonOut(['success' => false, 'error' => '账号不存在'], 401); }
    $_SESSION['cmt_fp'] = computeSessionFp($reqFp);
    $_SESSION['cmt_tv'] = (int)$curTV;
    // v4.6.0：保持原登录时间（refresh 不重置）——后台 24h 过期按登录时间算，续期不能绕过
    $_SESSION['cmt_login_ts'] = (int)($row['created'] ?? 0) ?: time();
    $_SESSION['cmt_user'] = [
        'id' => $found['id'], 'qq' => $found['qq'],
        'nickname' => $found['nickname'] ?? '',
        'avatar' => $found['avatar'] ?? getAvatarUrl($found['qq'] ?? ''),
        'signature' => $found['signature'] ?? '',
        'role' => $found['role'] ?? 'user',
        'email' => $found['email'] ?? '',
        'pw_hash' => $found['password']
    ];
    $safeUser = sanitizeUserForClient($_SESSION['cmt_user']);
    jsonOut(['success' => true, 'user' => $safeUser, 'env_bound' => true]);
}
if ($action === 'check') {
    $u = validateHomeUser();
    if ($u) {
        // v4.5.0：登录态会话但环境校验失败（换浏览器/设备/隐私模式/被踢）→ 提示重新登录
        if (!requireSessionEnv()) {
            jsonOut(['success' => true, 'loggedIn' => false, 'env_invalid' => true, 'error' => '登录环境已变化，请重新登录']);
        }
        $safeUser = sanitizeUserForClient($u);
        jsonOut(['success' => true, 'loggedIn' => true, 'user' => $safeUser]);
    } else {
        // v2.7.1：超管且已开启「超管主页评论」时，前端按登录态渲染以启用评论框（主页仍无管理入口，见 user-status）
        $sess = validateSession();
        $checkCfg = loadSiteConfig();
        if ($sess && ($sess['role'] ?? '') === ROLE_SUPER_ADMIN && !empty($checkCfg['super_admin_comment'])) {
            jsonOut([
                'success' => true,
                'loggedIn' => true,
                'isSuperAdmin' => true,
                'user' => ['id' => $sess['id'], 'nickname' => $sess['nickname'] ?? '超管', 'role' => 'super_admin', 'avatar' => '', 'signature' => ''],
            ]);
        }
        jsonOut(['success' => true, 'loggedIn' => false]);
    }
}
if ($action === 'user-status') {
    // v2.6.5：超管在主页显示「超管」身份（右侧用户区），不提供「快捷进入管理」（后台仅 OTP 入口）；
    // v2.10.2：提供「退出登录」（与后台 logout 一致吊销 JWT）
    $sess = validateSession();
    if ($sess && ($sess['role'] ?? '') === ROLE_SUPER_ADMIN) {
        jsonOut([
            'success' => true,
            'loggedIn' => true,
            'isSuperAdmin' => true,
            'user' => ['id' => $sess['id'], 'nickname' => $sess['nickname'] ?? '超管', 'role' => 'super_admin'],
            'canAccessAdmin' => false,
            'adminUrl' => '',
        ]);
    }
    $u = validateHomeUser();
    if ($u) {
        $role = $u['role'] ?? ROLE_GUEST;
        $roleLevel = ROLE_HIERARCHY[$role] ?? 0;
        $authorLevel = ROLE_HIERARCHY[ROLE_AUTHOR] ?? 30;
        $stationAdminLevel = ROLE_HIERARCHY[ROLE_STATION_ADMIN] ?? 40;
        $superAdminLevel = ROLE_HIERARCHY[ROLE_SUPER_ADMIN] ?? 50;
        // 普通管理员：author/station_admin，超管不走前端下拉菜单
        $canAccessAdmin = $roleLevel >= $authorLevel && $roleLevel < $superAdminLevel;
        $adminUrl = '';
        if ($roleLevel >= $stationAdminLevel && $roleLevel < $superAdminLevel) {
            $adminUrl = '/' . getStationPath() . '/dashboard.php';
        } elseif ($roleLevel >= $authorLevel && $roleLevel < $stationAdminLevel) {
            $adminUrl = '/' . getAuthorPath() . '/dashboard.php';
        }
        // v4.6.0：从首页点击「快捷进入管理」→ 重新开始 24h 后台计时（登录时间刷新；回退首页再进即重新计时）
        if ($canAccessAdmin) {
            $_SESSION['cmt_login_ts'] = time();
        }
        jsonOut([
            'success' => true,
            'loggedIn' => true,
            'user' => [
                'id' => $u['id'],
                'nickname' => $u['nickname'] ?? '',
                'role' => $role,
            ],
            'canAccessAdmin' => $canAccessAdmin,
            'adminUrl' => $adminUrl,
        ]);
    } else {
        jsonOut(['success' => true, 'loggedIn' => false]);
    }
}
if ($action === 'update_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = validateHomeUser();
    if (!$u) jsonOut(['success' => false, 'error' => '请先登录'], 401);
    // v4.5.0：环境校验（改昵称/签名/密码等敏感操作）
    if (!requireSessionEnv()) jsonOut(['success' => false, 'error' => '登录环境已变化，请重新登录', 'env_invalid' => true], 401);
    $input = json_decode(file_get_contents('php://input'), true);
    $nick = trim($input['nickname'] ?? '');
    $sign = trim($input['signature'] ?? '');
    if (empty($nick)) jsonOut(['success' => false, 'error' => '昵称不能为空'], 400);
    $nick = mb_substr($nick, 0, 20, 'UTF-8');
    $sign = mb_substr($sign, 0, 16, 'UTF-8');
    $users = loadUsers();
    foreach ($users as &$usr) {
        if ($usr['id'] === $u['id']) {
            $usr['nickname'] = $nick;
            $usr['signature'] = $sign;
            break;
        }
    }
    unset($usr);
    saveUsers($users);
    $_SESSION['cmt_user']['nickname'] = $nick;
    $_SESSION['cmt_user']['signature'] = $sign;
    jsonOut(['success' => true, 'user' => sanitizeUserForClient($_SESSION['cmt_user'])]);
}
if ($action === 'admin_setup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = validateSession();
    if (!$u || ($u['role'] ?? '') !== ROLE_SUPER_ADMIN) { logAbnormal(getClientIP(), '越权尝试修改站长信息'); jsonOut(['success' => false, 'error' => '无权限'], 403); }
    $input = json_decode(file_get_contents('php://input'), true);
    $qq = trim($input['qq'] ?? '');
    $nick = trim($input['nickname'] ?? '');
    $pw = $input['password'] ?? '';
    if (empty($qq)) jsonOut(['success' => false, 'error' => '请填写QQ号'], 400);
    if (empty($nick)) jsonOut(['success' => false, 'error' => '请填写昵称'], 400);
    if ($pw) {
        $vp = validatePassword($pw);
        if ($vp !== true) jsonOut(['success' => false, 'error' => $vp], 400);
    }
    $nick = mb_substr($nick, 0, 20, 'UTF-8');
    $avatarUrl = getAvatarUrl($qq);
    $users = loadUsers();
    foreach ($users as &$usr) {
        if ($usr['id'] === $u['id']) {
            $usr['qq'] = $qq;
            $usr['nickname'] = $nick;
            $usr['avatar'] = $avatarUrl;
            if ($pw) $usr['password'] = password_hash($pw, PASSWORD_DEFAULT);
            break;
        }
    }
    unset($usr);
    saveUsers($users);
    $_SESSION['cmt_user']['qq'] = $qq;
    $_SESSION['cmt_user']['nickname'] = $nick;
    $_SESSION['cmt_user']['avatar'] = $avatarUrl;
    jsonOut(['success' => true, 'user' => sanitizeUserForClient($_SESSION['cmt_user'])]);
}
if ($action === 'get') {
    $article = $_GET['article'] ?? '';
    if (empty($article)) jsonOut(['success' => false, 'error' => '缺少文章参数'], 400);
    // v3.3.11：公告关联文章不展示评论区
    if (isAnnouncementArticle($article)) jsonOut(['success' => true, 'comments' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'total_pages' => 0]);
    // v3.2.6：对外脱敏评论者的 qq 与 QQ 头像 URL（防枚举真实 QQ 号）
    // v3.3.15：评论区根评论分页（默认每页 20 条，独立实现不复用后台组件；回复随根评论整棵展示不单独分页）
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $all = loadComments($article, true);
    // 根评论按时间倒序（新评论在前）
    usort($all, function($a, $b) { return strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''); });
    $total = count($all);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $comments = array_slice($all, ($page - 1) * $perPage, $perPage);
    jsonOut(['success' => true, 'comments' => $comments, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => $totalPages]);
}
if ($action === 'post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientIP = getClientIP();
    if (isIPBanned($clientIP, 'comment')) jsonOut(['success' => false, 'error' => '你的 IP 已被封禁，无法评论'], 403);
    // v4.7.1：联动封锁拦截（堵住 comment 绕过联动封锁路径）
    $linkLeft = checkLinkedBlock();
    if ($linkLeft > 0) jsonOut(['success' => false, 'error' => '触发联动风控，请 ' . $linkLeft . ' 秒后再试', 'locked_seconds' => $linkLeft], 429);
    $siteCfg = loadSiteConfig();
    if (!($siteCfg['comments_enabled'] ?? true)) jsonOut(['success' => false, 'error' => '评论区已关闭'], 403);
    // v2.6.5：超管身份默认不参与前台评论（可在超管后台「系统配置」开启 super_admin_comment）
    if (($_SESSION['cmt_user']['role'] ?? '') === ROLE_SUPER_ADMIN && empty($siteCfg['super_admin_comment'])) {
        jsonOut(['success' => false, 'error' => '超管身份不参与前台评论'], 403);
    }
    $u = validateHomeUser();
    // v2.7.1：开启「超管主页评论」后，超管以超管身份评论（不走访客分支）
    if (!$u && ($_SESSION['cmt_user']['role'] ?? '') === ROLE_SUPER_ADMIN && !empty($siteCfg['super_admin_comment'])) {
        $u = validateSession();
    }
    if (!$u && empty($siteCfg['guest_comments_enabled'])) jsonOut(['success' => false, 'error' => '请先登录'], 401);
    if (!$u && !empty($siteCfg['guest_comments_enabled'])) {
        $u = ['id' => 'guest', 'nickname' => '访客', 'avatar' => '', 'qq' => '', 'role' => 'guest'];
    }
    // v4.5.0：登录态用户评论前校验环境（换浏览器/设备/被踢 → 拒绝，提示重新登录）；访客不受影响
    if (($u['id'] ?? '') !== 'guest' && !requireSessionEnv()) {
        jsonOut(['success' => false, 'error' => '登录环境已变化，请重新登录后再评论', 'env_invalid' => true], 401);
    }
    $reqFp = getRequestFp();
    db_rate_add('comment_rates', $clientIP, $reqFp);
    $ipRates = db_rate_count('comment_rates', $clientIP, 60, $reqFp); // 1 分钟窗口（指纹+IP 双维）
    $maxCommentsPerMin = max(1, intval($siteCfg['max_comments_per_minute'] ?? 5));
    if ($ipRates > $maxCommentsPerMin) {
        logAbnormal($clientIP, '频繁评论（' . $ipRates . '条/分钟）');
        logThreat('comment_flood', $clientIP, $reqFp, 300);
        if ($siteCfg['auto_ban'] ?? false) addBan($clientIP, ['comment'], '自动封禁：频繁评论');
        jsonOut(['success' => false, 'error' => '评论太频繁，请稍后再试'], 429);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $article = trim($input['article'] ?? '');
    $content = trim($input['content'] ?? '');
    $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content);
    if (empty($article)) jsonOut(['success' => false, 'error' => '缺少文章参数'], 400);
    if (isAnnouncementArticle($article)) jsonOut(['success' => false, 'error' => '公告不支持评论'], 403);
    if (empty($content)) jsonOut(['success' => false, 'error' => '内容不能为空'], 400);
    if (mb_strlen($content, 'UTF-8') > 1000) jsonOut(['success' => false, 'error' => '评论不能超过1000字'], 400);
    $comments = loadComments($article);
    $users = loadUsers();
    $userNick = $u['nickname'] ?? '用户';
    $userSign = '';
    $userAvatar = $u['avatar'] ?? '';
    $userQQ = $u['qq'] ?? '';
    foreach ($users as $usr) {
        if ($usr['id'] === $u['id']) {
            $userNick = $usr['nickname'] ?? '用户';
            $userSign = $usr['signature'] ?? '';
            $userAvatar = $usr['avatar'] ?? getAvatarUrl($usr['qq'] ?? '');
            $userQQ = $usr['qq'] ?? '';
            break;
        }
    }
    $new = [
        'id' => genId(), 'user_id' => $u['id'], 'qq' => $userQQ, 'nickname' => $userNick,
        'avatar' => $userAvatar, 'signature' => $userSign, 'content' => $content,
        'likes' => 0, 'replies' => [], 'created_at' => date('Y-m-d H:i:s')
    ];
    $comments[] = $new;
    saveComments($article, $comments);
    // v4.0.0：评论邮件订阅通知（受 config comment_notify_enabled 控制，失败不阻断评论）
    notifyComment($article, $userNick, $content);
    jsonOut(['success' => true, 'comment' => $new]);
}
if ($action === 'reply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientIP = getClientIP();
    if (isIPBanned($clientIP, 'comment')) jsonOut(['success' => false, 'error' => '你的 IP 已被封禁，无法回复'], 403);
    // v4.7.1：联动封锁拦截（堵住 reply 绕过联动封锁路径）
    $linkLeft = checkLinkedBlock();
    if ($linkLeft > 0) jsonOut(['success' => false, 'error' => '触发联动风控，请 ' . $linkLeft . ' 秒后再试', 'locked_seconds' => $linkLeft], 429);
    $u = validateHomeUser();
    $replyCfg = loadSiteConfig();
    // v2.6.5：超管身份默认不参与前台回复（可在超管后台「系统配置」开启 super_admin_comment）
    if (($_SESSION['cmt_user']['role'] ?? '') === ROLE_SUPER_ADMIN && empty($replyCfg['super_admin_comment'])) {
        jsonOut(['success' => false, 'error' => '超管身份不参与前台回复'], 403);
    }
    // v2.7.1：开启「超管主页评论」后，超管以超管身份回复（不走访客分支）
    if (!$u && ($_SESSION['cmt_user']['role'] ?? '') === ROLE_SUPER_ADMIN && !empty($replyCfg['super_admin_comment'])) {
        $u = validateSession();
    }
    if (!$u && empty($replyCfg['guest_comments_enabled'])) jsonOut(['success' => false, 'error' => '请先登录'], 401);
    if (!$u && !empty($replyCfg['guest_comments_enabled'])) {
        $u = ['id' => 'guest', 'nickname' => '访客', 'avatar' => '', 'qq' => '', 'role' => 'guest'];
    }
    // v4.5.0：登录态用户回复前校验环境（换浏览器/设备/被踢 → 拒绝）；访客不受影响
    if (($u['id'] ?? '') !== 'guest' && !requireSessionEnv()) {
        jsonOut(['success' => false, 'error' => '登录环境已变化，请重新登录后再回复', 'env_invalid' => true], 401);
    }
    $replyFp = getRequestFp();
    db_rate_add('comment_rates', $clientIP, $replyFp);
    $replyRates = db_rate_count('comment_rates', $clientIP, 60, $replyFp);
    $maxRepliesPerMin = max(1, intval($replyCfg['max_comments_per_minute'] ?? 5));
    if ($replyRates > $maxRepliesPerMin) {
        logAbnormal($clientIP, '频繁回复（' . $replyRates . '条/分钟）');
        logThreat('comment_flood', $clientIP, $replyFp, 300);
        if ($replyCfg['auto_ban'] ?? false) addBan($clientIP, ['comment'], '自动封禁：频繁回复');
        jsonOut(['success' => false, 'error' => '回复太频繁，请稍后再试'], 429);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $article = trim($input['article'] ?? '');
    $parentId = trim($input['parent_id'] ?? '');
    $content = trim($input['content'] ?? '');
    $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content);
    if (empty($article) || empty($parentId) || empty($content)) jsonOut(['success' => false, 'error' => '参数不完整'], 400);
    if (isAnnouncementArticle($article)) jsonOut(['success' => false, 'error' => '公告不支持评论'], 403);
    if (mb_strlen($content, 'UTF-8') > 1000) jsonOut(['success' => false, 'error' => '回复不能超过1000字'], 400);
    $comments = loadComments($article);
    $users = loadUsers();
    $userNick = $u['nickname'] ?? '用户';
    $userAvatar = $u['avatar'] ?? '';
    $userQQ = $u['qq'] ?? '';
    foreach ($users as $usr) {
        if ($usr['id'] === $u['id']) {
            $userNick = $usr['nickname'] ?? '用户';
            $userAvatar = $usr['avatar'] ?? getAvatarUrl($usr['qq'] ?? '');
            $userQQ = $usr['qq'] ?? '';
            break;
        }
    }
    $reply = [
        'id' => genId(), 'user_id' => $u['id'], 'qq' => $userQQ, 'nickname' => $userNick,
        'avatar' => $userAvatar, 'content' => $content,
        'likes' => 0, 'replies' => [], 'created_at' => date('Y-m-d H:i:s')
    ];
    $added = false;
    $parentNickForMail = ''; // v4.0.0：回复通知带出被回复者昵称
    foreach ($comments as &$c) {
        if ($c['id'] === $parentId) {
            if (!isset($c['replies'])) $c['replies'] = [];
            $c['replies'][] = $reply;
            $added = true;
            $parentNickForMail = $c['nickname'] ?? '';
            break;
        }
        if (!empty($c['replies'])) {
            if (addReplyRecursive($c['replies'], $parentId, $reply)) {
                $added = true;
                $parentNickForMail = findCommentNickname($c['replies'], $parentId) ?? ($c['nickname'] ?? '');
                break;
            }
        }
    }
    unset($c);
    if ($added) {
        saveComments($article, $comments);
        // v4.0.0：回复邮件订阅通知（受 config comment_notify_enabled 控制）
        notifyComment($article, $userNick, $content, true, $parentNickForMail);
        jsonOut(['success' => true]);
    }
    jsonOut(['success' => false, 'error' => '父评论不存在'], 404);
}
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = validateHomeUser();
    // v2.7.1：开启「超管主页评论」后，超管以超管身份删除评论（超管本身具备管理员删除权限）
    if (!$u && ($_SESSION['cmt_user']['role'] ?? '') === ROLE_SUPER_ADMIN && !empty(loadSiteConfig()['super_admin_comment'])) {
        $u = validateSession();
    }
    if (!$u) jsonOut(['success' => false, 'error' => '请先登录'], 401);
    // v4.5.0：登录态用户删除评论前校验环境（换浏览器/设备/被踢 → 拒绝）
    if (($u['id'] ?? '') !== 'guest' && !requireSessionEnv()) {
        jsonOut(['success' => false, 'error' => '登录环境已变化，请重新登录后再操作', 'env_invalid' => true], 401);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $article = trim($input['article'] ?? '');
    $delId = trim($input['id'] ?? '');
    if (empty($article) || empty($delId)) jsonOut(['success' => false, 'error' => '参数不完整'], 400);
    $comments = loadComments($article);
    $isAdmin = in_array(($u['role'] ?? ''), [ROLE_SUPER_ADMIN, ROLE_STATION_ADMIN]);
    $found = false;
    foreach ($comments as $i => $c) {
        if ($c['id'] === $delId && ($isAdmin || $c['user_id'] === $u['id'])) {
            array_splice($comments, $i, 1);
            $found = true;
            break;
        }
    }
    if (!$found) {
        foreach ($comments as &$c) {
            if (!empty($c['replies'])) {
                if (delReplyRecursive($c['replies'], $delId, $u['id'], $isAdmin)) {
                    $found = true;
                    break;
                }
            }
        }
        unset($c);
    }
    if ($found) { saveComments($article, $comments); jsonOut(['success' => true]); }
    logAbnormal(getClientIP(), '越权尝试删除评论: ' . $delId . ' (文章: ' . $article . ')');
    jsonOut(['success' => false, 'error' => '评论不存在或无权删除'], 404);
}
if ($action === 'bg_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = validateSession();
    if (!$u || ($u['role'] ?? '') !== ROLE_SUPER_ADMIN) jsonOut(['success' => false, 'error' => '无权限'], 403);
    if (!isset($_FILES['bg_image']) || $_FILES['bg_image']['error'] !== UPLOAD_ERR_OK) jsonOut(['success' => false, 'error' => '上传失败'], 400);
    $file = $_FILES['bg_image'];
    $extMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $detectedMime = null;
    if (function_exists('getimagesize')) {
        $imgInfo = @getimagesize($file['tmp_name']);
        if ($imgInfo && isset($imgInfo['mime'])) $detectedMime = $imgInfo['mime'];
    }
    if (!$detectedMime && isset($extMap[$origExt])) {
        $detectedMime = $extMap[$origExt];
    }
    if (!$detectedMime || !in_array($detectedMime, array_values($extMap))) {
        jsonOut(['success' => false, 'error' => '仅支持 JPG/PNG/GIF/WebP 格式'], 400);
    }
    if ($file['size'] > 10 * 1024 * 1024) jsonOut(['success' => false, 'error' => '文件大小不能超过 10MB'], 400);
    $saveExt = array_search($detectedMime, $extMap) ?: $origExt;
    $filename = 'bg_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $saveExt;
    $bgDir = './data/bg/';
    if (!is_dir($bgDir)) mkdir($bgDir, 0755, true);
    if (move_uploaded_file($file['tmp_name'], $bgDir . $filename)) {
        jsonOut(['success' => true, 'path' => 'data/bg/' . $filename]);
    }
    jsonOut(['success' => false, 'error' => '保存失败，请检查 data/bg/ 目录权限'], 500);
}
if ($action === 'bg_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = validateSession();
    if (!$u || ($u['role'] ?? '') !== ROLE_SUPER_ADMIN) jsonOut(['success' => false, 'error' => '无权限'], 403);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) jsonOut(['success' => false, 'error' => '无效的请求数据'], 400);
    $config = loadSiteConfig();
    $config['bg_type'] = in_array($input['bg_type'] ?? '', ['none', 'image', 'api']) ? $input['bg_type'] : 'none';
    // bg_image 仅允许站内 data/bg/ 路径或 http(s) URL，防止 CSS 值注入
    $bgImage = trim($input['bg_image'] ?? '');
    if ($bgImage !== '' && strpos($bgImage, 'data/bg/') !== 0 && !preg_match('#^https?://#i', $bgImage)) $bgImage = '';
    $config['bg_image'] = $bgImage;
    // v4.2.0：API 背景固定默认源（留空保存自动回退固定 16:9 图片 API）
    $config['bg_api_url'] = trim($input['bg_api_url'] ?? '');
    if ($config['bg_api_url'] === '') $config['bg_api_url'] = FIXED_IMG_API;
    $config['bg_blur_enabled'] = !empty($input['bg_blur_enabled']);
    $config['bg_blur_level'] = max(0, min(50, intval($input['bg_blur_level'] ?? 0)));
    $config['bg_card_opacity'] = max(50, min(100, intval($input['bg_card_opacity'] ?? 100)));
    saveSiteConfig($config);
    jsonOut(['success' => true]);
}
if ($action === 'bg_config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $config = loadSiteConfig();
    jsonOut([
        'success' => true,
        'bg_type' => $config['bg_type'] ?? 'none',
        'bg_image' => $config['bg_image'] ?? '',
        'bg_api_url' => $config['bg_api_url'] ?? '',
        'bg_blur_enabled' => !empty($config['bg_blur_enabled']),
        'bg_blur_level' => $config['bg_blur_level'] ?? 0,
        'bg_card_opacity' => $config['bg_card_opacity'] ?? 100
    ]);
}
if ($action === 'entry_path_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = validateSession();
    if (!$u || ($u['role'] ?? '') !== ROLE_SUPER_ADMIN) jsonOut(['success' => false, 'error' => '无权限'], 403);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) jsonOut(['success' => false, 'error' => '无效的请求数据'], 400);
    $config = loadSiteConfig();
    $newStationPath = trim($input['station_path'] ?? '');
    $newAuthorPath = trim($input['author_path'] ?? '');
    if ($newStationPath !== '' && $newStationPath !== 'station') {
        $result = validateCustomPath($newStationPath);
        if ($result !== true) jsonOut(['success' => false, 'error' => '站长路径: ' . $result], 400);
        if ($newStationPath === $newAuthorPath) jsonOut(['success' => false, 'error' => '站长路径和写作者路径不能相同'], 400);
        $config['station_path'] = $newStationPath;
    } else {
        $config['station_path'] = 'station';
    }
    if ($newAuthorPath !== '' && $newAuthorPath !== 'author') {
        $result = validateCustomPath($newAuthorPath);
        if ($result !== true) jsonOut(['success' => false, 'error' => '写作者路径: ' . $result], 400);
        $config['author_path'] = $newAuthorPath;
    } else {
        $config['author_path'] = 'author';
    }
    $config['hide_default_paths'] = !empty($input['hide_default_paths']);
    saveSiteConfig($config);
    auditLog('config_update', 'entry_paths', '修改自定义入口路径: station=' . $config['station_path'] . ', author=' . $config['author_path']);
    jsonOut(['success' => true, 'station_path' => $config['station_path'], 'author_path' => $config['author_path']]);
}
if ($action === 'entry_path_config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $config = loadSiteConfig();
    jsonOut([
        'success' => true,
        'station_path' => $config['station_path'] ?? 'station',
        'author_path' => $config['author_path'] ?? 'author',
        'hide_default_paths' => !empty($config['hide_default_paths']),
    ]);
}
jsonOut(['success' => false, 'error' => '未知操作'], 400);
