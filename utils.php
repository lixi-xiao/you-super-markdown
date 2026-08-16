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
function isPrivateIp($ip) {
    $ip = strtolower(trim((string)$ip));
    if ($ip === '') return true;
    // IPv4
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        if ($long === false) return true; // 未识别地址 → 默认拒绝
        $ranges = [
            [ip2long('0.0.0.0'), ip2long('0.255.255.255')],
            [ip2long('10.0.0.0'), ip2long('10.255.255.255')],
            [ip2long('100.64.0.0'), ip2long('100.127.255.255')],   // CGNAT
            [ip2long('127.0.0.0'), ip2long('127.255.255.255')],
            [ip2long('169.254.0.0'), ip2long('169.254.255.255')],
            [ip2long('172.16.0.0'), ip2long('172.31.255.255')],
            [ip2long('192.0.0.0'), ip2long('192.0.0.255')],        // 保留（含 192.0.0.0/24）
            [ip2long('192.168.0.0'), ip2long('192.168.255.255')],
            [ip2long('198.18.0.0'), ip2long('198.19.255.255')],    // 基准测试
            [ip2long('198.51.100.0'), ip2long('198.51.100.255')],  // TEST-NET
            [ip2long('203.0.113.0'), ip2long('203.0.113.255')],    // TEST-NET
            [ip2long('224.0.0.0'), ip2long('239.255.255.255')],    // 组播
            [ip2long('240.0.0.0'), ip2long('255.255.255.255')],    // 保留
        ];
        foreach ($ranges as $r) {
            if ($long >= $r[0] && $long <= $r[1]) return true;
        }
        return false;
    }
    // IPv6
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) return true; // 未识别 → 默认拒绝
        $b0 = ord($bin[0]);
        $b1 = ord($bin[1]);
        if ($ip === '::1' || $ip === '::') return true;         // loopback / unspecified
        if (($b0 & 0xfe) === 0xfc) return true;                 // fc00::/7 ULA
        if ($b0 === 0xfe && ($b1 & 0xc0) === 0x80) return true; // fe80::/10 link-local
        if ($b0 === 0xff) return true;                          // ff00::/8 multicast
        if (substr($bin, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") { // ::ffff:0:0/96
            return isPrivateIp(inet_ntop(substr($bin, 12)));
        }
        if (substr($bin, 0, 4) === "\x20\x01\x0d\xb8") return true; // 2001:db8::/32 文档
        if ($b0 === 0x01 && $b1 === 0x00 && substr($bin, 2, 6) === "\x00\x00\x00\x00\x00\x00") return true; // 100::/64 discard-only
        return false;
    }
    return true; // 非 IP → 默认拒绝
}
// 解析域名全部 A/AAAA 记录；任一内网即视为私有（防多 A 记录绕过）
function resolveAllHostIps($host) {
    $ips = [];
    $a = @gethostbynamel($host);
    if (is_array($a)) foreach ($a as $ip) $ips[] = $ip;
    $aaaa = @dns_get_record($host, DNS_AAAA);
    if (is_array($aaaa)) {
        foreach ($aaaa as $rec) {
            if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
        }
    }
    return array_values(array_unique($ips));
}
function isPrivateHost($host) {
    $host = strtolower(trim(trim((string)$host), '[]'));
    if ($host === '') return true; // 空 → 默认拒绝
    if ($host === 'localhost' || $host === '0') return true;
    // IP 字面量：直接校验（IPv4/IPv6 均覆盖）
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return isPrivateIp($host);
    }
    // 域名：解析全部 A/AAAA 记录，任一内网即拒绝
    $ips = resolveAllHostIps($host);
    if (empty($ips)) return true; // 解析失败/无记录 → 默认拒绝
    foreach ($ips as $ip) {
        if (isPrivateIp($ip)) return true;
    }
    return false;
}
// 解析出第一个公网 IP 用于直连（与 isPrivateHost 同源判定），无则返回 null（默认拒绝）
function resolvePublicIp($host) {
    $host = strtolower(trim(trim((string)$host), '[]'));
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return isPrivateIp($host) ? null : $host;
    }
    foreach (resolveAllHostIps($host) as $ip) {
        if (!isPrivateIp($ip)) return $ip;
    }
    return null;
}
// SSRF 安全抓取：一次解析 + 固定解析后的 IP 直连（Host/SNI 保留原域名），消除 DNS rebinding TOCTOU
function fetchHttpContent($url, $ua = null) {
    $parts = parse_url($url);
    $scheme = strtolower($parts['scheme'] ?? '');
    $host = strtolower(trim($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') return false;
    if (isPrivateHost($host)) return false; // 域名/IP 任一内网即拒
    $ip = resolvePublicIp($host);
    if ($ip === null) return false; // 未识别/无公网解析 → 默认拒绝
    if (strpos($ip, ':') !== false) $ip = '[' . $ip . ']'; // IPv6 括弧
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $targetUrl = $scheme . '://' . $ip . ':' . $port . $path;
    // v4.1.15：支持自定义 UA（封面图片池用桌面 UA 拉取横屏壁纸）
    $ua = $ua !== null ? $ua : (appConfig('app_name', 'You Super Markdown') . "/" . APP_VERSION);
    $header = "Host: " . $host . "\r\n"
        . "User-Agent: " . $ua . "\r\n"
        . "Connection: close\r\n";
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => $header,
            'timeout' => 10,
            'ignore_errors' => false,
        ],
    ];
    if ($scheme === 'https') {
        $opts['ssl'] = [
            'peer_name' => $host,        // TLS SNI 与证书校验仍用原域名
            'SNI_enabled' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'capture_peer_cert' => false,
        ];
    }
    return @file_get_contents($targetUrl, false, stream_context_create($opts));
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
        // v4.0.0：评论邮件订阅通知（新评论/回复时向站点管理员发信；默认关闭，超管后台可开）
        'comment_notify_enabled' => false,
        'comment_notify_email' => '',
        // v4.0.0：站内全文搜索开关（关闭则前端仅按标题/摘要/标签过滤）
        'fulltext_search_enabled' => true,
        // v4.1.16：卡片玻璃效果（毛玻璃/液态玻璃）+ 用户液态玻璃个人开关显示
        'card_glass_style' => 'frosted',   // frosted 毛玻璃 / liquid 液态玻璃（苹果 Liquid Glass 风格）
        'user_glass_toggle' => false,      // 是否在用户界面显示「液态玻璃」个人开关（低配设备可自行关闭）
        // v4.1.17：毛玻璃默认更透（50%），后台「卡片透明度」滑杆仍可 20-100% 自由调节
        'bg_card_opacity' => 50,
        'bg_blur_enabled' => false,
        'bg_blur_level' => 0,
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
// ============================================================
// v3.0.8 统一安全入口（checkScanner：扫描器 UA 黑名单检测）
// 命中 sqlmap/nikto/nmap/acunetix/masscan/zgrab/curl 等工具特征：
// 返回 403 + 记录越权日志 + 按现有封禁机制封禁来源 IP
// ============================================================
function checkScanner() {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') return false;
    $l = strtolower($ua);
    $tools = [
        'sqlmap', 'nikto', 'nmap', 'acunetix', 'masscan', 'zgrab', 'gobuster',
        'dirb', 'dirbuster', 'wpscan', 'nessus', 'openvas', 'hydra', 'metasploit',
        'curl', 'wget', 'python-requests', 'scrapy',
    ];
    foreach ($tools as $t) {
        if (strpos($l, $t) !== false) return $t;
    }
    return false;
}
function security_check() {
    $tool = checkScanner();
    if ($tool === false) return;
    $ip = getClientIP();
    logUnauthorized('扫描器UA检测: ' . $tool);
    addBan($ip, ['login', 'register', 'comment'], '扫描器UA: ' . $tool);
    logAbnormal($ip, '扫描器UA封禁: ' . $tool);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
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

// ============================================================
// v3.0.8 SMTP 密码可逆加密存储（应用密钥分层：独立 data/.app_secret，AES-256-GCM）
// 建议使用独立专用发信账号的客户端授权码，不复用个人邮箱密码
// ============================================================
function getAppSecret() {
    $f = __DIR__ . '/data/.app_secret';
    if (!file_exists($f)) {
        @file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($f, 0600);
    }
    return file_get_contents($f);
}
function encryptSecret($plain) {
    if ($plain === '') return '';
    $key = hash('sha256', getAppSecret(), true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) return '';
    return 'gcm:' . base64_encode($iv . $tag . $cipher);
}
function decryptSecret($stored) {
    if ($stored === '') return '';
    if (strpos($stored, 'gcm:') !== 0) return $stored; // 兼容历史明文（保存时自动迁移为密文）
    $raw = base64_decode(substr($stored, 4));
    if ($raw === false || strlen($raw) < 12 + 16) return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $key = hash('sha256', getAppSecret(), true);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}
// v3.3.0：视频文件合法性强制校验（防伪装文件/损坏文件）
// 三重校验：扩展名（调用方已校验）→ finfo MIME → 容器魔数/Box 结构
// MP4：文件头 8 字节必须为大端长度 + 'ftyp'（ftyp box 是 MP4 容器第一个 box）
// WebM：EBML 魔数 1A 45 DF A3
function validateVideoFile($path, $ext) {
    if (!is_file($path)) return false;
    $size = @filesize($path);
    if ($size === false || $size <= 0) return false;
    $head = (string)@file_get_contents($path, false, null, 0, 128);
    if (strlen($head) < 16) return false;
    if (!class_exists('finfo')) {
        // 无 fileinfo 扩展时仅做容器结构校验
        if ($ext === 'mp4') return substr($head, 4, 4) === 'ftyp';
        if ($ext === 'webm') return bin2hex(substr($head, 0, 4)) === '1a45dfa3';
        return false;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($path);
    if ($ext === 'mp4') {
        // ftyp box：前 4 字节大端长度（8~128），第 5~8 字节 'ftyp'
        $boxLen = @unpack('Nlen', substr($head, 0, 4))['len'] ?? 0;
        $ftypOk = substr($head, 4, 4) === 'ftyp' && $boxLen >= 8 && $boxLen <= 128;
        // MIME 需为 video/mp4（部分环境识别为 application/octet-stream 时靠 ftyp 兜底）
        $mimeOk = in_array($mime, ['video/mp4', 'application/mp4', 'video/quicktime'], true);
        return $ftypOk && ($mimeOk || $mime === 'application/octet-stream');
    }
    if ($ext === 'webm') {
        $ebmlOk = bin2hex(substr($head, 0, 4)) === '1a45dfa3';
        return $mime === 'video/webm' && $ebmlOk;
    }
    return false;
}
// v3.3.0：内存版校验（供富媒体 zip 解析用，zip 内文件不落盘直接校验）
function validateVideoBuffer($content, $ext) {
    if (strlen($content) < 16) return false;
    if ($ext === 'mp4') {
        $boxLen = @unpack('Nlen', substr($content, 0, 4))['len'] ?? 0;
        return substr($content, 4, 4) === 'ftyp' && $boxLen >= 8 && $boxLen <= 128;
    }
    if ($ext === 'webm') return bin2hex(substr($content, 0, 4)) === '1a45dfa3';
    return false;
}
function validateImageBuffer($content) {
    if (!class_exists('finfo')) return false;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->buffer($content);
    return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
}
// SMTP 密码来源：环境变量注入（YM_SMTP_PASS，php-fpm pool env，root 只读、Web 不可见）优先；
// 未注入时回退 config 表密文（AES-GCM 同机加密兜底，兼容旧部署）
// v3.2.2：SMTP 密码三级来源（env → config 密文 → CLI root 密钥文件），
// 修复 CLI（ym-admin/守护进程）场景拿不到密码导致告警邮件发不出的问题
function smtpSecretFile() {
    return '/opt/you-markdown/secrets/smtp_pass';
}
function smtpPassSource() {
    $env = getenv('YM_SMTP_PASS');
    if ($env !== false && $env !== '') return 'env';
    $cfg = loadSiteConfig();
    $stored = (string)($cfg['smtp_pass'] ?? '');
    if ($stored !== '') return 'config';
    if (is_readable(smtpSecretFile()) && trim((string)@file_get_contents(smtpSecretFile())) !== '') return 'file';
    return 'none';
}
function getSmtpConfig() {
    $c = loadSiteConfig();
    $env = getenv('YM_SMTP_PASS');
    $pass = '';
    if ($env !== false && $env !== '') {
        $pass = $env;
    } else {
        $pass = decryptSecret((string)($c['smtp_pass'] ?? ''));
        if ($pass === '' && is_readable(smtpSecretFile())) {
            $pass = trim((string)@file_get_contents(smtpSecretFile()));
        }
    }
    return [
        'host' => trim($c['smtp_host'] ?? ''),
        'port' => (int)($c['smtp_port'] ?? 465),
        'user' => trim($c['smtp_user'] ?? ''),
        'pass' => $pass,
        'from' => trim($c['smtp_from'] ?? ''),
        'enc' => in_array($c['smtp_enc'] ?? '', ['ssl', 'tls', 'plain'], true) ? $c['smtp_enc'] : 'ssl',
    ];
}

function saveSmtpConfig($host, $port, $user, $pass, $from, $enc) {
    $cfg = loadSiteConfig();
    $cfg['smtp_host'] = trim($host);
    $cfg['smtp_port'] = max(1, (int)$port);
    $cfg['smtp_user'] = trim($user);
    // v3.0.9：密码优先走环境变量注入（YM_SMTP_PASS），后台不再直接设置；
    // 仅当环境未注入时才允许写入 config 表密文兜底（供旧部署平滑过渡）
    $env = getenv('YM_SMTP_PASS');
    if ($env === false || $env === '') {
        $pass = (string)$pass;
        if ($pass === '') {
            $cfg['smtp_pass'] = $cfg['smtp_pass'] ?? '';
        } elseif (strpos($pass, 'gcm:') === 0) {
            $cfg['smtp_pass'] = $pass;
        } else {
            $cfg['smtp_pass'] = encryptSecret($pass);
        }
    }
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
        ROLE_SUPER_ADMIN => '超管',
        ROLE_STATION_ADMIN => '站长',
        ROLE_AUTHOR => '写作者',
    ][$u['role'] ?? ''] ?? ($u['role'] ?? '');
    // v4.1.11：超管登录同样发邮件通知（此前仅站长/写作者）
    if (!in_array($u['role'] ?? '', [ROLE_SUPER_ADMIN, ROLE_STATION_ADMIN, ROLE_AUTHOR], true)) return;
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

/**
 * v4.0.0：新评论/回复邮件订阅通知（向站点管理员发信）
 * 受 config comment_notify_enabled 控制；收件人优先 comment_notify_email，回退 admin_email。
 * $isReply=true 表示这是对已有评论的回复。
 */
function notifyComment($article, $nickname, $content, $isReply = false, $parentNick = '') {
    $config = loadSiteConfig();
    if (empty($config['comment_notify_enabled'])) return;
    $to = trim($config['comment_notify_email'] ?? '');
    if ($to === '') $to = trim($config['admin_email'] ?? '');
    if ($to === '' || !email_valid($to)) return;
    $site = $config['site_title'] ?? 'You Super Markdown';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $kind = $isReply ? '新回复' : '新评论';
    $subject = "[{$site} 通知] {$kind}：{$nickname}";
    $articleTitle = $article;
    if (preg_match('/<!--META(.*?)-->/s', @file_get_contents(__DIR__ . '/data/articles/' . basename($article)), $am)) {
        $ameta = json_decode(trim($am[1]), true);
        if (!empty($ameta['title'])) $articleTitle = $ameta['title'];
    }
    $now = date('Y-m-d H:i:s');
    $body = "文章：《{$articleTitle}》\n"
          . ($isReply && $parentNick !== '' ? "回复对象：{$parentNick}\n" : '')
          . "评论者：{$nickname}\n"
          . "内容：{$content}\n"
          . "时间：{$now}";
    $htmlDetail = '<div style="font-size:14.5px;line-height:2.0;color:#2d3748;">'
        . '<p style="margin:0 0 10px;">文章：《<b>' . htmlspecialchars($articleTitle, ENT_QUOTES, 'UTF-8') . '</b>》</p>'
        . ($isReply && $parentNick !== '' ? '<p style="margin:0 0 10px;color:#5b6b80;">回复对象：' . htmlspecialchars($parentNick, ENT_QUOTES, 'UTF-8') . '</p>' : '')
        . '<p style="margin:0 0 10px;color:#5b6b80;">评论者：' . htmlspecialchars($nickname, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<div style="background:#f7f9fc;border:1px solid #eaeef4;border-radius:12px;padding:14px 18px;color:#334155;margin:12px 0;">'
        . nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')) . '</div></div>';
    $html = renderMailHtml($site, $kind, $body, ['server' => $host, 'time' => $now], $htmlDetail);
    $smtp = getSmtpConfig();
    if ($smtp['host'] !== '' && $smtp['user'] !== '' && $smtp['pass'] !== '') {
        [$ok, $err] = sendSmtpMail($to, $subject, $body, $html);
        if ($ok) return;
        logAlertFail("SMTP 发送失败({$subject}): {$err}");
        return;
    }
    if (!file_exists(EMAIL_ALERT)) { logAlertFail("ym-alert 不存在({$subject})"); return; }
    $cmd = escapeshellcmd(EMAIL_ALERT) . ' ' . escapeshellarg($to) . ' ' . escapeshellarg($subject) . ' ' . escapeshellarg($body);
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
// ============ 公告（v3.1.6）============
// 公告数据存 announcement 表；站长后台「公告管理」tab 全权管理（添加/排序/删除，含更新公告）。
// 首页公告区 = 公告列表（ord 升序、date 降序），支持按公告标签即时筛选。

/**
 * 读取公告列表（默认按 ord 升序，其次 date 降序）
 * @param int $limit 0 为全部
 * @return array 公告数组
 */
function getAnnouncements($limit = 0) {
    $sql = 'SELECT * FROM announcement ORDER BY ord ASC, date DESC, rowid ASC';
    if ($limit > 0) $sql .= " LIMIT " . (int)$limit;
    $rows = db_all($sql);
    foreach ($rows as &$r) {
        $r['title'] = htmlspecialchars($r['title'] ?? '', ENT_QUOTES, 'UTF-8');
        $r['summary'] = htmlspecialchars($r['summary'] ?? '', ENT_QUOTES, 'UTF-8');
        $r['date'] = htmlspecialchars($r['date'] ?? '', ENT_QUOTES, 'UTF-8');
        $r['article'] = htmlspecialchars($r['article'] ?? '', ENT_QUOTES, 'UTF-8');
        // v3.2.3：body 为 markdown 原文，前端 marked 渲染，不做 HTML 转义（由前端 escapeHTML/DOMPurify 兜底）
    }
    return $rows;
}

/**
 * 读取单条公告
 */
function getAnnouncement($id) {
    return db_one('SELECT * FROM announcement WHERE id = ?', [$id]);
}

/**
 * 新增公告
 * @param string $type  manual / update
 * @param string $body  markdown 正文（可选，v3.2.3 起支持 .md 导入的富文本公告）
 */
function addAnnouncement($type, $article, $authorId, $title, $summary, $body = '') {
    $id = bin2hex(random_bytes(8));
    db_exec('INSERT INTO announcement (id, type, article, author_id, title, summary, body, date, ord) VALUES (?,?,?,?,?,?,?,?,?)', [
        $id, $type, $article, $authorId,
        mb_substr($title, 0, 120), mb_substr($summary, 0, 2000),
        mb_substr($body, 0, 60000),
        date('Y-m-d'), 0,
    ]);
    return $id;
}

/**
 * 更新公告（站长编辑标题/摘要/关联文章/正文）
 */
function updateAnnouncement($id, $article, $title, $summary, $body = '') {
    db_exec('UPDATE announcement SET article = ?, title = ?, summary = ?, body = ? WHERE id = ?', [
        $article, mb_substr($title, 0, 120), mb_substr($summary, 0, 2000),
        mb_substr($body, 0, 60000), $id,
    ]);
}

/**
 * 删除公告
 */
function deleteAnnouncement($id) {
    db_exec('DELETE FROM announcement WHERE id = ?', [$id]);
}

/**
 * 公告排序：接收 id 有序数组，按数组顺序重写 ord
 */
function reorderAnnouncements($ids) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE announcement SET ord = ? WHERE id = ?');
        foreach (array_values($ids) as $i => $id) {
            $st->execute([$i, $id]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * 更新公告是否已存在（apply-update 注入用：避免同版本重复生成）
 */
function updateAnnouncementExists($version) {
    return (bool)db_one('SELECT 1 FROM announcement WHERE type = ? AND title LIKE ? LIMIT 1', ['update', '%' . $version . '%']);
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
function paginateList(array $rows, array $fields, string $q = '', int $page = 1, int $perPage = 10) {
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

// v3.3.9：日志/列表分页控件渲染（原定义于超管后台，抽为公用函数供超管/站长后台复用；GET 无副作用，无需 CSRF）
// $p: paginateList() 结果；$pageParam: 页码参数名；$extra: 需保持的额外查询参数（如 tab/q，不含分页参数）
// $perPageParam: 每页条数参数名；$baseUrl: 页面 URL（默认取当前脚本路径，超管/站长后台自动适配）
function renderPager(array $p, string $pageParam, array $extra = [], string $perPageParam = 'per_page', string $baseUrl = ''): string {
    if ($baseUrl === '') {
        $baseUrl = (($_SERVER['SCRIPT_NAME'] ?? '') !== '') ? basename($_SERVER['SCRIPT_NAME']) : 'dashboard.php';
    }
    $page = (int)$p['page'];
    $pages = (int)$p['pages'];
    $perPage = (int)($p['per_page'] ?? 10);
    $enc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    // 页码按钮 URL 模板：固定 per_page，page 用 __PAGE__ 占位
    $urlTpl = $baseUrl . '?' . http_build_query(array_merge($extra, [$perPageParam => $perPage, $pageParam => '__PAGE__']));
    $link = fn($pg) => $enc(str_replace('__PAGE__', (string)$pg, $urlTpl));
    // 每页条数切换 URL 模板：per_page 用 __PP__ 占位，页码回第 1 页
    $ppTpl = $baseUrl . '?' . http_build_query(array_merge($extra, [$perPageParam => '__PP__', $pageParam => 1]));
    // 页码跳转 URL 模板：page 用 __PG__ 占位
    $pgTpl = $baseUrl . '?' . http_build_query(array_merge($extra, [$perPageParam => $perPage, $pageParam => '__PG__']));
    // 单页时仅保留「总数 + 每页条数选择器」，隐藏页码按钮与跳转区（避免分页栏整体消失）
    $showNav = $pages > 1;
    // 页码序列：页数少全显示，多则首末+当前±1+省略号
    $seq = [];
    if ($pages <= 7) {
        $seq = range(1, $pages);
    } else {
        $seq = [1];
        for ($i = max(2, $page - 1); $i <= min($pages - 1, $page + 1); $i++) $seq[] = $i;
        $seq[] = $pages;
        $seq = array_values(array_unique($seq));
    }
    $html = '<nav class="pagination">';
    // 左：总数 + 每页条数（始终显示）
    $html .= '<div class="pagination-info"><span class="page-info">共 <strong>' . (int)$p['total'] . '</strong> 条</span>'
           . '<label class="per-page">每页 <select data-pp-url="' . $enc($ppTpl) . '" onchange="if(this.dataset.ppUrl)location.href=this.dataset.ppUrl.replace(\'__PP__\',this.value)">';
    foreach ([10, 20, 50, 100] as $opt) {
        $html .= '<option value="' . $opt . '"' . ($opt === $perPage ? ' selected' : '') . '>' . $opt . '</option>';
    }
    $html .= '</select> 条</label></div>';
    // 中：页码按钮（仅多页时显示）
    if ($showNav) {
        $html .= '<div class="pagination-btns">';
        $html .= '<a class="page-btn" href="' . $link(1) . '" title="首页">« 首页</a>';
        $html .= '<a class="page-btn" href="' . $link(max(1, $page - 1)) . '" title="上一页">‹ 上一页</a>';
        $prev = 0;
        foreach ($seq as $pg) {
            if ($pg - $prev > 1) $html .= '<span class="page-btn page-ellipsis">…</span>';
            $html .= ($pg === $page)
                ? '<span class="page-btn current">' . $pg . '</span>'
                : '<a class="page-btn" href="' . $link($pg) . '">' . $pg . '</a>';
            $prev = $pg;
        }
        $html .= '<a class="page-btn" href="' . $link(min($pages, $page + 1)) . '" title="下一页">下一页 ›</a>';
        $html .= '<a class="page-btn" href="' . $link($pages) . '" title="末页">末页 »</a>';
        $html .= '</div>';
    }
    // 右：页码信息 + 跳转（仅多页时显示）
    if ($showNav) {
        $html .= '<div class="pagination-jump"><span class="page-info">第 ' . $page . ' / ' . $pages . ' 页</span>'
               . '<input type="number" class="page-jump" min="1" max="' . $pages . '" placeholder="页码" data-pg-url="' . $enc($pgTpl) . '" '
               . 'onchange="if(this.value&&this.dataset.pgUrl)location.href=this.dataset.pgUrl.replace(\'__PG__\',this.value)" '
               . 'onkeydown="if(event.key===\'Enter\')this.onchange()">'
               . '<button type="button" class="page-btn" onclick="var i=this.previousElementSibling;if(i&&i.value&&i.dataset.pgUrl)location.href=i.dataset.pgUrl.replace(\'__PG__\',i.value)">跳转</button></div>';
    }
    return $html . '</nav>';
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
    $cfg = ['interval_min' => 30, 'article_keep' => 7, 'manual_keep' => 5, 'trigger_backup' => true, 'single_restore' => true];
    if (is_file(BACKUP_CONF)) {
        foreach (file(BACKUP_CONF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k); $v = trim($v);
            if ($k === 'DB_BACKUP_INTERVAL_MIN') $cfg['interval_min'] = (int)$v;
            elseif ($k === 'ARTICLE_BACKUP_KEEP') $cfg['article_keep'] = (int)$v;
            elseif ($k === 'MANUAL_BACKUP_KEEP') $cfg['manual_keep'] = (int)$v;
            elseif ($k === 'ARTICLE_TRIGGER_BACKUP') $cfg['trigger_backup'] = in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
            elseif ($k === 'ARTICLE_SINGLE_RESTORE') $cfg['single_restore'] = in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
        }
    }
    // 白名单约束（与守护进程一致）
    $cfg['interval_min'] = ($cfg['interval_min'] >= 5 && $cfg['interval_min'] <= 1440) ? $cfg['interval_min'] : 30;
    $cfg['article_keep'] = ($cfg['article_keep'] >= 1 && $cfg['article_keep'] <= 90) ? $cfg['article_keep'] : 7;
    $cfg['manual_keep'] = ($cfg['manual_keep'] >= 1 && $cfg['manual_keep'] <= 30) ? $cfg['manual_keep'] : 5;
    return $cfg;
}

function saveBackupConfig($intervalMin, $articleKeep, $manualKeep, $triggerBackup = true, $singleRestore = true) {
    $intervalMin = (int)$intervalMin;
    $articleKeep = (int)$articleKeep;
    $manualKeep = (int)$manualKeep;
    $intervalMin = ($intervalMin >= 5 && $intervalMin <= 1440) ? $intervalMin : 30;
    $articleKeep = ($articleKeep >= 1 && $articleKeep <= 90) ? $articleKeep : 7;
    $manualKeep = ($manualKeep >= 1 && $manualKeep <= 30) ? $manualKeep : 5;
    // v3.3.5：上传触发备份 / 单篇篡改还原 开关（守护进程读取）
    $triggerBackup = !empty($triggerBackup) ? 1 : 0;
    $singleRestore = !empty($singleRestore) ? 1 : 0;
    $content = "# 自动备份配置（守护进程 ym-guard.py 读取；超管后台/SSH 可改）\n"
        . "DB_BACKUP_INTERVAL_MIN={$intervalMin}\n"
        . "ARTICLE_BACKUP_KEEP={$articleKeep}\n"
        . "MANUAL_BACKUP_KEEP={$manualKeep}\n"
        . "ARTICLE_TRIGGER_BACKUP={$triggerBackup}\n"
        . "ARTICLE_SINGLE_RESTORE={$singleRestore}\n";
    return file_put_contents(BACKUP_CONF, $content, LOCK_EX) !== false;
}

// v3.3.5：上传文章成功后写触发标记 → 守护进程 10 秒内立即备份文章（备份目录 root 锁定，Web 只能写标记）
function triggerArticleBackup() {
    $trigger = __DIR__ . '/data/.backup_trigger';
    @file_put_contents($trigger, (string)time(), LOCK_EX);
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
            'packages' => [],
        ];
    }
    // beta 通道查询 releases 列表（含 pre-release），stable 查询 latest
    $url = $channel === 'beta'
        ? rtrim($apiBase, '/') . "/releases?per_page=10"
        : rtrim($apiBase, '/') . "/releases/latest";
    // SSRF 安全抓取：一次解析 + pin IP 直连（消除 DNS rebinding TOCTOU；内网/未识别默认拒绝）
    $result = fetchHttpContent($url);
    if ($result) {
        $release = json_decode($result, true);
        if ($channel === 'beta' && is_array($release)) {
            $release = $release[0] ?? null;
        }
        if ($release && isset($release['tag_name'])) {
            $latest = ltrim($release['tag_name'], 'v');
            // v3.2.0：解析 Releases assets，自动识别全量包（*-full.tar.gz）与增量包（*-inc.tar.gz）
            $packages = [];
            if (!empty($release['assets']) && is_array($release['assets'])) {
                foreach ($release['assets'] as $asset) {
                    $aname = (string)($asset['name'] ?? '');
                    if (!preg_match('/\.(tar\.gz|zip)$/i', $aname)) continue;
                    $atype = '';
                    if (preg_match('/-full\.(tar\.gz|zip)$/i', $aname)) $atype = 'full';
                    elseif (preg_match('/-inc\.(tar\.gz|zip)$/i', $aname)) $atype = 'inc';
                    elseif (preg_match('/-to-v[\d.]+-inc\./i', $aname)) $atype = 'inc';
                    if ($atype === '') continue;
                    $packages[] = [
                        'type' => $atype,
                        'name' => $aname,
                        'url' => $asset['browser_download_url'] ?? '',
                        'size' => (int)($asset['size'] ?? 0),
                        'download_count' => (int)($asset['download_count'] ?? 0),
                    ];
                }
            }
            return [
                'available' => version_compare($latest, APP_VERSION) > 0,
                'latest_version' => $latest,
                'current_version' => APP_VERSION,
                'release_notes' => $release['body'] ?? '',
                'download_url' => $release['zipball_url'] ?? '',
                'published_at' => $release['published_at'] ?? '',
                'source' => 'github',
                'packages' => $packages,
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
        'packages' => [],
    ];
}

