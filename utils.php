<?php
require_once __DIR__ . '/db.php';
define('APP_CONFIG_FILE', __DIR__ . '/app-config.json');
function loadAppConfig() {
    static $config = null;
    if ($config !== null) return $config;
    $config = [];
    if (file_exists(APP_CONFIG_FILE)) {
        $config = json_decode(file_get_contents(APP_CONFIG_FILE), true) ?: [];
    }
    return $config;
}
function appConfig($key, $default = '') {
    $config = loadAppConfig();
    return (isset($config[$key]) && $config[$key] !== '') ? $config[$key] : $default;
}
// 版本唯一事实来源：app-config.json 的 version；代码禁止硬编码版本号
define('APP_VERSION', appConfig('version', '0.0.0'));
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function verifyCsrfToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) return false;
    $valid = hash_equals($_SESSION['csrf_token'], $token);
    unset($_SESSION['csrf_token']);
    return $valid;
}
function checkCsrfToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}
// v2.6.4：统一密码策略——至少 8 位，且必须同时包含大写字母、小写字母与数字
// 返回 true 表示合规，否则返回错误提示字符串（供各注册/改密/建号入口统一使用）
function validatePassword($pw) {
    if (strlen($pw) < 8) return '密码至少 8 位';
    if (!preg_match('/[a-z]/', $pw)) return '密码必须包含小写字母';
    if (!preg_match('/[A-Z]/', $pw)) return '密码必须包含大写字母';
    if (!preg_match('/[0-9]/', $pw)) return '密码必须包含数字';
    return true;
}
function isPrivateHost($host) {
    $host = strtolower(trim(trim((string)$host), '[]'));
    if ($host === '') return true;
    if ($host === 'localhost' || $host === '0') return true;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ip = $host;
    } else {
        $ip = @gethostbyname($host);
        if ($ip === $host) return false; // 解析失败，不拦截
    }
    if ($ip === '::1' || $ip === '::') return true;
    $long = ip2long($ip);
    if ($long === false) return false;
    $ranges = [
        [ip2long('0.0.0.0'), ip2long('0.255.255.255')],
        [ip2long('10.0.0.0'), ip2long('10.255.255.255')],
        [ip2long('127.0.0.0'), ip2long('127.255.255.255')],
        [ip2long('169.254.0.0'), ip2long('169.254.255.255')],
        [ip2long('172.16.0.0'), ip2long('172.31.255.255')],
        [ip2long('192.168.0.0'), ip2long('192.168.255.255')],
    ];
    foreach ($ranges as $r) {
        if ($long >= $r[0] && $long <= $r[1]) return true;
    }
    return false;
}
function loadUsers() {
    return db_all('SELECT * FROM users ORDER BY rowid');
}
function saveUsers($users) {
    // 全量替换（保持原函数语义：写入完整用户列表）
    // v2.5.4：INSERT OR REPLACE 防止列表内重复 id 触发唯一约束冲突导致事务回滚
    // v2.9.0：列清单加入 email（注册验证引入，漏列会导致全量替换时邮箱丢失）
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM users');
        $st = $pdo->prepare('INSERT OR REPLACE INTO users (id, qq, nickname, password, avatar, signature, role, station_id, created, created_by, email, disabled, last_login, login_count) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($users as $u) {
            $st->execute([
                $u['id'] ?? '', $u['qq'] ?? '', $u['nickname'] ?? '', $u['password'] ?? '',
                $u['avatar'] ?? '', $u['signature'] ?? '', $u['role'] ?? 'user',
                $u['station_id'] ?? '', $u['created'] ?? '', $u['created_by'] ?? '',
                $u['email'] ?? '', (int)($u['disabled'] ?? 0),
                $u['last_login'] ?? '', (int)($u['login_count'] ?? 0),
            ]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
function genId() { return bin2hex(random_bytes(8)); }

/** v2.11.5：生成随机强密码（符合统一策略：至少 8 位且同时包含大小写字母与数字；用于超管重置用户密码） */
function randomPassword($len = 12) {
    $lower = 'abcdefghijkmnpqrstuvwxyz';
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $digits = '23456789';
    $pwd = $lower[random_int(0, strlen($lower) - 1)]
        . $upper[random_int(0, strlen($upper) - 1)]
        . $digits[random_int(0, strlen($digits) - 1)];
    $pool = $lower . $upper . $digits;
    for ($i = 3; $i < $len; $i++) $pwd .= $pool[random_int(0, strlen($pool) - 1)];
    return str_shuffle($pwd);
}

function loadSiteConfig() {
    $defaults = [
        'site_title' => 'You Super Markdown',
        'reg_limit_per_ip' => 3,
        'comments_enabled' => true,
        'auto_ban' => true,
        'auto_ban_unauthorized' => false,
        'registration_enabled' => true,
        'guest_comments_enabled' => false,
        'max_login_fails' => 10,
        'max_comments_per_minute' => 5,
        'max_registrations_per_ip' => 3,
        'station_path' => 'station',
        'author_path' => 'author',
        'hide_default_paths' => true,
        // v2.9.0 注册验证与双重确认开关（正式版默认启用 / 测试版默认禁用，后台可随时切换）
        // v2.11.0：人机滑块验证已彻底移除（原 captcha_enabled 配置删除）
        'email_verify_enabled' => true,      // 注册邮箱验证码总开关
        'author_dual_verify_enabled' => true, // 站长创建写作者双重确认开关
        'verify_code_ttl' => 300,            // 验证码有效期（秒）
        'confirm_link_ttl' => 86400,         // 超管确认链接有效期（秒）
        'resend_cooldown' => 60,             // 验证码重发冷却（秒），超管后台操作不受限
    ];
    $rows = db_all('SELECT key, value FROM config');
    $config = $defaults;
    foreach ($rows as $r) {
        $config[$r['key']] = json_decode($r['value'], true);
    }
    return $config;
}
function saveSiteConfig($config) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // v2.5.4：逐 key 增量 upsert（config 无删除场景，去掉全表 DELETE 减少写放大）
        $st = $pdo->prepare('INSERT OR REPLACE INTO config (key, value) VALUES (?,?)');
        foreach ($config as $k => $v) {
            $st->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
        }
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
function getStationPath() {
    $config = loadSiteConfig();
    return ($config['station_path'] ?? 'station') ?: 'station';
}
function getAuthorPath() {
    $config = loadSiteConfig();
    return ($config['author_path'] ?? 'author') ?: 'author';
}
function isDefaultPathHidden() {
    $config = loadSiteConfig();
    return !empty($config['hide_default_paths']);
}
function validateCustomPath($path) {
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]{2,28}[a-zA-Z0-9]$/', $path)) {
        return '路径仅允许字母、数字和连字符，长度4-30字符，首尾必须是字母或数字';
    }
    $reserved = ['admin', 'api', 'data', 'css', 'js', 'fonts', 'music', 'sc',
                 'index', '404', 'youyou', 'oauth', 'login', 'logout', 'register'];
    if (in_array(strtolower($path), $reserved, true)) {
        return '该路径为系统保留关键字，请换一个';
    }
    return true;
}
function loadBansList() {
    $rows = db_all('SELECT ip, types_json, reason, time FROM bans ORDER BY time DESC');
    $bans = [];
    foreach ($rows as $r) {
        $bans[] = [
            'ip' => $r['ip'],
            'types' => json_decode($r['types_json'] ?? '[]', true) ?: [],
            'reason' => $r['reason'] ?? '',
            'time' => $r['time'] ?? '',
        ];
    }
    return $bans;
}
function saveBansList($bans) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 全量替换（解封 = 从列表移除，必须保留删除语义）；v2.5.4 用 OR REPLACE 防重复 ip 冲突
        $pdo->exec('DELETE FROM bans');
        $st = $pdo->prepare('INSERT OR REPLACE INTO bans (ip, types_json, reason, time) VALUES (?,?,?,?)');
        foreach ($bans as $b) {
            $st->execute([
                $b['ip'] ?? '', json_encode($b['types'] ?? [], JSON_UNESCAPED_UNICODE),
                $b['reason'] ?? '', $b['time'] ?? '',
            ]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
function addBan($ip, $types, $reason = '') {
    $existing = db_one('SELECT * FROM bans WHERE ip = ?', [$ip]);
    if ($existing) {
        $merged = json_decode($existing['types_json'] ?? '[]', true) ?: [];
        foreach ($types as $t) { if (!in_array($t, $merged)) $merged[] = $t; }
        db_exec('UPDATE bans SET types_json = ?, reason = ? WHERE ip = ?',
            [json_encode($merged, JSON_UNESCAPED_UNICODE), $reason, $ip]);
    } else {
        db_exec('INSERT INTO bans (ip, types_json, reason, time) VALUES (?,?,?,?)',
            [$ip, json_encode($types, JSON_UNESCAPED_UNICODE), $reason, date('Y-m-d H:i:s')]);
    }
}
function isIPBanned($ip, $type) {
    $row = db_one('SELECT types_json FROM bans WHERE ip = ?', [$ip]);
    if (!$row) return false;
    $types = json_decode($row['types_json'] ?? '[]', true) ?: [];
    return in_array($type, $types, true);
}
function getClientIP() {
    if (!empty($_SERVER['HTTP_X_REAL_IP']) && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1') {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function loadLogsList() {
    return db_all('SELECT ip, action, time FROM logs ORDER BY time ASC');
}
function saveLogsList($logs) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM logs');
        $st = $pdo->prepare('INSERT INTO logs (ip, action, time) VALUES (?,?,?)');
        foreach ($logs as $l) {
            $st->execute([$l['ip'] ?? '', $l['action'] ?? '', $l['time'] ?? '']);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
function logAbnormal($ip, $action) {
    db_exec('INSERT INTO logs (ip, action, time) VALUES (?,?,?)', [$ip, $action, date('Y-m-d H:i:s')]);
    // 上限 500 条
    $cnt = db_one('SELECT COUNT(*) AS c FROM logs')['c'] ?? 0;
    if ($cnt > 500) {
        db_exec('DELETE FROM logs WHERE rowid IN (SELECT rowid FROM logs ORDER BY rowid ASC LIMIT ?)', [$cnt - 500]);
    }
}
function logUnauthorized($action, $ban = false) {
    $ip = getClientIP();
    db_exec('INSERT INTO unauthorized (ip, action, user, user_id, ua, time) VALUES (?,?,?,?,?,?)', [
        $ip,
        $action,
        $_SESSION['cmt_user']['nickname'] ?? '未登录',
        $_SESSION['cmt_user']['id'] ?? '',
        mb_substr(htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? '', ENT_QUOTES, 'UTF-8'), 0, 256),
        date('Y-m-d H:i:s')
    ]);
    // 上限 1000 条
    $cnt = db_one('SELECT COUNT(*) AS c FROM unauthorized')['c'] ?? 0;
    if ($cnt > 1000) {
        db_exec('DELETE FROM unauthorized WHERE rowid IN (SELECT rowid FROM unauthorized ORDER BY rowid ASC LIMIT ?)', [$cnt - 1000]);
    }
    if ($ban) {
        $config = loadSiteConfig();
        if (!empty($config['auto_ban_unauthorized'])) {
            addBan($ip, ['register', 'comment', 'login'], '自动封禁：越权操作 - ' . $action);
            logAbnormal($ip, '自动封禁越权用户: ' . $action);
        }
    }
}
// ============================================================
// v2.2 五层角色体系
// ============================================================
define('ROLE_SUPER_ADMIN', 'super_admin');
define('ROLE_STATION_ADMIN', 'station_admin');
define('ROLE_AUTHOR', 'author');
define('ROLE_USER', 'user');
define('ROLE_GUEST', 'guest');
define('ROLE_HIERARCHY', [
    ROLE_SUPER_ADMIN => 50,
    ROLE_STATION_ADMIN => 40,
    ROLE_AUTHOR => 30,
    ROLE_USER => 20,
    ROLE_GUEST => 10,
    'admin' => 40,  // 向后兼容旧版 admin 角色
]);

function loadRoles() {
    // 角色定义基本静态，从 meta 表读取覆盖（若无则用默认值）
    $defaults = [
        ROLE_SUPER_ADMIN => ['label' => '高级管理员', 'can' => ['*']],
        ROLE_STATION_ADMIN => ['label' => '站长', 'can' => ['article.create','article.edit','article.delete','article.edit_any','article.delete_any','author.create','author.delete','user.view']],
        ROLE_AUTHOR => ['label' => '写作者', 'can' => ['article.create','article.edit_own','article.delete_own']],
        ROLE_USER => ['label' => '用户', 'can' => ['comment.create','profile.edit']],
        ROLE_GUEST => ['label' => '访客', 'can' => ['article.read']],
    ];
    $row = db_one('SELECT value FROM meta WHERE key = ?', ['roles']);
    if ($row) {
        $d = json_decode($row['value'], true);
        if (is_array($d)) return $d;
    }
    return $defaults;
}

function checkRole($requiredRole) {
    $user = $_SESSION['cmt_user'] ?? null;
    if (!$user) return false;
    $userRole = $user['role'] ?? ROLE_GUEST;
    $userLevel = ROLE_HIERARCHY[$userRole] ?? 0;
    $requiredLevel = ROLE_HIERARCHY[$requiredRole] ?? 0;
    return $userLevel >= $requiredLevel;
}

function requireRole($role) {
    if (!checkRole($role)) {
        logUnauthorized("越权尝试：需要 {$role} 角色");
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => '权限不足'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function getCurrentUserRole() {
    return $_SESSION['cmt_user']['role'] ?? ROLE_GUEST;
}

function getCurrentUserId() {
    return $_SESSION['cmt_user']['id'] ?? '';
}

// ============================================================
// v2.2 JWT 认证
// ============================================================
function getJWTSecret() {
    $f = __DIR__ . '/data/.jwt_secret';
    if (!file_exists($f)) {
        file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX);
        chmod($f, 0600);
    }
    return file_get_contents($f);
}

function generateJWT($userId, $role, $ttl = 1800) {
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode([
        'sub' => $userId,
        'role' => $role,
        'iat' => time(),
        'exp' => time() + $ttl,
        'jti' => bin2hex(random_bytes(8))
    ])), '+/', '-_'), '=');
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", getJWTSecret(), true)), '+/', '-_'), '=');
    return "{$header}.{$payload}.{$signature}";
}

function validateJWT($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    [$header, $payload, $signature] = $parts;
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", getJWTSecret(), true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $signature)) return false;
    $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    if (!$data || ($data['exp'] ?? 0) < time()) return false;
    // v2.10.0-fix：jti 黑名单吊销检查（登出后即使 JWT 未过期、session 残留也立即失效）
    if (jwt_blacklist_has($data['jti'] ?? '')) return false;
    // v2.7.2：吊销校验——签发用户已不在 users 表（被删除/吊销）时视为无效，会话立即失效
    $uid = $data['sub'] ?? '';
    if ($uid !== '') {
        $found = false;
        foreach (loadUsers() as $u) {
            if ($u['id'] === $uid) { $found = true; break; }
        }
        if (!$found) return false;
    }
    return $data;
}

// v2.10.0-fix：JWT 吊销链——jti 黑名单（登出即吊销，JWT 三层吊销链的最后一层兜底）
function jwt_blacklist_add($jti, $exp) {
    if (!is_string($jti) || $jti === '') return;
    // 顺带清理已过期条目，防止黑名单表无限增长
    db_exec('DELETE FROM jwt_blacklist WHERE expires < ?', [time()]);
    db_exec('INSERT OR REPLACE INTO jwt_blacklist (jti, expires, created) VALUES (?,?,?)', [$jti, (int)$exp, time()]);
}
function jwt_blacklist_has($jti) {
    if (!is_string($jti) || $jti === '') return false;
    $r = db_one('SELECT 1 AS x FROM jwt_blacklist WHERE jti = ?', [$jti]);
    return !empty($r);
}
// 吊销当前会话 JWT（登出时调用）：解码 session 中 jwt 的 jti 并加入黑名单
function revokeCurrentJWT() {
    $jwt = $_SESSION['cmt_user']['jwt'] ?? '';
    if ($jwt === '') return;
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return;
    $data = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    if (!$data) return;
    jwt_blacklist_add($data['jti'] ?? '', (int)($data['exp'] ?? time()));
}

// v2.7.2：后台鉴权校验当前登录用户在 users 表仍存在（账号被删/吊销后会话立即失效）
// 站长/写作者后台无 JWT（仅超管 OTP 用 JWT），依赖 Session + checkRole，需此校验兜底
function validateBackendUser() {
    $uid = $_SESSION['cmt_user']['id'] ?? '';
    if ($uid === '') return false;
    foreach (loadUsers() as $u) {
        if ($u['id'] === $uid) {
            // v2.11.4：被禁用账号后台立即失效（踢出会话）
            if (!empty($u['disabled'])) return false;
            return true;
        }
    }
    return false;
}

// ============================================================
// v2.2 审计日志 + 哈希链（SQLite 存储）
// ============================================================
// 链尾文件保留（root 只读镜像 + 守护背书依赖）；主日志存 audit 表
define('AUDIT_CHAIN_FILE', __DIR__ . '/data/.audit_chain');
define('AUDIT_MIRROR_DIR', '/opt/you-markdown/logs/');
define('AUDIT_MIRROR_DB', AUDIT_MIRROR_DIR . 'ym.db');
define('EMAIL_ALERT', '/usr/local/bin/ym-alert');

function auditLog($action, $target = '', $detail = '', $result = 'success') {
    $user = $_SESSION['cmt_user'] ?? null;
    $entry = [
        'id' => bin2hex(random_bytes(8)),
        'ts' => date('Y-m-d H:i:s.v'),
        'user_id' => $user['id'] ?? 'guest',
        'user_name' => $user['nickname'] ?? '访客',
        'role' => $user['role'] ?? ROLE_GUEST,
        'ip' => getClientIP(),
        'action' => $action,
        'target' => $target,
        'detail' => $detail,
        'result' => $result,
    ];
    // v2.8.1 修复（踩坑 #25）：prev_hash 以 audit 表最后一条 hash 为唯一真源，
    // 不再信任 .audit_chain 链尾文件——镜像背书与链尾文件独立更新可能错位，
    // 恢复审计表后链尾文件残留旧值会导致下一条记录断链。链尾文件仅作冗余备份照常刷新。
    $prevHash = '';
    try {
        $last = db_one('SELECT hash FROM audit ORDER BY rowid DESC LIMIT 1');
        if ($last && !empty($last['hash'])) {
            $prevHash = $last['hash'];
        }
    } catch (Exception $e) {
        // 表异常时兜底读链尾文件（不应发生，仅防御）
        if (file_exists(AUDIT_CHAIN_FILE)) {
            $prevHash = trim(file_get_contents(AUDIT_CHAIN_FILE));
        }
    }
    $entry['prev_hash'] = $prevHash;
    $entryJson = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $entry['hash'] = hash('sha256', $entryJson);

    db_exec('INSERT INTO audit (id,ts,user_id,user_name,role,ip,action,target,detail,result,hash,prev_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
        $entry['id'], $entry['ts'], $entry['user_id'], $entry['user_name'], $entry['role'],
        $entry['ip'], $entry['action'], $entry['target'], $entry['detail'], $entry['result'],
        $entry['hash'], $prevHash,
    ]);
    // 上限 10000 条
    $cnt = db_one('SELECT COUNT(*) AS c FROM audit')['c'] ?? 0;
    if ($cnt > 10000) {
        db_exec('DELETE FROM audit WHERE rowid IN (SELECT rowid FROM audit ORDER BY rowid ASC LIMIT ?)', [$cnt - 10000]);
    }
    file_put_contents(AUDIT_CHAIN_FILE, $entry['hash'], LOCK_EX);
    if (is_dir(AUDIT_MIRROR_DIR)) {
        file_put_contents(AUDIT_MIRROR_DIR . 'audit_chain', $entry['hash'], LOCK_EX);
    }
    return $entry;
}

function loadAuditLogs() {
    return db_all('SELECT * FROM audit ORDER BY rowid DESC');
}

function clearAuditLogs() {
    db_exec('DELETE FROM audit');
    file_put_contents(AUDIT_CHAIN_FILE, '', LOCK_EX);
    if (is_dir(AUDIT_MIRROR_DIR)) {
        file_put_contents(AUDIT_MIRROR_DIR . 'audit_chain', '', LOCK_EX);
    }
}

function verifyAuditChain() {
    $logs = db_all('SELECT * FROM audit ORDER BY rowid ASC');
    if (empty($logs)) return ['valid' => true, 'count' => 0];
    $prevHash = '';
    for ($i = 0; $i < count($logs); $i++) {
        $entry = $logs[$i];
        $expectedHash = $entry['hash'] ?? '';
        $checkData = [
            'id' => $entry['id'], 'ts' => $entry['ts'], 'user_id' => $entry['user_id'],
            'user_name' => $entry['user_name'], 'role' => $entry['role'], 'ip' => $entry['ip'],
            'action' => $entry['action'], 'target' => $entry['target'], 'detail' => $entry['detail'],
            'result' => $entry['result'],
        ];
        $checkData['prev_hash'] = $prevHash;
        $checkJson = json_encode($checkData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $computedHash = hash('sha256', $checkJson);
        if (!hash_equals($computedHash, $expectedHash)) {
            return ['valid' => false, 'broken_at' => $i, 'count' => count($logs)];
        }
        $prevHash = $expectedHash;
    }
    return ['valid' => true, 'count' => count($logs)];
}

function recoverAuditFromMirror() {
    if (!is_dir(AUDIT_MIRROR_DIR) || !file_exists(AUDIT_MIRROR_DB)) return false;
    try {
        $mpdo = new PDO('sqlite:' . AUDIT_MIRROR_DB);
        $mpdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rows = $mpdo->query('SELECT * FROM audit ORDER BY rowid ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM audit');
        $st = $pdo->prepare('INSERT INTO audit (id,ts,user_id,user_name,role,ip,action,target,detail,result,hash,prev_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($rows as $r) {
            $st->execute([$r['id'],$r['ts'],$r['user_id'],$r['user_name'],$r['role'],$r['ip'],$r['action'],$r['target'],$r['detail'],$r['result'],$r['hash'],$r['prev_hash']]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
    // v2.8.1 修复（踩坑 #25）：恢复后必须把链尾文件校正为恢复后表尾 hash，
    // 不可 copy 镜像 audit_chain——镜像 ym.db 与 audit_chain 文件由不同时机更新（背书/写日志）可能错位，
    // 残留旧链尾会导致下一条记录断链。
    try {
        $last = $pdo->query('SELECT hash FROM audit ORDER BY rowid DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $tail = ($last && !empty($last['hash'])) ? $last['hash'] : '';
        file_put_contents(AUDIT_CHAIN_FILE, $tail, LOCK_EX);
        if (is_dir(AUDIT_MIRROR_DIR)) {
            @file_put_contents(AUDIT_MIRROR_DIR . 'audit_chain', $tail, LOCK_EX);
        }
    } catch (Exception $e) {
        // 链尾刷新失败不应判定恢复失败（表已恢复），仅记录
        error_log('recoverAuditFromMirror 链尾刷新失败: ' . $e->getMessage());
    }
    return true;
}

// ============================================================
// v2.8.0 邮件通道（SMTP 直连，无 MTA 依赖；失败落盘 alert.log 可追溯）
// ============================================================
function logAlertFail($detail) {
    // 告警发送失败落盘（root 目录，可追溯"邮件没发出去"）
    @file_put_contents(ALERT_LOG, date('Y-m-d H:i:s') . " [FAIL] {$detail}\n", FILE_APPEND | LOCK_EX);
}

function getSmtpConfig() {
    $c = loadSiteConfig();
    return [
        'host' => trim($c['smtp_host'] ?? ''),
        'port' => (int)($c['smtp_port'] ?? 465),
        'user' => trim($c['smtp_user'] ?? ''),
        'pass' => (string)($c['smtp_pass'] ?? ''),
        'from' => trim($c['smtp_from'] ?? ''),
        'enc' => in_array($c['smtp_enc'] ?? '', ['ssl', 'tls', 'plain'], true) ? $c['smtp_enc'] : 'ssl',
    ];
}

function saveSmtpConfig($host, $port, $user, $pass, $from, $enc) {
    $cfg = loadSiteConfig();
    $cfg['smtp_host'] = trim($host);
    $cfg['smtp_port'] = max(1, (int)$port);
    $cfg['smtp_user'] = trim($user);
    $cfg['smtp_pass'] = (string)$pass;
    $cfg['smtp_from'] = trim($from);
    $cfg['smtp_enc'] = in_array($enc, ['ssl', 'tls', 'plain'], true) ? $enc : 'ssl';
    return saveSiteConfig($cfg);
}

// 邮件类型 → 配色方案（v2.10.1：顶部栏按功能分色——告警红 / 恢复绿 / 验证蓝 / 默认深蓝）
function mailPalette($type) {
    if (preg_match('/失败|断裂|异常|告警|篡改|无法|错误|超时/', (string)$type)) {
        return ['g1' => '#7f1d1d', 'g2' => '#b91c1c', 'badge' => '#c0392b', 'sub' => '安全告警 · 请及时处理'];
    }
    if (preg_match('/已恢复|通过|成功/', (string)$type)) {
        return ['g1' => '#14532d', 'g2' => '#1e8449', 'badge' => '#1e8449', 'sub' => '系统状态 · 已恢复正常'];
    }
    if (preg_match('/验证|确认|绑定|注册/', (string)$type)) {
        return ['g1' => '#1e3a8a', 'g2' => '#2563eb', 'badge' => '#2563eb', 'sub' => '身份验证 · 请勿泄露'];
    }
    return ['g1' => '#1f3a5f', 'g2' => '#2a4a75', 'badge' => '#1f3a5f', 'sub' => '安全通知 · 系统自动发送'];
}

// HTML 邮件基础模板（v2.10.1 统一设计：顶部栏按功能分色 + 卡片放大 660px + 内容分层 + 大圆角；内联样式兼容主流邮件客户端）
// $htmlDetail 为非空时直接作为正文区 HTML（调用方负责转义动态值），否则将 $detail 按纯文本转义后渲染（默认安全）
function renderMailHtml($site, $type, $detail, $extra = [], $htmlDetail = null) {
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $siteE = $e($site);
    $typeE = $e($type);
    $server = $e($extra['server'] ?? 'localhost');
    $time = $e($extra['time'] ?? date('Y-m-d H:i:s'));
    if ($htmlDetail !== null) {
        $detailHtml = (string)$htmlDetail;
    } else {
        $detailHtml = nl2br($e($detail));
    }
    $p = mailPalette($type);
    $iconLetter = mb_substr($siteE, 0, 1, 'UTF-8');
    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#eef1f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',\'Microsoft YaHei\',sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f6;padding:32px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="660" cellpadding="0" cellspacing="0" style="max-width:660px;width:100%;background:#ffffff;border-radius:24px;overflow:hidden;border:1px solid #dde3ec;box-shadow:0 16px 44px rgba(15,42,82,0.12);">'
        // 顶部栏：按功能分色渐变
        . '<tr><td style="background:linear-gradient(135deg,' . $p['g1'] . ' 0%,' . $p['g2'] . ' 100%);padding:30px 38px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="vertical-align:middle;"><div style="color:#ffffff;font-size:20px;font-weight:700;letter-spacing:0.5px;">' . $siteE . '</div>'
        . '<div style="color:rgba(255,255,255,0.72);font-size:12px;margin-top:6px;">' . $p['sub'] . '</div></td>'
        . '<td align="right" style="vertical-align:middle;"><div style="width:44px;height:44px;border-radius:14px;background:rgba(255,255,255,0.16);color:#fff;font-size:19px;font-weight:700;text-align:center;line-height:44px;">' . $iconLetter . '</div></td>'
        . '</tr></table>'
        . '</td></tr>'
        // 主内容区（分层一：类型徽标 + 正文）
        . '<tr><td style="padding:38px 40px 26px;">'
        . '<div style="display:inline-block;background:' . $p['badge'] . ';color:#ffffff;font-size:12px;font-weight:600;padding:7px 18px;border-radius:999px;letter-spacing:0.5px;">' . $typeE . '</div>'
        . '<div style="margin-top:22px;color:#2d3748;font-size:14.5px;line-height:2.0;">' . $detailHtml . '</div>'
        . '</td></tr>'
        // 分层二：元信息面板（浅色内嵌卡）
        . '<tr><td style="padding:0 40px 34px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7f9fc;border-radius:14px;border:1px solid #eaeef4;padding:14px 20px;font-size:12.5px;color:#5b6b80;">'
        . '<tr><td style="padding:6px 0;width:72px;color:#93a2b4;">服务器</td><td>' . $server . '</td></tr>'
        . '<tr><td style="padding:6px 0;width:72px;color:#93a2b4;">时间</td><td>' . $time . '</td></tr>'
        . '</table>'
        . '</td></tr>'
        // 页脚
        . '<tr><td style="background:#f7f9fc;padding:18px 40px;border-top:1px solid #eaeef4;color:#93a2b4;font-size:11px;text-align:center;line-height:1.9;">You Super Markdown · 此邮件为系统自动发送，请勿直接回复<br>如非本人操作请忽略，谨防泄露验证信息</td></tr>'
        . '</table>'
        . '</td></tr></table>'
        . '</body></html>';
}

// 验证码邮件专用模板（v2.10.1 统一设计：蓝色顶栏 + 验证码卡放大 + 分层 + 大圆角）
function renderMailCode($site, $purposeLabel, $code, $ttlMin, $extra = []) {
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $siteE = $e($site);
    $purposeE = $e($purposeLabel);
    $codeE = $e($code);
    $codeFmt = (strlen($codeE) === 6) ? substr($codeE, 0, 3) . '&nbsp;&nbsp;' . substr($codeE, 3) : $codeE;
    $server = $e($extra['server'] ?? 'localhost');
    $time = $e($extra['time'] ?? date('Y-m-d H:i:s'));
    $ttlMin = max(1, (int)$ttlMin);
    $p = mailPalette('验证'); // 验证码统一蓝色系
    $iconLetter = mb_substr($siteE, 0, 1, 'UTF-8');
    $out = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#eef1f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',\'Microsoft YaHei\',sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f6;padding:32px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="660" cellpadding="0" cellspacing="0" style="max-width:660px;width:100%;background:#ffffff;border-radius:24px;overflow:hidden;border:1px solid #dde3ec;box-shadow:0 16px 44px rgba(15,42,82,0.12);">'
        . '<tr><td style="background:linear-gradient(135deg,' . $p['g1'] . ' 0%,' . $p['g2'] . ' 100%);padding:30px 38px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="vertical-align:middle;"><div style="color:#ffffff;font-size:20px;font-weight:700;letter-spacing:0.5px;">' . $siteE . '</div>'
        . '<div style="color:rgba(255,255,255,0.72);font-size:12px;margin-top:6px;">' . $p['sub'] . '</div></td>'
        . '<td align="right" style="vertical-align:middle;"><div style="width:44px;height:44px;border-radius:14px;background:rgba(255,255,255,0.16);color:#fff;font-size:19px;font-weight:700;text-align:center;line-height:44px;">' . $iconLetter . '</div></td>'
        . '</tr></table>'
        . '</td></tr>'
        . '<tr><td style="padding:40px 44px 26px;">'
        . '<div style="text-align:center;">'
        . '<div style="display:inline-block;background:#eef3fb;color:#1e3a8a;font-size:12px;font-weight:600;padding:7px 18px;border-radius:999px;">' . $purposeE . '</div>'
        . '</div>'
        // 验证码卡片（放大：圆角 18px、内距加大）
        . '<div style="text-align:center;margin-top:26px;">'
        . '<div style="display:inline-block;background:#f0f4ff;border:2px dashed #b9cbe6;border-radius:18px;padding:26px 46px;">'
        . '<div style="color:#8a97ad;font-size:12px;margin-bottom:12px;letter-spacing:1px;">您的验证码</div>'
        . '<div style="font-family:Consolas,Menlo,monospace;font-size:40px;font-weight:700;letter-spacing:5px;color:#1e3a8a;">' . $codeFmt . '</div>'
        . '</div>'
        . '</div>'
        . '<div style="text-align:center;margin-top:22px;">'
        . '<span style="display:inline-block;background:#fff3e0;color:#b26a00;font-size:12px;font-weight:600;padding:6px 16px;border-radius:999px;">有效期 ' . $ttlMin . ' 分钟 · 一次性使用</span>'
        . '</div>';
    $link = (string)($extra['link'] ?? '');
    if ($link !== '') {
        $out .= '<div style="text-align:center;margin-top:24px;">'
            . '<a href="' . $e($link) . '" style="display:inline-block;background:linear-gradient(135deg,' . $p['g1'] . ' 0%,' . $p['g2'] . ' 100%);color:#ffffff;font-size:14px;font-weight:600;padding:14px 38px;border-radius:999px;text-decoration:none;">前往完成验证</a>'
            . '</div>';
    }
    // 分层：安全提示面板
    $out .= '<div style="margin-top:26px;padding:16px 20px;background:#f7f9fc;border:1px solid #eaeef4;border-radius:14px;color:#5b6b80;font-size:12.5px;line-height:1.9;">'
        . '如果这不是您本人的操作，请忽略本邮件，您的账号不会受到任何影响。<br>请勿将验证码转发给任何人，工作人员不会向您索要验证码。'
        . '</div>'
        // 分层：元信息面板
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;background:#f7f9fc;border-radius:14px;border:1px solid #eaeef4;padding:14px 20px;font-size:12.5px;color:#5b6b80;">'
        . '<tr><td style="padding:6px 0;width:72px;color:#93a2b4;">服务器</td><td>' . $server . '</td></tr>'
        . '<tr><td style="padding:6px 0;width:72px;color:#93a2b4;">时间</td><td>' . $time . '</td></tr>'
        . '</table>'
        . '</td></tr>'
        . '<tr><td style="background:#f7f9fc;padding:18px 40px;border-top:1px solid #eaeef4;color:#93a2b4;font-size:11px;text-align:center;line-height:1.9;">You Super Markdown · 此邮件为系统自动发送，请勿直接回复</td></tr>'
        . '</table>'
        . '</td></tr></table>'
        . '</body></html>';
    return $out;
}

// HTML 折行（v2.10.2-fix-mailqp2）：在标签边界插入换行，确保 8bit 每行 < 998 字符（RFC 5322 行长度限制）。
// 实测：163 网页版对 base64、quoted-printable 均不解码（显示原始 MIME 源码），对超长单行 8bit 也不解析；
// 仅短行 8bit（v2.9.0 及以下）可正常渲染——故回归 8bit 并在标签间折行。标签间换行不影响 HTML 渲染。
function mailFoldHtml($html) {
    return str_replace('><', ">\n<", (string)$html);
}

// 轻量 SMTP 客户端（AUTH LOGIN + MAIL/RCPT/DATA），返回 [success, error]
function sendSmtpMail($to, $subject, $body, $htmlBody = '') {
    $s = getSmtpConfig();
    if ($s['host'] === '' || $s['user'] === '' || $s['pass'] === '') {
        return [false, 'SMTP 未配置'];
    }
    $port = $s['port'] ?: 465;
    $prefix = $s['enc'] === 'ssl' ? 'ssl://' : 'tcp://';
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client("{$prefix}{$s['host']}:{$port}", $errno, $errstr, 15);
    if (!$fp) return [false, "连接失败: {$errstr}"];
    $resp = fgets($fp, 512);
    if (substr($resp, 0, 3) !== '220') { fclose($fp); return [false, 'SMTP 握手失败: ' . trim($resp)]; }
    $ehlo = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // 多行响应读取：3 位码 + '-' 为续行，读到非续行（3 位码 + 空格）为止（修复 EHLO 多行响应残留导致 AUTH 读取错位）
    $readResp = function () use ($fp) {
        $lines = '';
        while (true) {
            $line = fgets($fp, 512);
            if ($line === false) break;
            $lines .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return $lines;
    };
    $cmd = function ($c) use ($fp, $readResp) { fwrite($fp, $c . "\r\n"); return $readResp(); };
    if ($s['enc'] === 'tls') {
        $r = $cmd('EHLO ' . $ehlo);
        if (stripos($r, 'STARTTLS') === false) { fclose($fp); return [false, '服务器不支持 STARTTLS']; }
        $r = $cmd('STARTTLS');
        if (substr($r, 0, 3) !== '220') { fclose($fp); return [false, 'STARTTLS 失败: ' . trim($r)]; }
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $cmd('EHLO ' . $ehlo);
    } else {
        $cmd('EHLO ' . $ehlo);
    }
    $r = $cmd('AUTH LOGIN');
    if (substr($r, 0, 3) !== '334') { fclose($fp); return [false, 'AUTH 被拒: ' . trim($r)]; }
    $cmd(base64_encode($s['user']));
    $r = $cmd(base64_encode($s['pass']));
    if (substr($r, 0, 3) !== '235') { fclose($fp); return [false, 'SMTP 认证失败（检查账号/授权码）: ' . trim($r)]; }
    $from = $s['from'] !== '' ? $s['from'] : $s['user'];
    $r = $cmd('MAIL FROM:<' . $from . '>');
    if (substr($r, 0, 3) !== '250') { fclose($fp); return [false, 'MAIL FROM 失败: ' . trim($r)]; }
    $r = $cmd('RCPT TO:<' . $to . '>');
    if (substr($r, 0, 3) !== '250') { fclose($fp); return [false, 'RCPT TO 失败: ' . trim($r)]; }
    $r = $cmd('DATA');
    if (substr($r, 0, 3) !== '354') { fclose($fp); return [false, 'DATA 被拒: ' . trim($r)]; }
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $textBody = str_replace("\r\n", "\n", $body);
    $html = ($htmlBody !== '') ? mailFoldHtml($htmlBody) : '';
    // v2.10.2-fix-mailqp2：正文统一 8bit（回归 v2.9.0 兼容方式）+ HTML 标签间折行（每行 <998 字符）。
    // 实测：163 网页版对 base64、quoted-printable 均显示原始 MIME 源码，对超长单行 8bit 也不解析；
    // 仅短行 8bit 可正常渲染（v2.9.0 12:42 邮件）。报文内部统一 \n，发送前一次性转 \r\n，
    // 避免正文中已含换行被二次替换成 \r\r\n 破坏 multipart 边界（v2.10.2-fix-mailqp 根因）。
    if ($html !== '') {
        // multipart/alternative：HTML 版（8bit 短行）+ 纯文本版（8bit，老客户端可见纯文本）
        $boundary = '----=_Part_' . bin2hex(random_bytes(8));
        $msg = "From: {$from}\nTo: {$to}\nSubject: {$encSubject}\nDate: " . date('r')
            . "\nMessage-ID: <" . bin2hex(random_bytes(8)) . "@{$ehlo}>\nX-Mailer: You Super Markdown"
            . "\nMIME-Version: 1.0\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"\n\n"
            . "--{$boundary}\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n\n" . $textBody . "\n"
            . "--{$boundary}\nContent-Type: text/html; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n\n" . $html . "\n"
            . "--{$boundary}--\n";
    } else {
        $msg = "From: {$from}\nTo: {$to}\nSubject: {$encSubject}\nDate: " . date('r')
            . "\nMessage-ID: <" . bin2hex(random_bytes(8)) . "@{$ehlo}>\nX-Mailer: You Super Markdown"
            . "\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n\n"
            . $textBody;
    }
    fwrite($fp, str_replace("\n", "\r\n", $msg) . "\r\n.\r\n");
    $r = fgets($fp, 512);
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    if (substr($r, 0, 3) !== '250') return [false, 'SMTP 发送失败: ' . trim($r)];
    return [true, ''];
}

function sendAlert($type, $detail) {
    $config = loadSiteConfig();
    $adminEmail = $config['admin_email'] ?? '';
    if (!$adminEmail) return;
    $site = $config['site_title'] ?? 'You Super Markdown';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $subject = "[{$site} 告警] {$type}";
    $now = date('Y-m-d H:i:s');
    $body = "时间：{$now}\n"
          . "服务器：{$host}\n"
          . "事件类型：{$type}\n"
          . "详情：{$detail}\n";
    $html = renderMailHtml($site, $type, $detail, ['server' => $host, 'time' => $now]);
    // v2.8.0：优先 SMTP 直连（无 MTA 依赖）；未配置退回 mail 命令；失败落盘 alert.log 可追溯
    $smtp = getSmtpConfig();
    if ($smtp['host'] !== '' && $smtp['user'] !== '' && $smtp['pass'] !== '') {
        [$ok, $err] = sendSmtpMail($adminEmail, $subject, $body, $html);
        if ($ok) return;
        logAlertFail("SMTP 发送失败({$type}): {$err}");
        return;
    }
    if (!file_exists(EMAIL_ALERT)) { logAlertFail("ym-alert 不存在({$type})"); return; }
    $cmd = escapeshellcmd(EMAIL_ALERT) . ' ' . escapeshellarg($adminEmail) . ' ' . escapeshellarg($subject) . ' ' . escapeshellarg($body);
    exec($cmd . ' > /dev/null 2>&1 &');
    // mail 命令为异步后台，其内部失败由 ym-alert 落盘 alert.log（见 v2.8.0 ym-alert 改造）
}

/**
 * v2.11.1：站长/写作者登录成功通知管理员（复用 SMTP 告警通道；普通用户登录不通知，防轰炸）
 */
function notifyLoginEvent($u, $clientIP) {
    $config = loadSiteConfig();
    $adminEmail = $config['admin_email'] ?? '';
    if (!$adminEmail) return;
    $roleName = [
        ROLE_STATION_ADMIN => '站长',
        ROLE_AUTHOR => '写作者',
    ][$u['role'] ?? ''] ?? ($u['role'] ?? '');
    if (!in_array($u['role'] ?? '', [ROLE_STATION_ADMIN, ROLE_AUTHOR], true)) return;
    $site = $config['site_title'] ?? 'You Super Markdown';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $subject = "[{$site} 通知] {$roleName}登录";
    $now = date('Y-m-d H:i:s');
    $body = "时间：{$now}\n"
          . "服务器：{$host}\n"
          . "账号：{$u['nickname']}（" . maskQQ($u['qq'] ?? '') . "）\n"
          . "角色：{$roleName}\n"
          . "IP：{$clientIP}";
    $html = renderMailHtml($site, $subject, $body, ['server' => $host, 'time' => $now]);
    $smtp = getSmtpConfig();
    if ($smtp['host'] !== '' && $smtp['user'] !== '' && $smtp['pass'] !== '') {
        [$ok, $err] = sendSmtpMail($adminEmail, $subject, $body, $html);
        if ($ok) return;
        logAlertFail("SMTP 发送失败({$subject}): {$err}");
        return;
    }
    if (!file_exists(EMAIL_ALERT)) { logAlertFail("ym-alert 不存在({$subject})"); return; }
    $cmd = escapeshellcmd(EMAIL_ALERT) . ' ' . escapeshellarg($adminEmail) . ' ' . escapeshellarg($subject) . ' ' . escapeshellarg($body);
    exec($cmd . ' > /dev/null 2>&1 &');
}

// ============================================================
// v2.9.0 注册验证：滑块人机验证 + 邮箱验证码 + 写作者双重确认
// ============================================================

/** 邮箱格式校验 */
function email_valid($email) {
    return is_string($email) && strlen($email) <= 254
        && preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $email);
}

/**
 * 头像上传（v2.10.0）。
 * 校验：仅 JPG/PNG/WEBP、≤2MB、真实图像（getimagesize 双重校验，防伪装可执行文件）。
 * 保存到 data/avatars/{userId}.{ext}，覆盖旧头像并清理同名异扩展残留，更新 users.avatar 与当前 session。
 * @return array [bool, mixed] 成功返回 [true, url]，失败返回 [false, 原因]
 */
function avatar_upload($userId, $file) {
    if (!is_string($userId) || $userId === '') return [false, '用户标识无效'];
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [false, '未收到有效的文件'];
    }
    if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) return [false, '图片不能超过 2MB'];
    $tmp = $file['tmp_name'] ?? '';
    if (!is_file($tmp)) return [false, '文件读取失败'];
    $info = @getimagesize($tmp);
    if (!$info) return [false, '文件不是有效的图片'];
    $extMap = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!isset($extMap[$info[2]])) return [false, '仅支持 JPG / PNG / WEBP 格式'];
    $ext = $extMap[$info[2]];
    $dir = __DIR__ . '/data/avatars/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = preg_replace('/[^a-f0-9]/i', '', $userId); // 用户 id 为 hex，固定安全文件名
    $dest = $dir . $name . '.' . $ext;
    if (!move_uploaded_file($tmp, $dest) && !@copy($tmp, $dest)) {
        return [false, '头像保存失败（请检查 data/avatars/ 目录权限）'];
    }
    @chmod($dest, 0644);
    // 清理同名旧头像（其他扩展名），避免脏文件残留
    foreach (['jpg', 'png', 'webp'] as $e) {
        if ($e !== $ext && file_exists($dir . $name . '.' . $e)) @unlink($dir . $name . '.' . $e);
    }
    $url = 'data/avatars/' . $name . '.' . $ext . '?v=' . time();
    $users = loadUsers();
    foreach ($users as &$u) {
        if ($u['id'] === $userId) { $u['avatar'] = $url; break; }
    }
    unset($u);
    saveUsers($users);
    if (!empty($_SESSION['cmt_user']['id']) && $_SESSION['cmt_user']['id'] === $userId) {
        $_SESSION['cmt_user']['avatar'] = $url;
    }
    return [true, $url];
}

/**
 * 用户公开详情（v2.10.0）：个人详情页数据。
 * 排除超管：超管无公开页面，返回 null。
 * @return array|null ['id','qq','nickname','avatar','signature','created','role']
 */
function get_public_user($id) {
    if (!is_string($id) || $id === '') return null;
    $users = loadUsers();
    foreach ($users as $u) {
        if ($u['id'] === $id) {
            if (($u['role'] ?? '') === ROLE_SUPER_ADMIN) return null;
            return [
                'id' => $u['id'],
                'qq' => $u['qq'] ?? '',
                'nickname' => $u['nickname'] ?? '',
                'avatar' => $u['avatar'] ?? '',
                'signature' => $u['signature'] ?? '',
                'created' => $u['created'] ?? '',
                'role' => $u['role'] ?? ROLE_USER,
            ];
        }
    }
    return null;
}

/**
 * v2.11.0：登录失败计数（IP+账号双级写入 login_fails）
 */
function loginFailAdd($ip, $qq) {
    db_exec('INSERT INTO login_fails (ip, t, acc) VALUES (?,?,?)', [$ip, time(), $qq]);
}
/** 60 秒窗口内，同 IP 或同账号失败次数（二者任一命中即计数） */
function loginFailCount($ip, $qq, $window = 60) {
    $cutoff = time() - $window;
    $r = db_one('SELECT COUNT(*) AS c FROM login_fails WHERE t > ? AND (ip = ? OR acc = ?)', [$cutoff, $ip, $qq]);
    return (int)($r['c'] ?? 0);
}
function loginFailClear($ip, $qq) {
    db_exec('DELETE FROM login_fails WHERE ip = ? OR acc = ?', [$ip, $qq]);
}
/** 登录锁定：返回剩余秒数；0 表示未锁定（过期自动清理） */
function loginLocked($key) {
    $r = db_one('SELECT locked_until FROM login_locks WHERE key = ?', [$key]);
    if (!$r) return 0;
    $left = (int)$r['locked_until'] - time();
    if ($left <= 0) { db_exec('DELETE FROM login_locks WHERE key = ?', [$key]); return 0; }
    return $left;
}
function lockLogin($key, $seconds) {
    db_exec(
        'INSERT INTO login_locks (key, locked_until) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET locked_until = ?',
        [$key, time() + $seconds, time() + $seconds]
    );
}

/**
 * v2.11.0：QQ 号隐私打码（非超管视角）。保留前 3 后 4；短号（≤7 位）保留首尾各 1；≤4 位全打码。
 */
function maskQQ($qq) {
    $qq = (string)$qq;
    $len = strlen($qq);
    if ($len <= 4) return str_repeat('*', $len);
    if ($len <= 7) return substr($qq, 0, 1) . str_repeat('*', $len - 2) . substr($qq, -1);
    return substr($qq, 0, 3) . str_repeat('*', $len - 7) . substr($qq, -4);
}

/**
 * v2.11.1：对外返回的用户信息统一脱敏——去除 pw_hash，QQ 打码（防登录/注册/check
 * 接口泄露完整 QQ 给前端；头像走 avatar 字段，前端不再依赖完整 qq 拼 URL）。
 */
function sanitizeUserForClient($u) {
    if (!is_array($u)) return $u;
    unset($u['pw_hash']);
    if (isset($u['qq']) && $u['qq'] !== '') $u['qq'] = maskQQ($u['qq']);
    return $u;
}

/** 邮箱是否已被账户占用 */
function email_exists($email) {
    $r = db_one('SELECT COUNT(*) AS c FROM users WHERE email = ?', [$email]);
    return ($r['c'] ?? 0) > 0;
}

/**
 * 发送邮箱验证码。
 * @param string $email     目标邮箱
 * @param string $purpose   register | author_verify
 * @param string $target    关联对象描述（如注册邮箱 / 待创建写作者昵称）
 * @param string $operatorRole 操作者角色（super_admin 后台操作跳过 60s 冷却）
 * @param string $link      可选的验证链接（author_verify 时附在邮件内，写作者自助输入验证码）
 * @return array [bool, mixed] 成功返回 [true, code记录数组]，失败返回 [false, 原因]
 */
function email_code_send($email, $purpose, $target = '', $operatorRole = '', $link = '') {
    if (!email_valid($email)) return [false, '邮箱格式不正确'];
    $cfg = loadSiteConfig();
    $cooldown = max(10, (int)($cfg['resend_cooldown'] ?? 60));
    // 60s 冷却（按邮箱）；超管后台主动操作不受限
    $isAdminOp = ($operatorRole === ROLE_SUPER_ADMIN);
    if (!$isAdminOp) {
        $last = db_one('SELECT MAX(created) AS m FROM email_codes WHERE email = ?', [$email]);
        if ($last && $last['m'] && (time() - (int)$last['m']) < $cooldown) {
            $wait = $cooldown - (time() - (int)$last['m']);
            return [false, '发送过于频繁，请 ' . $wait . ' 秒后重试'];
        }
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $ttl = max(60, (int)($cfg['verify_code_ttl'] ?? 300));
    $rowId = genId();
    db_exec('INSERT INTO email_codes (id,email,code,purpose,expires,used,created,ip,operator_role) VALUES (?,?,?,?,?,0,?,?,?)', [
        $rowId, $email, $code, $purpose, time() + $ttl, time(), getClientIP(), $operatorRole,
    ]);
    $site = $cfg['site_title'] ?? 'You Super Markdown';
    $now = date('Y-m-d H:i:s');
    $subject = "[{$site}] 邮箱验证码";
    $body = "您的验证码为：{$code}\n\n"
          . "有效期为 " . intdiv($ttl, 60) . " 分钟，仅限一次性使用。\n";
    if ($link !== '') {
        $body .= "请点击以下链接输入验证码完成验证：\n{$link}\n\n";
    }
    $body .= "若非本人操作，请忽略本邮件。\n时间：{$now}";
    // v2.9.0：验证码邮件用专用大号展示模板（3-3 分组 / 有效期徽标 / 防泄露提示 / 自助验证链接）
    // v2.10.0：purpose 扩展 email_change（更换绑定邮箱），统一走同一模板
    $purposeLabels = [
        'register' => '注册邮箱验证',
        'author_verify' => '写作者邮箱验证',
        'email_change' => '更换绑定邮箱验证',
    ];
    $purposeLabel = $purposeLabels[$purpose] ?? '邮箱验证';
    $html = renderMailCode($site, $purposeLabel, $code, intdiv($ttl, 60), [
        'server' => $_SERVER['HTTP_HOST'] ?? 'localhost',
        'time' => $now,
        'link' => $link,
    ]);
    [$ok, $err] = sendSmtpMail($email, $subject, $body, $html);
    if (!$ok) {
        logAlertFail("验证码邮件发送失败({$purpose} → {$email}): {$err}");
        return [false, '验证码邮件发送失败：' . $err];
    }
    return [true, ['id' => $rowId, 'ttl' => $ttl]];
}

/**
 * 校验邮箱验证码（一次性，未过期即原子消费）。
 * @return array [bool, mixed] 成功返回 [true, 验证码记录]，失败返回 [false, 原因]
 */
function email_code_verify($email, $code, $purpose) {
    if (!email_valid($email) || !preg_match('/^\d{6}$/', (string)$code)) return [false, '验证码不正确'];
    $row = db_one('SELECT * FROM email_codes WHERE email = ? AND code = ? AND purpose = ? AND used = 0 ORDER BY created DESC LIMIT 1', [$email, $code, $purpose]);
    if (!$row) return [false, '验证码不正确'];
    if ((int)$row['expires'] < time()) return [false, '验证码已过期'];
    db_exec('UPDATE email_codes SET used = 1 WHERE id = ?', [$row['id']]);
    return [true, $row];
}

/** 创建写作者双重确认中间态，返回 [pendingId, confirmToken]；$status: verify_pending(等写作者验证码) / pending(待超管确认) */
function create_pending_author($email, $nickname, $qq, $passwordHash, $stationId, $verifyCodeId = '', $status = 'pending') {
    $id = genId();
    $token = ($status === 'pending') ? bin2hex(random_bytes(16)) : '';
    db_exec('INSERT INTO pending_author_creates (id,email,nickname,qq,password_hash,station_id,verify_code_id,confirm_token,status,created,confirmed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
        $id, $email, $nickname, $qq, $passwordHash, $stationId, $verifyCodeId, $token, $status, time(), '',
    ]);
    return [$id, $token];
}
/** 按确认 token 查待确认记录（未过期） */
function get_pending_author_by_token($token) {
    if (!is_string($token) || strlen($token) !== 32) return null;
    $row = db_one('SELECT * FROM pending_author_creates WHERE confirm_token = ?', [$token]);
    if (!$row || $row['status'] !== 'pending') return null;
    return $row;
}
/** 按 id 查待确认记录 */
function get_pending_author_by_id($id) {
    return db_one('SELECT * FROM pending_author_creates WHERE id = ?', [$id]);
}
/** 标记待确认记录状态 */
function update_pending_author_status($id, $status) {
    db_exec("UPDATE pending_author_creates SET status = ?, confirmed_at = ? WHERE id = ?", [$status, date('Y-m-d H:i:s'), $id]);
}
/** 创建写作者账号（超管确认后执行）；邮箱/QQ 冲突返回 false */
function create_author_from_pending($row) {
    $users = loadUsers();
    foreach ($users as $u) {
        if (($u['qq'] ?? '') === $row['qq']) return false;
        if (!empty($u['email']) && ($u['email'] ?? '') === $row['email']) return false;
    }
    $users[] = [
        'id' => genId(),
        'qq' => $row['qq'],
        'email' => $row['email'],
        'nickname' => $row['nickname'],
        'password' => $row['password_hash'],
        'role' => ROLE_AUTHOR,
        'station_id' => $row['station_id'],
        'created' => date('Y-m-d H:i:s'),
        'created_by' => 'dual_verify',
    ];
    saveUsers($users);
    return true;
}

/** 给超管发送写作者创建确认邮件（一次性链接，confirm_link_ttl 秒有效） */
function sendAdminConfirmMail($pendingId, $token, $nick, $qq, $email) {
    $cfg = loadSiteConfig();
    $adminEmail = $cfg['admin_email'] ?? '';
    if (!$adminEmail) return false;
    $ttl = max(300, (int)($cfg['confirm_link_ttl'] ?? 86400));
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $link = "{$scheme}://{$host}/verify-confirm.php?token={$token}";
    $site = $cfg['site_title'] ?? 'You Super Markdown';
    $now = date('Y-m-d H:i:s');
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $subject = "[{$site}] 写作者创建确认";
    $body = "站长申请创建写作者：\n昵称：{$nick}\nQQ：{$qq}\n邮箱：{$email}\n\n"
          . "点击以下链接确认（" . intdiv($ttl, 3600) . " 小时内有效，一次性）：\n{$link}\n\n时间：{$now}";
    // v2.9.0：确认邮件信息卡（昵称/QQ/邮箱）+ 渐变确认按钮
    $htmlDetail = '<div style="text-align:center;">'
        . '<span style="display:inline-block;background:#eef3fb;color:#1f3a5f;font-size:12px;font-weight:600;padding:6px 16px;border-radius:999px;">站长申请创建写作者</span>'
        . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;background:#f8fafc;border:1px solid #edf0f5;border-radius:12px;">'
        . '<tr><td style="padding:14px 20px;width:76px;color:#a0aec0;font-size:12px;">昵称</td><td style="padding:14px 20px;color:#2d3748;font-size:14px;font-weight:600;">' . $e($nick) . '</td></tr>'
        . '<tr><td style="padding:10px 20px;width:76px;color:#a0aec0;font-size:12px;border-top:1px solid #edf0f5;">QQ</td><td style="padding:10px 20px;color:#2d3748;font-size:14px;border-top:1px solid #edf0f5;">' . $e($qq) . '</td></tr>'
        . '<tr><td style="padding:10px 20px;width:76px;color:#a0aec0;font-size:12px;border-top:1px solid #edf0f5;">邮箱</td><td style="padding:10px 20px;color:#2d3748;font-size:14px;border-top:1px solid #edf0f5;">' . $e($email) . '</td></tr>'
        . '</table>'
        . '<div style="text-align:center;margin-top:26px;">'
        . '<a href="' . $link . '" style="display:inline-block;background:linear-gradient(135deg,#1f3a5f 0%,#2a4a75 100%);color:#ffffff;font-size:15px;font-weight:600;padding:14px 40px;border-radius:999px;text-decoration:none;">确认创建写作者</a>'
        . '</div>'
        . '<div style="text-align:center;margin-top:16px;">'
        . '<span style="display:inline-block;background:#fff3e0;color:#b26a00;font-size:12px;font-weight:600;padding:5px 14px;border-radius:999px;">链接 ' . intdiv($ttl, 3600) . ' 小时内有效 · 一次性</span>'
        . '</div>';
    $html = renderMailHtml($site, '写作者创建确认', '', ['server' => $host, 'time' => $now], $htmlDetail);
    [$ok, $err] = sendSmtpMail($adminEmail, $subject, $body, $html);
    if (!$ok) {
        logAlertFail("写作者确认邮件发送失败: {$err}");
        return false;
    }
    return true;
}

// ============================================================
// v2.2 在线更新辅助函数
// ============================================================
define('UPDATE_REQUEST_FILE', '/tmp/ym-update-request.json');
define('UPDATE_LOCK_FILE', '/tmp/ym-update.lock');
define('BACKUP_DIR', '/opt/you-markdown/backups');
define('BACKUP_CONF', '/opt/you-markdown/backup.conf');           // 自动备份配置（root:www-data 664）
define('BACKUP_DB_DIR', BACKUP_DIR . '/db');                        // 数据库 30 分钟备份（固定 1 份）
define('BACKUP_ARTICLES_DIR', BACKUP_DIR . '/articles');            // 文章每日备份（保留 N 份）
define('GUARD_STATE_FILE', '/opt/you-markdown/guard-state.json');   // 守护进程状态（含备份状态）
define('ALERT_LOG', '/opt/you-markdown/alert.log');                  // 告警发送失败日志（可追溯）

// 服务器挑战码校验（300 秒、单次）：匹配 code + 未过期 + 未使用，通过则原子消费
function verifyChallenge($code) {
    if (empty($code)) return false;
    $rows = db_all('SELECT id, code, expires, used FROM challenge ORDER BY rowid');
    $valid = false;
    $id = null;
    foreach ($rows as $c) {
        if (strtoupper($c['code'] ?? '') === strtoupper($code) && (int)($c['expires'] ?? 0) > time() && empty($c['used'])) {
            $id = $c['id'];
            $valid = true;
            break;
        }
    }
    if ($valid && $id !== null) {
        db_exec('UPDATE challenge SET used = 1 WHERE id = ?', [$id]);
    }
    return $valid;
}

// ============================================================
// OTP 入口（entries）与置顶（pinned）与频率计数封装
// ============================================================
function loadEntries() {
    $rows = db_all('SELECT * FROM entries ORDER BY rowid');
    $list = [];
    foreach ($rows as $r) {
        $list[] = [
            'token' => $r['token'], 'otp_hash' => $r['otp_hash'],
            'expires' => (int)$r['expires'], 'used' => (int)$r['used'],
            'created' => $r['created'],
        ];
    }
    return $list;
}
function saveEntries($entries) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM entries');
        $st = $pdo->prepare('INSERT INTO entries (id, token, otp_hash, expires, used, created) VALUES (?,?,?,?,?,?)');
        foreach ($entries as $e) {
            $st->execute([
                $e['id'] ?? bin2hex(random_bytes(8)),
                $e['token'] ?? '', $e['otp_hash'] ?? '', (int)($e['expires'] ?? 0),
                (int)($e['used'] ?? 0), $e['created'] ?? '',
            ]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
function addEntry($token, $otpHash, $expires) {
    db_exec('INSERT INTO entries (id, token, otp_hash, expires, used, created) VALUES (?,?,?,?,?,?)', [
        bin2hex(random_bytes(8)), $token, $otpHash, (int)$expires, 0, date('Y-m-d H:i:s'),
    ]);
}
function loadChallenges() {
    $rows = db_all('SELECT * FROM challenge ORDER BY rowid');
    $list = [];
    foreach ($rows as $r) {
        $list[] = ['code' => $r['code'], 'expires' => (int)$r['expires'], 'used' => (int)$r['used'], 'created' => (int)$r['created']];
    }
    return $list;
}
function addChallenge($code, $expires) {
    db_exec('DELETE FROM challenge WHERE expires < ?', [time()]); // 清理过期
    db_exec('INSERT INTO challenge (id, code, expires, used, created) VALUES (?,?,?,?,?)', [
        bin2hex(random_bytes(8)), $code, (int)$expires, 0, time(),
    ]);
}
function getPinnedList() {
    $rows = db_all('SELECT article FROM pinned ORDER BY rowid');
    return array_map(function($r) { return $r['article']; }, $rows);
}
function savePinnedList($list) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM pinned');
        $st = $pdo->prepare('INSERT INTO pinned (article) VALUES (?)');
        foreach (array_values($list) as $a) {
            $st->execute([$a]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
// 频率计数（滑动窗口）：table 为 login_fails / reg_rates / comment_rates
function db_rate_count($table, $ip, $window) {
    $now = time();
    $cutoff = $now - $window;
    $st = db()->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE ip = ? AND t > ?");
    $st->execute([$ip, $cutoff]);
    return (int)($st->fetch()['c'] ?? 0);
}
function db_rate_add($table, $ip) {
    db_exec("INSERT INTO {$table} (ip, t) VALUES (?,?)", [$ip, time()]);
    // v2.5.4：改为概率清理（1/64 触发），降低每次写入的写放大；
    // 过期记录由 db_rate_count() 的 t>cutoff 条件过滤，不影响计数判定
    if (random_int(0, 63) === 0) {
        $cutoff = time() - 2592000;
        db_exec("DELETE FROM {$table} WHERE t < ?", [$cutoff]);
    }
}
function db_rate_clear_ip($table, $ip) {
    db_exec("DELETE FROM {$table} WHERE ip = ?", [$ip]);
}

function getUpdateRequest() {
    if (!file_exists(UPDATE_REQUEST_FILE)) return null;
    $data = json_decode(file_get_contents(UPDATE_REQUEST_FILE), true);
    return is_array($data) ? $data : null;
}

function saveUpdateRequest($data) {
    $dir = dirname(UPDATE_REQUEST_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return file_put_contents(UPDATE_REQUEST_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function isUpdateInProgress() {
    if (!file_exists(UPDATE_LOCK_FILE)) return false;
    $data = json_decode(file_get_contents(UPDATE_LOCK_FILE), true);
    if (!is_array($data)) return false;
    // 锁过期则清理
    if (($data['expires'] ?? 0) < time()) {
        @unlink(UPDATE_LOCK_FILE);
        return false;
    }
    return true;
}

function setUpdateLock($token, $ttl = 600) {
    $data = [
        'token' => $token,
        'expires' => time() + $ttl,
        'created' => time(),
        'reason' => 'system_update',
    ];
    $dir = dirname(UPDATE_LOCK_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return file_put_contents(UPDATE_LOCK_FILE, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function clearUpdateLock() {
    @unlink(UPDATE_LOCK_FILE);
}

function getUpdateStatus() {
    $req = getUpdateRequest();
    if (!$req) return ['status' => 'idle', 'version' => APP_VERSION];
    // 请求超时处理：pending/in_progress 超过 expires 自动标记 failed（防"等待中/进行中"卡死）
    $st = $req['status'] ?? 'pending';
    if (in_array($st, ['pending', 'in_progress'], true) && (int)($req['expires'] ?? 0) > 0 && time() > (int)$req['expires']) {
        $req['status'] = 'failed';
        $req['error'] = '请求超时';
        $req['completed_at'] = time();
        saveUpdateRequest($req);
        @chmod(UPDATE_REQUEST_FILE, 0666);
        clearUpdateLock();
        $st = 'failed';
    }
    return [
        'status' => $st,
        'from_version' => $req['from_version'] ?? APP_VERSION,
        'to_version' => $req['to_version'] ?? '',
        'channel' => $req['channel'] ?? 'stable',
        'created' => $req['created'] ?? 0,
        'completed_at' => $req['completed_at'] ?? null,
        'error' => $req['error'] ?? '',
    ];
}

// v2.5.5：从审计日志读取完整更新历史（audit 表 action='system_update'，按 rowid 倒序）
// detail 格式："系统更新: v2.5.1 → v2.5.4"，解析出 from/to 版本
function getUpdateHistory($limit = 30) {
    $limit = max(1, min(100, (int)$limit));
    $rows = db_all("SELECT ts, detail, result FROM audit WHERE action = 'system_update' ORDER BY rowid DESC LIMIT " . $limit);
    $history = [];
    foreach ($rows as $r) {
        if (!preg_match('/v([\d.]+)\s*→\s*v([\d.]+)/', $r['detail'] ?? '', $m)) {
            continue;
        }
        $history[] = [
            'from_version' => $m[1],
            'to_version' => $m[2],
            // ts 形如 "2026-08-14 04:50:02.123"，截断到秒
            'completed_at' => preg_replace('/\.\d+$/', '', $r['ts'] ?? ''),
            'status' => ($r['result'] === 'success') ? 'completed' : ($r['result'] ?: 'failed'),
        ];
    }
    return $history;
}

/**
 * 通用列表分页 + 关键词过滤（v2.6.6 超管后台日志查看）
 * 对全量数组做多字段模糊匹配过滤后分页，避免重复实现
 * @param array $rows     全量数据（已按需排序）
 * @param array $fields   参与搜索的字段名
 * @param string $q       搜索关键词（空串不过滤）
 * @param int $page       页码（从 1 起，自动收敛到有效范围）
 * @param int $perPage    每页条数
 * @return array{items:array,total:int,page:int,pages:int,per_page:int}
 */
function paginateList(array $rows, array $fields, string $q = '', int $page = 1, int $perPage = 50) {
    $perPage = max(1, min(200, (int)$perPage));
    if ($q !== '') {
        $qLower = mb_strtolower($q);
        $rows = array_values(array_filter($rows, function ($r) use ($fields, $qLower) {
            foreach ($fields as $f) {
                if (isset($r[$f]) && $r[$f] !== null && $r[$f] !== '' && mb_strpos(mb_strtolower((string)$r[$f]), $qLower) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }
    $total = count($rows);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($pages, (int)$page));
    $items = array_slice($rows, ($page - 1) * $perPage, $perPage);
    return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
}

function getBackupList() {
    $backups = [];
    if (is_dir(BACKUP_DIR)) {
        foreach (glob(BACKUP_DIR . '/pre-update-*.tar.gz') ?: [] as $f) {
            $basename = basename($f);
            // 更新备份格式: pre-update-{version}-{timestamp}.tar.gz
            if (preg_match('/^pre-update-v?([\d.]+)-(\d+)\.tar\.gz$/', $basename, $m)) {
                $backups[] = [
                    'file' => $basename, 'path' => $f, 'version' => $m[1],
                    'timestamp' => (int)$m[2], 'size' => filesize($f), 'type' => 'update',
                ];
            }
        }
        foreach (glob(BACKUP_DIR . '/ym-backup-*.tar.gz') ?: [] as $f) {
            $basename = basename($f);
            // 手动备份格式: ym-backup-{yyyyMMdd}-{HHmmss}.tar.gz
            if (preg_match('/^ym-backup-(\d{8})-(\d{6})\.tar\.gz$/', $basename, $m2)) {
                $backups[] = [
                    'file' => $basename, 'path' => $f, 'version' => '手动备份',
                    'timestamp' => (int)strtotime($m2[1] . ' ' . $m2[2]), 'size' => filesize($f), 'type' => 'manual',
                ];
            }
        }
        // 数据库 30 分钟自动备份（固定 1 份滚动）
        foreach (glob(BACKUP_DB_DIR . '/ym-db-latest.tar.gz') ?: [] as $f) {
            $backups[] = [
                'file' => basename($f), 'path' => $f, 'version' => '数据库自动备份',
                'timestamp' => (int)filemtime($f), 'size' => filesize($f), 'type' => 'db',
            ];
        }
        // 文章每日自动备份
        foreach (glob(BACKUP_ARTICLES_DIR . '/ym-articles-*.tar.gz') ?: [] as $f) {
            if (preg_match('/^ym-articles-(\d{8})\.tar\.gz$/', basename($f), $m3)) {
                $backups[] = [
                    'file' => basename($f), 'path' => $f, 'version' => '文章每日备份',
                    'timestamp' => (int)strtotime($m3[1]), 'size' => filesize($f), 'type' => 'articles',
                ];
            }
        }
    }
    // 按时间降序
    usort($backups, function($a, $b) { return $b['timestamp'] - $a['timestamp']; });
    return $backups;
}

// ============================================================
// 自动备份配置（backup.conf，与守护进程 ym-guard.py 同源）
// ============================================================
function getBackupConfig() {
    $cfg = ['interval_min' => 30, 'article_keep' => 7, 'manual_keep' => 5];
    if (is_file(BACKUP_CONF)) {
        foreach (file(BACKUP_CONF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k); $v = trim($v);
            if ($k === 'DB_BACKUP_INTERVAL_MIN') $cfg['interval_min'] = (int)$v;
            elseif ($k === 'ARTICLE_BACKUP_KEEP') $cfg['article_keep'] = (int)$v;
            elseif ($k === 'MANUAL_BACKUP_KEEP') $cfg['manual_keep'] = (int)$v;
        }
    }
    // 白名单约束（与守护进程一致）
    $cfg['interval_min'] = ($cfg['interval_min'] >= 5 && $cfg['interval_min'] <= 1440) ? $cfg['interval_min'] : 30;
    $cfg['article_keep'] = ($cfg['article_keep'] >= 1 && $cfg['article_keep'] <= 90) ? $cfg['article_keep'] : 7;
    $cfg['manual_keep'] = ($cfg['manual_keep'] >= 1 && $cfg['manual_keep'] <= 30) ? $cfg['manual_keep'] : 5;
    return $cfg;
}

function saveBackupConfig($intervalMin, $articleKeep, $manualKeep) {
    $intervalMin = (int)$intervalMin;
    $articleKeep = (int)$articleKeep;
    $manualKeep = (int)$manualKeep;
    $intervalMin = ($intervalMin >= 5 && $intervalMin <= 1440) ? $intervalMin : 30;
    $articleKeep = ($articleKeep >= 1 && $articleKeep <= 90) ? $articleKeep : 7;
    $manualKeep = ($manualKeep >= 1 && $manualKeep <= 30) ? $manualKeep : 5;
    $content = "# 自动备份配置（守护进程 ym-guard.py 读取；超管后台/SSH 可改）\n"
        . "DB_BACKUP_INTERVAL_MIN={$intervalMin}\n"
        . "ARTICLE_BACKUP_KEEP={$articleKeep}\n"
        . "MANUAL_BACKUP_KEEP={$manualKeep}\n";
    return file_put_contents(BACKUP_CONF, $content, LOCK_EX) !== false;
}

function getGuardState() {
    if (!is_file(GUARD_STATE_FILE)) return [];
    $s = @file_get_contents(GUARD_STATE_FILE);
    $d = json_decode((string)$s, true);
    return is_array($d) ? $d : [];
}

function checkForUpdates($channel = 'stable') {
    // 从配置读取仓库 API 地址，留空则跳过更新检查
    $apiBase = appConfig('repo_api_url', '');
    if ($apiBase === '') {
        return [
            'available' => false,
            'latest_version' => APP_VERSION,
            'current_version' => APP_VERSION,
            'release_notes' => '',
            'download_url' => '',
            'published_at' => '',
            'source' => 'local',
        ];
    }
    // beta 通道查询 releases 列表（含 pre-release），stable 查询 latest
    $url = $channel === 'beta'
        ? rtrim($apiBase, '/') . "/releases?per_page=10"
        : rtrim($apiBase, '/') . "/releases/latest";
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: " . appConfig('app_name', 'You Super Markdown') . "/" . APP_VERSION . "\r\n",
            'timeout' => 10,
        ],
    ]);
    $result = @file_get_contents($url, false, $context);
    if ($result) {
        $release = json_decode($result, true);
        if ($channel === 'beta' && is_array($release)) {
            $release = $release[0] ?? null;
        }
        if ($release && isset($release['tag_name'])) {
            $latest = ltrim($release['tag_name'], 'v');
            return [
                'available' => version_compare($latest, APP_VERSION) > 0,
                'latest_version' => $latest,
                'current_version' => APP_VERSION,
                'release_notes' => $release['body'] ?? '',
                'download_url' => $release['zipball_url'] ?? '',
                'published_at' => $release['published_at'] ?? '',
                'source' => 'github',
            ];
        }
    }
    // 降级：返回本地版本信息
    return [
        'available' => false,
        'latest_version' => APP_VERSION,
        'current_version' => APP_VERSION,
        'release_notes' => '',
        'download_url' => '',
        'published_at' => '',
        'source' => 'local',
    ];
}

// ============================================================
// v2.11.0：WebAuthn 设备认证（可选快速登录）
// 电脑 Windows Hello PIN / 手机指纹等平台认证器；仅支持 ES256(P-256)；
// 服务端只存公钥与计数器，不存任何生物特征（隐私友好）。
// ============================================================

function webauthn_base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function webauthn_base64url_decode($data) {
    $b64 = strtr((string)$data, '-_', '+/');
    $b64 = str_pad($b64, (int)(4 * ceil(strlen($b64) / 4)), '=', STR_PAD_RIGHT);
    return base64_decode($b64, true);
}

/** RP 信息：域名（从 site_url 提取主机名）；缺省回退 HTTP_HOST */
function webauthn_rp_id() {
    $host = parse_url(appConfig('site_url', ''), PHP_URL_HOST);
    if (!$host) $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $host;
}
function webauthn_origin() {
    $siteUrl = appConfig('site_url', '');
    $scheme = parse_url($siteUrl, PHP_URL_SCHEME);
    $host = parse_url($siteUrl, PHP_URL_HOST);
    if (!$scheme || !$host) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    }
    $port = parse_url($siteUrl, PHP_URL_PORT);
    return $scheme . '://' . $host . ($port ? ':' . $port : '');
}

/** 生成 32 字节 challenge 存 session（300 秒一次性） */
function webauthn_new_challenge($purpose) {
    $challenge = webauthn_base64url_encode(random_bytes(32));
    $_SESSION['webauthn'][$purpose] = ['challenge' => $challenge, 'expires' => time() + 300];
    return $challenge;
}
function webauthn_consume_challenge($purpose) {
    $c = $_SESSION['webauthn'][$purpose] ?? null;
    unset($_SESSION['webauthn'][$purpose]);
    if (!$c) return null;
    if (($c['expires'] ?? 0) < time()) return null;
    return $c['challenge'];
}

/** 校验 clientDataJSON：type / challenge / origin */
function webauthn_verify_client_data($clientDataJson, $expectedType, $expectedChallenge) {
    $d = json_decode($clientDataJson, true);
    if (!is_array($d)) return false;
    if (($d['type'] ?? '') !== $expectedType) return false;
    if (!hash_equals($expectedChallenge, $d['challenge'] ?? '')) return false;
    if (($d['origin'] ?? '') !== webauthn_origin()) return false;
    return true;
}

/**
 * 最小 CBOR 解码（仅支持 WebAuthn COSE 所需子集）
 * 返回 [value, 新 offset]；value：int|string|array
 */
function webauthn_cbor_decode($buf, &$off) {
    if ($off >= strlen($buf)) throw new Exception('CBOR 越界');
    $ib = ord($buf[$off]);
    $major = ($ib >> 5) & 0x07;
    $ai = $ib & 0x1f;
    $off++;
    $val = $ai;
    if ($ai === 24) { $val = ord($buf[$off]); $off++; }
    elseif ($ai === 25) { $val = unpack('n', substr($buf, $off, 2))[1]; $off += 2; }
    elseif ($ai === 26) { $val = unpack('N', substr($buf, $off, 4))[1]; $off += 4; }
    elseif ($ai === 27) { $val = unpack('J', substr($buf, $off, 8))[1]; $off += 8; }
    elseif ($ai === 31) throw new Exception('不支持的 indefinite 编码');
    switch ($major) {
        case 0: return $val;                              // uint
        case 1: return -1 - $val;                         // nint
        case 2: $s = substr($buf, $off, $val); $off += $val; return $s;   // bytes
        case 3: $s = substr($buf, $off, $val); $off += $val; return $s;   // text
        case 5: {                                         // map
            $m = [];
            for ($i = 0; $i < $val; $i++) {
                $k = webauthn_cbor_decode($buf, $off);
                $v = webauthn_cbor_decode($buf, $off);
                $m[$k] = $v;
            }
            return $m;
        }
        case 6: return webauthn_cbor_decode($buf, $off); // tag（跳过内层）
        case 7: if ($ai === 20) return false; if ($ai === 21) return true; if ($ai === 22) return null; return $val;
    }
    throw new Exception('CBOR major 不支持: ' . $major);
}

/**
 * COSE 公钥 → PEM（仅 ES256 / P-256）
 * @return array{pem:string, alg:int}
 */
function webauthn_cose_to_pem($coseBytes) {
    $off = 0;
    $c = webauthn_cbor_decode($coseBytes, $off);
    if (!is_array($c)) throw new Exception('COSE 格式错误');
    $kty = $c[1] ?? null;
    if ($kty === 2) { // EC2
        if (($c[-1] ?? null) !== 1) throw new Exception('仅支持 P-256 曲线');
        $x = $c[-2] ?? ''; $y = $c[-3] ?? '';
        if (strlen($x) !== 32 || strlen($y) !== 32) throw new Exception('P-256 坐标长度错误');
        $der = "\x30\x59"
            . "\x30\x13"
            . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"         // id-ecPublicKey
            . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"     // prime256v1
            . "\x03\x42\x00\x04"
            . $x . $y;
        return [
            'pem' => "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n",
            'alg' => (int)($c[3] ?? -7),
        ];
    }
    throw new Exception('仅支持 ES256（EC P-256）');
}

/**
 * 解析 authenticatorData
 * @param string $authData
 * @param bool $withCred 注册(create)时 true（含凭据数据）
 */
function webauthn_parse_auth_data($authData, $withCred) {
    $off = 0;
    $rpIdHash = substr($authData, 0, 32); $off += 32;
    $flags = ord($authData[$off]); $off += 1;
    $counter = unpack('N', substr($authData, $off, 4))[1]; $off += 4;
    $out = ['rpIdHash' => $rpIdHash, 'flags' => $flags, 'counter' => $counter, 'credentialId' => '', 'cose' => ''];
    if ($withCred) {
        $credIdLen = unpack('n', substr($authData, $off, 2))[1]; $off += 2;
        $out['credentialId'] = substr($authData, $off, $credIdLen); $off += $credIdLen;
        $out['cose'] = substr($authData, $off);
    }
    return $out;
}

/** ECDSA raw r||s → DER 签名（openssl_verify 需要） */
function webauthn_raw_to_der($sig) {
    if (strlen($sig) !== 64) return $sig;
    $r = ltrim(substr($sig, 0, 32), "\x00");
    $s = ltrim(substr($sig, 32, 32), "\x00");
    if (strlen($r) === 0) $r = "\x00";
    if (strlen($s) === 0) $s = "\x00";
    if (ord($r[0]) & 0x80) $r = "\x00" . $r;
    if (ord($s[0]) & 0x80) $s = "\x00" . $s;
    return "\x30" . chr(2 + strlen($r) + 2 + strlen($s))
        . "\x02" . chr(strlen($r)) . $r
        . "\x02" . chr(strlen($s)) . $s;
}

/** 注册开始：返回前端 PublicKeyCredentialCreationOptions */
function webauthn_register_begin($user) {
    $challenge = webauthn_new_challenge('register');
    return [
        'challenge' => $challenge,
        'rp' => ['id' => webauthn_rp_id(), 'name' => appConfig('app_name', 'You Super Markdown')],
        'user' => [
            'id' => webauthn_base64url_encode(hash('sha256', $user['id'], true)),
            'name' => (string)$user['qq'],
            'displayName' => $user['nickname'] ?? $user['qq'],
        ],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
        // v2.11.8：按 Android 平台认证器（Google/MIUI 密码管理器）最佳实践配置——
        // ① 恢复 authenticatorAttachment=platform（明确走系统指纹/通行证路径，改善 GPM 集成）；
        // ② residentKey=preferred（允许无用户名通行证，GPM 友好）；③ timeout 120s（Android 创建流程较慢）
        'authenticatorSelection' => [
            'authenticatorAttachment' => 'platform',
            'residentKey' => 'preferred',
            'userVerification' => 'preferred',
        ],
        'timeout' => 120000,
        'attestation' => 'none',
        'extensions' => ['credProps' => true],
    ];
}

/** 注册完成：校验并保存凭据 */
function webauthn_register_complete($input, $userId, $deviceName) {
    $challenge = webauthn_consume_challenge('register');
    if (!$challenge) return ['ok' => false, 'err' => '验证会话已过期，请重试'];
    $clientData = webauthn_base64url_decode($input['clientDataJSON'] ?? '');
    $attObj = webauthn_base64url_decode($input['attestationObject'] ?? '');
    if ($clientData === false || $attObj === false) return ['ok' => false, 'err' => '数据格式错误'];
    if (!webauthn_verify_client_data($clientData, 'webauthn.create', $challenge)) {
        return ['ok' => false, 'err' => '校验失败（challenge/来源不匹配）'];
    }
    $off = 0;
    try {
        $att = webauthn_cbor_decode($attObj, $off);
    } catch (Exception $e) {
        return ['ok' => false, 'err' => 'attestation 解析失败'];
    }
    if (!is_array($att) || !isset($att['authData'])) return ['ok' => false, 'err' => 'attestation 缺少 authData'];
    $ad = webauthn_parse_auth_data($att['authData'], true);
    if (!hash_equals(hash('sha256', webauthn_rp_id(), true), $ad['rpIdHash'])) {
        return ['ok' => false, 'err' => 'RP 标识不匹配'];
    }
    if (($ad['flags'] & 0x01) === 0) return ['ok' => false, 'err' => '用户在场位未设置'];
    if (empty($ad['credentialId']) || empty($ad['cose'])) return ['ok' => false, 'err' => '凭据数据缺失'];
    $credIdB64 = webauthn_base64url_encode($ad['credentialId']);
    if (db_one('SELECT id FROM webauthn_credentials WHERE credential_id = ?', [$credIdB64])) {
        return ['ok' => false, 'err' => '该设备已绑定'];
    }
    try {
        $pem = webauthn_cose_to_pem($ad['cose']);
    } catch (Exception $e) {
        return ['ok' => false, 'err' => $e->getMessage()];
    }
    db_exec(
        'INSERT INTO webauthn_credentials (user_id, credential_id, public_key, counter, device_name, created, last_used) VALUES (?,?,?,?,?,?,?)',
        [$userId, $credIdB64, $pem['pem'], $ad['counter'], $deviceName, time(), time()]
    );
    auditLog('webauthn_bind', $userId, "绑定设备: {$deviceName}");
    return ['ok' => true];
}

/** 登录开始：返回 PublicKeyCredentialRequestOptions（含该账号已绑定凭据） */
function webauthn_login_begin($qq) {
    $challenge = webauthn_new_challenge('login');
    $creds = db_all(
        'SELECT credential_id FROM webauthn_credentials WHERE user_id = (SELECT id FROM users WHERE qq = ?)',
        [$qq]
    );
    $allow = [];
    foreach ($creds as $c) {
        $allow[] = ['type' => 'public-key', 'id' => $c['credential_id']];
    }
    return [
        'challenge' => $challenge,
        'rpId' => webauthn_rp_id(),
        'allowCredentials' => $allow,
        // v2.11.6：同注册端，放宽为 preferred 提高兼容性
        'userVerification' => 'preferred',
        'timeout' => 60000,
    ];
}

/** 登录完成：验证断言 → 建立会话（与密码登录一致） */
function webauthn_login_complete($input, $qq) {
    $challenge = webauthn_consume_challenge('login');
    if (!$challenge) return ['ok' => false, 'err' => '验证会话已过期，请重试'];
    $clientData = webauthn_base64url_decode($input['clientDataJSON'] ?? '');
    $authData = webauthn_base64url_decode($input['authenticatorData'] ?? '');
    $signature = webauthn_base64url_decode($input['signature'] ?? '');
    $credIdB64 = $input['id'] ?? '';
    if ($clientData === false || $authData === false || $signature === false || $credIdB64 === '') {
        return ['ok' => false, 'err' => '数据格式错误'];
    }
    if (!webauthn_verify_client_data($clientData, 'webauthn.get', $challenge)) {
        return ['ok' => false, 'err' => '校验失败（challenge/来源不匹配）'];
    }
    $cred = db_one('SELECT * FROM webauthn_credentials WHERE credential_id = ?', [$credIdB64]);
    if (!$cred) return ['ok' => false, 'err' => '设备未绑定'];
    $owner = db_one('SELECT id, qq FROM users WHERE id = ?', [$cred['user_id']]);
    if (!$owner || $owner['qq'] !== $qq) return ['ok' => false, 'err' => '设备与账号不匹配'];
    // v2.11.4：被禁用账号拒绝设备登录
    $ownerFull = null;
    foreach (loadUsers() as $ou) {
        if ($ou['id'] === $cred['user_id']) { $ownerFull = $ou; break; }
    }
    if (!$ownerFull || !empty($ownerFull['disabled'])) return ['ok' => false, 'err' => '该账号已被禁用，请联系管理员'];
    $ad = webauthn_parse_auth_data($authData, false);
    if (!hash_equals(hash('sha256', webauthn_rp_id(), true), $ad['rpIdHash'])) {
        return ['ok' => false, 'err' => 'RP 标识不匹配'];
    }
    if (($ad['flags'] & 0x01) === 0) return ['ok' => false, 'err' => '用户在场位未设置'];
    if (($ad['flags'] & 0x04) === 0) return ['ok' => false, 'err' => '未完成用户验证（PIN/指纹）'];
    if ((int)$cred['counter'] > 0 && $ad['counter'] <= (int)$cred['counter']) {
        return ['ok' => false, 'err' => '计数器异常（凭据可能被克隆）'];
    }
    $signed = $authData . hash('sha256', $clientData, true);
    $ok = openssl_verify($signed, webauthn_raw_to_der($signature), $cred['public_key'], OPENSSL_ALGO_SHA256);
    if ($ok !== 1) return ['ok' => false, 'err' => '签名验证失败'];
    db_exec('UPDATE webauthn_credentials SET counter = ?, last_used = ? WHERE credential_id = ?', [$ad['counter'], time(), $credIdB64]);
    $u = null;
    foreach (loadUsers() as $uu) {
        if ($uu['id'] === $cred['user_id']) { $u = $uu; break; }
    }
    if (!$u) return ['ok' => false, 'err' => '账号不存在'];
    session_regenerate_id(true);
    // v2.11.5：设备登录同样记录最后登录时间与次数
    db_exec('UPDATE users SET last_login = ?, login_count = login_count + 1 WHERE id = ?', [date('Y-m-d H:i:s'), $u['id']]);
    $_SESSION['cmt_user'] = [
        'id' => $u['id'], 'qq' => $u['qq'],
        'nickname' => $u['nickname'] ?? '',
        'avatar' => $u['avatar'] ?? (preg_match('/^\d+$/', $u['qq']) ? 'https://q1.qlogo.cn/g?b=qq&nk=' . $u['qq'] . '&s=100' : ''),
        'signature' => $u['signature'] ?? '',
        'role' => $u['role'] ?? 'user',
        'email' => $u['email'] ?? '',
        'pw_hash' => $u['password']
    ];
    auditLog('webauthn_login', $u['id'], '设备快速登录（PIN/指纹）');
    return ['ok' => true, 'user' => sanitizeUserForClient($_SESSION['cmt_user'])];
}

/** 列出账号已绑定设备（不暴露 credential_id 原文） */
function webauthn_list_devices($userId) {
    $rows = db_all('SELECT id, device_name, created, last_used FROM webauthn_credentials WHERE user_id = ? ORDER BY id', [$userId]);
    return array_map(function($r) {
        return [
            'id' => (int)$r['id'],
            'device_name' => $r['device_name'] ?? '未知设备',
            'created' => (int)$r['created'],
            'last_used' => (int)$r['last_used'],
        ];
    }, $rows);
}
function webauthn_remove_device($userId, $deviceId) {
    db_exec('DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?', [(int)$deviceId, $userId]);
}
/** v2.11.1：重命名已绑定设备（≤30 字符） */
function webauthn_rename_device($userId, $deviceId, $name) {
    $name = trim((string)$name);
    if ($name === '' || mb_strlen($name) > 30) return false;
    db_exec('UPDATE webauthn_credentials SET device_name = ? WHERE id = ? AND user_id = ?', [$name, (int)$deviceId, $userId]);
    auditLog('webauthn_rename', $userId, '重命名设备 #' . (int)$deviceId . ' → ' . $name);
    return true;
}
