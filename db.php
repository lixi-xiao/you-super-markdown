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
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_fails (ip TEXT, t INTEGER, acc TEXT)');
    // v2.11.0：老库 login_fails 无 acc 列（登录失败按账号维度计数），幂等补齐
    try { $pdo->exec('ALTER TABLE login_fails ADD COLUMN acc TEXT'); } catch (Exception $e) { /* 列已存在 */ }
    $pdo->exec('CREATE TABLE IF NOT EXISTS reg_rates (ip TEXT, t INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS comment_rates (ip TEXT, t INTEGER)');
    // v2.11.0：登录锁定表（60 秒内同 IP 或同账号失败 ≥3 次 → 锁 15 分钟，IP+账号双级）
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_locks (
        key TEXT PRIMARY KEY,
        locked_until INTEGER
    )');
    // v2.11.0：WebAuthn 设备凭据表（电脑 Windows Hello PIN / 手机指纹等平台认证器快速登录）
    $pdo->exec('CREATE TABLE IF NOT EXISTS webauthn_credentials (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT,
        credential_id TEXT UNIQUE,
        public_key TEXT,
        counter INTEGER DEFAULT 0,
        device_name TEXT,
        created INTEGER,
        last_used INTEGER
    )');
    // v2.5.4 性能优化：频率计数表索引
    // (ip, t) 复合索引加速 db_rate_count() 的按 IP 窗口计数；t 单列索引加速 30 天过期清理
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_fails_ip_t ON login_fails(ip, t)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_fails_t ON login_fails(t)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_fails_acc ON login_fails(acc)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reg_rates_ip_t ON reg_rates(ip, t)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reg_rates_t ON reg_rates(t)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comment_rates_ip_t ON comment_rates(ip, t)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comment_rates_t ON comment_rates(t)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_webauthn_user ON webauthn_credentials(user_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS logs (ip TEXT, action TEXT, time TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS unauthorized (
        ip TEXT, action TEXT, user TEXT, user_id TEXT, ua TEXT, time TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)');
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
