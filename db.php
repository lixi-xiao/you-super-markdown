<?php
// ============================================================
// You Super Markdown — SQLite 数据访问层（v2.5.0）
// 单一 PDO 连接 + schema 建表 + 通用查询辅助。
// 数据文件：data/ym.db（WAL 模式）；articles/*.md 仍为文件。
// 注意：本文件被 utils.php require_once，所有读写函数复用同一个连接。
// ============================================================

define('YM_DB_FILE', __DIR__ . '/data/ym.db');

/**
 * 获取 PDO 单例（含 schema 初始化）
 * @return PDO
 */
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(YM_DB_FILE);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $pdo = new PDO('sqlite:' . YM_DB_FILE);
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
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id TEXT PRIMARY KEY,
        qq TEXT UNIQUE,
        nickname TEXT,
        password TEXT,
        avatar TEXT,
        signature TEXT,
        role TEXT,
        station_id TEXT,
        created TEXT,
        created_by TEXT
    )');
    // v2.9.0：users 表新增 email 字段（注册邮箱验证用；老用户无邮箱不受影响）。
    // SQLite 的 ADD COLUMN 不支持 UNIQUE 约束，邮箱唯一性由应用层检查保证。
    try {
        $pdo->exec('ALTER TABLE users ADD COLUMN email TEXT');
    } catch (Exception $e) {
        // 列已存在（幂等），忽略
    }
    // v2.11.4：users 表新增 disabled 字段（超管禁用账号开关，1=已禁用；禁用后无法登录/评论/进后台）
    try {
        $pdo->exec('ALTER TABLE users ADD COLUMN disabled INTEGER DEFAULT 0');
    } catch (Exception $e) {
        // 列已存在（幂等），忽略
    }
    // v2.11.5：users 表新增 last_login / login_count（超管用户详情统计：最后登录时间与登录次数）
    try {
        $pdo->exec('ALTER TABLE users ADD COLUMN last_login TEXT');
    } catch (Exception $e) {
        // 列已存在（幂等），忽略
    }
    try {
        $pdo->exec('ALTER TABLE users ADD COLUMN login_count INTEGER DEFAULT 0');
    } catch (Exception $e) {
        // 列已存在（幂等），忽略
    }
    // v4.5.0：users 表新增 tv 字段（token_version，同账号并发踢旧——每次登录 +1，
    // 旧会话/token 携带的 tv 与新值不符即失效）
    try {
        $pdo->exec('ALTER TABLE users ADD COLUMN tv INTEGER DEFAULT 0');
    } catch (Exception $e) {
        // 列已存在（幂等），忽略
    }
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
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_refresh_tokens_user ON refresh_tokens(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_refresh_tokens_expires ON refresh_tokens(expires)');
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
        qq TEXT,
        password_hash TEXT,
        station_id TEXT,
        verify_code_id TEXT,
        confirm_token TEXT,
        status TEXT,
        created INTEGER,
        confirmed_at TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS config (
        key TEXT PRIMARY KEY,
        value TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS bans (
        ip TEXT PRIMARY KEY,
        types_json TEXT,
        reason TEXT,
        time TEXT
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
        qq TEXT,
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
    // v3.2.3：公告表补 body 列（markdown 正文，站长可上传 .md 导入；老库幂等补齐）
    try { $pdo->exec('ALTER TABLE announcement ADD COLUMN body TEXT'); } catch (Exception $e) { /* 列已存在 */ }
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_fails (ip TEXT, t INTEGER, acc TEXT)');
    // v2.11.0：老库 login_fails 无 acc 列（登录失败按账号维度计数），幂等补齐
    try { $pdo->exec('ALTER TABLE login_fails ADD COLUMN acc TEXT'); } catch (Exception $e) { /* 列已存在 */ }
    // v4.5.0：限速表补 fp 列（环境指纹维度——指纹+IP 双维限速；旧记录 fp 为空按 IP 维度兼容）
    foreach (['login_fails', 'reg_rates', 'comment_rates', 'honeypot_rates', 'music_rates'] as $rt) {
        try { $pdo->exec("ALTER TABLE {$rt} ADD COLUMN fp TEXT"); } catch (Exception $e) { /* 列已存在 */ }
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS reg_rates (ip TEXT, t INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS comment_rates (ip TEXT, t INTEGER)');
    // v4.4.0：注册蜜罐触发计数表（短时间连续命中蜜罐 → 自动封禁 IP）
    $pdo->exec('CREATE TABLE IF NOT EXISTS honeypot_rates (ip TEXT, t INTEGER)');
    // v4.7.4：音乐接口出站限速表（第三方 API 聚合接口，防滥用放大外呼；fp 列与 db_rate_add 对齐）
    $pdo->exec('CREATE TABLE IF NOT EXISTS music_rates (ip TEXT, fp TEXT, t INTEGER)');
    // v4.4.0：bans 表补 expires 列（0=永久封禁；>0=过期时间戳，老库幂等补齐）
    try { $pdo->exec('ALTER TABLE bans ADD COLUMN expires INTEGER DEFAULT 0'); } catch (Exception $e) { /* 列已存在 */ }
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
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)');
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
