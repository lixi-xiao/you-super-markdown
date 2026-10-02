<?php
require_once __DIR__ . '/utils.php';
secureSessionStart();
header('Content-Type: application/json; charset=utf-8');

// v3.0.8 统一安全入口：扫描器 UA 黑名单检测（命中返回 403 + 记录 + 封禁来源 IP）
runRequestSecurityCheck();

// v3.3.11：公告为单向通知，禁止评论——公告关联文章（当前即「更新历史.md」）的评论读写全部拦截
function isAnnouncementLinkedArticle($article) {
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
// $sanitize=true 时对外脱敏：account 置空、avatar 若为 QQ 头像 URL（含账号）也置空，
// 防止 ?action=get&article=<任意> 批量枚举评论者的真实 QQ 号（v2.6.2）
function assembleCommentTree($rows, $sanitize = false) {
    $map = [];
    foreach ($rows as $r) {
        $avatar = $r['avatar'] ?? '';
        if ($sanitize && strpos((string)$avatar, 'qlogo') !== false) $avatar = '';
        $map[$r['id']] = [
            'id' => $r['id'],
            'user_id' => $r['user_id'],
            'account' => $sanitize ? '' : $r['account'],
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
function flattenCommentRows($comments, $article, $parentId, &$out) {
    foreach ($comments as $c) {
        $out[] = [
            'id' => $c['id'] ?? bin2hex(random_bytes(8)),
            'article' => $article,
            'parent_id' => $parentId,
            'user_id' => $c['user_id'] ?? '',
            'account' => $c['account'] ?? '',
            'nickname' => $c['nickname'] ?? '',
            'avatar' => $c['avatar'] ?? '',
            'signature' => $c['signature'] ?? '',
            'content' => $c['content'] ?? '',
            'likes' => (int)($c['likes'] ?? 0),
            'created_at' => $c['created_at'] ?? '',
        ];
        if (!empty($c['replies']) && is_array($c['replies'])) {
            flattenCommentRows($c['replies'], $article, $out[count($out) - 1]['id'], $out);
        }
    }
}

/**
 * v4.7.0：完成登录会话（login 与 device_verify 共用）。
 * 设置 session + tv+1 并发踢旧 + 环境指纹绑定 + 签发 refresh + 记录日志/通知。
 */
function finalizeLogin($u, $reqFp) {
    session_regenerate_id(true);
    $avatar = $u['avatar'] ?? resolveAvatarUrl($u['account'] ?? '');
    $_SESSION['cmt_user'] = [
        'id' => $u['id'], 'account' => $u['account'],
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
    // v5.3.1：完整认证完成 → 建立独立的后台会话（与前台登录态解耦；过期只失效该标记）
    establishBackendSession();
    if (($u['role'] ?? '') !== ROLE_SUPER_ADMIN) {
        issueRefreshToken($u['id'], $_SESSION['cmt_fp'], $newTV);
    }
    $clientIP = getClientIP();
    loginFailClear($clientIP, $u['account'] ?? '');
    db_exec('UPDATE users SET last_login = ?, login_count = login_count + 1 WHERE id = ?', [date('Y-m-d H:i:s'), $u['id']]);
    notifyLoginEvent($u, $clientIP);
    $safeUser = sanitizeUserForClient($_SESSION['cmt_user']);
    return ['success' => true, 'user' => $safeUser,
        'isAdminFirstLogin' => in_array($u['role'] ?? '', [ROLE_SUPER_ADMIN, ROLE_STATION_ADMIN]),
        'env_bound' => true];
}

function fetchCommentTree($article, $sanitize = false) {
    $rows = db_all('SELECT * FROM comments WHERE article = ? ORDER BY rowid', [$article]);
    return assembleCommentTree($rows, $sanitize);
}
function persistCommentTree($article, $comments) {
    $flat = [];
    flattenCommentRows($comments, $article, null, $flat);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_exec('DELETE FROM comments WHERE article = ?', [$article]);
        $st = $pdo->prepare('INSERT INTO comments (id, article, parent_id, user_id, account, nickname, avatar, signature, content, likes, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($flat as $r) {
            $st->execute([$r['id'], $r['article'], $r['parent_id'], $r['user_id'], $r['account'], $r['nickname'], $r['avatar'], $r['signature'], $r['content'], $r['likes'], $r['created_at']]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
function currentSessionUser() { return empty($_SESSION['cmt_user']) ? null : $_SESSION['cmt_user']; }
function verifySessionUser() {
    if (empty($_SESSION['cmt_user'])) return null;
    $sessionRow = $_SESSION['cmt_user'];
    $roster = fetchAllUsers();
    foreach ($roster as $candidate) {
        if ($candidate['id'] === ($sessionRow['id'] ?? '')) {
            if (($candidate['password'] ?? '') !== ($sessionRow['pw_hash'] ?? '')) {
                session_unset();
                session_destroy();
                return null;
            }
            $_SESSION['cmt_user']['role'] = $candidate['role'] ?? 'user';
            return $_SESSION['cmt_user'];
        }
    }
    session_unset();
    session_destroy();
    return null;
}
// v2.6.1：主页视角的登录用户 —— 超管彻底分离（OTP 入口登录的系统级角色在主页隐身，
// 主页 check/user-status/评论一律按未登录处理；后台鉴权不受影响，超管后台仍走 verifySessionUser/JWT）
function verifyHomeUser() {
    $actor = verifySessionUser();
    if (!$actor) return null;
    if (($actor['role'] ?? '') === ROLE_SUPER_ADMIN) return null;
    // v2.11.4：被禁用账号主页按未登录处理（隐身 + 不能评论/绑定设备）
    foreach (fetchAllUsers() as $probe) {
        if ($probe['id'] === ($actor['id'] ?? '') && !empty($probe['disabled'])) return null;
    }
    return $actor;
}
function sendJson($responseData, $httpStatus = 200) {
    http_response_code($httpStatus);
    echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
    exit;
}

function resolveAvatarUrl($account) {
    return 'https://q1.qlogo.cn/g?b=qq&nk=' . urlencode($account) . '&s=100';
}
function attachReplyTo(&$branch, $parentKey, $answer) {
    foreach ($branch as &$node) {
        if ($node['id'] === $parentKey) {
            if (!isset($node['replies'])) $node['replies'] = [];
            $node['replies'][] = $answer;
            return true;
        }
        if (!empty($node['replies'])) {
            if (attachReplyTo($node['replies'], $parentKey, $answer)) return true;
        }
    }
    return false;
}
function removeReplyFrom(&$branch, $victimId, $ownerId, $privileged) {
    foreach ($branch as $pos => $node) {
        if ($node['id'] === $victimId && ($privileged || $node['user_id'] === $ownerId)) {
            array_splice($branch, $pos, 1);
            return true;
        }
        if (!empty($node['replies'])) {
            if (removeReplyFrom($node['replies'], $victimId, $ownerId, $privileged)) return true;
        }
    }
    return false;
}
// v4.0.0：递归查找评论昵称（回复邮件通知带出被回复者；找不到返回 null）
function lookupReplyNickname($replies, $findId) {
    foreach ($replies as $r) {
        if ($r['id'] === $findId) return $r['nickname'] ?? '';
        if (!empty($r['replies'])) {
            $n = lookupReplyNickname($r['replies'], $findId);
            if ($n !== null) return $n;
        }
    }
    return null;
}
// CSRF 防护：所有 POST 请求需携带有效 X-CSRF-Token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        sendJson(['success' => false, 'error' => 'CSRF 校验失败'], 403);
    }
}
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ===== v2.11.0：滑块人机验证已彻底移除（原 captcha_new 分支删除） =====

// ===== v2.11.1：动态 CSRF token 获取（修复「会话失败」根因） =====
// 登录/注册等匿名前置操作在提交前先取本接口的 token（同一请求链内 cookie 与 token
// 必然同 session），解决浏览器未携带 PHPSESSID / session 过期导致的 token 不匹配
if ($action === 'csrf') {
    sendJson(['success' => true, 'csrf_token' => generateCsrfToken()]);
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
    sendJson(['success' => $fpOk]);
}

// ===== v2.9.0：注册邮箱验证码发送（60s 冷却，按邮箱；注册为匿名，不走超管豁免） =====
// v4.4.0：发送前必须先通过随机算术人机验证（点击「获取验证码」→ 弹窗答题 → 答对才真正发码）
if ($action === 'arith_challenge') {
    sendJson(['success' => true, 'expression' => genArithChallenge()]);
}

if ($action === 'send_register_code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteCfg = loadSiteConfig();
    // v4.7.1：联动封锁拦截（与 login/找回一致，堵住绕过路径）
    $linkLeft = checkLinkedBlock();
    if ($linkLeft > 0) sendJson(['success' => false, 'error' => '触发联动风控，请 ' . $linkLeft . ' 秒后再试', 'locked_seconds' => $linkLeft], 429);
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    // v4.4.0：算术人机验证——答错/未答一律拒绝发码（一次性消费，重放无效）
    [$arithOk, $arithErr] = verifyArithChallenge($input['arith_answer'] ?? '');
    if (!$arithOk) sendJson(['success' => false, 'error' => $arithErr], 400);
    // v4.5.0：指纹+IP 双维发码限速（60 秒 ≥5 次，防换 IP/换邮箱轰炸）；无指纹请求按 no-fp 维度计
    $ipNow = getClientIP();
    $reqFp = getRequestFp();
    if (db_rate_count('reg_rates', $ipNow, 60, $reqFp) >= 5) {
        logThreat('reg_flood', $ipNow, $reqFp, 300);
        sendJson(['success' => false, 'error' => '发送过于频繁，请稍后再试'], 429);
    }
    if (email_exists($email)) sendJson(['success' => false, 'error' => '该邮箱已被注册'], 409);
    [$ok, $err] = email_code_send($email, 'register', $email);
    if (!$ok) sendJson(['success' => false, 'error' => $err], 400);
    db_rate_add('reg_rates', $ipNow, $reqFp);
    sendJson(['success' => true, 'ttl' => is_array($err) ? ($err['ttl'] ?? 300) : 300]);
}

if ($action === 'avatar') {
    // v2.6.3：收紧——仅登录用户可用，防止未登录批量探测 QQ 号（配合评论脱敏，前台已无匿名头像需求）
    if (!verifySessionUser()) sendJson(['success' => false, 'error' => '请先登录'], 403);
    $account = trim($_GET['account'] ?? '');
    if (empty($account)) sendJson(['success' => false, 'error' => '缺少QQ号'], 400);
    $remoteUrl = resolveAvatarUrl($account);
    $streamCtx = stream_context_create(['http' => ['timeout' => 5, 'method' => 'GET']]);
    $blob = @file_get_contents($remoteUrl, false, $streamCtx);
    if ($blob !== false && strlen($blob) > 100) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        echo $blob;
    } else {
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=86400');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="#8b95a5"/><text x="50" y="58" text-anchor="middle" fill="#fff" font-size="40" font-family="sans-serif">' . htmlspecialchars(mb_substr($account, 0, 1, 'UTF-8')) . '</text></svg>';
    }
    exit;
}
// ===== v2.9.0：站长创建写作者——发送验证码到写作者邮箱（需站长登录 + CSRF；v2.11.0 起滑块已移除） =====
if ($action === 'send_author_code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkRole(ROLE_STATION_ADMIN)) sendJson(['success' => false, 'error' => '无权限'], 403);
    if (!verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) sendJson(['success' => false, 'error' => 'CSRF 校验失败'], 403);
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $cfg = loadSiteConfig();
    if (!email_valid($email)) sendJson(['success' => false, 'error' => '邮箱格式不正确'], 400);
    $ipNow = getClientIP();
    $recent = db_one('SELECT COUNT(*) AS c FROM email_codes WHERE ip = ? AND created > ?', [$ipNow, time() - 60])['c'] ?? 0;
    if ($recent >= 5) sendJson(['success' => false, 'error' => '发送过于频繁，请稍后再试'], 429);
    if (email_exists($email)) sendJson(['success' => false, 'error' => '该邮箱已被使用'], 409);
    [$ok, $err] = email_code_send($email, 'author_verify', '站长创建写作者', ROLE_STATION_ADMIN);
    if (!$ok) sendJson(['success' => false, 'error' => $err], 400);
    sendJson(['success' => true, 'ttl' => is_array($err) ? ($err['ttl'] ?? 300) : 300]);
}

// ===== v2.10.0：更换绑定邮箱——发送验证码（登录 + CSRF；受 email_verify_enabled 开关控制，关闭即禁用） =====
if ($action === 'send_email_change_code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = verifyHomeUser();
    if (!$u) sendJson(['success' => false, 'error' => '请先登录'], 401);
    if (!checkCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) sendJson(['success' => false, 'error' => 'CSRF 校验失败'], 403);
    // v4.5.0：环境校验（换环境/被踢 → 拒绝敏感操作）
    if (!requireSessionEnv()) sendJson(['success' => false, 'error' => '登录环境已变化，请重新登录', 'env_invalid' => true], 401);
    $siteCfg = loadSiteConfig();
    if (empty($siteCfg['email_verify_enabled'])) sendJson(['success' => false, 'error' => '邮箱验证已关闭'], 403);
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    if (!email_valid($email)) sendJson(['success' => false, 'error' => '邮箱格式不正确'], 400);
    if (email_exists($email)) sendJson(['success' => false, 'error' => '该邮箱已被其他账号使用'], 409);
    $ipNow = getClientIP();
    $recent = db_one('SELECT COUNT(*) AS c FROM email_codes WHERE ip = ? AND created > ?', [$ipNow, time() - 60])['c'] ?? 0;
    if ($recent >= 5) sendJson(['success' => false, 'error' => '发送过于频繁，请稍后再试'], 429);
    [$ok, $err] = email_code_send($email, 'email_change', '更换绑定邮箱', $u['role'] ?? '');
    if (!$ok) sendJson(['success' => false, 'error' => $err], 400);
    sendJson(['success' => true, 'ttl' => is_array($err) ? ($err['ttl'] ?? 300) : 300]);
}

// ===== v2.10.0：更换绑定邮箱——验证码原子确认（登录 + CSRF） =====
if ($action === 'update_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = verifyHomeUser();
    if (!$u) sendJson(['success' => false, 'error' => '请先登录'], 401);
    if (!checkCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) sendJson(['success' => false, 'error' => 'CSRF 校验失败'], 403);
    // v4.5.0：环境校验（换环境/被踢 → 拒绝敏感操作）
    if (!requireSessionEnv()) sendJson(['success' => false, 'error' => '登录环境已变化，请重新登录', 'env_invalid' => true], 401);
    $siteCfg = loadSiteConfig();
    if (empty($siteCfg['email_verify_enabled'])) sendJson(['success' => false, 'error' => '邮箱验证已关闭'], 403);
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $code = trim($input['code'] ?? '');
    if (!email_valid($email)) sendJson(['success' => false, 'error' => '邮箱格式不正确'], 400);
    if (email_exists($email)) sendJson(['success' => false, 'error' => '该邮箱已被其他账号使用'], 409);
    [$ok, $verr] = email_code_verify($email, $code, 'email_change');
    if (!$ok) sendJson(['success' => false, 'error' => $verr], 400);
    $users = fetchAllUsers();
    foreach ($users as &$usr) {
        if ($usr['id'] === $u['id']) { $usr['email'] = $email; break; }
    }
    unset($usr);
    replaceAllUsers($users);
    $_SESSION['cmt_user']['email'] = $email;
    auditLog('email_change', $u['id'], '更换绑定邮箱为 ' . $email);
    sendJson(['success' => true, 'email' => $email]);
}

// ===== v2.10.0：头像上传（登录 + CSRF；multipart/form-data，字段名 avatar） =====
if ($action === 'avatar_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = verifyHomeUser();
    if (!$u) sendJson(['success' => false, 'error' => '请先登录'], 401);
    if (!checkCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) sendJson(['success' => false, 'error' => 'CSRF 校验失败'], 403);
    // v4.5.0：环境校验（换环境/被踢 → 拒绝敏感操作）
    if (!requireSessionEnv()) sendJson(['success' => false, 'error' => '登录环境已变化，请重新登录', 'env_invalid' => true], 401);
    [$ok, $res] = avatar_upload($u['id'], $_FILES['avatar'] ?? null);
    if (!$ok) sendJson(['success' => false, 'error' => $res], 400);
    auditLog('avatar_update', $u['id'], '更新头像');
    sendJson(['success' => true, 'avatar' => $res]);
}

// ===== v3.1.6：文章图片上传（站长/写作者 + CSRF；multipart/form-data，字段名 image；≤5MB） =====
if ($action === 'article_image_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 写作者与站长均可上传文章配图
    if (!checkRole(ROLE_AUTHOR)) sendJson(['success' => false, 'error' => '无权限'], 403);
    if (!checkCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) sendJson(['success' => false, 'error' => 'CSRF 校验失败'], 403);
    $file = $_FILES['image'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        sendJson(['success' => false, 'error' => '未收到文件'], 400);
    }
    if ($file['size'] <= 0 || $file['size'] > 5 * 1024 * 1024) {
        sendJson(['success' => false, 'error' => '图片大小需在 5MB 以内'], 400);
    }
    // MIME 白名单：getimagesize 实测 + 扩展名双重校验（防伪装文件）
    $extMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info['mime'], array_values($extMap), true)) {
        sendJson(['success' => false, 'error' => '仅支持 JPG/PNG/GIF/WebP 图片'], 400);
    }
    $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($extMap[$origExt])) $origExt = array_search($info['mime'], $extMap) ?: 'png';
    // 内容必须为图片（getimagesize 返回宽高 > 0）
    if ($info[0] <= 0 || $info[1] <= 0) sendJson(['success' => false, 'error' => '图片文件无效'], 400);
    $dir = __DIR__ . '/data/images/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fname = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $origExt;
    if (!move_uploaded_file($file['tmp_name'], $dir . $fname)) {
        sendJson(['success' => false, 'error' => '保存失败，请检查 data/images/ 目录权限'], 500);
    }
    auditLog('article_image_upload', $fname, '上传文章图片');
    sendJson(['success' => true, 'url' => 'data/images/' . $fname]);
}

// ===== v3.3.0：文章视频上传（站长/写作者 + CSRF；multipart/form-data，字段名 video；≤20MB） =====
// 合法性强制校验：扩展名白名单 → finfo MIME → 容器结构（ftyp box / EBML 魔数）三重校验
if ($action === 'article_video_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkRole(ROLE_AUTHOR)) sendJson(['success' => false, 'error' => '无权限'], 403);
    if (!checkCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) sendJson(['success' => false, 'error' => 'CSRF 校验失败'], 403);
    $file = $_FILES['video'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        sendJson(['success' => false, 'error' => '未收到文件'], 400);
    }
    if ($file['size'] <= 0 || $file['size'] > 20 * 1024 * 1024) {
        sendJson(['success' => false, 'error' => '视频大小需在 20MB 以内'], 400);
    }
    $extMap = ['mp4' => 'video/mp4', 'webm' => 'video/webm'];
    $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($extMap[$origExt])) {
        sendJson(['success' => false, 'error' => '仅支持 MP4/WebM 视频'], 400);
    }
    // 强制校验：内容与声明格式一致、容器结构合法（防伪装/损坏文件）
    if (!validateVideoFile($file['tmp_name'], $origExt)) {
        sendJson(['success' => false, 'error' => '视频文件校验失败（内容与声明格式不符或文件损坏）'], 400);
    }
    $dir = __DIR__ . '/data/videos/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fname = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $origExt;
    if (!move_uploaded_file($file['tmp_name'], $dir . $fname)) {
        sendJson(['success' => false, 'error' => '保存失败，请检查 data/videos/ 目录权限'], 500);
    }
    auditLog('article_video_upload', $fname, '上传文章视频');
    sendJson(['success' => true, 'url' => 'data/videos/' . $fname]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'register') {
    $cfgSnap = loadSiteConfig();
    if (empty($cfgSnap['registration_enabled'])) {
        sendJson(['success' => false, 'error' => '注册已关闭'], 403);
    }
    $ipAddr = getClientIP();
    if (isIPBanned($ipAddr, 'register')) sendJson(['success' => false, 'error' => '你的 IP 已被封禁，无法注册'], 403);
    // v4.7.1：联动封锁拦截（bans type=link + link:ip/fp 登录锁，堵住 register 绕过联动封锁路径）
    $cooldownLeft = checkLinkedBlock();
    if ($cooldownLeft > 0) sendJson(['success' => false, 'error' => '触发联动风控，请 ' . $cooldownLeft . ' 秒后再试', 'locked_seconds' => $cooldownLeft], 429);
    $regCap = max(1, intval($cfgSnap['max_registrations_per_ip'] ?? $cfgSnap['reg_limit_per_ip'] ?? 3));
    $ipRegCount = db_rate_count('reg_rates', $ipAddr, 2592000); // 30 天累计
    if ($ipRegCount >= $regCap) {
        logAbnormal($ipAddr, '频繁注册（累计' . $ipRegCount . '次，限制' . $regCap . '次）');
        logThreat('reg_flood', $ipAddr, getRequestFp(), 300);
        // v4.8.0：移除单一 addBan，全部走联动封禁（logThreat 已在上行写入）
        sendJson(['success' => false, 'error' => '注册次数已达上限'], 429);
    }
    $payload = json_decode(file_get_contents('php://input'), true);
    $fpToken = getRequestFp();
    // v4.4.0：蜜罐字段——隐藏输入框仅机器人会填；命中即判定机器人，静默拒绝（返回成功但不注册，浪费其时间）
    if (!empty($payload['website'])) {
        auditLog('register_honeypot', substr((string)$payload['website'], 0, 64), '注册蜜罐命中，静默拒绝（IP ' . $ipAddr . '）');
        // v4.4.0/v4.5.0：短时间（10 分钟窗口）连续命中达阈值 → 自动封禁 IP（指纹+IP 双维计数）
        db_rate_add('honeypot_rates', $ipAddr, $fpToken);
        logThreat('honeypot', $ipAddr, $fpToken, 300);
        $trapHits = db_rate_count('honeypot_rates', $ipAddr, 600, $fpToken);
        $trapLimit = max(1, (int)($cfgSnap['honeypot_ban_count'] ?? 3));
        if ($trapHits >= $trapLimit && !isIPBanned($ipAddr, 'register')) {
            // v4.8.0：移除单一 addBan，全部走联动封禁（logThreat 已在上行写入 threat_events）
            auditLog('honeypot_auto_ban', $ipAddr, '注册蜜罐 ' . $trapHits . ' 次命中，联动封禁升级', 'banned');
        }
        sendJson(['success' => true, 'user' => null]);
    }
    $qqNo = trim($payload['account'] ?? '');
    $displayName = trim($payload['nickname'] ?? '');
    $secret = $payload['password'] ?? '';
    $mailBox = trim($payload['email'] ?? '');
    $otp = $payload['code'] ?? '';
    if (empty($qqNo) || empty($secret)) sendJson(['success' => false, 'error' => 'QQ号和密码不能为空'], 400);
    $pwChk = validatePassword($secret);
    if ($pwChk !== true) sendJson(['success' => false, 'error' => $pwChk], 400);
    if (empty($displayName)) $displayName = '用户' . substr($qqNo, -4);
    $displayName = mb_substr($displayName, 0, 20, 'UTF-8');
    // v2.11.0：滑块人机验证已彻底移除
    // v2.9.0 邮箱验证码（开关启用时：邮箱格式 + 唯一 + 验证码一次性校验）
    if (!empty($cfgSnap['email_verify_enabled'])) {
        if (!email_valid($mailBox)) sendJson(['success' => false, 'error' => '邮箱格式不正确'], 400);
        if (email_exists($mailBox)) sendJson(['success' => false, 'error' => '该邮箱已被注册'], 409);
        [$mailOk, $mailErr] = email_code_verify($mailBox, $otp, 'register');
        if (!$mailOk) sendJson(['success' => false, 'error' => $mailErr], 400);
    }
    $roster = fetchAllUsers();
    foreach ($roster as $candidate) { if (($candidate['account'] ?? '') === $qqNo) sendJson(['success' => false, 'error' => '该QQ号已注册'], 409); }
    $avatarLink = resolveAvatarUrl($qqNo);
    $fresh = [
        'id' => genId(), 'account' => $qqNo, 'nickname' => $displayName,
        'email' => $mailBox,
        'password' => password_hash($secret, PASSWORD_DEFAULT),
        'avatar' => $avatarLink, 'signature' => '', 'role' => 'user',
        'created' => date('Y-m-d H:i:s')
    ];
    $roster[] = $fresh;
    replaceAllUsers($roster);
    db_rate_add('reg_rates', $ipAddr, $fpToken);
    session_regenerate_id(true);
    // v4.5.0：注册即绑定环境指纹 + token_version（并发踢旧）+ 签发 refresh token
    $tvStamp = bumpUserTV($fresh['id']);
    $_SESSION['cmt_fp'] = computeSessionFp($fpToken);
    $_SESSION['cmt_tv'] = $tvStamp;
    // v4.6.0：记录登录时间（后台 24h 过期按登录时间算）
    $_SESSION['cmt_login_ts'] = time();
    issueRefreshToken($fresh['id'], $_SESSION['cmt_fp'], $tvStamp);
    $_SESSION['cmt_user'] = [
        'id' => $fresh['id'], 'account' => $qqNo, 'nickname' => $displayName,
        'avatar' => $avatarLink, 'signature' => '', 'role' => 'user',
        'email' => $mailBox,   // v2.10.0：注册即写邮箱，供个人设置展示/更换
        'pw_hash' => $fresh['password']
    ];
    $publicUser = sanitizeUserForClient($_SESSION['cmt_user']);
    sendJson(['success' => true, 'user' => $publicUser, 'env_bound' => true]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $ipAddr = getClientIP();
    if (isIPBanned($ipAddr, 'login')) sendJson(['success' => false, 'error' => '你的 IP 已被封禁，无法登录'], 403);
    // v4.7.0：联动威胁评分拦截（IP/指纹任一维度累计超阈值 → 联动封锁）
    $cooldownLeft = checkLinkedBlock();
    if ($cooldownLeft > 0) sendJson(['success' => false, 'error' => '触发联动风控，请 ' . $cooldownLeft . ' 秒后再试', 'locked_seconds' => $cooldownLeft], 429);
    $payload = json_decode(file_get_contents('php://input'), true);
    $qqNo = trim($payload['account'] ?? '');
    $secret = $payload['password'] ?? '';
    // v4.5.0：登录环境指纹（前端上报，服务端与 UA 一起绑定签名）
    $fpToken = getRequestFp();
    if (empty($qqNo) || empty($secret)) sendJson(['success' => false, 'error' => 'QQ号和密码不能为空'], 400);
    // v2.11.0：登录锁定检查（IP+账号双级；60 秒内失败 ≥3 次 → 锁 15 分钟）
    $lockRemain = loginLocked('ip:' . $ipAddr);
    if ($lockRemain <= 0) $lockRemain = loginLocked('account:' . $qqNo);
    if ($lockRemain > 0) {
        // v4.7.0：锁定期仍尝试 → 威胁计分（逐步升级联动封锁）
        logThreat('login_locked_try', $ipAddr, $fpToken, 60);
        sendJson(['success' => false, 'error' => '登录失败次数过多，请 ' . $lockRemain . ' 秒后重试', 'locked_seconds' => $lockRemain], 429);
    }
    $roster = fetchAllUsers();
    foreach ($roster as $candidate) {
        if (($candidate['account'] ?? '') === $qqNo && password_verify($secret, $candidate['password'])) {
            // v2.11.4：被禁用账号拒绝登录
            if (!empty($candidate['disabled'])) sendJson(['success' => false, 'error' => '该账号已被禁用，请联系管理员'], 403);
            // v4.7.0：管理角色陌生设备 → 邮件二次验证（密码通过后仍需验证码才完成登录）
            $isManager = in_array($candidate['role'] ?? '', [ROLE_SUPER_ADMIN, ROLE_STATION_ADMIN, ROLE_AUTHOR], true);
            $fpDigest = computeSessionFp($fpToken);
            if ($isManager && !isKnownDevice($candidate['id'], $fpDigest)) {
                // v4.7.3：验证码发送目标——账号绑定邮箱优先；管理角色未绑定时回退后台 admin_email（即超管的验证通道）
                $otpMail = trim((string)($candidate['email'] ?? ''));
                if ($otpMail === '') $otpMail = trim((string)(loadSiteConfig()['admin_email'] ?? ''));
                if ($otpMail === '') {
                    // 账号无邮箱且后台未配置管理员邮箱：跳过设备验证直接登录（OTP 入口+环境指纹+短会话已足够强）
                    auditLog('login_device_skipped', $candidate['id'], '管理角色未绑定邮箱且未配置管理员邮箱，跳过设备二次验证');
                    sendJson(finalizeLogin($candidate, $fpToken));
                }
                // 该设备正被陌生设备风控锁定 → 拒绝
                $fpLockRemain = loginLocked('fp:' . $fpDigest);
                if ($fpLockRemain > 0) sendJson(['success' => false, 'error' => '该设备触发风控，请 ' . $fpLockRemain . ' 秒后重试'], 429);
                $_SESSION['cmt_pending_dev'] = [
                    'uid' => $candidate['id'], 'fp_hash' => $fpDigest, 'fp' => $fpToken,
                    'email' => $otpMail, 'role' => $candidate['role'] ?? '',
                    'ts' => time(),   // v4.7.3：记录发起时间，check 恢复弹窗时按 10 分钟过期清理
                ];
                $_SESSION['dev_verify_fails'] = 0;
                [$sendOk, $sendErr] = email_code_send($otpMail, 'device_login', '陌生设备登录验证', $candidate['role'] ?? '');
                if (!$sendOk) sendJson(['success' => false, 'error' => $sendErr], 400);
                auditLog('login_device_pending', $candidate['id'], '陌生设备登录待二次验证（' . maskEmailAddr($otpMail) . '）');
                sendJson(['success' => false, 'need_device_verify' => true,
                    'masked_email' => maskEmailAddr($otpMail),
                    'ttl' => is_array($sendErr) ? ($sendErr['ttl'] ?? 300) : 300,
                    'error' => '新设备登录，验证码已发送至 ' . maskEmailAddr($otpMail)], 200);
            }
            sendJson(finalizeLogin($candidate, $fpToken));
        }
    }
    // v2.11.0/v4.5.0：失败计数（IP+账号+指纹三维，60 秒窗口 ≥3 → 锁 15 分钟）
    loginFailAdd($ipAddr, $qqNo, $fpToken);
    // v4.7.0：失败威胁计分——管理角色账号按「陌生设备失败」高权重（3 次即触发 15min 设备风控，逐次升级）
    $suspect = null;
    foreach ($roster as $candidate) { if (($candidate['account'] ?? '') === $qqNo) { $suspect = $candidate; break; } }
    $suspectIsManager = $suspect !== null && in_array($suspect['role'] ?? '', [ROLE_SUPER_ADMIN, ROLE_STATION_ADMIN, ROLE_AUTHOR], true);
    if ($suspectIsManager) {
        logThreat('device_login_fail', $ipAddr, $fpToken, 30);
        if ($fpToken !== '') fpRiskLock(computeSessionFp($fpToken));
    } else {
        logThreat('login_fail', $ipAddr, $fpToken, 30);
    }
    $failCount = loginFailCount($ipAddr, $qqNo, 60, $fpToken);
    $authCfg = loadSiteConfig();
    if ($failCount >= 3) {
        lockLogin('ip:' . $ipAddr, 900);
        lockLogin('account:' . $qqNo, 900);
        logThreat('login_lock', $ipAddr, $fpToken, 60);
        logAbnormal($ipAddr, '频繁错误登录（60秒内' . $failCount . '次，已锁定15分钟）');
        // v4.8.0：移除单一 addBan，全部走联动封禁（logThreat 已在上行写入）
        loginFailClear($ipAddr, $qqNo);
        sendJson(['success' => false, 'error' => '登录失败次数过多，请 900 秒后重试', 'locked_seconds' => 900], 429);
    }
    sendJson(['success' => false, 'error' => 'QQ号或密码错误'], 401);
}

// ===== v4.7.0：陌生设备登录邮件二次验证（验证码原子消费 → 记录设备 → 完成登录） =====
if ($action === 'device_verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $linkLeft = checkLinkedBlock();
    if ($linkLeft > 0) sendJson(['success' => false, 'error' => '触发联动风控，请 ' . $linkLeft . ' 秒后再试'], 429);
    $input = json_decode(file_get_contents('php://input'), true);
    $code = trim($input['code'] ?? '');
    $pending = $_SESSION['cmt_pending_dev'] ?? null;
    if (!is_array($pending) || empty($pending['uid']) || empty($pending['email'])) sendJson(['success' => false, 'error' => '验证会话已失效，请重新登录'], 400);
    // 重新加载用户（防等待验证码期间被禁用/删除）
    $users = fetchAllUsers();
    $u = null;
    foreach ($users as $uu) { if ($uu['id'] === $pending['uid']) { $u = $uu; break; } }
    if (!$u) { unset($_SESSION['cmt_pending_dev']); sendJson(['success' => false, 'error' => '账号不存在或已删除'], 400); }
    if (!empty($u['disabled'])) { unset($_SESSION['cmt_pending_dev']); sendJson(['success' => false, 'error' => '该账号已被禁用'], 403); }
    [$ok, $verr] = email_code_verify($pending['email'], $code, 'device_login');
    if (!$ok) {
        // 验证码输错 → 威胁计分；连续错过多直接清 pending 防爆破
        logThreat('device_code_fail', getClientIP(), $pending['fp'] ?? '', 30);
        $fails = (int)($_SESSION['dev_verify_fails'] ?? 0) + 1;
        $_SESSION['dev_verify_fails'] = $fails;
        if ($fails >= 5) unset($_SESSION['cmt_pending_dev'], $_SESSION['dev_verify_fails']);
        sendJson(['success' => false, 'error' => $verr, 'fails' => $fails], 400);
    }
    unset($_SESSION['dev_verify_fails']);
    // 验证通过：记录设备为已信任 → 完成登录
    recordDevice($u['id'], $pending['fp_hash'], $_SERVER['HTTP_USER_AGENT'] ?? '');
    unset($_SESSION['cmt_pending_dev']);
    auditLog('login_device_verified', $u['id'], '陌生设备验证通过，已加入信任设备');
    sendJson(finalizeLogin($u, $pending['fp'] ?? ''));
}

// ===== v4.7.0：找回密码（首页弹窗；管理角色仅限常用设备自助找回，陌生设备联系超管；超管协助走 admin_reset 一次性重置码） =====
if ($action === 'password_reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $mode = $input['mode'] ?? '';
    $account = trim($input['account'] ?? '');
    $email = trim($input['email'] ?? '');
    $code = trim($input['code'] ?? '');
    $newPw = $input['new_password'] ?? '';
    $reqFp = getRequestFp();
    $clientIP = getClientIP();

    if ($mode === 'send_code') {
        $linkLeft = checkLinkedBlock();
        if ($linkLeft > 0) sendJson(['success' => false, 'error' => '触发联动风控，请 ' . $linkLeft . ' 秒后再试'], 429);
        if (empty($account) || empty($email)) sendJson(['success' => false, 'error' => '请填写账号与绑定邮箱'], 400);
        // 发码限速（IP 60 秒 ≥5 次 → 计分）
        $recent = db_one('SELECT COUNT(*) AS c FROM email_codes WHERE ip = ? AND created > ?', [$clientIP, time() - 60])['c'] ?? 0;
        if ($recent >= 5) { logThreat('reset_flood', $clientIP, $reqFp, 300); sendJson(['success' => false, 'error' => '发送过于频繁，请稍后再试'], 429); }
        // 账号+邮箱双重匹配（不暴露账号是否存在，统一提示）
        $users = fetchAllUsers();
        $target = null;
        foreach ($users as $u) {
            if (($u['account'] ?? '') === $account && strcasecmp((string)($u['email'] ?? ''), $email) === 0) { $target = $u; break; }
        }
        if (!$target) sendJson(['success' => false, 'error' => '账号与邮箱不匹配'], 400);
        // 管理角色：仅常用设备可自助找回；陌生设备 → 联系超管（不发送验证码）
        $isMgmt = in_array($target['role'] ?? '', [ROLE_SUPER_ADMIN, ROLE_STATION_ADMIN, ROLE_AUTHOR], true);
        if ($isMgmt) {
            $fpHash = computeSessionFp($reqFp);
            if (!isKnownDevice($target['id'], $fpHash)) {
                sendJson(['success' => false, 'error' => '当前设备非常用设备，请使用常用设备找回，或联系站点管理员协助'], 403);
            }
        }
        [$ok, $err] = email_code_send($email, 'password_reset', '找回密码', $target['role'] ?? '');
        if (!$ok) sendJson(['success' => false, 'error' => $err], 400);
        auditLog('password_reset_send', $target['id'], '找回密码验证码已发送');
        sendJson(['success' => true, 'masked_email' => maskEmailAddr($email), 'ttl' => is_array($err) ? ($err['ttl'] ?? 300) : 300]);
    }

    if ($mode === 'do_reset') {
        $linkLeft = checkLinkedBlock();
        if ($linkLeft > 0) sendJson(['success' => false, 'error' => '触发联动风控，请 ' . $linkLeft . ' 秒后再试'], 429);
        if (empty($account) || empty($code) || empty($newPw)) sendJson(['success' => false, 'error' => '请完整填写账号、验证码与新密码'], 400);
        // v4.7.0：同账号验证码失败锁定——3 次失败锁 15 分钟（防 6 位验证码暴力枚举）
        $rstKey = 'rst:' . hash('sha256', 'account|' . strtolower($account));
        $rstLock = loginLocked($rstKey);
        if ($rstLock > 0) {
            logThreat('login_locked_try', $clientIP, $reqFp, 60);
            sendJson(['success' => false, 'error' => '验证失败次数过多，请 ' . $rstLock . ' 秒后重试', 'locked_seconds' => $rstLock], 429);
        }
        $users = fetchAllUsers();
        $target = null;
        foreach ($users as $u) { if (($u['account'] ?? '') === $account) { $target = $u; break; } }
        // 两种凭证：邮箱验证码（自助找回）或超管一次性重置码（协助找回）；
        // 账号不存在也走同一失败路径（统一提示，防存在性探测）
        [$ok1, $v1] = $target ? email_code_verify($target['email'] ?? '', $code, 'password_reset') : [false, ''];
        [$ok2, $v2] = $target ? email_code_verify($target['email'] ?? '', $code, 'admin_reset') : [false, ''];
        if (!$ok1 && !$ok2) {
            logThreat('code_fail', $clientIP, $reqFp, 30);
            db_exec('INSERT INTO login_fails (ip, t, acc, fp) VALUES (?,?,?,?)', [$clientIP, time(), 'rst:' . strtolower($account), '']);
            $rstFails = (int)(db_one('SELECT COUNT(*) AS c FROM login_fails WHERE acc = ? AND t > ?', ['rst:' . strtolower($account), time() - 900])['c'] ?? 0);
            if ($rstFails >= 3) {
                lockLogin($rstKey, 900);
                logThreat('login_lock', $clientIP, $reqFp, 60);
                sendJson(['success' => false, 'error' => '验证失败次数过多，请 900 秒后重试', 'locked_seconds' => 900], 429);
            }
            sendJson(['success' => false, 'error' => '验证码不正确或已过期'], 400);
        }
        $vp = validatePassword($newPw);
        if ($vp !== true) sendJson(['success' => false, 'error' => $vp], 400);
        $newHash = password_hash($newPw, PASSWORD_DEFAULT);
        $updated = false;
        foreach ($users as &$uu) { if ($uu['id'] === $target['id']) { $uu['password'] = $newHash; $updated = true; break; } }
        unset($uu);
        if (!$updated) sendJson(['success' => false, 'error' => '账号状态异常，请联系管理员'], 400);
        replaceAllUsers($users);
        db_exec('DELETE FROM login_fails WHERE acc = ?', ['rst:' . strtolower($account)]); // 重置成功清除失败计数
        // 密码已变：tv+1 踢全部旧会话 + 吊销 refresh token
        bumpUserTV($target['id']);
        revokeUserRefreshTokens($target['id']);
        // 若重置的是当前登录会话 → 强制退出
        if (($_SESSION['cmt_user']['id'] ?? '') === $target['id']) unset($_SESSION['cmt_user']);
        auditLog('password_reset', $target['id'], '通过验证码找回重置密码');
        // v4.7.2：密码重置邮件告警（区分自助找回 / 超管协助重置码）
        notifyPasswordReset($target, $clientIP, $ok2 ? 'admin_reset' : 'self_reset');
        sendJson(['success' => true]);
    }
    sendJson(['success' => false, 'error' => '未知操作'], 400);
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
    sendJson(['success' => true]);
}
// v4.5.0：refresh 接口——登录态过期后，用 httpOnly ysm_rt + 当前环境指纹自动续期（换环境则失败，需重新登录）
if ($action === 'refresh' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rt = $_COOKIE['ysm_rt'] ?? '';
    $reqFp = getRequestFp();
    $row = consumeRefreshToken($rt, $reqFp, -1); // 先按记录自身 tv 校验，再取用户当前 tv
    if (!$row) {
        clearRefreshCookie();
        sendJson(['success' => false, 'error' => '登录已过期或登录环境已变化，请重新登录'], 401);
    }
    $curTV = getUserTV($row['user_id']);
    if ((int)$row['tv'] !== $curTV) {
        revokeUserRefreshTokens($row['user_id']);
        clearRefreshCookie();
        sendJson(['success' => false, 'error' => '账号已在其他设备登录，当前会话已失效'], 401);
    }
    // 校验通过：重建登录态（保持同一 tv，不踢旧）
    session_regenerate_id(true);
    $found = null;
    foreach (fetchAllUsers() as $uu) {
        if ($uu['id'] === $row['user_id']) { $found = $uu; break; }
    }
    if (!$found) { clearRefreshCookie(); sendJson(['success' => false, 'error' => '账号不存在'], 401); }
    $_SESSION['cmt_fp'] = computeSessionFp($reqFp);
    $_SESSION['cmt_tv'] = (int)$curTV;
    // v4.6.0：保持原登录时间（refresh 不重置）。
    // v5.3.1：前台续期**不重建后台会话**（cmt_backend_ts 仅在完整认证时写入）——站长/写作者后台
    //         24h 到期后须重新验证身份方可再进后台，refresh 无法绕过。
    $_SESSION['cmt_login_ts'] = (int)($row['created'] ?? 0) ?: time();
    $_SESSION['cmt_user'] = [
        'id' => $found['id'], 'account' => $found['account'],
        'nickname' => $found['nickname'] ?? '',
        'avatar' => $found['avatar'] ?? resolveAvatarUrl($found['account'] ?? ''),
        'signature' => $found['signature'] ?? '',
        'role' => $found['role'] ?? 'user',
        'email' => $found['email'] ?? '',
        'pw_hash' => $found['password']
    ];
    $safeUser = sanitizeUserForClient($_SESSION['cmt_user']);
    sendJson(['success' => true, 'user' => $safeUser, 'env_bound' => true]);
}
if ($action === 'check') {
    $u = verifyHomeUser();
    if ($u) {
        // v4.5.0：登录态会话但环境校验失败（换浏览器/设备/隐私模式/被踢）→ 提示重新登录
        if (!requireSessionEnv()) {
            sendJson(['success' => true, 'loggedIn' => false, 'env_invalid' => true, 'error' => '登录环境已变化，请重新登录']);
        }
        $safeUser = sanitizeUserForClient($u);
        sendJson(['success' => true, 'loggedIn' => true, 'user' => $safeUser]);
    } else {
        // v2.7.1：超管且已开启「超管主页评论」时，前端按登录态渲染以启用评论框（主页仍无管理入口，见 user-status）
        $sess = verifySessionUser();
        $checkCfg = loadSiteConfig();
        if ($sess && ($sess['role'] ?? '') === ROLE_SUPER_ADMIN && !empty($checkCfg['super_admin_comment'])) {
            sendJson([
                'success' => true,
                'loggedIn' => true,
                'isSuperAdmin' => true,
                'user' => ['id' => $sess['id'], 'nickname' => $sess['nickname'] ?? '超管', 'role' => 'super_admin', 'avatar' => '', 'signature' => ''],
            ]);
        }
        // v4.7.3：陌生设备验证进行中（前端切后台看邮箱/刷新页面导致弹窗丢失）——报告 pending 状态，
        // 前端据此恢复设备验证弹窗；超过 10 分钟视为放弃，清理 pending 不再反复弹窗
        $pending = $_SESSION['cmt_pending_dev'] ?? null;
        if (is_array($pending) && !empty($pending['email'])) {
            $pTs = (int)($pending['ts'] ?? 0);
            if ($pTs > 0 && (time() - $pTs) > 600) {
                unset($_SESSION['cmt_pending_dev'], $_SESSION['dev_verify_fails']);
                $pending = null;
            }
        }
        if (is_array($pending) && !empty($pending['email'])) {
            sendJson([
                'success' => true,
                'loggedIn' => false,
                'pending_device_verify' => true,
                'masked_email' => maskEmailAddr($pending['email']),
            ]);
        }
        sendJson(['success' => true, 'loggedIn' => false]);
    }
}
if ($action === 'user-status') {
    // v2.6.5：超管在主页显示「超管」身份（右侧用户区），不提供「快捷进入管理」（后台仅 OTP 入口）；
    // v2.10.2：提供「退出登录」（与后台 logout 一致吊销 JWT）
    $sess = verifySessionUser();
    if ($sess && ($sess['role'] ?? '') === ROLE_SUPER_ADMIN) {
        sendJson([
            'success' => true,
            'loggedIn' => true,
            'isSuperAdmin' => true,
            'user' => ['id' => $sess['id'], 'nickname' => $sess['nickname'] ?? '超管', 'role' => 'super_admin'],
            'canAccessAdmin' => false,
            'adminUrl' => '',
        ]);
    }
    $u = verifyHomeUser();
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
        // v5.3.1：安全不降级——**取消** v4.6.0「从首页点击快捷进入管理即重置后台计时」的行为。
        // 该行为会让「仅凭前台登录态」重开后台会话（绕过重新验证）。此后后台会话只能由一次完整认证
        // （登录 / OTP）重建；前台登录态可继续浏览，但进后台必须重新验证。本接口返回结构保持不变。
        sendJson([
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
        sendJson(['success' => true, 'loggedIn' => false]);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_profile') {
    $actor = verifyHomeUser();
    if (!$actor) sendJson(['success' => false, 'error' => '请先登录'], 401);
    // v4.5.0：环境校验（改昵称/签名/密码等敏感操作）
    if (!requireSessionEnv()) sendJson(['success' => false, 'error' => '登录环境已变化，请重新登录', 'env_invalid' => true], 401);
    $payload = json_decode(file_get_contents('php://input'), true);
    $displayName = trim($payload['nickname'] ?? '');
    $motto = trim($payload['signature'] ?? '');
    if (empty($displayName)) sendJson(['success' => false, 'error' => '昵称不能为空'], 400);
    $displayName = mb_substr($displayName, 0, 20, 'UTF-8');
    $motto = mb_substr($motto, 0, 16, 'UTF-8');
    $roster = fetchAllUsers();
    foreach ($roster as &$entry) {
        if ($entry['id'] === $actor['id']) {
            $entry['nickname'] = $displayName;
            $entry['signature'] = $motto;
            break;
        }
    }
    unset($entry);
    replaceAllUsers($roster);
    $_SESSION['cmt_user']['nickname'] = $displayName;
    $_SESSION['cmt_user']['signature'] = $motto;
    sendJson(['success' => true, 'user' => sanitizeUserForClient($_SESSION['cmt_user'])]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'admin_setup') {
    $actor = verifySessionUser();
    if (!$actor || ($actor['role'] ?? '') !== ROLE_SUPER_ADMIN) { logAbnormal(getClientIP(), '越权尝试修改站长信息'); sendJson(['success' => false, 'error' => '无权限'], 403); }
    $payload = json_decode(file_get_contents('php://input'), true);
    $qqNo = trim($payload['account'] ?? '');
    $displayName = trim($payload['nickname'] ?? '');
    $secret = $payload['password'] ?? '';
    if (empty($qqNo)) sendJson(['success' => false, 'error' => '请填写QQ号'], 400);
    if (empty($displayName)) sendJson(['success' => false, 'error' => '请填写昵称'], 400);
    if ($secret) {
        $pwChk = validatePassword($secret);
        if ($pwChk !== true) sendJson(['success' => false, 'error' => $pwChk], 400);
    }
    $displayName = mb_substr($displayName, 0, 20, 'UTF-8');
    $avatarLink = resolveAvatarUrl($qqNo);
    $roster = fetchAllUsers();
    foreach ($roster as &$entry) {
        if ($entry['id'] === $actor['id']) {
            $entry['account'] = $qqNo;
            $entry['nickname'] = $displayName;
            $entry['avatar'] = $avatarLink;
            if ($secret) $entry['password'] = password_hash($secret, PASSWORD_DEFAULT);
            break;
        }
    }
    unset($entry);
    replaceAllUsers($roster);
    $_SESSION['cmt_user']['account'] = $qqNo;
    $_SESSION['cmt_user']['nickname'] = $displayName;
    $_SESSION['cmt_user']['avatar'] = $avatarLink;
    sendJson(['success' => true, 'user' => sanitizeUserForClient($_SESSION['cmt_user'])]);
}
if ($action === 'get') {
    $slug = $_GET['article'] ?? '';
    if (empty($slug)) sendJson(['success' => false, 'error' => '缺少文章参数'], 400);
    // v3.3.11：公告关联文章不展示评论区
    if (isAnnouncementLinkedArticle($slug)) sendJson(['success' => true, 'comments' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'total_pages' => 0]);
    // v3.2.6：对外脱敏评论者的 account 与 QQ 头像 URL（防枚举真实 QQ 号）
    // v3.3.15：评论区根评论分页（默认每页 20 条，独立实现不复用后台组件；回复随根评论整棵展示不单独分页）
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $all = fetchCommentTree($slug, true);
    // 根评论按时间倒序（新评论在前）
    usort($all, function($a, $b) { return strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''); });
    $total = count($all);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $comments = array_slice($all, ($page - 1) * $perPage, $perPage);
    sendJson(['success' => true, 'comments' => $comments, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => $totalPages]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'post') {
    $ipAddr = getClientIP();
    if (isIPBanned($ipAddr, 'comment')) sendJson(['success' => false, 'error' => '你的 IP 已被封禁，无法评论'], 403);
    // v4.7.1：联动封锁拦截（堵住 comment 绕过联动封锁路径）
    $cooldownLeft = checkLinkedBlock();
    if ($cooldownLeft > 0) sendJson(['success' => false, 'error' => '触发联动风控，请 ' . $cooldownLeft . ' 秒后再试', 'locked_seconds' => $cooldownLeft], 429);
    $cfgSnap = loadSiteConfig();
    if (!($cfgSnap['comments_enabled'] ?? true)) sendJson(['success' => false, 'error' => '评论区已关闭'], 403);
    // v2.6.5：超管身份默认不参与前台评论（可在超管后台「系统配置」开启 super_admin_comment）
    if (($_SESSION['cmt_user']['role'] ?? '') === ROLE_SUPER_ADMIN && empty($cfgSnap['super_admin_comment'])) {
        sendJson(['success' => false, 'error' => '超管身份不参与前台评论'], 403);
    }
    $actor = verifyHomeUser();
    // v2.7.1：开启「超管主页评论」后，超管以超管身份评论（不走访客分支）
    if (!$actor && ($_SESSION['cmt_user']['role'] ?? '') === ROLE_SUPER_ADMIN && !empty($cfgSnap['super_admin_comment'])) {
        $actor = verifySessionUser();
    }
    if (!$actor && empty($cfgSnap['guest_comments_enabled'])) sendJson(['success' => false, 'error' => '请先登录'], 401);
    if (!$actor && !empty($cfgSnap['guest_comments_enabled'])) {
        $actor = ['id' => 'guest', 'nickname' => '访客', 'avatar' => '', 'account' => '', 'role' => 'guest'];
    }
    // v4.5.0：登录态用户评论前校验环境（换浏览器/设备/被踢 → 拒绝，提示重新登录）；访客不受影响
    if (($actor['id'] ?? '') !== 'guest' && !requireSessionEnv()) {
        sendJson(['success' => false, 'error' => '登录环境已变化，请重新登录后再评论', 'env_invalid' => true], 401);
    }
    $fpToken = getRequestFp();
    db_rate_add('comment_rates', $ipAddr, $fpToken);
    $burstCount = db_rate_count('comment_rates', $ipAddr, 60, $fpToken); // 1 分钟窗口（指纹+IP 双维）
    $rateCap = max(1, intval($cfgSnap['max_comments_per_minute'] ?? 5));
    // 计数含本次（先 add 后 count）：用 > 才恰好放行 cap 次
    if ($burstCount > $rateCap) {
        logAbnormal($ipAddr, '频繁评论（' . $burstCount . '条/分钟）');
        logThreat('comment_flood', $ipAddr, $fpToken, 300);
        // v4.8.0：移除单一 addBan，全部走联动封禁（logThreat 已在上行写入）
        sendJson(['success' => false, 'error' => '评论太频繁，请稍后再试'], 429);
    }
    $payload = json_decode(file_get_contents('php://input'), true);
    $slug = trim($payload['article'] ?? '');
    $body = trim($payload['content'] ?? '');
    $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $body);
    if (empty($slug)) sendJson(['success' => false, 'error' => '缺少文章参数'], 400);
    if (isAnnouncementLinkedArticle($slug)) sendJson(['success' => false, 'error' => '公告不支持评论'], 403);
    if (empty($body)) sendJson(['success' => false, 'error' => '内容不能为空'], 400);
    if (mb_strlen($body, 'UTF-8') > 1000) sendJson(['success' => false, 'error' => '评论不能超过1000字'], 400);
    $thread = fetchCommentTree($slug);
    $roster = fetchAllUsers();
    $authorName = $actor['nickname'] ?? '用户';
    $authorMotto = '';
    $authorAvatar = $actor['avatar'] ?? '';
    $authorAccount = $actor['account'] ?? '';
    foreach ($roster as $entry) {
        if ($entry['id'] === $actor['id']) {
            $authorName = $entry['nickname'] ?? '用户';
            $authorMotto = $entry['signature'] ?? '';
            $authorAvatar = $entry['avatar'] ?? resolveAvatarUrl($entry['account'] ?? '');
            $authorAccount = $entry['account'] ?? '';
            break;
        }
    }
    $fresh = [
        'id' => genId(), 'user_id' => $actor['id'], 'account' => $authorAccount, 'nickname' => $authorName,
        'avatar' => $authorAvatar, 'signature' => $authorMotto, 'content' => $body,
        'likes' => 0, 'replies' => [], 'created_at' => date('Y-m-d H:i:s')
    ];
    $thread[] = $fresh;
    persistCommentTree($slug, $thread);
    // v4.0.0：评论邮件订阅通知（受 config comment_notify_enabled 控制，失败不阻断评论）
    notifyComment($slug, $authorName, $body);
    sendJson(['success' => true, 'comment' => $fresh]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reply') {
    $ipAddr = getClientIP();
    if (isIPBanned($ipAddr, 'comment')) sendJson(['success' => false, 'error' => '你的 IP 已被封禁，无法回复'], 403);
    // v4.7.1：联动封锁拦截（堵住 reply 绕过联动封锁路径）
    $cooldownLeft = checkLinkedBlock();
    if ($cooldownLeft > 0) sendJson(['success' => false, 'error' => '触发联动风控，请 ' . $cooldownLeft . ' 秒后再试', 'locked_seconds' => $cooldownLeft], 429);
    $actor = verifyHomeUser();
    $replyConfig = loadSiteConfig();
    // v2.6.5：超管身份默认不参与前台回复（可在超管后台「系统配置」开启 super_admin_comment）
    if (($_SESSION['cmt_user']['role'] ?? '') === ROLE_SUPER_ADMIN && empty($replyConfig['super_admin_comment'])) {
        sendJson(['success' => false, 'error' => '超管身份不参与前台回复'], 403);
    }
    // v2.7.1：开启「超管主页评论」后，超管以超管身份回复（不走访客分支）
    if (!$actor && ($_SESSION['cmt_user']['role'] ?? '') === ROLE_SUPER_ADMIN && !empty($replyConfig['super_admin_comment'])) {
        $actor = verifySessionUser();
    }
    if (!$actor && empty($replyConfig['guest_comments_enabled'])) sendJson(['success' => false, 'error' => '请先登录'], 401);
    if (!$actor && !empty($replyConfig['guest_comments_enabled'])) {
        $actor = ['id' => 'guest', 'nickname' => '访客', 'avatar' => '', 'account' => '', 'role' => 'guest'];
    }
    // v4.5.0：登录态用户回复前校验环境（换浏览器/设备/被踢 → 拒绝）；访客不受影响
    if (($actor['id'] ?? '') !== 'guest' && !requireSessionEnv()) {
        sendJson(['success' => false, 'error' => '登录环境已变化，请重新登录后再回复', 'env_invalid' => true], 401);
    }
    $fpToken = getRequestFp();
    db_rate_add('comment_rates', $ipAddr, $fpToken);
    $burstCount = db_rate_count('comment_rates', $ipAddr, 60, $fpToken);
    $rateCap = max(1, intval($replyConfig['max_comments_per_minute'] ?? 5));
    // 计数含本次（先 add 后 count）：用 > 才恰好放行 cap 次
    if ($burstCount > $rateCap) {
        logAbnormal($ipAddr, '频繁回复（' . $burstCount . '条/分钟）');
        logThreat('comment_flood', $ipAddr, $fpToken, 300);
        // v4.8.0：移除单一 addBan，全部走联动封禁（logThreat 已在上行写入）
        sendJson(['success' => false, 'error' => '回复太频繁，请稍后再试'], 429);
    }
    $payload = json_decode(file_get_contents('php://input'), true);
    $slug = trim($payload['article'] ?? '');
    $parentKey = trim($payload['parent_id'] ?? '');
    $body = trim($payload['content'] ?? '');
    $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $body);
    if (empty($slug) || empty($parentKey) || empty($body)) sendJson(['success' => false, 'error' => '参数不完整'], 400);
    if (isAnnouncementLinkedArticle($slug)) sendJson(['success' => false, 'error' => '公告不支持评论'], 403);
    if (mb_strlen($body, 'UTF-8') > 1000) sendJson(['success' => false, 'error' => '回复不能超过1000字'], 400);
    $thread = fetchCommentTree($slug);
    $roster = fetchAllUsers();
    $authorName = $actor['nickname'] ?? '用户';
    $authorAvatar = $actor['avatar'] ?? '';
    $authorAccount = $actor['account'] ?? '';
    foreach ($roster as $entry) {
        if ($entry['id'] === $actor['id']) {
            $authorName = $entry['nickname'] ?? '用户';
            $authorAvatar = $entry['avatar'] ?? resolveAvatarUrl($entry['account'] ?? '');
            $authorAccount = $entry['account'] ?? '';
            break;
        }
    }
    $answer = [
        'id' => genId(), 'user_id' => $actor['id'], 'account' => $authorAccount, 'nickname' => $authorName,
        'avatar' => $authorAvatar, 'content' => $body,
        'likes' => 0, 'replies' => [], 'created_at' => date('Y-m-d H:i:s')
    ];
    $inserted = false;
    $parentName = ''; // v4.0.0：回复通知带出被回复者昵称
    foreach ($thread as &$node) {
        if ($node['id'] === $parentKey) {
            if (!isset($node['replies'])) $node['replies'] = [];
            $node['replies'][] = $answer;
            $inserted = true;
            $parentName = $node['nickname'] ?? '';
            break;
        }
        if (!empty($node['replies'])) {
            if (attachReplyTo($node['replies'], $parentKey, $answer)) {
                $inserted = true;
                $parentName = lookupReplyNickname($node['replies'], $parentKey) ?? ($node['nickname'] ?? '');
                break;
            }
        }
    }
    unset($node);
    if ($inserted) {
        persistCommentTree($slug, $thread);
        // v4.0.0：回复邮件订阅通知（受 config comment_notify_enabled 控制）
        notifyComment($slug, $authorName, $body, true, $parentName);
        sendJson(['success' => true]);
    }
    sendJson(['success' => false, 'error' => '父评论不存在'], 404);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $actor = verifyHomeUser();
    // v2.7.1：开启「超管主页评论」后，超管以超管身份删除评论（超管本身具备管理员删除权限）
    if (!$actor && ($_SESSION['cmt_user']['role'] ?? '') === ROLE_SUPER_ADMIN && !empty(loadSiteConfig()['super_admin_comment'])) {
        $actor = verifySessionUser();
    }
    if (!$actor) sendJson(['success' => false, 'error' => '请先登录'], 401);
    // v4.5.0：登录态用户删除评论前校验环境（换浏览器/设备/被踢 → 拒绝）
    if (($actor['id'] ?? '') !== 'guest' && !requireSessionEnv()) {
        sendJson(['success' => false, 'error' => '登录环境已变化，请重新登录后再操作', 'env_invalid' => true], 401);
    }
    $payload = json_decode(file_get_contents('php://input'), true);
    $slug = trim($payload['article'] ?? '');
    $victimId = trim($payload['id'] ?? '');
    if (empty($slug) || empty($victimId)) sendJson(['success' => false, 'error' => '参数不完整'], 400);
    $thread = fetchCommentTree($slug);
    $privileged = in_array(($actor['role'] ?? ''), [ROLE_SUPER_ADMIN, ROLE_STATION_ADMIN]);
    $located = false;
    foreach ($thread as $pos => $node) {
        if ($node['id'] === $victimId && ($privileged || $node['user_id'] === $actor['id'])) {
            array_splice($thread, $pos, 1);
            $located = true;
            break;
        }
    }
    if (!$located) {
        foreach ($thread as &$node) {
            if (!empty($node['replies'])) {
                if (removeReplyFrom($node['replies'], $victimId, $actor['id'], $privileged)) {
                    $located = true;
                    break;
                }
            }
        }
        unset($node);
    }
    if ($located) { persistCommentTree($slug, $thread); sendJson(['success' => true]); }
    logAbnormal(getClientIP(), '越权尝试删除评论: ' . $victimId . ' (文章: ' . $slug . ')');
    sendJson(['success' => false, 'error' => '评论不存在或无权删除'], 404);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'bg_upload') {
    $actor = verifySessionUser();
    if (!$actor || ($actor['role'] ?? '') !== ROLE_SUPER_ADMIN) sendJson(['success' => false, 'error' => '无权限'], 403);
    if (!isset($_FILES['bg_image']) || $_FILES['bg_image']['error'] !== UPLOAD_ERR_OK) sendJson(['success' => false, 'error' => '上传失败'], 400);
    $upload = $_FILES['bg_image'];
    $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    $rawExt = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
    $probedMime = null;
    if (function_exists('getimagesize')) {
        $probe = @getimagesize($upload['tmp_name']);
        if ($probe && isset($probe['mime'])) $probedMime = $probe['mime'];
    }
    if (!$probedMime && isset($mimes[$rawExt])) {
        $probedMime = $mimes[$rawExt];
    }
    if (!$probedMime || !in_array($probedMime, array_values($mimes))) {
        sendJson(['success' => false, 'error' => '仅支持 JPG/PNG/GIF/WebP 格式'], 400);
    }
    if ($upload['size'] > 10 * 1024 * 1024) sendJson(['success' => false, 'error' => '文件大小不能超过 10MB'], 400);
    $keepExt = array_search($probedMime, $mimes) ?: $rawExt;
    $assetName = 'bg_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $keepExt;
    $assetDir = './data/bg/';
    if (!is_dir($assetDir)) mkdir($assetDir, 0755, true);
    if (move_uploaded_file($upload['tmp_name'], $assetDir . $assetName)) {
        sendJson(['success' => true, 'path' => 'data/bg/' . $assetName]);
    }
    sendJson(['success' => false, 'error' => '保存失败，请检查 data/bg/ 目录权限'], 500);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'bg_config') {
    $actor = verifySessionUser();
    if (!$actor || ($actor['role'] ?? '') !== ROLE_SUPER_ADMIN) sendJson(['success' => false, 'error' => '无权限'], 403);
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!$payload) sendJson(['success' => false, 'error' => '无效的请求数据'], 400);
    $settings = loadSiteConfig();
    $settings['bg_type'] = in_array($payload['bg_type'] ?? '', ['none', 'image', 'api']) ? $payload['bg_type'] : 'none';
    // bg_image 仅允许站内 data/bg/ 路径或 http(s) URL，防止 CSS 值注入
    $bgImg = trim($payload['bg_image'] ?? '');
    if ($bgImg !== '' && strpos($bgImg, 'data/bg/') !== 0 && !preg_match('#^https?://#i', $bgImg)) $bgImg = '';
    $settings['bg_image'] = $bgImg;
    // v4.2.0：API 背景固定默认源（留空保存自动回退固定 16:9 图片 API）
    $settings['bg_api_url'] = trim($payload['bg_api_url'] ?? '');
    if ($settings['bg_api_url'] === '') $settings['bg_api_url'] = FIXED_IMG_API;
    $settings['bg_blur_enabled'] = !empty($payload['bg_blur_enabled']);
    $settings['bg_blur_level'] = max(0, min(50, intval($payload['bg_blur_level'] ?? 0)));
    $settings['bg_card_opacity'] = max(50, min(100, intval($payload['bg_card_opacity'] ?? 100)));
    saveSiteConfig($settings);
    sendJson(['success' => true]);
}
if ($action === 'bg_config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $settings = loadSiteConfig();
    sendJson([
        'success' => true,
        'bg_type' => $settings['bg_type'] ?? 'none',
        'bg_image' => $settings['bg_image'] ?? '',
        'bg_api_url' => $settings['bg_api_url'] ?? '',
        'bg_blur_enabled' => !empty($settings['bg_blur_enabled']),
        'bg_blur_level' => $settings['bg_blur_level'] ?? 0,
        'bg_card_opacity' => $settings['bg_card_opacity'] ?? 100
    ]);
}

// ============================================================================
// v5.4.0-beta：AI 写作（服务端代理调用）
//   - 使用者：站长 / 写作者；超管不参与创作（其会话在校验中被排除 → 403）。
//   - 前端只发"动作 + 选中文本 + 目标服务商"；Key 永不出后端、永不回传明文。
//   - base_url 只取自服务端白名单（不接受任何用户传入 URL → 防 SSRF）。
// ============================================================================
/** 校验当前用户为"可参与创作"的站长/写作者（未登录/超管/普通用户一律 403） */
function aiActionUser() {
    $actor = verifyHomeUser();
    if (!$actor) sendJson(['success' => false, 'error' => '无权限'], 403);
    $role = $actor['role'] ?? '';
    if (!in_array($role, [ROLE_STATION_ADMIN, ROLE_AUTHOR], true)) {
        logUnauthorized('越权尝试使用 AI 写作接口');
        sendJson(['success' => false, 'error' => '无权限'], 403);
    }
    return $actor;
}
/** 校验当前用户且该角色已被超管开放 AI */
function aiActionUserAllowed() {
    $actor = aiActionUser();
    if (!aiRoleAllowed($actor['role'] ?? '')) sendJson(['success' => false, 'error' => 'AI 功能未开放'], 403);
    return $actor;
}
/** 流式输出一个 SSE 事件并立即 flush */
function aiSseEmit($payload) {
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    @flush();
}
function aiJsonBody() {
    $j = json_decode(file_get_contents('php://input'), true);
    return is_array($j) ? $j : [];
}

// GET ai_config：编辑器/后台据此决定入口可见性与选项（不含任何 Key 明文）
if ($action === 'ai_config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $actor = aiActionUser();
    $role = $actor['role'] ?? '';
    sendJson([
        'success' => true,
        'enabled' => !empty(loadSiteConfig()['ai_enabled']),
        'allowed' => aiRoleAllowed($role),
        'providers' => aiProvidersForClient(),
        'actions' => aiActions(),
        'styles' => aiStyleOptions(),
        'langs' => aiLangOptions(),
        'privacy_ack' => aiPrivacyAcked($actor['id']),
        'keys' => aiKeysForClient($actor['id']),
        'default_provider' => aiDefaultProvider($actor['id']),
    ]);
}

// POST ai_privacy_ack：每角色首次使用确认"内容将发送至第三方服务商"（记录已同意，避免每次弹）
if ($action === 'ai_privacy_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = aiActionUser();
    aiSetPrivacyAck($actor['id']);
    auditLog('ai_privacy_ack', $actor['id'], '确认 AI 隐私提示');
    sendJson(['success' => true]);
}

// GET ai_keys：返回该账号各服务商状态（已配置(****末四位)/未配置 + 是否默认），无明文
if ($action === 'ai_keys' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $actor = aiActionUser();
    sendJson([
        'success' => true,
        'providers' => aiProvidersForClient(),
        'keys' => aiKeysForClient($actor['id']),
        'default_provider' => aiDefaultProvider($actor['id']),
    ]);
}

// POST ai_key_test：连通性测试（用所选白名单 base_url + 该 Key + 模型名发最小请求）；不落库
if ($action === 'ai_key_test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = aiActionUserAllowed();
    if (!requireSessionEnv()) sendJson(['success' => false, 'error' => '登录环境已变化，请重新登录', 'env_invalid' => true], 401);
    $body = aiJsonBody();
    $provider = (string)($body['provider'] ?? '');
    $model = trim((string)($body['model'] ?? ''));
    $key = trim((string)($body['key'] ?? ''));
    if (aiProviderBaseUrl($provider) === '') {
        auditLog('ai_key_test', $provider, '连通性测试：服务商不可用', 'failed');
        sendJson(['success' => false, 'ok' => false, 'message' => '服务商不可用'], 400);
    }
    [$ok, $reason] = aiConnectivityTest($provider, $key, $model);
    auditLog('ai_key_test', $provider, '连通性测试：' . ($ok ? '成功' : '失败'), $ok ? 'success' : 'failed');
    sendJson(['success' => $ok, 'ok' => $ok, 'message' => $ok ? '连接成功' : $reason]);
}

// POST ai_key_save：必须先做连通性测试，通过才固化（失败不落库，返回可读原因）
if ($action === 'ai_key_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = aiActionUserAllowed();
    if (!requireSessionEnv()) sendJson(['success' => false, 'error' => '登录环境已变化，请重新登录', 'env_invalid' => true], 401);
    $body = aiJsonBody();
    $provider = (string)($body['provider'] ?? '');
    $model = trim((string)($body['model'] ?? ''));
    $key = trim((string)($body['key'] ?? ''));
    $isDefault = !empty($body['is_default']);
    if (aiProviderBaseUrl($provider) === '') {
        auditLog('ai_key_save', $provider, '保存失败：服务商不可用', 'failed');
        sendJson(['success' => false, 'error' => '服务商不可用'], 400);
    }
    if ($key === '') sendJson(['success' => false, 'error' => '请填写 Key'], 400);
    if ($model === '') sendJson(['success' => false, 'error' => '请填写模型名'], 400);
    // 未通过连通性测试 → 拒绝固化（不落库）
    [$ok, $reason] = aiConnectivityTest($provider, $key, $model);
    if (!$ok) {
        auditLog('ai_key_save', $provider, '连通性测试未通过，未保存', 'failed');
        sendJson(['success' => false, 'error' => $reason, 'test_failed' => true], 400);
    }
    $exist = db_one('SELECT 1 AS x FROM ai_keys WHERE user_id = ? AND provider = ?', [$actor['id'], $provider]);
    if (!aiSaveKey($actor['id'], $provider, $model, $key, $isDefault)) {
        auditLog('ai_key_save', $provider, '保存失败（加密不可用）', 'failed');
        sendJson(['success' => false, 'error' => '保存失败'], 500);
    }
    auditLog('ai_key_' . ($exist ? 'update' : 'add'), $provider,
        ($exist ? '更新' : '新增') . ' Key（模型=' . $model . '；测试通过；不留明文）');
    sendJson(['success' => true, 'keys' => aiKeysForClient($actor['id']), 'default_provider' => aiDefaultProvider($actor['id'])]);
}

// POST ai_key_delete：删除该账号某服务商 Key
if ($action === 'ai_key_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = aiActionUser();
    if (!requireSessionEnv()) sendJson(['success' => false, 'error' => '登录环境已变化，请重新登录', 'env_invalid' => true], 401);
    $body = aiJsonBody();
    $provider = (string)($body['provider'] ?? '');
    if (!isset(aiBuiltinProviders()[$provider])) sendJson(['success' => false, 'error' => '服务商不存在'], 400);
    $n = aiDeleteKey($actor['id'], $provider);
    auditLog('ai_key_delete', $provider, '删除 Key');
    // 删除后若默认缺失，自动补一个默认
    sendJson(['success' => true, 'deleted' => $n, 'keys' => aiKeysForClient($actor['id']), 'default_provider' => aiDefaultProvider($actor['id'])]);
}

// POST ai_run：AI 代理调用（流式 SSE 或普通 JSON）；Key/正文均不出后端记录
if ($action === 'ai_run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = aiActionUserAllowed();
    if (!requireSessionEnv()) sendJson(['success' => false, 'error' => '登录环境已变化，请重新登录', 'env_invalid' => true], 401);
    $body = aiJsonBody();
    $act = (string)($body['action'] ?? '');
    if (!array_key_exists($act, aiActions())) sendJson(['success' => false, 'error' => '不支持的动作'], 400);
    $text = trim((string)($body['text'] ?? ''));
    if ($text === '') sendJson(['success' => false, 'error' => '请先选中要处理的文本'], 400);
    if (mb_strlen($text) > AI_MAX_INPUT) sendJson(['success' => false, 'error' => '选中文本过长（上限 ' . AI_MAX_INPUT . ' 字）'], 400);
    $style = in_array((string)($body['style'] ?? ''), aiStyleOptions(), true) ? (string)$body['style'] : '';
    $lang = in_array((string)($body['lang'] ?? ''), aiLangOptions(), true) ? (string)$body['lang'] : '';
    if ($act === 'style' && $style === '') sendJson(['success' => false, 'error' => '请选择目标风格'], 400);
    if ($act === 'translate' && $lang === '') sendJson(['success' => false, 'error' => '请选择目标语言'], 400);
    // 目标服务商：仅接受白名单内 id（不接受 base_url）；缺省用账号默认
    $provider = (string)($body['provider'] ?? '');
    if (aiProviderBaseUrl($provider) === '') $provider = aiDefaultProvider($actor['id']);
    if ($provider === '' || aiProviderBaseUrl($provider) === '') {
        sendJson(['success' => false, 'error' => '请先在后台配置 AI Key'], 400);
    }
    $row = db_one('SELECT * FROM ai_keys WHERE user_id = ? AND provider = ?', [$actor['id'], $provider]);
    if (!$row) sendJson(['success' => false, 'error' => '该服务商尚未配置 Key'], 400);
    $apiKey = aiDecryptKey($row['key_cipher']);
    if ($apiKey === '') sendJson(['success' => false, 'error' => 'Key 不可用，请重新配置'], 400);
    $model = trim((string)$row['model']);
    if ($model === '') sendJson(['success' => false, 'error' => '未填写模型名'], 400);
    // 出站限速（复用 ai_rates；防滥用放大外呼）
    $ip = getClientIP();
    $fp = $_SESSION['cmt_fp'] ?? '';
    if (aiRateBlocked($ip, $fp)) sendJson(['success' => false, 'error' => '操作过于频繁，请稍后再试'], 429);
    aiRateHit($ip, $fp);
    $messages = aiBuildMessages($act, $text, $style, $lang);
    $base = aiProviderBaseUrl($provider);
    $url = rtrim($base, '/') . '/chat/completions';
    $t0 = microtime(true);
    $stream = !empty($body['stream']);
    if (!$stream) {
        $res = aiHttpJson($url, $apiKey, ['model' => $model, 'messages' => $messages, 'stream' => false], AI_TIMEOUT);
        $ms = (int)((microtime(true) - $t0) * 1000);
        if (!$res['ok']) {
            $reason = aiReadableError($res['code'], $res['err'], $res['raw']);
            auditLog('ai_call', $provider . '/' . $model, "动作={$act}; 耗时={$ms}ms; 失败", 'failed');
            sendJson(['success' => false, 'error' => $reason !== '' ? $reason : '调用失败'], 400);
        }
        $out = (string)($res['data']['choices'][0]['message']['content'] ?? '');
        auditLog('ai_call', $provider . '/' . $model, "动作={$act}; 耗时={$ms}ms; 成功");
        sendJson(['success' => true, 'result' => $out]);
    }
    // ===== 流式（SSE）=====
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) { @ob_end_flush(); }
    if (!function_exists('curl_init')) {
        aiSseEmit(['event' => 'error', 'message' => '服务器不支持流式传输']);
        exit;
    }
    aiSseEmit(['event' => 'start', 'provider' => $provider, 'model' => $model]);
    $buf = ''; $acc = ''; $rawHead = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'messages' => $messages, 'stream' => true], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey, 'Accept: text/event-stream'],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => AI_TIMEOUT_STREAM,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf, &$acc, &$rawHead) {
            if (strlen($rawHead) < 400) $rawHead .= $chunk;
            $buf .= $chunk;
            while (($nl = strpos($buf, "\n")) !== false) {
                $line = rtrim(substr($buf, 0, $nl), "\r");
                $buf = substr($buf, $nl + 1);
                if ($line === '' || strpos($line, 'data:') !== 0) continue;
                $jsonStr = trim(substr($line, 5));
                if ($jsonStr === '[DONE]') continue;
                $j = json_decode($jsonStr, true);
                if (!is_array($j)) continue;
                $delta = $j['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '' && $delta !== null) {
                    $acc .= $delta;
                    aiSseEmit(['event' => 'delta', 'text' => $delta]);
                }
            }
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    $ms = (int)((microtime(true) - $t0) * 1000);
    if ($acc === '' && ($ok === false || $code >= 400)) {
        $reason = aiReadableError($code, $cerr, $rawHead);
        aiSseEmit(['event' => 'error', 'message' => $reason !== '' ? $reason : '调用失败']);
        auditLog('ai_call', $provider . '/' . $model, "动作={$act}; 耗时={$ms}ms; 失败", 'failed');
    } else {
        aiSseEmit(['event' => 'done', 'chars' => mb_strlen($acc)]);
        auditLog('ai_call', $provider . '/' . $model, "动作={$act}; 耗时={$ms}ms; 成功");
    }
    exit;
}

sendJson(['success' => false, 'error' => '未知操作'], 400);
