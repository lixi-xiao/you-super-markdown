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
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM users');
        $st = $pdo->prepare('INSERT OR REPLACE INTO users (id, qq, nickname, password, avatar, signature, role, station_id, created, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($users as $u) {
            $st->execute([
                $u['id'] ?? '', $u['qq'] ?? '', $u['nickname'] ?? '', $u['password'] ?? '',
                $u['avatar'] ?? '', $u['signature'] ?? '', $u['role'] ?? 'user',
                $u['station_id'] ?? '', $u['created'] ?? '', $u['created_by'] ?? '',
            ]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
function genId() { return bin2hex(random_bytes(8)); }

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
    return $data;
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
    $prevHash = '';
    if (file_exists(AUDIT_CHAIN_FILE)) {
        $prevHash = trim(file_get_contents(AUDIT_CHAIN_FILE));
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
    $mirrorChain = AUDIT_MIRROR_DIR . 'audit_chain';
    if (file_exists($mirrorChain)) copy($mirrorChain, AUDIT_CHAIN_FILE);
    return true;
}

function sendAlert($type, $detail) {
    $config = loadSiteConfig();
    $adminEmail = $config['admin_email'] ?? '';
    if (!$adminEmail || !file_exists(EMAIL_ALERT)) return;
    $site = $config['site_title'] ?? 'You Super Markdown';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $subject = "[{$site} 告警] {$type}";
    $body = "时间：" . date('Y-m-d H:i:s') . "\n"
          . "服务器：{$host}\n"
          . "事件类型：{$type}\n"
          . "详情：{$detail}\n";
    $cmd = escapeshellcmd(EMAIL_ALERT) . ' ' . escapeshellarg($adminEmail) . ' ' . escapeshellarg($subject) . ' ' . escapeshellarg($body);
    exec($cmd . ' > /dev/null 2>&1 &');
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
