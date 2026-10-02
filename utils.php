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
// v4.2.0：卡片封面 / API 背景固定图片源（16:9 横屏随机图，服务器不落地存储、不存缩略图）
define('FIXED_IMG_API', 'https://uapis.cn/api/v1/random/image?category=acg');
// v4.6.0：统一会话启动——PHPSESSID 加 HttpOnly + SameSite=Strict + Secure（修复「无 HttpOnly + 无 SameSite」薄弱点）。
// 所有入口文件在 session_start() 前调用本函数（参数必须在 session 启动前设置才生效）。
function secureSessionStart() {
    // v5.0.0 P2：已在会话中则直接返回（防入口重复调用触发 session_start() 二次启动警告）
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => $secure,
            'httponly' => true, 'samesite' => 'Strict',
        ]);
    } else {
        session_set_cookie_params(0, '/', '', $secure, true);
    }
    session_start();
}
// v4.6.0：后台会话 24 小时显式过期（站长/写作者，按登录时间算）。
// v5.3.1：后台会话与前台登录态（长效 30 天）**分离**——后台会话使用独立标记 cmt_backend_ts
//         （仅由一次完整认证建立），过期时**只失效该标记**，绝不动前台登录态（cmt_user / refresh token）；
//         前台登录态一律不得建立 / 续期后台会话（必须重新验证）。
const BACKEND_TTL = 86400; // 24 小时
/** 建立后台会话（登录 / OTP 完成后调用）：写独立时间戳，与前台 cmt_login_ts 解耦 */
function establishBackendSession() {
    $_SESSION['cmt_backend_ts'] = time();
}
/** 后台会话是否在有效期内（无标记一律视为无效——必须重新验证才能进后台） */
function backendSessionValid(): bool {
    $ts = (int)($_SESSION['cmt_backend_ts'] ?? 0);
    return $ts > 0 && (time() - $ts) <= BACKEND_TTL;
}
function backendSessionExpired(): bool {
    if (empty($_SESSION['cmt_user'])) return false;
    $role = $_SESSION['cmt_user']['role'] ?? '';
    if (!in_array($role, [ROLE_STATION_ADMIN, ROLE_AUTHOR], true)) return false;
    return !backendSessionValid();
}
/** v5.3.1：仅失效后台会话标记（回退首页用）——不触碰前台登录态 */
function clearBackendSession() {
    unset($_SESSION['cmt_backend_ts'], $_SESSION['cmt_fp_ok']);
}
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
// SSRF 安全抓取：单跳实现——一次解析 + 固定解析后的 IP 直连（Host/SNI 保留原域名），消除 DNS rebinding TOCTOU
// 返回 ['code'=>int,'location'=>string,'body'=>string]；非 http/https、内网/未识别主机、请求失败 → false
function fetchHttpOnce($url, $ua) {
    $parts = parse_url($url);
    if (!is_array($parts)) return false;
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
    $header = "Host: " . $host . "\r\n"
        . "User-Agent: " . $ua . "\r\n"
        . "Connection: close\r\n";
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => $header,
            'timeout' => 10,
            'ignore_errors' => true, // 取回 3xx/4xx 响应头以便自行处理重定向
            'follow_location' => 0,  // 由 fetchHttpContent 逐跳校验后跟随，禁用底层自动跟随
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
    $body = @file_get_contents($targetUrl, false, stream_context_create($opts));
    if ($body === false) return false;
    $code = 0;
    $location = '';
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int)$m[1]; }
        elseif (stripos($h, 'Location:') === 0) { $location = trim(substr($h, 9)); }
    }
    return ['code' => $code, 'location' => $location, 'body' => $body];
}
// 相对 Location → 绝对 URL（爬取跳转用）
function resolveRedirectUrl($base, $loc) {
    $loc = trim((string)$loc);
    if ($loc === '') return '';
    if (preg_match('#^https?://#i', $loc)) return $loc;
    $bp = parse_url($base);
    if (!is_array($bp) || empty($bp['scheme']) || empty($bp['host'])) return '';
    $authority = $bp['host'] . (isset($bp['port']) ? ':' . $bp['port'] : '');
    if (strpos($loc, '//') === 0) return $bp['scheme'] . ':' . $loc;
    if ($loc[0] === '/') return $bp['scheme'] . '://' . $authority . $loc;
    $dir = isset($bp['path']) ? preg_replace('#/[^/]*$#', '/', $bp['path']) : '/';
    return $bp['scheme'] . '://' . $authority . $dir . $loc;
}
// SSRF 安全抓取（对外）：最多跟随 3 跳，每一跳目标都必须为公网且非内网（复用 isPrivateHost/resolvePublicIp）；
// 非 http/https、跳数超限、目标内网 → 失败。返回响应体字符串或 false（对既有调用方保持兼容）。
function fetchHttpContent($url, $ua = null) {
    // v4.1.15：支持自定义 UA（封面图片池用桌面 UA 拉取横屏壁纸）
    $ua = $ua !== null ? $ua : (appConfig('app_name', 'You Super Markdown') . "/" . APP_VERSION);
    $maxHops = 3;
    for ($hop = 0; $hop <= $maxHops; $hop++) {
        $res = fetchHttpOnce($url, $ua);
        if ($res === false) return false;
        $code = (int)($res['code'] ?? 0);
        if (in_array($code, [301, 302, 303, 307, 308], true)) {
            if ($hop === $maxHops) return false; // 超过最大跳数
            $next = resolveRedirectUrl($url, $res['location'] ?? '');
            if ($next === '') return false;
            $url = $next; // 下一跳仍会经 fetchHttpOnce 做公网/内网校验（含 scheme 白名单）
            continue;
        }
        if ($code >= 200 && $code < 300) return $res['body'];
        return false; // 其他状态码（含无 Location 的 3xx、4xx/5xx）→ 失败
    }
    return false;
}
function fetchAllUsers() {
    return db_all('SELECT * FROM users ORDER BY rowid');
}
function replaceAllUsers($users) {
    // 全量替换（保持原函数语义：写入完整用户列表）
    // v2.5.4：INSERT OR REPLACE 防止列表内重复 id 触发唯一约束冲突导致事务回滚
    // v2.9.0：列清单加入 email（注册验证引入，漏列会导致全量替换时邮箱丢失）
    // v4.5.0：列清单加入 tv（token_version 并发踢旧，漏列会导致全量替换时所有会话被误踢）
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM users');
        $st = $pdo->prepare('INSERT OR REPLACE INTO users (id, account, nickname, password, avatar, signature, role, station_id, created, created_by, email, disabled, last_login, login_count, tv) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($users as $u) {
            $st->execute([
                $u['id'] ?? '', $u['account'] ?? '', $u['nickname'] ?? '', $u['password'] ?? '',
                $u['avatar'] ?? '', $u['signature'] ?? '', $u['role'] ?? 'user',
                $u['station_id'] ?? '', $u['created'] ?? '', $u['created_by'] ?? '',
                $u['email'] ?? '', (int)($u['disabled'] ?? 0),
                $u['last_login'] ?? '', (int)($u['login_count'] ?? 0),
                (int)($u['tv'] ?? 0),
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
        // v5.3.0：更新通道（stable=仅正式 Release / beta=含预发布）；超管后台「在线更新」或 CLI 可切换
        'update_channel' => 'stable',
        // v5.3.0：发现新版本已邮件通知到的最新版本号（去重：同一新版本只通知一次）
        'update_notified_version' => '',
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
        // v4.1.17：毛玻璃默认更透（50%），后台「卡片透明度」滑杆仍可 20-100% 自由调节
        'bg_card_opacity' => 50,
        'bg_blur_enabled' => false,
        'bg_blur_level' => 0,
        // v4.2.0：API 背景固定默认源（后台可改，留空保存时自动回退该固定源）
        'bg_api_url' => FIXED_IMG_API,
        // v4.2.4：首页卡片封面图片源（后台可自定义；留空回退 FIXED_IMG_API，走同一池化缓存策略）
        'card_cover_api' => '',
        // v4.4.0：音乐默认自动播放歌曲名关键词（站长/超管后台可配；留空则打开歌单不自动播放）
        'music_auto_play' => '',
        // v4.4.3：本地背景音乐开关（站长/超管后台上传单曲，前台播放器弹窗内仅可开/关，单曲循环）
        'bg_music_enabled' => false,
        // v4.4.0：注册蜜罐自动封禁——短时间（10 分钟）内连续命中蜜罐达阈值即自动封禁 IP
        'honeypot_ban_count' => 3,          // 触发次数（超管可调）
        'honeypot_ban_duration' => 3600,    // 封禁秒数（超管可调，默认 1 小时；0=永久）
        // v4.7.0：联动威胁评分（IP+浏览器双维聚合，超阈值自动联动封锁；权重见 threatWeight，可用 threat_w_<reason> 覆盖）
        'threat_enable' => true,            // 总开关
        'threat_window' => 86400,           // 评分滑动窗口（秒，默认 24h，过期自动衰减）
        'threat_l1' => 40,                  // 一级阈值 → 联动封锁 15 分钟
        'threat_l1_dur' => 900,
        'threat_l1_5' => 80,                // v4.8.0：一级半阈值 → 联动封锁 6 小时
        'threat_l1_5_dur' => 21600,
        'threat_l2' => 150,                 // 二级阈值 → 联动封锁 24 小时
        'threat_l2_dur' => 86400,
        'threat_l3' => 250,                 // 三级阈值 → 自动永久封禁（超管面板可解除）
        // v4.8.1：威胁评分衰减——无新事件超过 N 天后评分自动减半，防止长期误封
        'threat_decay_days' => 3,           // 无新事件多少天后开始衰减
        'threat_decay_ratio' => 0.5,        // 每个衰减周期评分乘以此系数（如 0.5=每周期减半）
        // v5.4.0-beta：AI 写作（超管只管"站"的层面）——
        //   ai_enabled：AI 功能总开关；ai_role_station_admin / ai_role_author：可分别开关"站长/写作者可用"；
        //   ai_providers：服务商预设白名单（启用的 provider id 列表；base_url 固定，使用者不可自定义）。
        'ai_enabled' => false,
        'ai_role_station_admin' => false,
        'ai_role_author' => false,
        'ai_providers' => ['deepseek', 'mimo-payg', 'mimo-plan', 'qwen'],
        // v5.4.4：服务器自身 IP 白名单（数组）——命中者不参与威胁计分/不封禁/不加锁（自封防护）。
        // 安装与更新流程在能确定服务器出口/域名解析 IP 时自动登记（--auto）；亦可用
        // `sudo ysm-admin set-self-ip --add=<ip>` 维护。
        'threat_self_ips' => [],
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

/** v5.0.0：解析文章 front-matter（文首 <!--META{...}--> 的 JSON）——原 sc.php 局部函数上移为共享实现，
 *          供后台文档管理与首页服务端分享卡片（og/twitter）复用，避免再造一套解析。 */
function readArticleMeta($filePath) {
    $raw = @file_get_contents($filePath);
    if ($raw && preg_match('/<!--META(.*?)-->/s', $raw, $m)) {
        $meta = json_decode(trim($m[1]), true);
        if (is_array($meta)) return $meta;
    }
    return [];
}

// ===== v4.4.2：QQ 音乐通道已移除（cookie 检测过严、服务器端申请违反用户协议），
// qqCookieCheck() 随之删除 =====
// v4.5.0：背景音乐上传转码见下方 v4.5.0 分区（<100MB 多格式 → ffmpeg 转 96kbps mp3）


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
                 'index', '404', 'oauth', 'login', 'logout', 'register'];
    if (in_array(strtolower($path), $reserved, true)) {
        return '该路径为系统保留关键字，请换一个';
    }
    return true;
}
function loadBansList() {
    $rows = db_all('SELECT ip, types_json, reason, time, expires FROM bans ORDER BY time DESC');
    $bans = [];
    foreach ($rows as $r) {
        $bans[] = [
            'ip' => $r['ip'],
            'types' => json_decode($r['types_json'] ?? '[]', true) ?: [],
            'reason' => $r['reason'] ?? '',
            'time' => $r['time'] ?? '',
            'expires' => (int)($r['expires'] ?? 0),
        ];
    }
    return $bans;
}
function replaceAllBans($bans) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 全量替换（解封 = 从列表移除，必须保留删除语义）；v2.5.4 用 OR REPLACE 防重复 ip 冲突
        $pdo->exec('DELETE FROM bans');
        $st = $pdo->prepare('INSERT OR REPLACE INTO bans (ip, types_json, reason, time, expires) VALUES (?,?,?,?,?)');
        foreach ($bans as $b) {
            $st->execute([
                $b['ip'] ?? '', json_encode($b['types'] ?? [], JSON_UNESCAPED_UNICODE),
                $b['reason'] ?? '', $b['time'] ?? '', (int)($b['expires'] ?? 0),
            ]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
function banIp($ip, $types, $reason = '', $duration = 0) {
    // $duration：0=永久封禁；>0=封禁秒数（v4.4.0 注册蜜罐自动封禁使用）
    // v5.4.4：自封保护——目标属回环/内网/服务器自身 IP（threat_self_ips）时不写封禁，改为只告警
    //（alert.log + 审计 + 邮件），绝不阻断/封禁服务器自身，杜绝「服务器自己封自己」
    if (isSelfProtectedHost($ip)) {
        selfProtectAlert('banIp', $ip, 'types=' . implode(',', (array)$types) . ($reason !== '' ? '；' . $reason : ''));
        return;
    }
    $expires = $duration > 0 ? time() + $duration : 0;
    $existing = db_one('SELECT * FROM bans WHERE ip = ?', [$ip]);
    if ($existing) {
        $merged = json_decode($existing['types_json'] ?? '[]', true) ?: [];
        foreach ($types as $t) { if (!in_array($t, $merged)) $merged[] = $t; }
        db_exec('UPDATE bans SET types_json = ?, reason = ?, expires = ? WHERE ip = ?',
            [json_encode($merged, JSON_UNESCAPED_UNICODE), $reason, $expires, $ip]);
    } else {
        db_exec('INSERT INTO bans (ip, types_json, reason, time, expires) VALUES (?,?,?,?,?)',
            [$ip, json_encode($types, JSON_UNESCAPED_UNICODE), $reason, date('Y-m-d H:i:s'), $expires]);
    }
}
function isIPBanned($ip, $type) {
    $row = db_one('SELECT types_json, expires FROM bans WHERE ip = ?', [$ip]);
    if (!$row) return false;
    // v4.4.0：临时封禁到期自动解除（expires>0 且已过期 → 清除记录视为未封禁）
    if (!empty($row['expires']) && (int)$row['expires'] > 0 && (int)$row['expires'] < time()) {
        db_exec('DELETE FROM bans WHERE ip = ?', [$ip]);
        return false;
    }
    $types = json_decode($row['types_json'] ?? '[]', true) ?: [];
    return in_array($type, $types, true);
}
function getClientIP() {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    // v5.4.5：标记「本次客户端 IP 是否取自代理头」——供 isSelfProtectedHost() 使用：
    //   经被采信 XFF / X-Real-IP 得到且非内网的 IP，不享受 isSelfIp（服务器自身 IP）豁免，
    //   防攻击者自带 `X-Forwarded-For: <服务器自身IP>` 命中 isSelfIp 免疫。
    //   ⚠ 反代/CDN 部署必须配合「真值透传」（见 说明文档/PACKAGING_AND_DEPLOY.md §11.9）。
    $GLOBALS['YSM_IP_FROM_PROXY'] = false;
    // v5.0.0：仅当直连方为本机/私有网段（受控代理）时，才考虑采信代理头；
    // 且代理头值本身必须是合法公网 IP 才采用——否则一律回落 REMOTE_ADDR，
    // 防伪造 X-Real-IP / X-Forwarded-For 绕过限流与封禁。
    // v5.0.0 第6轮 M3（部署约束，暂不引入 trusted_proxies 配置以免扩大改动面）：
    //   本策略依赖「反向代理位于本机或私网」。若反代部署在公网 IP（REMOTE_ADDR 为公网），
    //   则不会采信 XFF，getClientIP() 将一直返回反代自身 IP，导致限流/封禁按反代聚合。
    //   此类部署需在反代侧透传真实 IP，或后续引入「受信代理 IP/网段」白名单后才可安全采信 XFF。
    if ($remote !== '' && isPrivateIp($remote)) {
        foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $hdr) {
            $val = trim((string)($_SERVER[$hdr] ?? ''));
            if ($val === '') continue;
            $candidate = trim(explode(',', $val)[0]); // XFF 可能是列表，取首个（原始客户端）
            if (filter_var($candidate, FILTER_VALIDATE_IP) && !isPrivateIp($candidate)) {
                $GLOBALS['YSM_IP_FROM_PROXY'] = true; // v5.4.5：本次客户端 IP 取自代理头
                return $candidate;
            }
        }
    }
    return $remote !== '' ? $remote : '0.0.0.0';
}
// ============================================================
// v5.4.4：服务器自访问与内部请求识别（自封防护基础）
//   · threat_self_ips：站点配置里的「服务器自身 IP」白名单（数组）；安装/更新流程在能确定
//     出口/域名解析 IP 时自动登记，亦可用 `sudo ysm-admin set-self-ip [--add=<ip>|--auto]` 维护；
//   · 内部 UA 前缀 YSM-（如 YSM-HealthCheck/1.0、YSM-Update/1.0、YSM-Probe/1.0）：站内自检请求标识；
//   · 命中上述任一（或回环/内网）即视为「内部来源」——不参与威胁计分、不封禁、不加锁。
// 背景：服务器用默认 UA 的 curl 访问自身域名会被判扫描器（scanner_ua 40 + unauthorized 25），
//       累计达永久阈值后把自己公网 IP 写进 bans（expires=0）——本次起从根上避免「自封」。
// v5.4.5 加固：内部请求判定改为「双因子」——内部 UA（YSM- 前缀）**且** 来源可信（回环/内网/自身 IP）。
//       修复 v5.4.4 引入的攻击窗口：外部攻击者仅凭伪造 `-A 'YSM-xxx'` 即被当作「内部请求」，
//       从而① 绕过扫描器判定（runRequestSecurityCheck 直接 return）② 越权不计分 → 永不被联动封禁。
// ============================================================
/** 读取服务器自身 IP 白名单（threat_self_ips），归一化为小写集合（单次请求内缓存） */
function selfIpList() {
    static $list = null;
    if ($list !== null) return $list;
    $cfg = loadSiteConfig();
    $raw = $cfg['threat_self_ips'] ?? [];
    $list = [];
    if (is_array($raw)) {
        foreach ($raw as $x) {
            $x = strtolower(trim(trim((string)$x), '[]'));
            if ($x !== '') $list[$x] = true;
        }
    }
    return $list;
}
/** 是否为服务器自身 IP（命中 threat_self_ips） */
function isSelfIp($ip) {
    $ip = strtolower(trim(trim((string)$ip), '[]'));
    if ($ip === '') return false;
    return isset(selfIpList()[$ip]);
}
/** v5.4.5：客户端 IP 是否取自被采信的代理头（X-Real-IP / X-Forwarded-For） */
function clientIpFromProxy() {
    return !empty($GLOBALS['YSM_IP_FROM_PROXY']);
}
/**
 * v5.4.5：真内网/回环判定（受信来源）——仅回环、RFC1918 私网、CGNAT、链路本地、IPv6 ULA/链路本地。
 * 刻意排除 TEST-NET（192.0.2.0/24、198.51.100.0/24、203.0.113.0/24）、文档/基准/组播/保留段：
 * 它们并非「本机内网」，不可作为内部来源豁免。
 * 与 isPrivateIp() 的区别：后者面向 SSRF 一律 fail-closed（刻意放宽，含 TEST-NET 等）；
 * 本函数面向「信任」，必须收窄——避免把文档网段误当可豁免的内部地址。
 */
function isTrustedInternalIp($ip) {
    $ip = strtolower(trim(trim((string)$ip), '[]'));
    if ($ip === '') return false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        if ($long === false) return false;
        $ranges = [
            [ip2long('10.0.0.0'),    ip2long('10.255.255.255')],   // RFC1918
            [ip2long('100.64.0.0'),  ip2long('100.127.255.255')],  // CGNAT
            [ip2long('127.0.0.0'),   ip2long('127.255.255.255')],  // 回环
            [ip2long('169.254.0.0'), ip2long('169.254.255.255')],  // 链路本地
            [ip2long('172.16.0.0'),  ip2long('172.31.255.255')],   // RFC1918
            [ip2long('192.168.0.0'), ip2long('192.168.255.255')],  // RFC1918
        ];
        foreach ($ranges as $r) { if ($long >= $r[0] && $long <= $r[1]) return true; }
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = @inet_pton($ip);
        if ($bin === false) return false;
        if ($ip === '::1') return true;                          // 回环
        $b0 = ord($bin[0]);
        $b1 = ord($bin[1]);
        if (($b0 & 0xfe) === 0xfc) return true;                  // fc00::/7 ULA
        if ($b0 === 0xfe && ($b1 & 0xc0) === 0x80) return true;  // fe80::/10 链路本地
        return false;
    }
    return false;
}
/** 自封保护判定：回环/内网/服务器自身 IP —— 一律不得写封禁/加锁（仅告警） */
function isSelfProtectedHost($ip) {
    $ip = trim(trim((string)$ip), '[]');
    if ($ip === '') return false;              // v5.4.5：空 IP 不可信（v5.4.4 曾错误地视为受保护）
    if (isTrustedInternalIp($ip)) return true; // 回环/内网始终可信（含经 XFF 识别出的内网地址）
    // v5.4.5：服务器自身 IP 豁免仅对「直连来源」生效——客户端 IP 若取自被采信的 XFF/X-Real-IP，
    //         不再享受 isSelfIp 豁免（防自带 `X-Forwarded-For: <服务器自身IP>` 命中免疫）。
    if (!clientIpFromProxy() && isSelfIp($ip)) return true;
    return false;
}
/** 内部请求 UA（前缀 YSM-）——站内自检/更新/探针请求标识 */
function isInternalUA() {
    return strncasecmp((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'YSM-', 4) === 0;
}
/** 内部来源请求（v5.4.5 双因子：内部 UA **且** 来源可信——回环/内网/自身 IP） */
function isInternalRequest() {
    return isInternalUA() && isSelfProtectedHost(getClientIP());
}
/** 自封保护告警：只告警（alert.log + 审计 + 邮件走既有告警通道），不写封禁/不加锁、不阻断调用方 */
function selfProtectAlert($context, $ip, $detail = '') {
    $msg = '目标地址属服务器自身/内网/回环，已按自封保护跳过封禁与加锁（' . $context . '）'
         . ($detail !== '' ? '：' . $detail : '');
    auditLog('self_protect_skip', 'security', $msg, 'blocked');
    @file_put_contents(ALERT_LOG, date('Y-m-d H:i:s') . " [自封保护] {$msg}\n", FILE_APPEND | LOCK_EX);
    sendAlert('自封保护（已跳过封禁）', $msg . "\nIP：" . $ip . "\n上下文：" . $context);
}
// ============================================================
// v3.0.8 统一安全入口（detectScannerUA：扫描器 UA 黑名单检测）
// 命中 sqlmap/nikto/nmap/acunetix/masscan/zgrab/curl 等工具特征：
// 返回 403 + 记录越权日志 + 按现有封禁机制封禁来源 IP
// ============================================================
function detectScannerUA() {
    // v5.4.5：仅「内部请求（双因子：内部 UA 且 来源可信）」放过；单独伪造 YSM- 前缀不再豁免，
    //         外部来源（如 UA=YSM-curl/1.0）照常判为扫描器。
    if (isInternalRequest()) return false;
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
// v5.4.5：curl/wget 属「通用命令行工具」——低权重单列（reason=tool_ua，5 分/次）：
//   仍 403 并记日志；独立计数，窗口内累计达阈值 → 短时封禁 15 分钟；
//   不参与联动评分升级（含永久阈值 L3）——避免运维脚本裸 curl 自检被永久自封。
//   sqlmap/nmap/nikto/acunetix 等真实攻击工具保持原权重与升级链路不变（不削弱风控）。
function scannerUADegraded($tool) {
    return in_array($tool, ['curl', 'wget'], true);
}
/** v5.4.5：curl/wget 降级计数策略（默认 10 次 / 10 分钟 → 短时封禁 15 分钟；可被 config threat_tool_ua_* 覆盖） */
function toolUaBanThreshold() { return (int)threatCfg('threat_tool_ua_count', 10); }
function toolUaBanWindow() { return (int)threatCfg('threat_tool_ua_window', 600); }
function toolUaBanDuration() { return (int)threatCfg('threat_tool_ua_dur', 900); }
/** v5.4.5：纯函数——计数是否达短时封禁阈值（供单元断言/策略验证，无副作用） */
function toolUaBanReached($count, $threshold = null) {
    if ($threshold === null) $threshold = toolUaBanThreshold();
    return (int)$count >= (int)$threshold;
}
/** v5.4.5：curl/wget 降级短时封禁——同一 IP 窗口内累计达阈值 → 15 分钟临时锁（extend-only，不参与永久阈值） */
function toolUaTempBan($ip) {
    if ($ip === '' || isSelfProtectedHost($ip)) return false;
    $win = toolUaBanWindow();
    $cnt = (int)(db_one('SELECT COUNT(*) AS c FROM threat_events WHERE dim_type = ? AND dim_key = ? AND reason = ? AND created > ?',
        ['ip', $ip, 'tool_ua', time() - $win])['c'] ?? 0);
    if (!toolUaBanReached($cnt)) return false;
    linkedLock('link:ip:' . $ip, toolUaBanDuration(),
        '命令行走量工具UA短时封禁（累计' . $cnt . '次/' . max(1, intdiv($win, 60)) . '分钟）');
    logAbnormal($ip, '命令行走量工具UA短时封禁: 累计' . $cnt . '次');
    return true;
}
function runRequestSecurityCheck() {
    // v5.4.5：仅「内部请求（双因子：内部 UA 且 来源可信）」直接放过；
    //         外部攻击者伪造 YSM- 前缀不再获得任何豁免。
    if (isInternalRequest()) return;
    $tool = detectScannerUA();
    if ($tool === false) return;
    $ip = getClientIP();
    // v5.4.5：curl/wget 降权——仍 403 + 记日志；低权重(reason=tool_ua, 5 分)独立计数，
    //         窗口内累计达阈值 → 短时封禁 15 分钟；不写越权计分、不参与联动/永久阈值升级。
    if (scannerUADegraded($tool)) {
        logUnauthorized('扫描器UA检测(降级·低权重): ' . $tool, false, false);
        logAbnormal($ip, '扫描器UA拦截(降级·低权重): ' . $tool);
        logThreat('tool_ua', $ip, '', 0);
        toolUaTempBan($ip);
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Forbidden');
    }
    logUnauthorized('扫描器UA检测: ' . $tool);
    // v4.8.0：移除单一 addBan，全部走联动封禁（logThreat 写入 threat_events 后自动升级）
    logThreat('scanner_ua', $ip, '', 300);
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
function logUnauthorized($action, $ban = false, $score = true) {
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
        // v4.8.0：移除单一 addBan，全部走联动封禁（logThreat 已在下行无条件调用）
        if (!empty($config['auto_ban_unauthorized'])) {
            logAbnormal($ip, '自动封禁越权用户: ' . $action);
        }
    }
    // v4.7.0：越权事件计入联动威胁评分（独立于 auto_ban_unauthorized 开关，自动升级联动封锁）
    // v5.4.4：只对「非内部来源」计分——内部来源（YSM- 内部UA / 服务器自身 IP / 内网回环）只写日志；
    //         $score=false 供 curl/wget 降级类等显式跳过计分
    if ($score && !isInternalRequest()) {
        logThreat('unauthorized', $ip, '', 300);
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
        'jti' => bin2hex(random_bytes(8)),
        // v4.5.0：携带 token_version——同账号新登录使 tv+1，旧 JWT 立即失效（并发踢旧）
        'tv' => getUserTV($userId)
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
        foreach (fetchAllUsers() as $u) {
            if ($u['id'] === $uid) { $found = true; break; }
        }
        if (!$found) return false;
        // v4.5.0：token_version 比对——同账号新登录（tv+1）后旧 JWT 立即失效
        if (!jwtTVMatches($data)) return false;
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
    foreach (fetchAllUsers() as $u) {
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
define('AUDIT_MIRROR_DIR', '/opt/you-super-markdown/logs/');
define('AUDIT_MIRROR_DB', AUDIT_MIRROR_DIR . 'ysm.db');
define('EMAIL_ALERT', '/usr/local/bin/ysm-alert');
// v5.0.0 P7（审计链加固）：审计母密钥——安装期由 ysm-install.sh 用 openssl rand -hex 32 生成一次，
// 存放于 webroot 外 /opt/you-super-markdown/secrets/audit_key（root 0600）。仅 root/守护进程可读；
// www-data（PHP）读不到，故 PHP 无法计算合法 hash（链由 root 守护进程加封）。
define('AUDIT_KEY_FILE', '/opt/you-super-markdown/secrets/audit_key');
// v5.0.0 P0-4：预期审计链 epoch（安装/迁移写入 secrets/audit_epoch，root 0600）。
// 预期 >=2 时，链必须至少进入 sealed(HMAC) 段；整条链停在 legacy(sha256) 视为 invalid
// ——防止「纯 legacy(sha256) 链」被整体伪造（sha256 无密钥，任何写入者都能重算）。
// www-data 读不到 secrets 目录（0700 root）→ readAuditEpoch() 返回 0（未知），保持既有 delegated 语义。
define('AUDIT_EPOCH_FILE', '/opt/you-super-markdown/secrets/audit_epoch');

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
    // v5.0.0 P7（审计链加固）：PHP 不再计算 hash——只写"原始条目"，hash/prev_hash 置空字符串；
    // 由 root 守护进程 ysm-guard.py 读取母密钥后按 rowid 顺序用
    // HMAC-SHA256(audit_key, prev_hash + entry_json) 加封回填。www-data（PHP）拿不到密钥，
    // 故即使有库写权限也伪造不出合法 hash。审计内容/字段/查询接口（loadAuditLogs 等）保持不变。
    $entry['prev_hash'] = '';
    $entry['hash'] = '';

    db_exec('INSERT INTO audit (id,ts,user_id,user_name,role,ip,action,target,detail,result,hash,prev_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
        $entry['id'], $entry['ts'], $entry['user_id'], $entry['user_name'], $entry['role'],
        $entry['ip'], $entry['action'], $entry['target'], $entry['detail'], $entry['result'],
        $entry['hash'], $entry['prev_hash'],
    ]);
    // 上限 10000 条
    $cnt = db_one('SELECT COUNT(*) AS c FROM audit')['c'] ?? 0;
    if ($cnt > 10000) {
        db_exec('DELETE FROM audit WHERE rowid IN (SELECT rowid FROM audit ORDER BY rowid ASC LIMIT ?)', [$cnt - 10000]);
    }
    // 链尾文件（data/.audit_chain 与 root 镜像 audit_chain）改由守护进程加封/背书时写入；
    // PHP 已不持有 hash，此处不再写链尾（否则会以空串覆盖有效链尾）。
    return $entry;
}

// ------------------------------------------------------------------
// v5.0.0 P7：审计条目规范化（链计算的唯一事实来源）。
// 与 ysm-guard.py::audit_entry_json() 必须逐字节一致——两处必须同步（字段顺序、compact 无空格、
// 不转义 Unicode/斜杠）。字段顺序沿用旧 auditLog() 的 $entryJson（去掉 hash；prev_hash 参与计算置于末位）。
// ------------------------------------------------------------------
function auditEntryJson($entry, $prevHash) {
    $data = [
        'id' => $entry['id'], 'ts' => $entry['ts'], 'user_id' => $entry['user_id'],
        'user_name' => $entry['user_name'], 'role' => $entry['role'], 'ip' => $entry['ip'],
        'action' => $entry['action'], 'target' => $entry['target'], 'detail' => $entry['detail'],
        'result' => $entry['result'],
        'prev_hash' => $prevHash,
    ];
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

// v5.0.0 P7：读取审计母密钥（root 0600）。以 root/CLI 运行时才可读 → 可本地完整校验；
// www-data（Web 仪表盘）读不到 → 仅能校验旧段并做结构性校验，其余交由 root（guard / audit-report）校验。
function readAuditKey() {
    if (!is_readable(AUDIT_KEY_FILE)) return '';
    $k = trim((string)@file_get_contents(AUDIT_KEY_FILE));
    return $k;
}

// v5.0.0 P0-4：读取预期审计链 epoch（root 0600，webroot 外）。非 root/www-data 读不到 → 返回 0（未知）。
function readAuditEpoch() {
    if (!is_readable(AUDIT_EPOCH_FILE)) return 0;
    return (int)trim((string)@file_get_contents(AUDIT_EPOCH_FILE));
}

// v5.0.0 P7：epoch 分段校验（行为/返回兼容旧接口，新增 delegated/pending 字段）——
//   epoch 1 = 旧 sha256 链（历史段）：hash = sha256(entry_json)，genesis 为空串；
//   epoch 2 = 新 HMAC 链：hash = HMAC-SHA256(audit_key, prev_hash + entry_json)。
// 链形态为"旧段(sha256) 前缀 + 新段(HMAC) 后缀"：按行推进，旧段用 sha256 校验，
// 进入新段后仅用 HMAC 校验（不可回退）。尾部 hash='' 的行视为"待 guard 加封"，跳过不计入校验。
// 无密钥时（www-data）无法本地校验新段 → 置 delegated=true，仅做 prev_hash 链连续性结构校验。
function verifyAuditChain() {
    $logs = db_all('SELECT * FROM audit ORDER BY rowid ASC');
    if (empty($logs)) return ['valid' => true, 'count' => 0, 'delegated' => false, 'pending' => 0];
    $key = readAuditKey();
    $prevHash = '';
    $phase = 'legacy';    // legacy(旧段 sha256) → sealed(新段 HMAC)
    $delegated = false;   // 无密钥、无法本地校验新段
    $pending = 0;
    $hashed = 0;          // P0-4：已加封（hash 非空）参与校验的条目数（用于区分「空链」与「纯 legacy 链」）
    $n = count($logs);
    for ($i = 0; $i < $n; $i++) {
        $entry = $logs[$i];
        $expectedHash = $entry['hash'] ?? '';
        if ($expectedHash === '') { $pending = $n - $i; break; }   // 尾部待加封
        $hashed++;
        $entryJson = auditEntryJson($entry, $prevHash);
        if ($phase === 'legacy') {
            if (hash_equals(hash('sha256', $entryJson), $expectedHash)) { $prevHash = $expectedHash; continue; }  // 旧段
            // 旧段结束 → 尝试新段（HMAC）
            if ($key !== '') {
                if (!hash_equals(hash_hmac('sha256', $prevHash . $entryJson, $key), $expectedHash)) {
                    return ['valid' => false, 'broken_at' => $i, 'count' => $n, 'delegated' => $delegated, 'pending' => $pending];
                }
            } else {
                // 无密钥：无法本地校验，仅结构校验
                if (($entry['prev_hash'] ?? '') !== $prevHash) {
                    return ['valid' => false, 'broken_at' => $i, 'count' => $n, 'delegated' => $delegated, 'pending' => $pending];
                }
                $delegated = true;
            }
            $phase = 'sealed';
        } else {
            // 新段（HMAC）
            if ($key !== '') {
                if (!hash_equals(hash_hmac('sha256', $prevHash . $entryJson, $key), $expectedHash)) {
                    return ['valid' => false, 'broken_at' => $i, 'count' => $n, 'delegated' => $delegated, 'pending' => $pending];
                }
            } else {
                $delegated = true;
                if (($entry['prev_hash'] ?? '') !== $prevHash) {
                    return ['valid' => false, 'broken_at' => $i, 'count' => $n, 'delegated' => $delegated, 'pending' => $pending];
                }
            }
        }
        $prevHash = $expectedHash;
    }
    // v5.0.0 P0-4：预期 epoch>=2 时，链必须至少进入 sealed(HMAC) 段；
    // 整条链停在 legacy(sha256)（有已加封条目却从未进入 HMAC）→ 判 invalid（防纯 legacy 链整体伪造）。
    // 仅在能读到预期 epoch（root/CLI）时生效；www-data 读不到返回 0 → 跳过（既有 delegated 语义不变）。
    if (readAuditEpoch() >= 2 && $hashed > 0 && $phase !== 'sealed') {
        return ['valid' => false, 'broken_at' => -1, 'count' => $n, 'delegated' => $delegated, 'pending' => $pending];
    }
    return ['valid' => true, 'count' => $n, 'delegated' => $delegated, 'pending' => $pending];
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
    // 不可 copy 镜像 audit_chain——镜像 ysm.db 与 audit_chain 文件由不同时机更新（背书/写日志）可能错位，
    // 残留旧链尾会导致下一条记录断链。
    try {
        // v5.0.0 P7：链尾取"最后一条已加封（hash 非空）"的 hash——未加封行 hash 为空，
        // 直接取表尾会以空串覆盖有效链尾。
        $last = $pdo->query("SELECT hash FROM audit WHERE hash IS NOT NULL AND hash != '' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
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

// ============================================================
// v5.4.0-beta AI 写作（服务端代理调用）
//   - 使用者：站长 / 写作者（超管不参与创作）；超管只管"站"的层面（总开关/角色开关/白名单/清 Key）。
//   - 个人 Key 绑定站内账号（user_id），密文入库，明文永不回传。
//   - base_url 只取自服务端白名单（不接受任何用户传入 URL → 防 SSRF）。
// ============================================================
// AI Key 加密密钥文件：优先 /opt 下 secrets/（安装脚本生成），不可读时回退 webroot data/（0600）
define('AI_ENC_KEY_FILE', '/opt/you-super-markdown/secrets/ai_enc_key');
define('AI_ENC_KEY_FALLBACK', __DIR__ . '/data/.ai_enc_key');
// 请求体/响应长度与超时上限（不做站内成本控制；额度由账号自担）
// v5.4.3-beta.2：单次输入上限 / 最大输出 / 超时改为"按模型取超管配置值"，以下为字段缺失时的兜底默认。
define('AI_MAX_INPUT', 20000);        // 单次处理正文字符上限兜底（模型未配 max_input_chars 时）
define('AI_TIMEOUT', 60);             // 非流式调用超时兜底（秒）
define('AI_TIMEOUT_STREAM', 120);     // 流式调用超时兜底（秒，流式可更长）

/** 固定 base_url 的服务商预设白名单（唯一真源；使用者只能选，不能改 base_url） */
function aiBuiltinProviders() {
    return [
        'deepseek'  => ['id' => 'deepseek',  'label' => 'DeepSeek',              'base_url' => 'https://api.deepseek.com/v1'],
        'mimo-payg' => ['id' => 'mimo-payg', 'label' => 'MiMo（按量付费）',       'base_url' => 'https://api.xiaomimimo.com/v1'],
        'mimo-plan' => ['id' => 'mimo-plan', 'label' => 'MiMo（Token Plan 订阅）', 'base_url' => 'https://token-plan-cn.xiaomimimo.com/v1'],
        'qwen'      => ['id' => 'qwen',      'label' => '千问（DashScope 兼容）',   'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1'],
    ];
}
/** 超管维护的启用白名单（config.ai_providers = 启用的 provider id 列表）；字段缺失视为全部启用 */
function aiEnabledProviderIds() {
    $c = loadSiteConfig();
    $ids = $c['ai_providers'] ?? null;
    $all = aiBuiltinProviders();
    if (!is_array($ids)) return array_keys($all);
    $out = [];
    foreach ($all as $id => $p) { if (in_array($id, $ids, true)) $out[] = $id; }
    return $out;
}
/** 面向使用者的服务商清单（仅 id + 展示名，不含 base_url） */
function aiProvidersForClient() {
    $all = aiBuiltinProviders();
    $out = [];
    foreach (aiEnabledProviderIds() as $id) $out[] = ['id' => $id, 'label' => $all[$id]['label']];
    return $out;
}
/** 取服务商 base_url（仅白名单内且已启用；否则空——调用方据此拒绝） */
function aiProviderBaseUrl($id) {
    $all = aiBuiltinProviders();
    if (!isset($all[$id]) || !in_array($id, aiEnabledProviderIds(), true)) return '';
    return $all[$id]['base_url'];
}

// ---- v5.4.3-beta.2：模型与长度（超管按服务商维护模型名单，使用者只能从名单里选） ----
// 预设为官方口径的起步值，超管可在后台「模型与长度」按服务商增删模型并改四个数值（建议值，非硬性）。
//   · max_input_chars = 单次输入字符上限（取代写死的 AI_MAX_INPUT；未配置回退 20000）
//   · max_out_tokens  = 最大输出 tokens（未配置=不传，用服务商默认）
//   · timeout / timeout_stream = 超时秒数（未配置回退 60 / 120）
//   · deepseek-chat / deepseek-reasoner 官方已弃用（2026-07-24），不出现在预设名单。
/** 内置默认模型名单（站点配置 ai_model_catalog 字段缺失时回落） */
function aiBuiltinModels() {
    $mimo = [
        ['id' => 'mimo-v2.6-pro',            'label' => 'MiMo V2.6 Pro',            'max_input_chars' => 80000,  'max_out_tokens' => 8192,  'timeout' => 60, 'timeout_stream' => 180],
        ['id' => 'mimo-v2.6-flash',          'label' => 'MiMo V2.6 Flash',          'max_input_chars' => 80000,  'max_out_tokens' => 8192,  'timeout' => 60, 'timeout_stream' => 180],
        ['id' => 'mimo-v2.6-pro-ultraspeed', 'label' => 'MiMo V2.6 Pro UltraSpeed', 'max_input_chars' => 80000,  'max_out_tokens' => 8192,  'timeout' => 60, 'timeout_stream' => 180],
    ];
    $qwen = [
        ['id' => 'qwen3.8-max',   'label' => 'Qwen3.8 Max',   'max_input_chars' => 200000, 'max_out_tokens' => 32768, 'timeout' => 60, 'timeout_stream' => 180],
        ['id' => 'qwen3.7-plus',  'label' => 'Qwen3.7 Plus',  'max_input_chars' => 200000, 'max_out_tokens' => 16384, 'timeout' => 60, 'timeout_stream' => 180],
        ['id' => 'qwen3.8-flash', 'label' => 'Qwen3.8 Flash', 'max_input_chars' => 200000, 'max_out_tokens' => 16384, 'timeout' => 60, 'timeout_stream' => 180],
    ];
    $deepseek = [
        ['id' => 'deepseek-flash',  'label' => 'DeepSeek Flash',  'max_input_chars' => 200000, 'max_out_tokens' => 8192,  'timeout' => 60, 'timeout_stream' => 180],
        ['id' => 'deepseek-v4-pro', 'label' => 'DeepSeek V4 Pro', 'max_input_chars' => 200000, 'max_out_tokens' => 16384, 'timeout' => 60, 'timeout_stream' => 180],
    ];
    return [
        'mimo-payg' => $mimo,
        'mimo-plan' => $mimo,
        'qwen'      => $qwen,
        'deepseek'  => $deepseek,
    ];
}
/** 归一化单条模型配置（字段缺失/非法回落默认；max_out_tokens=0 视为"不传"） */
function aiNormalizeModelEntry($m) {
    if (!is_array($m)) return null;
    $id = trim((string)($m['id'] ?? ''));
    if ($id === '') return null;
    $mic = (int)($m['max_input_chars'] ?? 0);
    if ($mic <= 0) $mic = AI_MAX_INPUT;
    $mot = (int)($m['max_out_tokens'] ?? 0);
    if ($mot < 0) $mot = 0;
    $to = (int)($m['timeout'] ?? 0);
    if ($to <= 0) $to = AI_TIMEOUT;
    $tos = (int)($m['timeout_stream'] ?? 0);
    if ($tos <= 0) $tos = AI_TIMEOUT_STREAM;
    return [
        'id' => $id,
        'label' => trim((string)($m['label'] ?? '')),
        'max_input_chars' => $mic,
        'max_out_tokens' => $mot,
        'timeout' => $to,
        'timeout_stream' => $tos,
    ];
}
/** 站点配置里的模型名单（config.ai_model_catalog）；字段缺失回落内置默认 */
function aiModelsConfig() {
    $c = loadSiteConfig();
    $m = $c['ai_model_catalog'] ?? null;
    $builtin = aiBuiltinModels();
    if (!is_array($m)) return $builtin;
    $out = [];
    foreach (aiBuiltinProviders() as $pid => $p) {
        if (!array_key_exists($pid, $m)) { $out[$pid] = $builtin[$pid] ?? []; continue; }
        $list = [];
        if (is_array($m[$pid])) {
            foreach ($m[$pid] as $r) { $n = aiNormalizeModelEntry($r); if ($n !== null) $list[] = $n; }
        }
        $out[$pid] = $list;
    }
    return $out;
}
/** 某服务商的模型名单 */
function aiProviderModels($pid) {
    $all = aiModelsConfig();
    return $all[$pid] ?? [];
}
/** 在名单中查模型配置；不在名单返回 null */
function aiFindModel($pid, $modelId) {
    $modelId = trim((string)$modelId);
    if ($modelId === '') return null;
    foreach (aiProviderModels($pid) as $m) { if ($m['id'] === $modelId) return $m; }
    return null;
}
/** 取运行时参数（单次输入上限 / 最大输出 / 超时）；模型不在名单时回落全局兜底（调用方应已拒绝） */
function aiModelRuntime($pid, $modelId) {
    $m = aiFindModel($pid, $modelId);
    if ($m) return $m;
    return ['id' => $modelId, 'label' => '', 'max_input_chars' => AI_MAX_INPUT, 'max_out_tokens' => 0, 'timeout' => AI_TIMEOUT, 'timeout_stream' => AI_TIMEOUT_STREAM];
}
/** 面向使用者的模型名单（仅 id / 展示名 / 上限值，不含 base_url 与任何密钥） */
function aiPublicModels() {
    $out = [];
    foreach (aiEnabledProviderIds() as $pid) {
        $list = [];
        foreach (aiProviderModels($pid) as $m) {
            $list[] = ['id' => $m['id'], 'label' => $m['label'], 'max_input_chars' => $m['max_input_chars'], 'max_out_tokens' => $m['max_out_tokens']];
        }
        $out[$pid] = $list;
    }
    return $out;
}

/** 当前角色是否被允许使用 AI 写作（超管一律不参与） */
function aiRoleAllowed($role) {
    $c = loadSiteConfig();
    if (empty($c['ai_enabled'])) return false;
    if ($role === ROLE_STATION_ADMIN) return !empty($c['ai_role_station_admin']);
    if ($role === ROLE_AUTHOR) return !empty($c['ai_role_author']);
    return false;
}

/** 读取 AI Key 加密密钥（不存在则安全生成；均不可写时返回空 → 加密失败即拒绝保存） */
function getAiEncKey() {
    foreach ([AI_ENC_KEY_FILE, AI_ENC_KEY_FALLBACK] as $f) {
        if (is_readable($f)) {
            $v = trim((string)@file_get_contents($f));
            if ($v !== '') return $v;
        }
        $dir = dirname($f);
        if (is_dir($dir) && is_writable($dir)) {
            $v = bin2hex(random_bytes(32));
            if (@file_put_contents($f, $v, LOCK_EX) !== false) { @chmod($f, 0600); return $v; }
        }
    }
    return '';
}
/** 加密 AI Key（AES-256-GCM；返回 'gcm:'+base64(iv|tag|cipher)） */
function aiEncryptKey($plain) {
    if ($plain === '') return '';
    $secret = getAiEncKey();
    if ($secret === '') return '';
    $key = hash('sha256', 'ysm-ai:' . $secret, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) return '';
    return 'gcm:' . base64_encode($iv . $tag . $cipher);
}
/** 解密 AI Key */
function aiDecryptKey($stored) {
    if (!is_string($stored) || strpos($stored, 'gcm:') !== 0) return '';
    $secret = getAiEncKey();
    if ($secret === '') return '';
    $raw = base64_decode(substr($stored, 4));
    if ($raw === false || strlen($raw) < 28) return '';
    $key = hash('sha256', 'ysm-ai:' . $secret, true);
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}
/** 明文 Key 掩码（仅保留末四位；绝不明文回显的展示口径） */
function aiMaskKey($plain) {
    $plain = (string)$plain;
    if ($plain === '') return '';
    if (strlen($plain) <= 4) return '****';
    return '****' . substr($plain, -4);
}

/** 该账号已配置的 Key 行 */
function aiLoadKeys($uid) {
    if ($uid === '') return [];
    return db_all('SELECT user_id, provider, key_cipher, model, is_default, created, updated FROM ai_keys WHERE user_id = ? ORDER BY provider', [$uid]);
}
/** 面向使用者的 Key 列表：仅返回 状态 + 末四位 + 模型 + 是否默认（绝不含明文/密文） */
function aiKeysForClient($uid) {
    $all = aiBuiltinProviders();
    $rows = [];
    foreach (aiLoadKeys($uid) as $r) $rows[$r['provider']] = $r;
    $out = [];
    foreach (aiEnabledProviderIds() as $id) {
        $r = $rows[$id] ?? null;
        if ($r) {
            $plain = aiDecryptKey($r['key_cipher']);
            $hint = $plain !== '' ? '已配置(' . aiMaskKey($plain) . ')' : '已配置';
        } else {
            $hint = '未配置';
        }
        $out[] = [
            'provider' => $id,
            'label' => $all[$id]['label'],
            'configured' => $r ? true : false,
            'hint' => $hint,
            'model' => $r['model'] ?? '',
            'model_available' => $r ? (aiFindModel($id, $r['model'] ?? '') !== null) : true,
            'is_default' => $r ? !empty($r['is_default']) : false,
        ];
    }
    return $out;
}
/** 该账号默认服务商（无默认则取首个已配置且启用者） */
function aiDefaultProvider($uid) {
    $enabled = aiEnabledProviderIds();
    $rows = aiLoadKeys($uid);
    foreach ($rows as $r) { if (!empty($r['is_default']) && in_array($r['provider'], $enabled, true)) return $r['provider']; }
    foreach ($rows as $r) { if (in_array($r['provider'], $enabled, true)) return $r['provider']; }
    return '';
}
/** 保存（新增/更新）Key：加密后入库；可设默认（设默认时清除同账号其它默认） */
function aiSaveKey($uid, $provider, $model, $plain, $isDefault) {
    $cipher = aiEncryptKey($plain);
    if ($cipher === '') return false;
    $now = time();
    $exist = db_one('SELECT user_id FROM ai_keys WHERE user_id = ? AND provider = ?', [$uid, $provider]);
    if ($isDefault) db_exec('UPDATE ai_keys SET is_default = 0 WHERE user_id = ?', [$uid]);
    if ($exist) {
        db_exec('UPDATE ai_keys SET key_cipher = ?, model = ?, is_default = ?, updated = ? WHERE user_id = ? AND provider = ?',
            [$cipher, $model, $isDefault ? 1 : 0, $now, $uid, $provider]);
    } else {
        db_exec('INSERT INTO ai_keys (user_id, provider, key_cipher, model, is_default, created, updated) VALUES (?,?,?,?,?,?,?)',
            [$uid, $provider, $cipher, $model, $isDefault ? 1 : 0, $now, $now]);
    }
    // 保底：账号下若尚无默认，自动把当前这条设为默认
    if (!$isDefault) {
        $hasDefault = db_one('SELECT 1 AS x FROM ai_keys WHERE user_id = ? AND is_default = 1 LIMIT 1', [$uid]);
        if (!$hasDefault) db_exec('UPDATE ai_keys SET is_default = 1 WHERE user_id = ? AND provider = ?', [$uid, $provider]);
    }
    return true;
}
/** 删除某账号某服务商 Key */
function aiDeleteKey($uid, $provider) {
    return db_exec('DELETE FROM ai_keys WHERE user_id = ? AND provider = ?', [$uid, $provider]);
}
/** 清除某账号全部 AI Key（超管"只能清、不能看"；删除/吊销账号时一并清理），返回清除条数 */
function aiClearUserKeys($uid) {
    if ($uid === '') return 0;
    $n = (int)(db_one('SELECT COUNT(*) AS c FROM ai_keys WHERE user_id = ?', [$uid])['c'] ?? 0);
    db_exec('DELETE FROM ai_keys WHERE user_id = ?', [$uid]);
    return $n;
}

/** 隐私提示：每角色（账号）首次使用确认一次 */
function aiPrivacyAcked($uid) {
    if ($uid === '') return false;
    $r = db_one('SELECT value FROM meta WHERE key = ?', ['ai_privacy_ack:' . $uid]);
    return !empty($r);
}
function aiSetPrivacyAck($uid) {
    if ($uid === '') return;
    db_exec('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)', ['ai_privacy_ack:' . $uid, date('c')]);
}

// ---- v5.4.3-beta.2：思考模式（账号级，默认关闭）----
// 存储于 meta 表 ai_thinking:<uid>（'on'/'off'）；删除/禁用/吊销账号时随 purgeUserResiduals 一并清理。
/** 该账号是否开启思考模式（默认关闭） */
function aiThinkingEnabled($uid) {
    if ($uid === '') return false;
    $r = db_one('SELECT value FROM meta WHERE key = ?', ['ai_thinking:' . $uid]);
    return $r && trim((string)$r['value']) === 'on';
}
function aiSetThinkingEnabled($uid, $enabled) {
    if ($uid === '') return false;
    db_exec('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)', ['ai_thinking:' . $uid, $enabled ? 'on' : 'off']);
    return true;
}
/** 实测发现"不接受思考参数"的服务商（缓存于站点配置，避免重复下发导致请求被拒） */
function aiThinkingUnsupportedSet() {
    $c = loadSiteConfig();
    $v = $c['ai_thinking_unsupported'] ?? null;
    if (!is_array($v)) return [];
    return array_values(array_filter(array_map('strval', $v)));
}
function aiThinkingMarkUnsupported($pid) {
    if ($pid === '') return;
    $set = aiThinkingUnsupportedSet();
    if (in_array($pid, $set, true)) return;
    $set[] = $pid;
    $c = loadSiteConfig();
    $c['ai_thinking_unsupported'] = $set;
    saveSiteConfig($c);
}
/** 服务商"思考"支持能力（参数名/类型）；未知或已实测不支持 → supported=false（一律不下发）。
 *  参数口径：qwen（DashScope 兼容）用 enable_thinking（bool）；deepseek / mimo-* 用 thinking:{type}。
 *  ⚠ 若某服务商/模型实测不接受该参数，运行时将回退"忽略并不报错"并标记为不支持（界面随之提示）。 */
function aiThinkingCapability($pid) {
    if (in_array($pid, aiThinkingUnsupportedSet(), true)) return ['supported' => false];
    if ($pid === 'qwen') return ['supported' => true, 'kind' => 'enable_thinking'];
    if (in_array($pid, ['deepseek', 'mimo-payg', 'mimo-plan'], true)) return ['supported' => true, 'kind' => 'thinking'];
    return ['supported' => false];
}
/** 依据服务商能力 + 账号开关，返回需并入请求体的思考参数；未支持 → 空数组（绝不发未知参数） */
function aiThinkingParams($provider, $enabled) {
    $cap = aiThinkingCapability($provider);
    if (empty($cap['supported'])) return [];
    $kind = $cap['kind'] ?? '';
    if ($kind === 'enable_thinking') return ['enable_thinking' => $enabled ? true : false];
    if ($kind === 'thinking') return ['thinking' => ['type' => $enabled ? 'enabled' : 'disabled']];
    return [];
}
/** 面向使用者的思考支持情况（provider id → 是否可开关） */
function aiThinkingSupportMap() {
    $out = [];
    foreach (aiEnabledProviderIds() as $pid) $out[$pid] = !empty(aiThinkingCapability($pid)['supported']);
    return $out;
}

/** 动作枚举（首发仅两类能力：润色/纠错 · 风格转换/翻译） */
function aiActions() {
    return [
        'polish'    => '润色',
        'proofread' => '纠错',
        'style'     => '风格转换',
        'translate' => '翻译',
    ];
}
function aiStyleOptions() {
    return ['正式公文', '口语科普', '简明要点', '文艺抒情', '商务邮件'];
}
function aiLangOptions() {
    return ['英文', '简体中文', '繁体中文', '日文', '韩文'];
}
/** 按动作构造服务端固定提示词（不接受任意自定义 prompt，避免被滥用）。
 *  v5.4.3-beta.2 定稿：system = 基座 + 动作追加；用户选中文本仍只作为 user 消息。 */
function aiBuildMessages($action, $text, $style = '', $lang = '') {
    // 基座（所有动作共用）
    $base = "你是本站的写作助手，只做文字加工，不做加工以外的任何事。\n\n"
        . "硬性规则（必须严格遵守）：\n"
        . "1. 只输出处理后的正文本身：不要解释、说明、前言、后记、客套话、引号、代码围栏或标题。\n"
        . "2. 不添加原文没有的信息、数字、人名、地名与结论；不删减原文的信息点。\n"
        . "3. 保留原文的 Markdown 结构（标题层级、列表、表格、代码块、链接、图片语法、公式）与段落划分；除\"翻译\"任务外不改变语言。\n"
        . "4. 保留专业术语、专有名词、单位与原有大小写写法，全篇一致。\n"
        . "5. 原文中的引文、代码、URL、公式即使看起来有误也不修改。\n"
        . "6. 输出应可直接替换原片段：不额外多出内容，也不遗漏内容。";
    switch ($action) {
        case 'polish':
            // 润色追加
            $add = "任务：润色。在不改变原意、事实与信息量的前提下，使表达更通顺、准确、得体。\n"
                . "允许：调整句式与语序、替换更贴切的词、修正搭配与标点。\n"
                . "禁止：增删信息点、改变观点与语气强度、加入夸张或抒情。";
            break;
        case 'proofread':
            // 纠错追加
            $add = "任务：纠错。只修正错别字、标点、语法、用词与明显笔误，保持原有结构与表达习惯。\n"
                . "禁止：重写句子、替换同义词、调整语序、改变风格。\n"
                . "不确定是否为错误的表达一律保留。";
            break;
        case 'style':
            // 风格转换追加（风格名走既有白名单校验）
            $add = "任务：风格转换。将原文改写为「" . $style . "」的风格，原意与信息量不变。\n"
                . "保留术语、专有名词、数字与引用；只改变语体与表达方式（用词、句式、语气、繁简），不改变内容。";
            break;
        case 'translate':
            // 翻译追加（语言名走既有白名单校验）
            $add = "任务：翻译。将原文翻译为「" . $lang . "」，只输出译文。\n"
                . "保留 Markdown 结构与公式；术语、人名的译法全篇统一；不解释、不注释、不补充原文没有的内容。";
            break;
        default:
            return null;
    }
    return [
        ['role' => 'system', 'content' => $base . "\n\n" . $add],
        ['role' => 'user', 'content' => (string)$text],
    ];
}

/** AI 出站调用限速（复用 ai_rates 表） */
function aiRateBlocked($ip, $fp, $max = 20, $window = 60) {
    return db_rate_count('ai_rates', $ip, $window, $fp) >= $max;
}
function aiRateHit($ip, $fp) {
    db_rate_add('ai_rates', $ip, $fp);
}

/** 将服务商响应/错误映射为可读原因（脱敏：只返回固定可读文案 + HTTP 码/错误类别，
 *  绝不回传上游原始响应体片段；$raw 形参仅为保持调用签名不变）。 */
function aiReadableError($httpCode, $curlErr = '', $raw = '') {
    if ($curlErr !== '') {
        if (stripos($curlErr, 'timed out') !== false || stripos($curlErr, 'timeout') !== false) return '连接超时（网络不可达或服务商无响应）';
        if (stripos($curlErr, 'resolve') !== false || stripos($curlErr, 'Could not resolve') !== false) return '网络不可达（域名解析失败）';
        return '网络不可达（连接失败）';
    }
    switch ((int)$httpCode) {
        case 401: return '鉴权失败（Key 无效或无权限，HTTP 401）';
        case 403: return '鉴权失败（Key 被拒绝访问该模型/服务，HTTP 403）';
        case 404: return '接口或模型不存在（HTTP 404）';
        case 400: return '请求被拒绝（多为模型名不存在，HTTP 400）';
        case 429: return '请求过于频繁或额度不足（HTTP 429）';
        case 500: case 502: case 503: case 504: return '服务商暂时不可用（HTTP ' . (int)$httpCode . '）';
    }
    if ($httpCode >= 200 && $httpCode < 300) return '';
    return '调用失败（HTTP ' . (int)$httpCode . '）';
}

/** 统一 JSON POST（Authorization: Bearer）；返回 ['ok','code','data','raw','err']。
 *  仅允许调用白名单 base_url 拼出的地址（SSRF 防护：调用方已保证 base 来自白名单）。 */
function aiHttpJson($url, $apiKey, $payload, $timeout = 60) {
    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');
    if ($host === '' || isPrivateHost($host)) {
        return ['ok' => false, 'code' => 0, 'data' => null, 'raw' => '', 'err' => '目标地址不可用', 'blocked' => true];
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => max(5, (int)$timeout),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) return ['ok' => false, 'code' => $code, 'data' => null, 'raw' => '', 'err' => $err ?: '请求失败'];
        $data = json_decode($raw, true);
        return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'data' => is_array($data) ? $data : null, 'raw' => (string)$raw, 'err' => ''];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'timeout' => max(5, (int)$timeout),
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return ['ok' => false, 'code' => 0, 'data' => null, 'raw' => '', 'err' => '请求失败'];
    $code = 0;
    foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1]; }
    $data = json_decode($raw, true);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'data' => is_array($data) ? $data : null, 'raw' => (string)$raw, 'err' => ''];
}

/** 连通性测试：用所选白名单 base_url + 该账号 Key + 其填写的模型名发一次最小请求。
 *  成功返回 [true,'ok']，失败返回 [false, 可读原因]。绝不落库。 */
function aiConnectivityTest($providerId, $apiKey, $model) {
    $base = aiProviderBaseUrl($providerId);
    if ($base === '') return [false, '该服务商未启用或不存在'];
    if (trim((string)$apiKey) === '') return [false, '请填写 Key'];
    if (trim((string)$model) === '') return [false, '请填写模型名'];
    $url = rtrim($base, '/') . '/chat/completions';
    $payload = [
        'model' => trim($model),
        'messages' => [['role' => 'user', 'content' => 'ping']],
        'max_tokens' => 1,
        'stream' => false,
    ];
    $res = aiHttpJson($url, trim($apiKey), $payload, 30);
    if (!empty($res['blocked'])) return [false, '目标地址不可用（已被安全策略拦截）'];
    if ($res['ok']) return [true, 'ok'];
    $reason = aiReadableError($res['code'], $res['err'], $res['raw']);
    return [false, $reason !== '' ? $reason : '调用失败'];
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
// SMTP 密码来源：环境变量注入（YSM_SMTP_PASS，php-fpm pool env，root 只读、Web 不可见）优先；
// 未注入时回退 config 表密文（AES-GCM 同机加密兜底，兼容旧部署）
// v3.2.2：SMTP 密码三级来源（env → config 密文 → CLI root 密钥文件），
// 修复 CLI（ysm-admin/守护进程）场景拿不到密码导致告警邮件发不出的问题
function smtpSecretFile() {
    return '/opt/you-super-markdown/secrets/smtp_pass';
}
function smtpPassSource() {
    $env = getenv('YSM_SMTP_PASS');
    if ($env !== false && $env !== '') return 'env';
    $cfg = loadSiteConfig();
    $stored = (string)($cfg['smtp_pass'] ?? '');
    if ($stored !== '') return 'config';
    if (is_readable(smtpSecretFile()) && trim((string)@file_get_contents(smtpSecretFile())) !== '') return 'file';
    return 'none';
}
function getSmtpConfig() {
    $c = loadSiteConfig();
    $env = getenv('YSM_SMTP_PASS');
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
    // v3.0.9：密码优先走环境变量注入（YSM_SMTP_PASS），后台不再直接设置；
    // 仅当环境未注入时才允许写入 config 表密文兜底（供旧部署平滑过渡）
    $env = getenv('YSM_SMTP_PASS');
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

// v5.4.1：邮件类别（两行 badge 上行用）——告警 / 通知 / 验证，配色仍沿用 mailPalette（告警红 / 验证蓝 / 通知深蓝）
function mailCategory($type) {
    if (preg_match('/失败|断裂|异常|告警|篡改|无法|错误|超时|拒绝|降级|封禁|越权/', (string)$type)) return '告警';
    if (preg_match('/验证|确认|绑定|注册/', (string)$type)) return '验证';
    return '通知';
}

// v5.4.1：从邮件 type/主题中提取「具体事件」（两行 badge 下行用）——去掉形如「[站点 类别] 」的前缀
function mailEventText($type) {
    $raw = trim((string)$type);
    $ev = preg_replace('/^\[[^\]]*\]\s*/u', '', $raw);
    return ($ev === null || trim($ev) === '') ? $raw : trim($ev);
}

// v5.4.1：解析两行 badge 的「下行（具体事件）」——兜底防御，绝不允许下行只显示"通知/告警/验证"：
//   ① 事件非空且与「上行类别」不同 → 直接用；
//   ② 事件为空、或与类别相同 → 取 $detail 首行首句（截断 ≤30 字）；
//   ③ 仍为空 → 固定兜底文案（告警/验证/通知各自的可读文案）。
function mailResolveEvent($category, $typeOrEvent, $detail) {
    $cat = (string)$category;
    $ev = mailEventText($typeOrEvent);
    if ($ev !== '' && $ev !== $cat) return $ev;
    $first = '';
    foreach (preg_split('/\r\n|\r|\n/u', (string)$detail) as $ln) {
        $ln = trim($ln);
        if ($ln !== '') { $first = $ln; break; }
    }
    $first = trim(preg_replace('/\s+/u', ' ', $first));
    if (preg_match('/^(.{1,30}?)[。！？!?；;]/u', $first, $m)) { $first = $m[1]; }
    if (mb_strlen($first, 'UTF-8') > 30) { $first = mb_substr($first, 0, 30, 'UTF-8') . '…'; }
    if ($first !== '') return $first;
    $fixed = ['告警' => '系统告警', '验证' => '身份验证', '通知' => '系统通知'];
    return $fixed[$cat] ?? '系统通知';
}

// v5.4.1：两行 badge（上行=站点+类别，下行=具体事件）；纯内联样式，邮件客户端兼容，窄屏居中且可换行不截断
function mailBadgeHtml($site, $category, $event, $bg) {
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    return '<div style="display:inline-block;max-width:100%;box-sizing:border-box;background:' . $bg
        . ';border-radius:14px;padding:9px 22px;text-align:center;">'
        . '<div style="color:#ffffff;font-size:15px;font-weight:600;letter-spacing:0.5px;line-height:1.5;">'
        . $e($site) . ' ' . $e($category) . '</div>'
        . '<div style="color:rgba(255,255,255,0.92);font-size:14px;font-weight:400;margin-top:3px;line-height:1.5;word-break:break-word;overflow-wrap:anywhere;">'
        . $e($event) . '</div>'
        . '</div>';
}

// HTML 邮件基础模板（v2.10.1 统一设计：顶部栏按功能分色 + 卡片放大 660px + 内容分层 + 大圆角；内联样式兼容主流邮件客户端）
// $htmlDetail 为非空时直接作为正文区 HTML（调用方负责转义动态值），否则将 $detail 按纯文本转义后渲染（默认安全）
// $badgeEvent 可选：两行 badge 的「下行（具体事件）」显式文案；为空则从 $type/$detail 自动解析（见 mailResolveEvent）
function renderMailHtml($site, $type, $detail, $extra = [], $htmlDetail = null, $badgeEvent = null) {
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
    // v5.4.1：两行 badge 解析——上行=站点+类别，下行=具体事件（空/与类别相同则按 $detail 兜底）
    $badgeCat = mailCategory($type);
    $badgeEv = mailResolveEvent($badgeCat, $badgeEvent !== null ? $badgeEvent : $type, $detail);
    $iconLetter = mb_substr($siteE, 0, 1, 'UTF-8');
    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#eef1f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',\'Microsoft YaHei\',sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f6;padding:32px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="660" cellpadding="0" cellspacing="0" style="table-layout:fixed;max-width:660px;width:100%;background:#ffffff;border-radius:24px;overflow:hidden;border:1px solid #dde3ec;box-shadow:0 16px 44px rgba(15,42,82,0.12);">'
        // 顶部栏：按功能分色渐变
        . '<tr><td style="background:linear-gradient(135deg,' . $p['g1'] . ' 0%,' . $p['g2'] . ' 100%);padding:30px 38px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="vertical-align:middle;"><div style="color:#ffffff;font-size:20px;font-weight:700;letter-spacing:0.5px;">' . $siteE . '</div>'
        . '<div style="color:rgba(255,255,255,0.72);font-size:12px;margin-top:6px;">' . $p['sub'] . '</div></td>'
        . '<td align="right" style="vertical-align:middle;"><div style="width:44px;height:44px;border-radius:14px;background:rgba(255,255,255,0.16);color:#fff;font-size:19px;font-weight:700;text-align:center;line-height:44px;">' . $iconLetter . '</div></td>'
        . '</tr></table>'
        . '</td></tr>'
        // 主内容区（分层一：类型徽标（v5.4.1 两行：站点+类别 / 具体事件） + 正文）
        . '<tr><td style="padding:38px 40px 26px;">'
        . mailBadgeHtml($site, $badgeCat, $badgeEv, $p['badge'])
        . '<div style="margin-top:22px;color:#2d3748;font-size:14.5px;line-height:2.0;word-break:break-all;overflow-wrap:anywhere;">' . $detailHtml . '</div>'
        . '</td></tr>'
        // 分层二：元信息面板（浅色内嵌卡）
        . '<tr><td style="padding:0 40px 34px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;background:#f7f9fc;border-radius:14px;border:1px solid #eaeef4;padding:14px 20px;font-size:12.5px;color:#5b6b80;">'
        . '<tr><td style="padding:6px 0;width:72px;color:#93a2b4;">服务器</td><td style="word-break:break-all;overflow-wrap:anywhere;">' . $server . '</td></tr>'
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
        . mailBadgeHtml($site, '验证', '邮箱验证码（有效期 ' . $ttlMin . ' 分钟）', $p['badge'])
        . '</div>'
        . '<div style="text-align:center;margin-top:14px;">'
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

// v5.0.0 P2：请求 Host 净化（与 index.php 的 og/rss 同一白名单正则）——用于 SMTP EHLO / 邮件正文等；
// Host 头可被客户端伪造，未经净化写入邮件头/正文存在头部注入风险，非法一律回落 localhost。
function safeRequestHost() {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '' || !preg_match('/^[a-zA-Z0-9.-]+(?::\d{1,5})?$/', $host)) $host = 'localhost';
    return $host;
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
    $ehlo = safeRequestHost();
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
    $host = safeRequestHost();
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
    if (!file_exists(EMAIL_ALERT)) { logAlertFail("ysm-alert 不存在({$type})"); return; }
    $cmd = escapeshellcmd(EMAIL_ALERT) . ' ' . escapeshellarg($adminEmail) . ' ' . escapeshellarg($subject) . ' ' . escapeshellarg($body);
    exec($cmd . ' > /dev/null 2>&1 &');
    // mail 命令为异步后台，其内部失败由 ysm-alert 落盘 alert.log（见 v2.8.0 ysm-alert 改造）
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
    $host = safeRequestHost();
    $subject = "[{$site} 通知] {$roleName}登录";
    $now = date('Y-m-d H:i:s');
    $body = "时间：{$now}\n"
          . "服务器：{$host}\n"
          . "账号：{$u['nickname']}（" . maskQQ($u['account'] ?? '') . "）\n"
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
    if (!file_exists(EMAIL_ALERT)) { logAlertFail("ysm-alert 不存在({$subject})"); return; }
    $cmd = escapeshellcmd(EMAIL_ALERT) . ' ' . escapeshellarg($adminEmail) . ' ' . escapeshellarg($subject) . ' ' . escapeshellarg($body);
    exec($cmd . ' > /dev/null 2>&1 &');
}

/**
 * v4.7.2：密码已重置邮件告警（找回密码重置成功时通知管理员——用户密码被重置=可能盗号，管理员可及时排查）。
 * $mode: self_reset=邮箱验证码自助找回 / admin_reset=超管协助重置码。
 */
function notifyPasswordReset($u, $clientIP, $mode) {
    $config = loadSiteConfig();
    $adminEmail = trim($config['admin_email'] ?? '');
    if ($adminEmail === '' || !email_valid($adminEmail)) return;
    $site = $config['site_title'] ?? 'You Super Markdown';
    $host = safeRequestHost();
    $modeLabel = $mode === 'admin_reset' ? '超管协助重置码' : '邮箱验证码自助找回';
    $now = date('Y-m-d H:i:s');
    $subject = "[{$site} 通知] 账号密码已重置";
    $body = "账号：{$u['nickname']}（" . maskQQ($u['account'] ?? '') . "）\n"
          . "角色：{$u['role']}\n"
          . "重置方式：{$modeLabel}\n"
          . "IP：{$clientIP}\n"
          . "时间：{$now}\n\n"
          . "若非本人操作，请立即在超管后台检查该账号登录/越权日志。";
    $html = renderMailHtml($site, $subject, $body, ['server' => $host, 'time' => $now]);
    $smtp = getSmtpConfig();
    if ($smtp['host'] !== '' && $smtp['user'] !== '' && $smtp['pass'] !== '') {
        [$ok, $err] = sendSmtpMail($adminEmail, $subject, $body, $html);
        if ($ok) return;
        logAlertFail("SMTP 发送失败({$subject}): {$err}");
        return;
    }
    if (!file_exists(EMAIL_ALERT)) { logAlertFail("ysm-alert 不存在({$subject})"); return; }
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
    $host = safeRequestHost();
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
    if (!file_exists(EMAIL_ALERT)) { logAlertFail("ysm-alert 不存在({$subject})"); return; }
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
    $users = fetchAllUsers();
    foreach ($users as &$u) {
        if ($u['id'] === $userId) { $u['avatar'] = $url; break; }
    }
    unset($u);
    replaceAllUsers($users);
    if (!empty($_SESSION['cmt_user']['id']) && $_SESSION['cmt_user']['id'] === $userId) {
        $_SESSION['cmt_user']['avatar'] = $url;
    }
    return [true, $url];
}

/**
 * 用户公开详情（v2.10.0）：个人详情页数据。
 * 排除超管：超管无公开页面，返回 null。
 * @return array|null ['id','account','nickname','avatar','signature','created','role']
 */
function get_public_user($id) {
    if (!is_string($id) || $id === '') return null;
    $users = fetchAllUsers();
    foreach ($users as $u) {
        if ($u['id'] === $id) {
            if (($u['role'] ?? '') === ROLE_SUPER_ADMIN) return null;
            return [
                'id' => $u['id'],
                'account' => $u['account'] ?? '',
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
 * v4.5.0：$fp 非空时额外按环境指纹计数（防换 IP 绕过）
 */
function loginFailAdd($ip, $account, $fp = '') {
    db_exec('INSERT INTO login_fails (ip, t, acc, fp) VALUES (?,?,?,?)', [$ip, time(), $account, (string)$fp]);
}
/** 60 秒窗口内，同 IP / 同账号 / 同指纹跨 IP 失败次数（任一命中即计数） */
function loginFailCount($ip, $account, $window = 60, $fp = null) {
    $cutoff = time() - $window;
    if ($fp !== null && $fp !== '') {
        $r = db_one('SELECT COUNT(*) AS c FROM login_fails WHERE t > ? AND (ip = ? OR acc = ? OR (fp = ? AND fp != ""))', [$cutoff, $ip, $account, $fp]);
    } else {
        $r = db_one('SELECT COUNT(*) AS c FROM login_fails WHERE t > ? AND (ip = ? OR acc = ?)', [$cutoff, $ip, $account]);
    }
    return (int)($r['c'] ?? 0);
}
function loginFailClear($ip, $account) {
    db_exec('DELETE FROM login_fails WHERE ip = ? OR acc = ?', [$ip, $account]);
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

// ============================================================
// v4.5.0：Cookie 加固与风控——环境指纹绑定 / 双 token(access+refresh) /
// token_version 并发踢旧 / 敏感操作环境校验 / 指纹+IP 双维限速
// ============================================================

// ---- 环境指纹 ----
// 指纹 = hash(前端上报 fp(lang|tz|screen|canvas) | UA)。前端统一经 X-Fp 请求头携带，
// 仅登录态（登录/注册/评论/后台）校验；访客浏览不受影响。
function fp_hmac_key() {
    $f = __DIR__ . '/data/.fp_hmac';
    if (!file_exists($f)) {
        file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($f, 0600);
    }
    return file_get_contents($f);
}
/** 从请求头 X-Fp 取前端上报指纹（校验格式，防注入） */
function getRequestFp() {
    $fp = trim((string)($_SERVER['HTTP_X_FP'] ?? ''));
    return ($fp !== '' && preg_match('/^[a-f0-9]{16,64}$/i', $fp)) ? strtolower($fp) : '';
}
/** 登录/签发时计算服务端绑定指纹（前端指纹 + UA，防仅改 UA/仅改 canvas 单独绕过） */
function computeSessionFp($clientFp) {
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 256);
    return hash('sha256', ($clientFp !== '' ? (string)$clientFp : 'no-fp') . '|' . $ua);
}
/** 比对请求环境与会话绑定环境是否一致 */
function fpMatches($boundFp, $requestFp) {
    if ($boundFp === '' || $requestFp === '') return false;
    return hash_equals((string)$boundFp, computeSessionFp($requestFp));
}
/**
 * 校验当前登录态会话的环境与版本：
 * - 未登录 → false
 * - 旧会话（升级 v4.5.0 前签发、无 cmt_fp）→ false（升级即全量重登）
 * - token_version 与 users 表不一致（被新登录踢旧）→ false
 * - 请求指纹与会话绑定指纹不一致（换浏览器/设备/隐私模式）→ false
 */
function requireSessionEnv() {
    if (empty($_SESSION['cmt_user'])) return false;
    if (empty($_SESSION['cmt_fp'])) return false;
    if (!isset($_SESSION['cmt_tv'])) return false;
    $uid = $_SESSION['cmt_user']['id'] ?? '';
    if ($uid === '' || (int)$_SESSION['cmt_tv'] !== getUserTV($uid)) return false;
    return fpMatches($_SESSION['cmt_fp'], getRequestFp());
}

// ---- token_version（同账号并发踢旧）----
function getUserTV($uid) {
    $r = db_one('SELECT tv FROM users WHERE id = ?', [$uid]);
    return (int)($r['tv'] ?? 0);
}
/** 登录成功时调用：tv+1 使旧会话/旧 refresh 全部失效 */
function bumpUserTV($uid) {
    db_exec('UPDATE users SET tv = tv + 1 WHERE id = ?', [$uid]);
    return getUserTV($uid);
}

// ---- 双 token：refresh（httpOnly Cookie，30 天，绑定指纹 + tv）----
// v4.6.0：前台登录态长效 30 天（REFRESH_TTL 7 天 → 30 天）；超管不签发 refresh（严格 30 分钟 JWT）
const REFRESH_TTL = 2592000; // 30 天
/** 签发 refresh token（写库 + 写 httpOnly Cookie），返回明文 token */
function issueRefreshToken($uid, $sessionFp, $tv) {
    $token = bin2hex(random_bytes(32));
    db_exec('INSERT INTO refresh_tokens (id, user_id, token_hash, fp, tv, expires, created) VALUES (?,?,?,?,?,?,?)',
        [bin2hex(random_bytes(8)), $uid, hash('sha256', $token), (string)$sessionFp, (int)$tv, time() + REFRESH_TTL, time()]);
    setcookie('ysm_rt', $token, [
        'expires' => time() + REFRESH_TTL,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    return $token;
}
/**
 * 消费 refresh token：校验存在/未吊销/未过期/指纹匹配/tv 匹配，成功返回记录数组，失败返回 null。
 * 换环境（指纹不匹配）/被踢（tv 落后）→ 无效，触发重新登录。
 */
function consumeRefreshToken($token, $requestFp, $userTV) {
    if (!is_string($token) || strlen($token) !== 64 || !ctype_xdigit($token)) return null;
    $row = db_one('SELECT * FROM refresh_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
    if (!$row || !empty($row['revoked'])) return null;
    if ((int)$row['expires'] < time()) { db_exec('DELETE FROM refresh_tokens WHERE id = ?', [$row['id']]); return null; }
    if (!hash_equals((string)$row['fp'], computeSessionFp($requestFp))) return null;
    if ((int)$row['tv'] !== (int)$userTV) return null;
    db_exec('UPDATE refresh_tokens SET last_used = ? WHERE id = ?', [time(), $row['id']]);
    return $row;
}
/** 吊销用户全部 refresh token（登出/封禁/重置密码时调用） */
function revokeUserRefreshTokens($uid) {
    db_exec('UPDATE refresh_tokens SET revoked = 1 WHERE user_id = ?', [$uid]);
}
/**
 * v5.4.1：删除/吊销用户后清理其会话与设备残留（refresh_tokens、device_fps）。
 * 语义保留：comments / audit / unauthorized 属于业务与审计记录，不做删除。
 * 供 ysm-admin revoke-user、超管后台删除用户、站长后台删除写作者统一调用。
 */
function purgeUserResiduals($uid) {
    if ($uid === '' || $uid === null) return;
    db_exec('DELETE FROM refresh_tokens WHERE user_id = ?', [$uid]);
    db_exec('DELETE FROM device_fps WHERE user_id = ?', [$uid]);
    // v5.4.3-beta.2：账号级思考模式随账号一并清理
    db_exec('DELETE FROM meta WHERE key = ?', ['ai_thinking:' . $uid]);
}
function clearRefreshCookie() {
    if (isset($_COOKIE['ysm_rt'])) {
        setcookie('ysm_rt', '', ['expires' => time() - 42000, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
    }
}
/** v4.5.0：JWT payload 携带 token_version，校验时比对（超管 OTP 会话同样被踢旧） */
function jwtTVMatches($data) {
    $uid = $data['sub'] ?? '';
    return $uid !== '' && (int)($data['tv'] ?? 0) === getUserTV($uid);
}

// ---- 背景音乐上传转码（v4.5.0：<100MB 常见音频格式 → ffmpeg 转 96kbps mp3）----
function ffmpegAvailable() {
    @exec('ffmpeg -version 2>&1', $o, $rc);
    return $rc === 0;
}
function saveBgMusicFile($tmpPath, $origName) {
    if (!is_uploaded_file($tmpPath)) return [false, '上传失败，请重试'];
    $ext = strtolower(pathinfo((string)$origName, PATHINFO_EXTENSION));
    $allow = ['mp3', 'wav', 'flac', 'm4a', 'aac', 'ogg', 'opus', 'wma', 'ape'];
    if (!in_array($ext, $allow, true)) return [false, '不支持的音频格式（支持：' . implode('/', $allow) . '）'];
    $size = @filesize($tmpPath);
    if ($size === false || $size <= 0) return [false, '文件读取失败'];
    if ($size > 100 * 1024 * 1024) return [false, '文件过大（上限 100MB）'];
    if (!ffmpegAvailable()) return [false, '服务器缺少 ffmpeg 转码组件，无法自动压缩（请改用 mp3 直传或联系管理员）'];
    $dir = __DIR__ . '/data/bgm';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $tmpOut = $dir . '/.bgm_tmp_' . bin2hex(random_bytes(4)) . '.mp3';
    $dst = $dir . '/background.mp3';
    // 96kbps 立体声 mp3：背景音乐在保证听感的同时最大限度节省流量
    $cmd = 'ffmpeg -y -loglevel error -i ' . escapeshellarg($tmpPath) . ' -codec:a libmp3lame -b:a 96k -ac 2 ' . escapeshellarg($tmpOut) . ' 2>&1';
    @exec($cmd, $out, $rc);
    if ($rc !== 0 || !is_file($tmpOut)) {
        @unlink($tmpOut);
        $err = trim(implode("\n", (array)$out));
        $err = $err === '' ? '音频无法解码' : (mb_strlen($err, 'UTF-8') > 120 ? mb_substr($err, 0, 120, 'UTF-8') . '…' : $err);
        return [false, '音频转码失败：' . $err];
    }
    if (!@rename($tmpOut, $dst)) { @unlink($tmpOut); return [false, '保存失败，请检查目录权限']; }
    @chmod($dst, 0644);
    return [true, '背景音乐已更新并自动压缩为 ' . round(@filesize($dst) / 1024 / 1024, 1) . ' MB（96kbps mp3）'];
}
/** v4.4.3/v4.5.0：本地背景音乐当前文件大小（字节），无文件返回 0 */
function bgMusicSize() {
    $f = __DIR__ . '/data/bgm/background.mp3';
    return is_file($f) ? (int)@filesize($f) : 0;
}

/**
 * v2.11.0：QQ 号隐私打码（非超管视角）。保留前 3 后 4；短号（≤7 位）保留首尾各 1；≤4 位全打码。
 */
function maskQQ($account) {
    $account = (string)$account;
    $len = strlen($account);
    if ($len <= 4) return str_repeat('*', $len);
    if ($len <= 7) return substr($account, 0, 1) . str_repeat('*', $len - 2) . substr($account, -1);
    return substr($account, 0, 3) . str_repeat('*', $len - 7) . substr($account, -4);
}

/**
 * v2.11.1：对外返回的用户信息统一脱敏——去除 pw_hash，账号打码（防登录/注册/check
 * 接口泄露完整账号给前端；头像走 avatar 字段，前端不再依赖完整 account 拼 URL）。
 */
function sanitizeUserForClient($u) {
    if (!is_array($u)) return $u;
    unset($u['pw_hash']);
    if (isset($u['account']) && $u['account'] !== '') $u['account'] = maskQQ($u['account']);
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
        'device_login' => '新设备登录验证',
        'password_reset' => '找回密码验证',
    ];
    $purposeLabel = $purposeLabels[$purpose] ?? '邮箱验证';
    $html = renderMailCode($site, $purposeLabel, $code, intdiv($ttl, 60), [
        'server' => safeRequestHost(),
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

// ===== v4.7.0：管理角色陌生设备登录邮件二次验证（OTP/密码通过后，陌生设备需邮箱验证码确认才完成登录） =====
/** 设备是否已知（device_fps 表存在该账号+指纹哈希） */
function isKnownDevice($uid, $fpHash) {
    if ($uid === '' || $fpHash === '') return false;
    return db_one('SELECT 1 AS x FROM device_fps WHERE user_id = ? AND fp_hash = ?', [$uid, $fpHash]) !== null;
}
/** 记录设备指纹为已知（登录/验证通过后调用；同指纹只记一次，刷新 last_seen） */
function recordDevice($uid, $fpHash, $ua = '') {
    if ($uid === '' || $fpHash === '') return;
    db_exec('INSERT INTO device_fps (id, user_id, fp_hash, ua, first_seen, last_seen) VALUES (?,?,?,?,?,?)
             ON CONFLICT(user_id, fp_hash) DO UPDATE SET last_seen = excluded.last_seen, ua = excluded.ua',
        [bin2hex(random_bytes(8)), $uid, $fpHash, mb_substr((string)$ua, 0, 256, 'UTF-8'), time(), time()]);
}
/** 账号已信任设备列表（超管后台设备管理用） */
function getKnownDevices($uid) {
    return db_all('SELECT id, fp_hash, ua, first_seen, last_seen FROM device_fps WHERE user_id = ? ORDER BY last_seen DESC', [$uid]);
}
/** v4.8.2：分页获取所有管理角色的已信任设备（含用户信息），返回 [rows, total] */
function getDevicesPaginated($page, $per) {
    $page = max(1, (int)$page); $per = min(100, max(5, (int)$per));
    $roles = implode(',', array_map(function($r) { return "'" . $r . "'"; }, [ROLE_STATION_ADMIN, ROLE_AUTHOR, ROLE_SUPER_ADMIN]));
    $total = (int)(db_one("SELECT COUNT(*) AS c FROM device_fps d JOIN users u ON d.user_id = u.id WHERE u.role IN ($roles)")['c'] ?? 0);
    $rows = db_all("SELECT d.id, d.user_id, d.fp_hash, d.ua, d.first_seen, d.last_seen, u.account, u.role, u.nickname
        FROM device_fps d JOIN users u ON d.user_id = u.id WHERE u.role IN ($roles)
        ORDER BY d.last_seen DESC LIMIT ? OFFSET ?", [$per, ($page - 1) * $per]);
    return [$rows, $total];
}
/** 移除单个已信任设备（超管后台管理；返回是否影响行数） */
function removeKnownDevice($uid, $devId) {
    $st = db()->prepare('DELETE FROM device_fps WHERE id = ? AND user_id = ?');
    $st->execute([$devId, $uid]);
    return $st->rowCount() > 0;
}
/** 邮箱打码（如 a***@example.com），用于登录页提示验证码发往哪个邮箱 */
function maskEmailAddr($email) {
    if (!email_valid((string)$email)) return '';
    [$name, $domain] = explode('@', $email, 2);
    $len = mb_strlen($name, 'UTF-8');
    if ($len <= 2) return $name[0] . '***@' . $domain;
    return mb_substr($name, 0, 2, 'UTF-8') . '***@' . $domain;
}

// ===== v4.7.0：联动威胁评分（IP+浏览器双维聚合，超阈值自动联动封锁） =====
/** 读取威胁评分配置（可被 config 覆盖） */
function threatCfg($key, $def) {
    static $c = null;
    if ($c === null) $c = loadSiteConfig();
    return ($c[$key] ?? '') !== '' ? $c[$key] : $def;
}
/** 事件权重表；支持 config 键 threat_w_<reason> 覆盖单个权重 */
function threatWeight($reason) {
    $map = [
        // v4.8.0：高危攻击低容忍——提高高危事件权重，新增 hfish_attack 事件
        'login_fail'        => 5,   // 登录密码错误（普通账号，8 次=40 分触发 L1）
        'device_login_fail' => 25,  // 陌生设备登录失败（管理角色账号，2 次=50 分即触发 L1）
        'login_lock'        => 15,  // 触发登录锁定（3 次=45 分触发 L1）
        'login_locked_try'  => 10,  // 锁定期内仍尝试（4 次=40 分触发 L1）
        'scanner_ua'        => 40,  // 扫描器 UA 命中（1 次即触发 L1）
        'tool_ua'           => 5,   // v5.4.5：curl/wget 等命令行走量工具 UA（低权重；独立计数，不参与联动/永久阈值升级）
        'unauthorized'      => 25,  // 越权操作（2 次=50 分触发 L1）
        'honeypot'          => 25,  // 蜜罐命中（2 次=50 分触发 L1）
        'hfish_attack'      => 20,  // v4.8.0：HFish 蜜罐攻击事件（2 次=40 分触发 L1）
        'reg_flood'         => 5,   // 注册验证码超频（8 次=40 分触发 L1）
        'comment_flood'     => 5,   // 评论超频（8 次=40 分触发 L1）
        'otp_fail'          => 10,  // OTP 连续失败（4 次=40 分触发 L1）
        'code_fail'         => 5,   // 邮箱验证码校验失败（8 次=40 分触发 L1）
        'device_code_fail'  => 5,   // 陌生设备登录验证码输错（8 次=40 分触发 L1）
        'reset_flood'       => 5,   // 找回密码验证码超频（8 次=40 分触发 L1）
    ];
    $w = threatCfg('threat_w_' . $reason, $map[$reason] ?? 0);
    return max(0, (int)$w);
}
/** 写入威胁事件（双维度：请求 IP + 请求指纹），并触发联动封锁升级 */
function logThreat($reason, $ip = '', $fp = '', $dedupeWindow = 0) {
    if (!threatCfg('threat_enable', 1)) return;
    if ($ip === '') $ip = getClientIP();
    if ($fp === '') $fp = getRequestFp();
    $weight = threatWeight($reason);
    if ($weight <= 0) return;
    // v4.7.5：联动威胁评分本地/内网 IP 豁免——内网回环地址不参与自动联动封锁（与蜜罐内网豁免一致），
    // 防内网/本机测试误封（如 127.0.0.1 直连验证）；fp 维度照常计分
    if ($ip !== '' && isPrivateHost($ip)) $ip = '';
    // v5.4.4：服务器自身 IP 豁免——命中 threat_self_ips 一律不计分（含指纹维度，直接返回），
    // 从根上避免「服务器自访问 → 每次累计高分 → 把自己公网 IP 永久封禁（自封）」
    // v5.4.5：仅对「直连来源」生效——客户端 IP 若取自被采信的 XFF（clientIpFromProxy），
    //         不享受自身 IP 豁免（防自带 `X-Forwarded-For: <服务器自身IP>` 免疫计分）。
    if ($ip !== '' && isSelfIp($ip) && !clientIpFromProxy()) return;
    $now = time();
    $entries = [];
    if ($ip !== '') $entries[] = ['ip', $ip];
    if ($fp !== '') $entries[] = ['fp', $fp];
    foreach ($entries as [$dt, $dk]) {
        if ($dedupeWindow > 0) {
            $dup = db_one('SELECT 1 AS x FROM threat_events WHERE dim_type = ? AND dim_key = ? AND reason = ? AND created > ? LIMIT 1',
                [$dt, $dk, $reason, $now - $dedupeWindow]);
            if ($dup) continue;
        }
        db_exec('INSERT INTO threat_events (id, dim_type, dim_key, weight, reason, created) VALUES (?,?,?,?,?,?)',
            [bin2hex(random_bytes(8)), $dt, $dk, $weight, $reason, $now]);
    }
    foreach ($entries as [$dt, $dk]) maybeLinkedBlock($dt, $dk);
    // 概率清理 3 天前事件（1/64），窗口查询按 created>cutoff 过滤，不影响计分
    if (random_int(0, 63) === 0) db_exec('DELETE FROM threat_events WHERE created < ?', [$now - 259200]);
}
/** 计算某维度窗口内威胁总分（支持衰减：无新事件超过 N 天后评分自动降低） */
function threatScore($dimType, $dimKey, $window = null) {
    if ($window === null) $window = (int)threatCfg('threat_window', 86400);
    $r = db_one('SELECT COALESCE(SUM(weight),0) AS s, COALESCE(MAX(created),0) AS last_created FROM threat_events WHERE dim_type = ? AND dim_key = ? AND created > ?',
        [$dimType, $dimKey, time() - $window]);
    $score = (int)($r['s'] ?? 0);
    // v4.8.1：威胁评分衰减——按最后事件时间计算衰减系数
    $lastCreated = (int)($r['last_created'] ?? 0);
    if ($lastCreated > 0) {
        $decayDays = (int)threatCfg('threat_decay_days', 3);
        $decayRatio = (float)threatCfg('threat_decay_ratio', 0.5);
        $daysSinceLast = (time() - $lastCreated) / 86400;
        if ($daysSinceLast >= $decayDays && $decayRatio > 0 && $decayRatio < 1) {
            $periods = floor($daysSinceLast / $decayDays);
            $decayFactor = pow($decayRatio, $periods);
            $score = (int)round($score * $decayFactor);
        }
    }
    return $score;
}
/** 升级式锁定：只延长不缩短（防低等级覆盖高等级） */
function linkedLock($key, $seconds, $reason) {
    $until = time() + $seconds;
    $row = db_one('SELECT locked_until FROM login_locks WHERE key = ?', [$key]);
    if ($row && (int)$row['locked_until'] > $until) $until = (int)$row['locked_until'];
    db_exec('INSERT INTO login_locks (key, locked_until) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET locked_until = excluded.locked_until', [$key, $until]);
    if ($seconds >= 10 * 365 * 86400) logAbnormal(getClientIP(), $reason . '（' . $key . '）');
}
/** 按总分自动升级联动封锁（四级：L1 15min / L1.5 6h / L2 24h / L3 永久） */
function maybeLinkedBlock($dimType, $dimKey) {
    if (!threatCfg('threat_enable', 1)) return;
    // v5.4.4：自封保护——IP 维度目标属回环/内网/服务器自身 IP 时不写封禁、不加锁，改为只告警
    if ($dimType === 'ip' && isSelfProtectedHost($dimKey)) {
        $score = threatScore($dimType, $dimKey);
        if ($score > 0) selfProtectAlert('linkedBlock', $dimKey, '联动阈值升级（评分 ' . $score . '）');
        return;
    }
    $score = threatScore($dimType, $dimKey);
    // v5.4.5：curl/wget 降级事件(reason=tool_ua, 5 分)不参与联动评分升级（含永久阈值 L3）——
    //         其封禁走独立计数（toolUaTempBan：10 次/10 分钟 → 15 分钟临时锁）。其余原因行为不变。
    $toolUaScore = (int)(db_one('SELECT COALESCE(SUM(weight),0) AS s FROM threat_events WHERE dim_type = ? AND dim_key = ? AND reason = ? AND created > ?',
        [$dimType, $dimKey, 'tool_ua', time() - (int)threatCfg('threat_window', 86400)])['s'] ?? 0);
    if ($toolUaScore > 0) $score = max(0, $score - $toolUaScore);
    $l3 = (int)threatCfg('threat_l3', 250);
    $l2 = (int)threatCfg('threat_l2', 150);
    $l1_5 = (int)threatCfg('threat_l1_5', 80);
    $l1 = (int)threatCfg('threat_l1', 40);
    if ($score >= $l3) {
        // 永久：IP 进 bans（可超管解封）；指纹维度用远期登录锁（近十年）
        if ($dimType === 'ip') banIp($dimKey, ['link'], '联动封锁：威胁评分达永久阈值(' . $score . ')');
        else linkedLock('link:' . $dimType . ':' . $dimKey, 10 * 365 * 86400, '联动封锁永久');
    } elseif ($score >= $l2) {
        linkedLock('link:' . $dimType . ':' . $dimKey, (int)threatCfg('threat_l2_dur', 86400), '联动封锁24小时');
    } elseif ($score >= $l1_5) {
        linkedLock('link:' . $dimType . ':' . $dimKey, (int)threatCfg('threat_l1_5_dur', 21600), '联动封锁6小时');
    } elseif ($score >= $l1) {
        linkedLock('link:' . $dimType . ':' . $dimKey, (int)threatCfg('threat_l1_dur', 900), '联动封锁15分钟');
    }
    // v4.7.2：联动封锁邮件告警——同维度同等级只发一次，升级到下一等级再发（防刷屏）
    if ($score >= $l3) maybeAlertLock($dimType, $dimKey, $score, 'L3');
    elseif ($score >= $l2) maybeAlertLock($dimType, $dimKey, $score, 'L2');
    elseif ($score >= $l1_5) maybeAlertLock($dimType, $dimKey, $score, 'L1.5');
    elseif ($score >= $l1) maybeAlertLock($dimType, $dimKey, $score, 'L1');
}
/** 联动封锁告警去重+发送：用 threat_events 的 0 权重 __alert_<等级> 标记行记录已发等级 */
function maybeAlertLock($dimType, $dimKey, $score, $level) {
    $tag = '__alert_' . $level;
    if (db_one('SELECT 1 AS x FROM threat_events WHERE dim_type = ? AND dim_key = ? AND reason = ?', [$dimType, $dimKey, $tag]) !== null) return;
    db_exec('INSERT INTO threat_events (id, dim_type, dim_key, weight, reason, created) VALUES (?,?,?,0,?,?)',
        [bin2hex(random_bytes(8)), $dimType, $dimKey, $tag, time()]);
    // 事件摘要：窗口内 top 原因（排除告警标记行）
    $rows = db_all('SELECT reason, COUNT(*) AS c FROM threat_events WHERE dim_type = ? AND dim_key = ? AND created > ? AND reason NOT LIKE "__alert_%" GROUP BY reason ORDER BY c DESC LIMIT 5',
        [$dimType, $dimKey, time() - (int)threatCfg('threat_window', 86400)]);
    $summary = '';
    foreach ($rows as $r) $summary .= $r['reason'] . '×' . $r['c'] . '；';
    notifyThreatAlert($dimType, $dimKey, $score, $level, rtrim($summary, '；'));
}
/** 联动封锁邮件告警（发往 admin_email；SMTP 未配置回退 ysm-alert 脚本；统一 HTML 模板红色告警系） */
function notifyThreatAlert($dimType, $dimKey, $score, $level, $summary) {
    $config = loadSiteConfig();
    $adminEmail = trim($config['admin_email'] ?? '');
    if ($adminEmail === '' || !email_valid($adminEmail)) return;
    $site = $config['site_title'] ?? 'You Super Markdown';
    $host = safeRequestHost();
    $levelNames = ['L1' => '15 分钟', 'L2' => '24 小时', 'L3' => '永久'];
    $dimLabel = $dimType === 'ip' ? 'IP：' . $dimKey : '浏览器指纹：' . substr($dimKey, 0, 16) . '…';
    $levelName = $levelNames[$level] ?? $level;
    $now = date('Y-m-d H:i:s');
    $subject = "[{$site} 告警] 联动封锁触发（{$levelName}）";
    $body = "触发维度：{$dimLabel}\n"
          . "当前评分：{$score}\n"
          . "封锁等级：{$level}（{$levelName}）\n"
          . "事件摘要：{$summary}\n"
          . "时间：{$now}\n\n"
          . "如为误封，请在超管后台「联动风控」页解除（需 SSH 挑战码）。";
    $html = renderMailHtml($site, $subject, $body, ['server' => $host, 'time' => $now]);
    $smtp = getSmtpConfig();
    if ($smtp['host'] !== '' && $smtp['user'] !== '' && $smtp['pass'] !== '') {
        [$ok, $err] = sendSmtpMail($adminEmail, $subject, $body, $html);
        if ($ok) return;
        logAlertFail("SMTP 发送失败({$subject}): {$err}");
        return;
    }
    if (!file_exists(EMAIL_ALERT)) { logAlertFail("ysm-alert 不存在({$subject})"); return; }
    $cmd = escapeshellcmd(EMAIL_ALERT) . ' ' . escapeshellarg($adminEmail) . ' ' . escapeshellarg($subject) . ' ' . escapeshellarg($body);
    exec($cmd . ' > /dev/null 2>&1 &');
}
/** 检查单维度联动封锁：返回剩余秒数（0=未封锁） */
function linkedBlockLeft($dimType, $dimKey) {
    if (!threatCfg('threat_enable', 1)) return 0;
    $left = loginLocked('link:' . $dimType . ':' . $dimKey);
    if ($left > 0) return $left;
    if ($dimType === 'ip' && isIPBanned($dimKey, 'link')) return 10 * 365 * 86400;
    return 0;
}
/** 统一联动封锁拦截（各敏感入口调用）：命中返回剩余秒数，否则 0 */
function checkLinkedBlock() {
    if (!threatCfg('threat_enable', 1)) return 0;
    $left = 0;
    $ip = getClientIP();
    if (isIPBanned($ip, 'link')) $left = 10 * 365 * 86400;
    $l = loginLocked('link:ip:' . $ip);
    if ($l > 0 && $l > $left) $left = $l;
    $fp = getRequestFp();
    if ($fp !== '') {
        $l = loginLocked('link:fp:' . $fp);
        if ($l > 0 && $l > $left) $left = $l;
    }
    return $left;
}
/** 面板手动解除联动封锁（删除登录锁+评分事件+联动封禁记录） */
function clearLinkedBlock($dimType, $dimKey) {
    db_exec('DELETE FROM login_locks WHERE key = ?', ['link:' . $dimType . ':' . $dimKey]);
    db_exec('DELETE FROM threat_events WHERE dim_type = ? AND dim_key = ?', [$dimType, $dimKey]);
    if ($dimType === 'ip') {
        $row = db_one('SELECT types_json FROM bans WHERE ip = ?', [$dimKey]);
        if ($row) {
            $types = json_decode($row['types_json'] ?? '[]', true) ?: [];
            $types = array_values(array_filter($types, fn($t) => $t !== 'link'));
            if (empty($types)) db_exec('DELETE FROM bans WHERE ip = ?', [$dimKey]);
            else db_exec('UPDATE bans SET types_json = ? WHERE ip = ?', [json_encode($types, JSON_UNESCAPED_UNICODE), $dimKey]);
        }
    }
}
/** 面板明细：某维度窗口内事件列表（排除告警标记行） */
function threatEventsFor($dimType, $dimKey, $window = null) {
    if ($window === null) $window = (int)threatCfg('threat_window', 86400);
    return db_all('SELECT reason, weight, created FROM threat_events WHERE dim_type = ? AND dim_key = ? AND created > ? AND reason NOT LIKE "__alert_%" ORDER BY created DESC LIMIT 100',
        [$dimType, $dimKey, time() - $window]);
}
/** 威胁维度聚合列表（面板用，分页）：返回 [rows, total]；rows 含 score/cnt/last_ts/locked_left（排除告警标记行） */
function threatDims($page = 1, $per = 20) {
    $window = (int)threatCfg('threat_window', 86400);
    $cutoff = time() - $window;
    $page = max(1, (int)$page); $per = min(100, max(5, (int)$per));
    $total = (int)(db_one('SELECT COUNT(DISTINCT dim_type || "|" || dim_key) AS c FROM threat_events WHERE created > ? AND reason NOT LIKE "__alert_%"', [$cutoff])['c'] ?? 0);
    $rows = db_all('SELECT dim_type, dim_key, SUM(weight) AS score, COUNT(*) AS cnt, MAX(created) AS last_ts
        FROM threat_events WHERE created > ? AND reason NOT LIKE "__alert_%" GROUP BY dim_type, dim_key ORDER BY score DESC, last_ts DESC LIMIT ? OFFSET ?',
        [$cutoff, $per, ($page - 1) * $per]);
    foreach ($rows as &$r) {
        $r['score'] = (int)$r['score'];
        $r['cnt'] = (int)$r['cnt'];
        $r['locked_left'] = linkedBlockLeft($r['dim_type'], $r['dim_key']);
    }
    unset($r);
    return [$rows, $total];
}
/** 超管生成一次性重置码（协助找回）：写入 email_codes（purpose=admin_reset，30 分钟过期，一次性），返回 6 位码 */
function genAdminResetCode($userId, $email) {
    if (!email_valid((string)$email)) return [false, '该用户未绑定有效邮箱'];
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db_exec('INSERT INTO email_codes (id,email,code,purpose,expires,used,created,ip,operator_role) VALUES (?,?,?,?,?,0,?,?,?)', [
        bin2hex(random_bytes(8)), $email, $code, 'admin_reset', time() + 1800, time(), getClientIP(), ROLE_SUPER_ADMIN,
    ]);
    return [true, $code];
}
/** 陌生设备登录失败快速风控：连续失败 ≥3 → 直接锁设备指纹 15 分钟（不锁账号；升级交给威胁评分） */
function fpRiskLock($fp) {
    if ($fp === '') return 0;
    $fails = db_rate_count('login_fails', getClientIP(), 1800, $fp);
    if ($fails < 3) return 0;
    linkedLock('fp:' . $fp, (int)threatCfg('threat_l1_dur', 900), '陌生设备登录失败风控');
    return (int)threatCfg('threat_l1_dur', 900);
}

// ===== v4.4.0：注册算术人机验证（随机加减乘除，SESSION 存答案，60s 过期，一次性消费） =====
const ARITH_TTL = 60;
/** 生成一道简单四则运算题，答案存入 SESSION，返回题面（如 "7 + 8 = ?"）；不返回答案给客户端 */
function genArithChallenge() {
    $op = ['+', '-', '×', '÷'][random_int(0, 3)];
    switch ($op) {
        case '+':
            $a = random_int(2, 20); $b = random_int(2, 20); $ans = $a + $b; $expr = "{$a} + {$b}";
            break;
        case '-':
            $a = random_int(2, 20); $b = random_int(1, $a - 1); $ans = $a - $b; $expr = "{$a} − {$b}";
            break;
        case '×':
            $a = random_int(2, 9); $b = random_int(2, 9); $ans = $a * $b; $expr = "{$a} × {$b}";
            break;
        case '÷':
            $b = random_int(2, 9); $ans = random_int(2, 9); $a = $b * $ans; $expr = "{$a} ÷ {$b}";
            break;
    }
    $_SESSION['arith_answer'] = (string)$ans;
    $_SESSION['arith_expires'] = time() + ARITH_TTL;
    return $expr . ' = ?';
}
/** 校验算术题答案（一次性消费：无论对错均清除 SESSION，防重放） */
function verifyArithChallenge($answer) {
    if (empty($_SESSION['arith_answer'])) return [false, '请先获取算术验证题'];
    $expect = (string)$_SESSION['arith_answer'];
    $expires = (int)($_SESSION['arith_expires'] ?? 0);
    unset($_SESSION['arith_answer'], $_SESSION['arith_expires']);
    if (time() > $expires) return [false, '算术验证已过期，请重新获取'];
    $ans = (string)trim((string)$answer);
    if (!preg_match('/^-?\d+$/', $ans) || $ans !== $expect) return [false, '计算结果不正确'];
    return [true, ''];
}

/** 创建写作者双重确认中间态，返回 [pendingId, confirmToken]；$status: verify_pending(等写作者验证码) / pending(待超管确认) */
function create_pending_author($email, $nickname, $account, $passwordHash, $stationId, $verifyCodeId = '', $status = 'pending') {
    $id = genId();
    $token = ($status === 'pending') ? bin2hex(random_bytes(16)) : '';
    db_exec('INSERT INTO pending_author_creates (id,email,nickname,account,password_hash,station_id,verify_code_id,confirm_token,status,created,confirmed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
        $id, $email, $nickname, $account, $passwordHash, $stationId, $verifyCodeId, $token, $status, time(), '',
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
    $users = fetchAllUsers();
    foreach ($users as $u) {
        if (($u['account'] ?? '') === $row['account']) return false;
        if (!empty($u['email']) && ($u['email'] ?? '') === $row['email']) return false;
    }
    $users[] = [
        'id' => genId(),
        'account' => $row['account'],
        'email' => $row['email'],
        'nickname' => $row['nickname'],
        'password' => $row['password_hash'],
        'role' => ROLE_AUTHOR,
        'station_id' => $row['station_id'],
        'created' => date('Y-m-d H:i:s'),
        'created_by' => 'dual_verify',
    ];
    replaceAllUsers($users);
    return true;
}

/** 给超管发送写作者创建确认邮件（一次性链接，confirm_link_ttl 秒有效） */
function sendAdminConfirmMail($pendingId, $token, $nick, $account, $email) {
    $cfg = loadSiteConfig();
    $adminEmail = $cfg['admin_email'] ?? '';
    if (!$adminEmail) return false;
    $ttl = max(300, (int)($cfg['confirm_link_ttl'] ?? 86400));
    $host = safeRequestHost();
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $link = "{$scheme}://{$host}/verify-confirm.php?token={$token}";
    $site = $cfg['site_title'] ?? 'You Super Markdown';
    $now = date('Y-m-d H:i:s');
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $subject = "[{$site}] 写作者创建确认";
    $body = "站长申请创建写作者：\n昵称：{$nick}\nQQ：{$account}\n邮箱：{$email}\n\n"
          . "点击以下链接确认（" . intdiv($ttl, 3600) . " 小时内有效，一次性）：\n{$link}\n\n时间：{$now}";
    // v2.9.0：确认邮件信息卡（昵称/QQ/邮箱）+ 渐变确认按钮
    $htmlDetail = '<div style="text-align:center;">'
        . '<span style="display:inline-block;background:#eef3fb;color:#1f3a5f;font-size:12px;font-weight:600;padding:6px 16px;border-radius:999px;">站长申请创建写作者</span>'
        . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;background:#f8fafc;border:1px solid #edf0f5;border-radius:12px;">'
        . '<tr><td style="padding:14px 20px;width:76px;color:#a0aec0;font-size:12px;">昵称</td><td style="padding:14px 20px;color:#2d3748;font-size:14px;font-weight:600;">' . $e($nick) . '</td></tr>'
        . '<tr><td style="padding:10px 20px;width:76px;color:#a0aec0;font-size:12px;border-top:1px solid #edf0f5;">QQ</td><td style="padding:10px 20px;color:#2d3748;font-size:14px;border-top:1px solid #edf0f5;">' . $e($account) . '</td></tr>'
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
// v5.0.0 P1-4：更新请求文件移至 root 与 www-data 共享、其他用户不可写的位置
// （/opt/you-super-markdown/run/ root:www-data 0770，文件 0660）；旧 /tmp 路径保留为只读兼容回退。
define('UPDATE_REQUEST_FILE', '/opt/you-super-markdown/run/ysm-update-request.json');
define('UPDATE_REQUEST_FILE_LEGACY', '/tmp/ysm-update-request.json');
// v5.0.0：更新锁位于 root:www-data 共享、其他用户不可写的 /opt 目录（目录 0770、文件 0660）；
// 读取前做属主/权限校验，不可信锁视为「未加锁」（不再保留旧 /tmp 回退路径）
define('UPDATE_LOCK_FILE', '/opt/you-super-markdown/run/ysm-update.lock');
define('BACKUP_DIR', '/opt/you-super-markdown/backups');
define('BACKUP_CONF', '/opt/you-super-markdown/backup.conf');           // 自动备份配置（root:www-data 664）
define('BACKUP_DB_DIR', BACKUP_DIR . '/db');                        // 数据库 30 分钟备份（固定 1 份）
define('BACKUP_ARTICLES_DIR', BACKUP_DIR . '/articles');            // 文章每日备份（保留 N 份）
define('GUARD_STATE_FILE', '/opt/you-super-markdown/guard-state.json');   // 守护进程状态（含备份状态）
define('ALERT_LOG', '/opt/you-super-markdown/alert.log');                  // 告警发送失败日志（可追溯）

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
// 频率计数（滑动窗口）：table 为 login_fails / reg_rates / comment_rates / honeypot_rates
// v4.5.0：$fp 非空时按「指纹+IP」双维计数——命中同 IP 或同指纹跨 IP 都计数（防换 IP 绕过限速）
function db_rate_count($table, $ip, $window, $fp = null) {
    $now = time();
    $cutoff = $now - $window;
    if ($fp !== null && $fp !== '') {
        $st = db()->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE t > ? AND (ip = ? OR (fp = ? AND fp != ''))");
        $st->execute([$cutoff, $ip, $fp]);
        return (int)($st->fetch()['c'] ?? 0);
    }
    $st = db()->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE ip = ? AND t > ?");
    $st->execute([$ip, $cutoff]);
    return (int)($st->fetch()['c'] ?? 0);
}
function db_rate_add($table, $ip, $fp = '') {
    db_exec("INSERT INTO {$table} (ip, fp, t) VALUES (?,?,?)", [$ip, (string)$fp, time()]);
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
    // v5.0.0 P1-4：优先新共享目录，兼容回退旧 /tmp（过渡期旧后台仍写 /tmp）
    $file = is_file(UPDATE_REQUEST_FILE) ? UPDATE_REQUEST_FILE
          : (is_file(UPDATE_REQUEST_FILE_LEGACY) ? UPDATE_REQUEST_FILE_LEGACY : '');
    if ($file === '') return null;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function saveUpdateRequest($data) {
    // v5.0.0 P1-4：写入共享目录（0660，非世界可写）；目录不存在或不可写时回退旧 /tmp（一次性过渡）
    $path = UPDATE_REQUEST_FILE;
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    if (!is_dir($dir) || !is_writable($dir)) $path = UPDATE_REQUEST_FILE_LEGACY;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $n = @file_put_contents($path, $json, LOCK_EX);
    if ($n !== false) {
        @chmod($path, 0660);
        @unlink($path === UPDATE_REQUEST_FILE ? UPDATE_REQUEST_FILE_LEGACY : UPDATE_REQUEST_FILE);
    }
    return $n;
}

// v5.0.0：更新锁可信性校验——属主须为 root/www-data，且不得 world-writable（防普通用户伪造 /tmp 锁）。
// 不满足视为「未加锁」，避免伪造锁令更新期校验豁免被滥用。
function updateLockTrusted($path) {
    if (!is_file($path) || is_link($path)) return false;
    $st = @stat($path);
    if (!$st) return false;
    if ($st['mode'] & 0x0002) return false;   // 其他用户可写
    if ((int)$st['uid'] === 0) return true;   // root
    if (function_exists('posix_getpwnam')) {
        $pw = @posix_getpwnam('www-data');
        if ($pw && isset($pw['uid']) && (int)$pw['uid'] === (int)$st['uid']) return true;
    }
    return false;
}

function isUpdateInProgress() {
    $path = UPDATE_LOCK_FILE;
    if (!is_file($path)) return false;
    if (!updateLockTrusted($path)) return false;   // 不可信 → 按未加锁处理
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) return false;
    // 锁过期则清理
    if (($data['expires'] ?? 0) < time()) {
        @unlink($path);
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
    // v5.0.0：写共享目录（0770/0660）；目录不存在则创建，不可写即写入失败（不再回退旧 /tmp）
    $path = UPDATE_LOCK_FILE;
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $n = @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    if ($n !== false) @chmod($path, 0660);
    return $n;
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
        @chmod(UPDATE_REQUEST_FILE, 0660);
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
        foreach (glob(BACKUP_DIR . '/ysm-backup-*.tar.gz') ?: [] as $f) {
            $basename = basename($f);
            // 手动备份格式: ysm-backup-{yyyyMMdd}-{HHmmss}.tar.gz
            if (preg_match('/^ysm-backup-(\d{8})-(\d{6})\.tar\.gz$/', $basename, $m2)) {
                $backups[] = [
                    'file' => $basename, 'path' => $f, 'version' => '手动备份',
                    'timestamp' => (int)strtotime($m2[1] . ' ' . $m2[2]), 'size' => filesize($f), 'type' => 'manual',
                ];
            }
        }
        // 数据库 30 分钟自动备份（固定 1 份滚动）
        foreach (glob(BACKUP_DB_DIR . '/ysm-db-latest.tar.gz') ?: [] as $f) {
            $backups[] = [
                'file' => basename($f), 'path' => $f, 'version' => '数据库自动备份',
                'timestamp' => (int)filemtime($f), 'size' => filesize($f), 'type' => 'db',
            ];
        }
        // 文章每日自动备份
        foreach (glob(BACKUP_ARTICLES_DIR . '/ysm-articles-*.tar.gz') ?: [] as $f) {
            if (preg_match('/^ysm-articles-(\d{8})\.tar\.gz$/', basename($f), $m3)) {
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
// 自动备份配置（backup.conf，与守护进程 ysm-guard.py 同源）
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
    $content = "# 自动备份配置（守护进程 ysm-guard.py 读取；超管后台/SSH 可改）\n"
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

// v5.3.0：更新通道规范化（仅允许 stable / beta，非法值一律回落 stable）
function normalizeUpdateChannel($channel) {
    return ($channel === 'beta') ? 'beta' : 'stable';
}

// ============================================================
// v5.3.0：跨通道升级/降级判定的唯一真源（检查侧 pickLatestRelease/buildUpdateResult
//   与更新器 ysm-admin apply-update 共用同一套比较与判定，杜绝两处逻辑漂移）
// ============================================================

// 把版本号拆成 [核心数字段数组, 预发布标识]：形如 "v5.3.1-beta.2" → [[5,3,1], "beta.2"]；
//   非数字段取前导数字（缺省 0）；忽略 +build 构建元数据。
function ysmVersionParts($v) {
    $v = trim((string)$v);
    if ($v !== '' && ($v[0] === 'v' || $v[0] === 'V')) $v = substr($v, 1);
    $v = explode('+', $v, 2)[0];              // 丢弃 +build
    $core = $v;
    $pre = '';
    $dash = strpos($v, '-');
    if ($dash !== false) {
        $core = substr($v, 0, $dash);
        $pre  = substr($v, $dash + 1);
    }
    $nums = [];
    foreach (explode('.', $core) as $seg) {
        $nums[] = (int)preg_replace('/[^0-9].*$/s', '', $seg);
    }
    return [$nums, $pre];
}

// 是否为预发布版本（含 '-' 后缀，如 5.3.1-beta.2）
function ysmIsPrerelease($v) {
    $parts = ysmVersionParts($v);
    return $parts[1] !== '';
}

// 预发布标识比较（semver 简化版）：数字段按数值比、非数字段按字典序；数字段高于非数字段；
//   前缀相同时段数少者更低。返回 -1/0/1。例：beta.2 < beta.10，beta.2 < beta.2.1。
function ysmComparePrerelease($a, $b) {
    $ta = array_values(array_filter(preg_split('/[.\-]/', (string)$a), function ($x) { return $x !== ''; }));
    $tb = array_values(array_filter(preg_split('/[.\-]/', (string)$b), function ($x) { return $x !== ''; }));
    $len = max(count($ta), count($tb));
    for ($i = 0; $i < $len; $i++) {
        if (!isset($ta[$i])) return -1;
        if (!isset($tb[$i])) return 1;
        $xn = ctype_digit($ta[$i]);
        $yn = ctype_digit($tb[$i]);
        if ($xn && $yn) {
            if ((int)$ta[$i] !== (int)$tb[$i]) return (int)$ta[$i] < (int)$tb[$i] ? -1 : 1;
        } elseif ($xn !== $yn) {
            return $xn ? 1 : -1;               // 数字段 > 非数字段
        } else {
            $c = strcmp($ta[$i], $tb[$i]);
            if ($c !== 0) return $c < 0 ? -1 : 1;
        }
    }
    return 0;
}

// v5.3.0：检查侧与更新器共用的唯一版本比较语义。
//   规则：先比核心数字段（缺失按 0）；核心相同则「预发布 < 同基线正式版」；
//        两者皆为预发布时按预发布标识比较。返回 -1/0/1。
//   例：ysmCompareVersion('5.3.1-beta.2','5.3.1') = -1；('5.3.1','5.3.2') = -1。
function ysmCompareVersion($a, $b) {
    list($na, $pa) = ysmVersionParts($a);
    list($nb, $pb) = ysmVersionParts($b);
    $len = max(count($na), count($nb));
    for ($i = 0; $i < $len; $i++) {
        $x = $na[$i] ?? 0;
        $y = $nb[$i] ?? 0;
        if ($x !== $y) return $x < $y ? -1 : 1;
    }
    $aPre = ($pa !== '');
    $bPre = ($pb !== '');
    if ($aPre !== $bPre) return $aPre ? -1 : 1;  // 预发布 < 正式版
    if (!$aPre) return 0;                        // 同为正式版且核心相同 → 相等
    return ysmComparePrerelease($pa, $pb);
}

// v5.3.0：比较函数别名（任务约定名 ysmCompareVersions，等价于 ysmCompareVersion）
function ysmCompareVersions($a, $b) {
    return ysmCompareVersion($a, $b);
}

// v5.3.0：版本号合法性校验（语义化：可选 v 前缀 + N(.N)* + 可选 -预发布 / +构建）。
//   非法（空 / 非数字核心 / 含非法字符）返回 false，判定函数据此拒绝。
function ysmVersionValid($v) {
    $v = trim((string)$v);
    if ($v === '') return false;
    return (bool)preg_match('/^[vV]?[0-9]+(\.[0-9]+)*(-[0-9A-Za-z][0-9A-Za-z.\-]*)?(\+[0-9A-Za-z][0-9A-Za-z.\-]*)?$/', $v);
}

// v5.3.0：仅比较「核心数字段」（忽略预发布/构建），用于区分「降级」与「同号跨通道互转」。
//   返回 -1/0/1。例：ysmCompareCore('5.3.1-beta','5.3.1') = 0（同号）；('5.3.1','5.3.2') = -1。
function ysmCompareCore($a, $b) {
    $pa = ysmVersionParts($a);
    $pb = ysmVersionParts($b);
    $na = $pa[0];
    $nb = $pb[0];
    $len = max(count($na), count($nb));
    for ($i = 0; $i < $len; $i++) {
        $x = $na[$i] ?? 0;
        $y = $nb[$i] ?? 0;
        if ($x !== $y) return $x < $y ? -1 : 1;
    }
    return 0;
}

// 判定结果构造器
function ysmDecision($allow, $forceFull, $code, $reason) {
    return [
        'decision'   => $allow ? 'allow' : 'deny',
        'allowed'    => (bool)$allow,
        'force_full' => (bool)$forceFull,
        'code'       => $code,
        'reason'     => $reason,
    ];
}

// ============================================================
// v5.3.0：升级/降级与跨通道判定的唯一真源（检查侧 pickLatestRelease/buildUpdateResult
//   与更新器 ysm-admin apply-update 共用同一套规则，杜绝两处逻辑漂移）。
//   返回 ['decision'=>'allow'|'deny', 'allowed'=>bool, 'force_full'=>bool, 'code'=>string, 'reason'=>string]
//   规则（用户已拍板）：
//     ① 一律禁止降级：目标核心版本 < 当前核心版本 → 拒绝；
//     ② beta → stable：必须全量包（目标为正式版且当前为预发布 / 目标通道 stable 且当前通道 beta）→ 增量包拒绝；
//     ③ 同号跨通道互转（如 5.3.1 ↔ 5.3.1-beta）：允许，但必须全量包，增量包拒绝；
//     ④ stable → 更高 stable、beta → 更高 beta：按既有逻辑放行（增量或全量均可）；
//     ⑤ 同号互转视作「版本等同」：不得因 5.3.1 < 5.3.1-beta 之类比较误判为降级/升级（同号互转走规则③）。
//   说明：$curChannel/$targetChannel 取值 stable|beta（非法值回落 stable）；$type 取值 full|inc（兼容 incremental）。
// ============================================================
function ysmUpdateDecision($cur, $curChannel, $target, $targetChannel, $type = 'full') {
    $cur         = trim((string)$cur);
    $target      = trim((string)$target);
    $curChannel  = normalizeUpdateChannel($curChannel);
    $tgtChannel  = normalizeUpdateChannel($targetChannel);
    $isInc       = ($type === 'inc' || $type === 'incremental');
    if ($cur === '') $cur = '0.0.0';
    if ($target === '') {
        return ysmDecision(false, false, 'invalid', '目标版本号为空，已拒绝');
    }
    if (!ysmVersionValid($cur) || !ysmVersionValid($target)) {
        return ysmDecision(false, false, 'invalid', "版本号非法（当前 v{$cur} / 目标 v{$target}），已拒绝");
    }
    // ① 一律禁止降级（按核心数字段判定，"高版本→低版本 beta" 同样落入此处）
    if (ysmCompareCore($target, $cur) < 0) {
        return ysmDecision(false, false, 'downgrade', "目标版本 v{$target} 低于当前版本 v{$cur}，已拒绝（禁止降级）");
    }
    $curPre = ysmIsPrerelease($cur);
    $tgtPre = ysmIsPrerelease($target);
    // 稳定通道不收预发布目标
    if ($tgtChannel === 'stable' && $tgtPre) {
        return ysmDecision(false, false, 'channel', "稳定通道不接受预发布版本 v{$target}，已拒绝（请切换到测试版通道）");
    }
    // ② / ③：判定是否强制全量包
    $forceFull = false;
    $reason = '';
    if (ysmCompareCore($target, $cur) === 0) {
        // ⑤ 同号互转视作「版本等同」：预发布 ↔ 正式 的跨通道互转（规则③）必须全量
        if ($curPre !== $tgtPre) {
            $forceFull = true;
            $reason = "同号跨通道互转（v{$cur} ↔ v{$target}）必须使用全量包";
        }
    } elseif ($curPre && !$tgtPre) {
        // ② 预发布 → 正式（beta → stable）必须全量
        $forceFull = true;
        $reason = "从预发布版 v{$cur} 升级到正式版 v{$target}（beta → stable）必须使用全量包";
    } elseif ($tgtChannel === 'stable' && $curChannel === 'beta') {
        // ② 目标通道 stable 且当前通道 beta 必须全量
        $forceFull = true;
        $reason = "从测试版通道切换到稳定版通道（v{$cur} → v{$target}）必须使用全量包";
    }
    if ($forceFull && $isInc) {
        return ysmDecision(false, true, 'need_full', $reason . "，增量包已拒绝");
    }
    return ysmDecision(true, $forceFull, 'ok', '');
}

// v5.3.0：兼容旧调用点的包装（检查侧与更新器此前用 $channel 单参）——
//   当前通道由「当前版本是否预发布」推导，目标通道取传入 $channel；判定委托 ysmUpdateDecision（唯一真源）。
function ysmCanUpdate($from, $to, $pkgType = 'full', $channel = 'stable') {
    $curChannel = ysmIsPrerelease($from) ? 'beta' : 'stable';
    $d = ysmUpdateDecision($from, $curChannel, $to, $channel, $pkgType);
    return ['allowed' => $d['allowed'], 'force_full' => $d['force_full'], 'code' => $d['code'], 'reason' => $d['reason']];
}

// v5.3.0：从 Releases 列表挑选「最新」一条——
//   ① stable 通道：仅保留 prerelease=false 且非 draft（只认正式 Release）；
//   ② beta 通道：包含预发布（排除 draft）；
//   ③ 版本比较用同一套 ysmCompareVersion 处理语义化预发布号（5.3.0-beta.2 < 5.3.0 < 5.3.1）；
//   GitHub 列表按创建时间倒序、不保证版本最大，故按 tag 版本取最大者，而非取第 0 条。
function pickLatestRelease(array $releases, $channel) {
    $channel = normalizeUpdateChannel($channel);
    $cands = [];
    foreach ($releases as $r) {
        if (!is_array($r) || empty($r['tag_name'])) continue;
        if (!empty($r['draft'])) continue;
        if ($channel === 'stable' && !empty($r['prerelease'])) continue;
        $ver = ltrim((string)$r['tag_name'], 'v');
        if ($ver === '') continue;
        $ts = strtotime((string)($r['published_at'] ?? ''));
        $cands[] = ['r' => $r, 'ver' => $ver, 'ts' => $ts ?: 0, 'pkg' => releaseHasPackage($r)];
    }
    if (!$cands) return null;
    // v5.4.7：优先在「带可用更新包」的 Release 中挑选——避免选中没有资产的条目（点了更新却拿不到包）
    $pool = array_values(array_filter($cands, function ($c) { return $c['pkg']; }));
    if (!$pool) $pool = $cands;
    if ($channel === 'beta') {
        // v5.4.7：beta 通道按「最新发布」取（published_at 优先，缺失时回退语义化版本）。
        //   修复：语义化比较下 5.4.6 > 5.4.6-beta，旧逻辑会一直选中"同号正式版"，
        //   导致站在 5.4.6 正式版上切到 beta 后「检查更新」显示"已是最新"、拿不到 5.4.6-beta。
        usort($pool, function ($a, $b) {
            if ($a['ts'] !== $b['ts']) return ($b['ts'] <=> $a['ts']);
            return ysmCompareVersion($b['ver'], $a['ver']);
        });
        return $pool[0]['r'];
    }
    // stable：保持既有语义——按语义化版本取最高（上游已用 /releases/latest 只认正式版）
    $best = null;
    $bestVer = null;
    foreach ($pool as $c) {
        if ($bestVer === null || ysmCompareVersion($c['ver'], $bestVer) > 0) {
            $best = $c['r'];
            $bestVer = $c['ver'];
        }
    }
    return $best;
}

/** v5.4.7：Release 是否含可用更新包（-full / -inc / -to-vX-inc） */
function releaseHasPackage($r) {
    if (empty($r['assets']) || !is_array($r['assets'])) return false;
    foreach ($r['assets'] as $a) {
        $n = (string)($a['name'] ?? '');
        if (preg_match('/-(full|inc)\.(tar\.gz|zip)$/i', $n)) return true;
        if (preg_match('/-to-v[\d.]+-inc\./i', $n)) return true;
    }
    return false;
}

// v5.3.0：把一条 Release 归一化为检查结果（含触发更新所需的包信息）
function buildUpdateResult($release, $channel) {
    $latest = ltrim((string)($release['tag_name'] ?? ''), 'v');
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
    // v5.4.7：同号跨通道互转（如 5.4.7(stable) ↔ 5.4.7-beta(beta)，语义化视为"版本等同"）也应视为"有可用更新"——
    //   否则站在同号正式版上切到 beta 通道后，「检查更新」会显示"已是最新"而拿不到同号 beta（必须全量包，判定交给 ysmCanUpdate）。
    $updateDecision = ysmCanUpdate(APP_VERSION, $latest, 'full', $channel);
    $verCmp = ysmCompareVersion($latest, APP_VERSION);
    $sameCross = ($latest !== APP_VERSION && ysmCompareCore($latest, APP_VERSION) === 0);
    $available = ($verCmp > 0) || ($sameCross && !empty($updateDecision['allowed']));
    return [
        'available' => $available,
        'latest_version' => $latest,
        'current_version' => APP_VERSION,
        'release_notes' => $release['body'] ?? '',
        'download_url' => $release['zipball_url'] ?? '',
        'published_at' => $release['published_at'] ?? '',
        'source' => 'github',
        'packages' => $packages,
        // v5.3.0：仅「追加」字段（既有字段语义不变）——本次判定的通道与是否预发布
        'channel' => normalizeUpdateChannel($channel),
        'prerelease' => !empty($release['prerelease']),
        // v5.3.0：与更新器（apply-update）同一套判定（ysmCanUpdate）——供前端默认选中全量包/展示原因。
        //   检查阶段尚不知请求的真实包类型，故按「全量包可用」预判（force_full 只提示，最终以 apply-update 为准）。
        'update_decision' => $updateDecision,
    ];
}

// v5.3.0：无仓库配置 / 抓取失败时的本地降级结果（字段与既有保持一致，仅追加 channel/prerelease）
function localUpdateResult($channel) {
    return [
        'available' => false,
        'latest_version' => APP_VERSION,
        'current_version' => APP_VERSION,
        'release_notes' => '',
        'download_url' => '',
        'published_at' => '',
        'source' => 'local',
        'packages' => [],
        'channel' => normalizeUpdateChannel($channel),
        'prerelease' => false,
    ];
}

function checkForUpdates($channel = 'stable') {
    $channel = normalizeUpdateChannel($channel);
    // 从配置读取仓库 API 地址，留空则跳过更新检查
    $apiBase = appConfig('repo_api_url', '');
    if ($apiBase === '') {
        return localUpdateResult($channel);
    }
    // stable：仅取正式 Release（GitHub /releases/latest 本身排除预发布与草稿）；
    // beta：取 Releases 列表（含预发布），交由 pickLatestRelease 按语义化版本取最高者。
    $url = $channel === 'beta'
        ? rtrim($apiBase, '/') . "/releases?per_page=30"
        : rtrim($apiBase, '/') . "/releases/latest";
    // SSRF 安全抓取：一次解析 + pin IP 直连（消除 DNS rebinding TOCTOU；内网/未识别默认拒绝）
    $result = fetchHttpContent($url);
    if ($result) {
        $release = json_decode($result, true);
        if ($channel === 'beta') {
            if (is_array($release) && isset($release['tag_name'])) {
                $release = [$release];   // 兼容个别自托管实现只返回单个对象
            }
            if (is_array($release)) {
                $release = pickLatestRelease($release, $channel);
            }
        }
        // stable 走 /releases/latest：此处再兜底拒绝被误标为预发布的条目（「只认正式」失败封闭）
        if ($release && isset($release['tag_name']) && ($channel !== 'stable' || empty($release['prerelease']))) {
            return buildUpdateResult($release, $channel);
        }
    }
    // 降级：返回本地版本信息
    return localUpdateResult($channel);
}

/**
 * v5.3.0：检查更新发现「比当前版本更新」时，向超管邮箱发送通知邮件。
 *  - 复用既有 SMTP 发送函数 sendSmtpMail 与统一 HTML 邮件模板 renderMailHtml；
 *  - 去重：config 表 update_notified_version 记录「已成功通知到」的版本，同一新版本只通知一次；
 *  - 无超管邮箱 / 无 SMTP：不静默——写 alert.log 落盘告警（复用 logAlertFail），不记录版本（下次检查仍会告警）；
 *  - 触发点：超管后台「在线更新」的检查更新入口（唯一人工检查入口，确定性高、无需新增常驻定时任务）。
 * @return bool true = 本次已成功发信并记录去重
 */
function notifyUpdateAvailable($result, $channel = 'stable') {
    if (empty($result['available'])) return false;
    $latest = trim((string)($result['latest_version'] ?? ''));
    $current = trim((string)($result['current_version'] ?? APP_VERSION));
    if ($latest === '' || ysmCompareVersion($latest, $current) <= 0) return false;
    $channel = normalizeUpdateChannel($channel);
    $config = loadSiteConfig();
    // 去重：同一新版本只通知一次
    if (trim((string)($config['update_notified_version'] ?? '')) === $latest) return false;
    $adminEmail = trim((string)($config['admin_email'] ?? ''));
    $smtp = getSmtpConfig();
    $smtpReady = ($smtp['host'] !== '' && $smtp['user'] !== '' && $smtp['pass'] !== '');
    if ($adminEmail === '' || !$smtpReady) {
        logAlertFail("发现新版本 v{$latest}（通道 {$channel}）但更新通知邮件不可用：" . ($adminEmail === '' ? '未配置超管邮箱' : 'SMTP 未配置'));
        return false;
    }
    $site = $config['site_title'] ?? 'You Super Markdown';
    $notes = trim((string)($result['release_notes'] ?? ''));
    if (mb_strlen($notes) > 800) $notes = mb_substr($notes, 0, 800) . '…';
    $chLabel = $channel === 'beta' ? '测试版（含预发布）' : '正式版';
    $now = date('Y-m-d H:i:s');
    $subject = "[{$site} 通知] 发现新版本 v{$latest}";
    $body = "检查更新发现新版本：\n"
          . "当前版本：v{$current}\n"
          . "最新版本：v{$latest}\n"
          . "更新通道：{$chLabel}\n"
          . ($notes !== '' ? "\n【版本说明】\n{$notes}\n" : '')
          . "\n请登录超管后台「在线更新」查看，并在 SSH 执行 sudo ysm-admin apply-update 完成升级（升级前自动备份、强制验签）。";
    $html = renderMailHtml($site, "发现新版本 v{$latest}", $body, ['server' => gethostname(), 'time' => $now]);
    [$ok, $err] = sendSmtpMail($adminEmail, $subject, $body, $html);
    if ($ok) {
        $config['update_notified_version'] = $latest;
        saveSiteConfig($config);
        return true;
    }
    logAlertFail("更新通知邮件发送失败(v{$latest}): {$err}");
    return false;
}

// ============================================================
// v5.3.0：更新历史（data/articles/更新历史.md）「同版本覆盖」写入 + 一次性去重清理
// 背景：旧逻辑仅用松匹配（strpos "## v<ver>"）+ 前插，同一版本换包重跑会在文件顶部
//       产生多个同名小节，出现「同一版本多条 / 顶部重复」。此处改为「按版本小节覆盖」，
//       保持「最新在前」，写入幂等；另提供一次性去重清理（先备份、只动重复小节）。
// ============================================================

// 生成一个版本小节文本（统一换行：## v<ver>\n\n<changelog>\n\n）
function renderChangelogSection($ver, $changelog) {
    return "## v" . trim((string)$ver) . "\n\n" . rtrim((string)$changelog) . "\n\n";
}

// 把「更新历史」正文拆成 [前言, 小节数组]；小节 = ['ver'=>版本号(不含 v), 'text'=>含标题的整段文本]
// 仅识别「整行即 ## v<版本>」的标题（严格匹配：避免 changelog 正文里的 ### 小标题或普通 ## 被误判）
function splitChangelogDoc($content) {
    $content = (string)$content;
    if (!preg_match_all('/^##[ \t]+v?([0-9][0-9A-Za-z._-]*)[ \t]*$/m', $content, $m, PREG_OFFSET_CAPTURE)) {
        return [$content, []];
    }
    $heads = $m[0];
    $vers  = $m[1];
    $preamble = substr($content, 0, $heads[0][1]);
    $sections = [];
    $n = count($heads);
    for ($i = 0; $i < $n; $i++) {
        $start = $heads[$i][1];
        $end = ($i + 1 < $n) ? $heads[$i + 1][1] : strlen($content);
        $sections[] = ['ver' => $vers[$i][0], 'text' => substr($content, $start, $end - $start)];
    }
    return [$preamble, $sections];
}

// 合并正文：目标版本「覆盖并置顶」（最新在前）；其它小节相对顺序保持不变。
// v5.3.2：修复「同版本已存在 → 原地覆盖但不置顶」——导致「最顶上的不是当前部署版本」。
// 现语义：无论目标版本此前是否存在，一律把该版本小节移动到最前（紧随前言/引言之后），
//          内容用本次 changelog 覆盖；已存在多个同名小节时合并为一份（顺带去重）。
// 幂等：同 (ver, changelog) 连续执行两次，结果完全一致（第二次目标节已在最前 → 原样返回）。
function mergeChangelogSection($content, $ver, $changelog) {
    $ver = trim((string)$ver);
    [$preamble, $sections] = splitChangelogDoc($content);
    $preamble = rtrim($preamble);
    $preamble = ($preamble === '') ? '' : $preamble . "\n\n";
    $section = renderChangelogSection($ver, $changelog);
    $others = [];
    foreach ($sections as $s) {
        if ($s['ver'] === $ver) { continue; } // 命中目标版本：不再保留原位，统一用新内容置顶（顺带去重）
        $others[] = $s['text'];
    }
    return $preamble . $section . implode('', $others);
}

// 一次性去重：同一版本只保留「最靠前」的一份（最新在前，顶部即最近一次写入），其余删除。
// 返回 [去重后正文, 被删除的小节数]。纯函数，不触碰文件；非重复小节与前言原样保留。
function dedupeChangelogSections($content) {
    [$preamble, $sections] = splitChangelogDoc($content);
    $seen = [];
    $out = [];
    $removed = 0;
    foreach ($sections as $s) {
        if (isset($seen[$s['ver']])) { $removed++; continue; }
        $seen[$s['ver']] = true;
        $out[] = $s['text'];
    }
    return [$preamble . implode('', $out), $removed];
}

// 写入「更新历史」文章（覆盖式、幂等）：文章不存在则建骨架。返回 true=内容确有变化。
// v5.3.2：写入前先把现有文章备份为同目录 .changelog-bak-<时间戳>（覆盖前先备份，可回退）。
function injectChangelogIntoArticle($artPath, $ver, $changelog) {
    $dir = dirname($artPath);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_file($artPath)) {
        $meta = json_encode(['title' => '更新历史', 'category' => '系统', 'tags' => '更新,版本,日志', 'author' => '系统', 'hidden' => true], JSON_UNESCAPED_UNICODE);
        @file_put_contents($artPath, "<!--META {$meta} -->\n\n# 更新历史\n\n> 全部版本更新记录，最新在前。\n\n", LOCK_EX);
    }
    $content = (string)@file_get_contents($artPath);
    $new = mergeChangelogSection($content, $ver, $changelog);
    if ($new === $content) return false;
    // 覆盖前先备份（同目录 .changelog-bak-<时间戳>），保留可回退副本
    $backup = $artPath . '.changelog-bak-' . date('YmdHis');
    if (!@copy($artPath, $backup)) {
        return false; // 备份失败 → 不覆盖（保持一致性与可回退）
    }
    return (bool)@file_put_contents($artPath, $new, LOCK_EX);
}

// 一次性清理：先备份（同目录 .dedup-bak-<时间戳>），仅重写重复小节，其它内容原样保留。
// 返回 ['ok'=>bool, 'removed'=>int, 'backup'=>string, 'path'=>string, 'error'=>string]
function cleanupChangelogArticle($artPath) {
    if (!is_file($artPath)) {
        return ['ok' => false, 'removed' => 0, 'backup' => '', 'path' => $artPath, 'error' => '文章不存在'];
    }
    $content = (string)@file_get_contents($artPath);
    [$new, $removed] = dedupeChangelogSections($content);
    if ($removed <= 0 || $new === $content) {
        return ['ok' => true, 'removed' => 0, 'backup' => '', 'path' => $artPath, 'error' => ''];
    }
    $backup = $artPath . '.dedup-bak-' . date('YmdHis');
    if (!@copy($artPath, $backup)) {
        return ['ok' => false, 'removed' => 0, 'backup' => '', 'path' => $artPath, 'error' => '备份失败，已中止清理（未改动原文件）'];
    }
    if (!@file_put_contents($artPath, $new, LOCK_EX)) {
        return ['ok' => false, 'removed' => 0, 'backup' => $backup, 'path' => $artPath, 'error' => '写入失败（备份已保留，可用备份还原）'];
    }
    return ['ok' => true, 'removed' => $removed, 'backup' => $backup, 'path' => $artPath, 'error' => ''];
}

// v5.3.0：邮件 / 通知链路体检（供 ysm-admin check|doctor 使用）。
// 说明：php-fpm 是否注入 YSM_SMTP_PASS 属服务器环境检查，在 CLI 侧读取 php-fpm pool 配置完成。
function systemMailHealth() {
    $config = loadSiteConfig();
    $adminEmail = trim((string)($config['admin_email'] ?? ''));
    $smtp = getSmtpConfig();
    return [
        'admin_email' => $adminEmail,
        'admin_email_set' => ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL) !== false),
        'smtp_host' => $smtp['host'],
        'smtp_user' => $smtp['user'],
        'smtp_port' => (int)$smtp['port'],
        'smtp_enc' => $smtp['enc'],
        'smtp_pass_source' => smtpPassSource(), // env / config / file / none
        'smtp_ready' => ($smtp['host'] !== '' && $smtp['user'] !== '' && $smtp['pass'] !== ''),
    ];
}

