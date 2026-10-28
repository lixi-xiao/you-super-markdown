<?php
// ============================================================
// You Super Markdown — SQLite 数据访问层（v5.0.0）
// 单一 PDO 连接 + schema 建表 + 通用查询辅助。
// 数据文件：data/ysm.db（WAL 模式）；articles/*.md 仍为文件。
// v5.0.0：schema 一次性成型（不再有 ALTER TABLE 增量补丁）；4.x 老库请用 ysm-migrate 迁移。
// 注意：本文件被 utils.php require_once，所有读写函数复用同一个连接。
// ============================================================

define('YSM_DB_FILE', __DIR__ . '/data/ysm.db');

/**
 * 获取 PDO 单例（含 schema 初始化）
 * @return PDO
 */
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(YSM_DB_FILE);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $pdo = new PDO('sqlite:' . YSM_DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        db_init_schema($pdo);
    }
    return $pdo;
}

/**
 * 建立全部表结构（幂等）
 */
function db_init_schema($pdo) {
    // v5.0.0：schema 一次成型——原 qq 列更名 account；email/disabled/last_login/login_count/tv 直接内建，
    // 不再使用 ALTER TABLE 增量补丁（4.x 老库的补列由 ysm-migrate 负责）。
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id TEXT PRIMARY KEY,
        account TEXT UNIQUE,
        nickname TEXT,
        password TEXT,
        avatar TEXT,
        signature TEXT,
        role TEXT,
        station_id TEXT,
        created TEXT,
        created_by TEXT,
        email TEXT,
        disabled INTEGER DEFAULT 0,
        last_login TEXT,
        login_count INTEGER DEFAULT 0,
        tv INTEGER DEFAULT 0,
        home_font TEXT,
        home_lang TEXT,
        username TEXT
    )');
    // v4.5.0：refresh token 表（双 token 短时效：登录态过期后用 refresh 自动续期，
    // 绑定环境指纹 + token_version，换环境/踢旧后失效）
    $pdo->exec('CREATE TABLE IF NOT EXISTS refresh_tokens (
        id TEXT PRIMARY KEY,
        user_id TEXT,
        token_hash TEXT UNIQUE,
        fp TEXT,
        tv INTEGER DEFAULT 0,
        expires INTEGER,
        created INTEGER,
        revoked INTEGER DEFAULT 0,
        last_used INTEGER
    )');
    // v6.0.0：旧库 refresh_tokens 可能缺 user_id/expires——幂等补列后再建索引
    $rtCols = [];
    foreach ($pdo->query('PRAGMA table_info(refresh_tokens)') as $c) { $rtCols[] = strtolower($c['name']); }
    if (!in_array('user_id', $rtCols, true)) { $pdo->exec('ALTER TABLE refresh_tokens ADD COLUMN user_id TEXT'); }
    if (!in_array('expires', $rtCols, true)) { $pdo->exec('ALTER TABLE refresh_tokens ADD COLUMN expires INTEGER'); }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_refresh_tokens_user ON refresh_tokens(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_refresh_tokens_expires ON refresh_tokens(expires)');
    // v6.0.0（E）：refresh_tokens 幂等补 sfp 列（已信任设备的稳定指纹子集；未升级则保持旧行为）
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(refresh_tokens)') as $c) { $cols[] = strtolower($c['name']); }
    if (!in_array('sfp', $cols, true)) {
        $pdo->exec('ALTER TABLE refresh_tokens ADD COLUMN sfp TEXT');
    }
    // v6.0.0：users 幂等补列（home_font 账号默认首页字体 / home_lang 账号基础语言；老库自动升级）
    $ucols = [];
    foreach ($pdo->query('PRAGMA table_info(users)') as $c) { $ucols[] = strtolower($c['name']); }
    if (!in_array('home_font', $ucols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN home_font TEXT DEFAULT ''");
    }
    if (!in_array('home_lang', $ucols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN home_lang TEXT DEFAULT ''");
    }
    // v6.2.0：用户名（登录 ID，独立于昵称）。补列 → 回填 → 去重 → 唯一索引（大小写不敏感）
    if (!in_array('username', $ucols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN username TEXT DEFAULT ''");
    }
    $need = $pdo->query("SELECT id, account FROM users WHERE username IS NULL OR username = '' ORDER BY rowid")->fetchAll();
    if ($need) {
        $used = [];
        foreach ($pdo->query("SELECT username FROM users WHERE username IS NOT NULL AND username <> ''") as $r) {
            $used[strtolower((string)$r['username'])] = true;
        }
        $upd = $pdo->prepare('UPDATE users SET username = ? WHERE id = ?');
        foreach ($need as $r) {
            $base = strtolower(trim((string)($r['account'] ?? '')));
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{2,19}$/', $base)) {
                $base = 'u_' . substr(sha1((string)$r['id']), 0, 8);
            }
            $cand = $base;
            $i = 2;
            while (isset($used[$cand])) {
                $cand = substr($base, 0, 16) . '_' . $i;
                $i++;
            }
            $used[$cand] = true;
            $upd->execute([$cand, $r['id']]);
        }
    }
    // v6.2.0：用户名唯一索引（大小写不敏感）。partial index 排除空值，避免多个「未设置用户名」互相冲突
    $idxDone = $pdo->query("SELECT value FROM schema_meta WHERE key = 'v620_username_index'")->fetchColumn();
    if ($idxDone === false) {
        try { $pdo->exec('DROP INDEX IF EXISTS users_username_ci'); } catch (Throwable $e) {}
        try {
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS users_username_ci ON users(lower(username)) WHERE username IS NOT NULL AND username <> ''");
            $pdo->exec("INSERT OR REPLACE INTO schema_meta (key, value) VALUES ('v620_username_index', '1')");
        } catch (Throwable $e) {
            // 极端情况下（历史数据已存在同名）不阻断站点；由 ysm-admin migrate-usernames 修复后再建索引
            @error_log('username unique index failed: ' . $e->getMessage());
        }
    }
    // v6.0.0：友站（友链）表——站长专属管理，访客只读 active
    $pdo->exec('CREATE TABLE IF NOT EXISTS links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_name TEXT, url TEXT UNIQUE, favicon TEXT,
        desc TEXT, status TEXT DEFAULT \'active\',
        place_time INTEGER, last_check INTEGER, check_count INTEGER DEFAULT 0,
        sort INTEGER DEFAULT 0, source TEXT DEFAULT \'manual\'
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_links_status_sort ON links(status, sort)');
    // v6.3.0：友链申请（前台提交 → 邮件通知站长 → 审核入站）
    $pdo->exec('CREATE TABLE IF NOT EXISTS link_applies (
        id TEXT PRIMARY KEY,
        site_name TEXT, url TEXT, desc TEXT, favicon TEXT, email TEXT,
        status TEXT DEFAULT \'pending\',
        token TEXT, token_expires INTEGER,
        ip TEXT, created TEXT, decided_at TEXT, decided_by TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_link_applies_status ON link_applies(status, created)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_link_applies_token ON link_applies(token)');
    // v2.9.0：邮箱验证码表（注册 / 写作者验证 / 超管确认链路）
    $pdo->exec('CREATE TABLE IF NOT EXISTS email_codes (
        id TEXT PRIMARY KEY,
        email TEXT,
        code TEXT,
        purpose TEXT,
        expires INTEGER,
        used INTEGER DEFAULT 0,
        created INTEGER,
        ip TEXT,
        operator_role TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_codes_email ON email_codes(email)');
    // v4.7.0：已信任设备指纹（管理角色陌生设备登录邮件二次验证；fp_hash 为环境指纹+UA 的 SHA256，非敏感）
    $pdo->exec('CREATE TABLE IF NOT EXISTS device_fps (
        id TEXT PRIMARY KEY,
        user_id TEXT,
        fp_hash TEXT,
        ua TEXT,
        first_seen INTEGER,
        last_seen INTEGER,
        UNIQUE (user_id, fp_hash)
    )');
    // v6.0.0：旧库 device_fps 可能缺 user_id（历史结构差异）——先幂等补列再建索引，避免升级后首页 500
    $dfCols = [];
    foreach ($pdo->query('PRAGMA table_info(device_fps)') as $c) { $dfCols[] = strtolower($c['name']); }
    if (!in_array('user_id', $dfCols, true)) { $pdo->exec('ALTER TABLE device_fps ADD COLUMN user_id TEXT'); }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_device_fps_user ON device_fps(user_id)');
    // v4.7.0：联动威胁评分事件流水（维度=ip|fp，滑动窗口求和；超阈值触发联动封锁）
    $pdo->exec('CREATE TABLE IF NOT EXISTS threat_events (
        id TEXT PRIMARY KEY,
        dim_type TEXT,
        dim_key TEXT,
        weight INTEGER,
        reason TEXT,
        created INTEGER
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_threat_dim ON threat_events(dim_type, dim_key, created)');
    // v2.10.0-fix：JWT jti 吊销黑名单（登出即吊销，防 session 残留导致超管会话复活）
    $pdo->exec('CREATE TABLE IF NOT EXISTS jwt_blacklist (
        jti TEXT PRIMARY KEY,
        expires INTEGER,
        created INTEGER
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jwt_blacklist_expires ON jwt_blacklist(expires)');
    // v2.9.0：站长创建写作者双重确认中间态表
    $pdo->exec('CREATE TABLE IF NOT EXISTS pending_author_creates (
        id TEXT PRIMARY KEY,
        email TEXT,
        nickname TEXT,
        account TEXT,
        username TEXT,
        password_hash TEXT,
        station_id TEXT,
        verify_code_id TEXT,
        confirm_token TEXT,
        status TEXT,
        created INTEGER,
        confirmed_at TEXT
    )');
    // v6.2.0：pending 表补 username（老库）
    $pcols = [];
    foreach ($pdo->query('PRAGMA table_info(pending_author_creates)') as $c) { $pcols[] = strtolower($c['name']); }
    if (!in_array('username', $pcols, true)) {
        $pdo->exec('ALTER TABLE pending_author_creates ADD COLUMN username TEXT');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS config (
        key TEXT PRIMARY KEY,
        value TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS bans (
        ip TEXT PRIMARY KEY,
        types_json TEXT,
        reason TEXT,
        time TEXT,
        expires INTEGER DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS audit (
        id TEXT PRIMARY KEY,
        ts TEXT,
        user_id TEXT,
        user_name TEXT,
        role TEXT,
        ip TEXT,
        action TEXT,
        target TEXT,
        detail TEXT,
        result TEXT,
        hash TEXT,
        prev_hash TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS comments (
        id TEXT PRIMARY KEY,
        article TEXT,
        parent_id TEXT,
        user_id TEXT,
        account TEXT,
        nickname TEXT,
        avatar TEXT,
        signature TEXT,
        content TEXT,
        likes INTEGER DEFAULT 0,
        created_at TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comments_article ON comments(article)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS entries (
        id TEXT PRIMARY KEY,
        token TEXT,
        otp_hash TEXT,
        expires INTEGER,
        used INTEGER DEFAULT 0,
        created TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS challenge (
        id TEXT PRIMARY KEY,
        code TEXT,
        expires INTEGER,
        used INTEGER DEFAULT 0,
        created INTEGER
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS pinned (
        article TEXT PRIMARY KEY
    )');
    // v3.1.6：公告表（站长后台「公告管理」tab 全权管理；type=update 为 apply-update 自动写入的更新公告）
    //   id: 唯一 ID；type: manual(站长手动) / update(更新公告)；article: 关联文章文件名（可空）；
    //   author_id: 创建人；title: 公告标题；summary: 摘要/更新描述；date: 发布日期；order: 排序（越小越前）
    $pdo->exec('CREATE TABLE IF NOT EXISTS announcement (
        id TEXT PRIMARY KEY,
        type TEXT DEFAULT \'manual\',
        article TEXT,
        author_id TEXT,
        title TEXT,
        summary TEXT,
        body TEXT,
        date TEXT,
        ord INTEGER DEFAULT 0
    )');
    // v5.0.0：公告表已将 body（markdown 正文）内建到 CREATE TABLE，不再 ALTER 补齐。
    // v5.0.0：限速表一次性内建 acc/fp 列（登录失败按 IP+账号+指纹计数；指纹+IP 双维限速）。
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_fails (ip TEXT, t INTEGER, acc TEXT, fp TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS reg_rates (ip TEXT, t INTEGER, fp TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS comment_rates (ip TEXT, t INTEGER, fp TEXT)');
    // v4.4.0：注册蜜罐触发计数表（短时间连续命中蜜罐 → 自动封禁 IP）
    $pdo->exec('CREATE TABLE IF NOT EXISTS honeypot_rates (ip TEXT, t INTEGER, fp TEXT)');
    // v4.7.4：音乐接口出站限速表（第三方 API 聚合接口，防滥用放大外呼；fp 列与 db_rate_add 对齐）
    $pdo->exec('CREATE TABLE IF NOT EXISTS music_rates (ip TEXT, fp TEXT, t INTEGER)');
    // v2.11.0：登录锁定表（60 秒内同 IP 或同账号失败 ≥3 次 → 锁 15 分钟，IP+账号双级）
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_locks (
        key TEXT PRIMARY KEY,
        locked_until INTEGER
    )');
    // v2.5.4 性能优化：频率计数表索引
    // (ip, t) 复合索引加速 db_rate_count() 的按 IP 窗口计数；t 单列索引加速 30 天过期清理
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_fails_ip_t ON login_fails(ip, t)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_fails_t ON login_fails(t)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_fails_acc ON login_fails(acc)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reg_rates_ip_t ON reg_rates(ip, t)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reg_rates_t ON reg_rates(t)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_honeypot_rates_ip_t ON honeypot_rates(ip, t)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_honeypot_rates_t ON honeypot_rates(t)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comment_rates_ip_t ON comment_rates(ip, t)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comment_rates_t ON comment_rates(t)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS logs (ip TEXT, action TEXT, time TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS unauthorized (
        ip TEXT, action TEXT, user TEXT, user_id TEXT, ua TEXT, time TEXT
    )');
    // v5.4.11：联动封锁告警汇总队列——L1/L1.5/L2 入队由守护进程每小时合并发一封（无事件不发）；L3 永久封禁仍即时通知
    $pdo->exec('CREATE TABLE IF NOT EXISTS alert_digest (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        dim_type TEXT, dim_key TEXT, level TEXT, score INTEGER,
        summary TEXT, created INTEGER, sent INTEGER DEFAULT 0
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_alert_digest_sent ON alert_digest(sent)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)');
    // v5.0.0：schema 版本表（迁移器据 schema_version/epoch 判断库结构版本，与业务 meta 分离）
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_meta (key TEXT PRIMARY KEY, value TEXT)');
    // v4.0.0：站内访问统计——page_views 每文章累计 PV；views_log 按 文章+IP+日期 去重计数防刷
    $pdo->exec('CREATE TABLE IF NOT EXISTS page_views (
        article TEXT PRIMARY KEY,
        views INTEGER DEFAULT 0,
        updated TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS views_log (
        article TEXT,
        ip TEXT,
        day TEXT,
        PRIMARY KEY (article, ip, day)
    )');
    // v4.0.0：评论邮件订阅设置（key 复用 config 表，无需新表）
    // v5.0.0：写入 schema 版本标记（s=5.0.0，epoch=2；迁移器据此判断是否需要升级）
    // v5.0.0 P7（审计链加固）：全新安装即使用新 HMAC 链，故 epoch=2（epoch1=旧 sha256 链，epoch2=新 HMAC 链）
    $pdo->exec("INSERT OR REPLACE INTO schema_meta (key, value) VALUES ('schema_version', '5.0.0')");
    $pdo->exec("INSERT OR REPLACE INTO schema_meta (key, value) VALUES ('epoch', '2')");
}

/**
 * 执行查询，返回全部行（默认 FETCH_ASSOC）
 */
function db_all($sql, $params = []) {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * 执行查询，返回单行或 null
 */
function db_one($sql, $params = []) {
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/**
 * 执行写操作，返回受影响行数
 */
function db_exec($sql, $params = []) {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}
