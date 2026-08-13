<?php
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
    $f = __DIR__ . '/data/.users.json';
    if (!file_exists($f)) return [];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function saveUsers($users) {
    $f = __DIR__ . '/data/.users.json';
    file_put_contents($f, json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}
function genId() { return bin2hex(random_bytes(8)); }

function loadSiteConfig() {
    $f = __DIR__ . '/data/.config.json';
    if (!file_exists($f)) return [
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
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function saveSiteConfig($config) {
    return file_put_contents(__DIR__ . '/data/.config.json', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
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
    $f = __DIR__ . '/data/.bans.json';
    if (!file_exists($f)) return [];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function saveBansList($bans) {
    file_put_contents(__DIR__ . '/data/.bans.json', json_encode($bans, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}
function addBan($ip, $types, $reason = '') {
    $bans = loadBansList();
    foreach ($bans as &$b) {
        if ($b['ip'] === $ip) {
            foreach ($types as $t) { if (!in_array($t, $b['types'])) $b['types'][] = $t; }
            $b['reason'] = $reason;
            saveBansList($bans);
            return;
        }
    }
    unset($b);
    $bans[] = ['ip' => $ip, 'types' => $types, 'reason' => $reason, 'time' => date('Y-m-d H:i:s')];
    saveBansList($bans);
}
function isIPBanned($ip, $type) {
    $bans = loadBansList();
    foreach ($bans as $b) { if ($b['ip'] === $ip && in_array($type, $b['types'] ?? [])) return true; }
    return false;
}
function getClientIP() {
    if (!empty($_SERVER['HTTP_X_REAL_IP']) && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1') {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function loadLogsList() {
    $f = __DIR__ . '/data/.logs.json';
    if (!file_exists($f)) return [];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function saveLogsList($logs) {
    file_put_contents(__DIR__ . '/data/.logs.json', json_encode($logs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}
function logAbnormal($ip, $action) {
    $logs = loadLogsList();
    $logs[] = ['ip' => $ip, 'action' => $action, 'time' => date('Y-m-d H:i:s')];
    if (count($logs) > 500) $logs = array_slice($logs, -500);
    saveLogsList($logs);
}
function logUnauthorized($action, $ban = false) {
    $logFile = __DIR__ . '/data/.unauthorized.json';
    $logs = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
    if (!is_array($logs)) $logs = [];
    $ip = getClientIP();
    $logs[] = [
        'ip' => $ip,
        'action' => $action,
        'user' => $_SESSION['cmt_user']['nickname'] ?? '未登录',
        'user_id' => $_SESSION['cmt_user']['id'] ?? '',
        'ua' => mb_substr(htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? '', ENT_QUOTES, 'UTF-8'), 0, 256),
        'time' => date('Y-m-d H:i:s')
    ];
    if (count($logs) > 1000) $logs = array_slice($logs, -1000);
    file_put_contents($logFile, json_encode($logs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
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
    $f = __DIR__ . '/data/.roles.json';
    $defaults = [
        ROLE_SUPER_ADMIN => ['label' => '高级管理员', 'can' => ['*']],
        ROLE_STATION_ADMIN => ['label' => '站长', 'can' => ['article.create','article.edit','article.delete','article.edit_any','article.delete_any','author.create','author.delete','user.view']],
        ROLE_AUTHOR => ['label' => '写作者', 'can' => ['article.create','article.edit_own','article.delete_own']],
        ROLE_USER => ['label' => '用户', 'can' => ['comment.create','profile.edit']],
        ROLE_GUEST => ['label' => '访客', 'can' => ['article.read']],
    ];
    if (!file_exists($f)) { file_put_contents($f, json_encode($defaults, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX); return $defaults; }
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : $defaults;
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
// v2.2 审计日志 + 哈希链
// ============================================================
define('AUDIT_LOG_FILE', __DIR__ . '/data/.audit.json');
define('AUDIT_CHAIN_FILE', __DIR__ . '/data/.audit_chain');
define('AUDIT_MIRROR_DIR', '/opt/you-markdown/logs/');
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
    $logs = [];
    if (file_exists(AUDIT_LOG_FILE)) {
        $logs = json_decode(file_get_contents(AUDIT_LOG_FILE), true) ?: [];
    }
    $prevHash = '';
    if (file_exists(AUDIT_CHAIN_FILE)) {
        $prevHash = trim(file_get_contents(AUDIT_CHAIN_FILE));
    }
    $entry['prev_hash'] = $prevHash;
    $entryJson = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $entry['hash'] = hash('sha256', $entryJson);
    unset($entry['prev_hash']);
    $logs[] = $entry;
    if (count($logs) > 10000) $logs = array_slice($logs, -10000);
    file_put_contents(AUDIT_LOG_FILE, json_encode($logs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    file_put_contents(AUDIT_CHAIN_FILE, $entry['hash'], LOCK_EX);
    if (is_dir(AUDIT_MIRROR_DIR)) {
        file_put_contents(AUDIT_MIRROR_DIR . 'audit.json', json_encode($logs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        file_put_contents(AUDIT_MIRROR_DIR . 'audit_chain', $entry['hash'], LOCK_EX);
    }
    return $entry;
}

function verifyAuditChain() {
    if (!file_exists(AUDIT_LOG_FILE)) return ['valid' => true, 'count' => 0];
    $logs = json_decode(file_get_contents(AUDIT_LOG_FILE), true) ?: [];
    if (empty($logs)) return ['valid' => true, 'count' => 0];
    $prevHash = '';
    for ($i = 0; $i < count($logs); $i++) {
        $entry = $logs[$i];
        $expectedHash = $entry['hash'] ?? '';
        $checkData = $entry;
        unset($checkData['hash']);
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
    if (!is_dir(AUDIT_MIRROR_DIR)) return false;
    $mirrorFile = AUDIT_MIRROR_DIR . 'audit.json';
    $mirrorChain = AUDIT_MIRROR_DIR . 'audit_chain';
    if (!file_exists($mirrorFile)) return false;
    copy($mirrorFile, AUDIT_LOG_FILE);
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

// 服务器挑战码校验（300 秒、单次）：匹配 code + 未过期 + 未使用，通过则原子消费
function verifyChallenge($code) {
    $f = __DIR__ . '/data/.challenge.json';
    if (!file_exists($f) || empty($code)) return false;
    $challenges = json_decode(file_get_contents($f), true);
    if (!is_array($challenges)) return false;
    $valid = false;
    foreach ($challenges as $i => $c) {
        if (strtoupper($c['code'] ?? '') === strtoupper($code) && ($c['expires'] ?? 0) > time() && empty($c['used'])) {
            $challenges[$i]['used'] = 1;
            $valid = true;
            break;
        }
    }
    if ($valid) {
        file_put_contents($f, json_encode($challenges, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }
    return $valid;
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

function getBackupList() {
    if (!is_dir(BACKUP_DIR)) return [];
    $files = array_merge(
        glob(BACKUP_DIR . '/pre-update-*.tar.gz') ?: [],
        glob(BACKUP_DIR . '/ym-backup-*.tar.gz') ?: []
    );
    $backups = [];
    foreach ($files as $f) {
        $basename = basename($f);
        // 更新备份格式: pre-update-{version}-{timestamp}.tar.gz
        if (preg_match('/^pre-update-v?([\d.]+)-(\d+)\.tar\.gz$/', $basename, $m)) {
            $backups[] = [
                'file' => $basename,
                'path' => $f,
                'version' => $m[1],
                'timestamp' => (int)$m[2],
                'size' => filesize($f),
                'type' => 'update',
            ];
        }
        // 手动备份格式: ym-backup-{yyyyMMdd}-{HHmmss}.tar.gz
        elseif (preg_match('/^ym-backup-(\d{8})-(\d{6})\.tar\.gz$/', $basename, $m2)) {
            $backups[] = [
                'file' => $basename,
                'path' => $f,
                'version' => '手动备份',
                'timestamp' => (int)strtotime($m2[1] . ' ' . $m2[2]),
                'size' => filesize($f),
                'type' => 'manual',
            ];
        }
    }
    // 按时间降序
    usort($backups, function($a, $b) { return $b['timestamp'] - $a['timestamp']; });
    return $backups;
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
