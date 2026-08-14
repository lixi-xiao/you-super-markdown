#!/usr/bin/env python3
"""You Super Markdown 文件守护进程（版本见 app-config.json）
六层保活：systemd Restart + Watchdog + cron五min兜底 + inotify耗尽降级 + 自校验
功能：
  1. inotify 监控核心文件，被篡改时秒级从母本恢复
  2. 每5分钟自动校验审计日志哈希链，断裂则从镜像恢复
  3. 邮件告警通知超管
  4. systemd watchdog 心跳
"""

import os
import sys
import json
import glob
import tarfile
import tempfile
import hashlib
import shutil
import sqlite3
import time
import signal
import subprocess
import threading
from pathlib import Path

# === 配置 ===
WEB_ROOT = os.environ.get('YM_WEB_ROOT', '/var/www/you-markdown')
INSTALL_BASE = '/opt/you-markdown/install-base'
DB_FILE = os.path.join(WEB_ROOT, 'data', 'ym.db')
AUDIT_CHAIN = os.path.join(WEB_ROOT, 'data', '.audit_chain')
AUDIT_MIRROR_DB = '/opt/you-markdown/logs/ym.db'
AUDIT_MIRROR_CHAIN = '/opt/you-markdown/logs/audit_chain'
GUARD_STATE = '/opt/you-markdown/guard-state.json'
EMAIL_ALERT_BIN = '/usr/local/bin/ym-alert'
ALERT_LOG = '/opt/you-markdown/alert.log'  # 告警发送失败日志（v2.8.0 可追溯）
WATCHDOG_USEC = int(os.environ.get('WATCHDOG_USEC', 0)) / 1_000_000  # systemd watchdog 间隔（秒）

# === 自动备份配置（backup.conf，root:www-data 664；守护进程读取）===
BACKUP_DIR = '/opt/you-markdown/backups'
BACKUP_DB_DIR = os.path.join(BACKUP_DIR, 'db')
BACKUP_ARTICLES_DIR = os.path.join(BACKUP_DIR, 'articles')
BACKUP_CONF = '/opt/you-markdown/backup.conf'
DB_BACKUP_FILE = os.path.join(BACKUP_DB_DIR, 'ym-db-latest.tar.gz')  # 数据库备份：固定 1 份滚动
ARTICLES_DIR = os.path.join(WEB_ROOT, 'data', 'articles')
DEFAULT_BACKUP_CONF = {'DB_BACKUP_INTERVAL_MIN': '30', 'ARTICLE_BACKUP_KEEP': '7', 'MANUAL_BACKUP_KEEP': '5'}

# 核心监控文件列表（相对 WEB_ROOT 的路径）
# 注：仅监控代码类文件；data/ 数据文件不在此列（母本排除 data/，无对照，由哈希链/镜像负责审计完整性）
WATCH_FILES = [
    'index.php',
    'api.php',
    'utils.php',
    'db.php',
    'admin/entry.php',
    'admin/dashboard.php',
    'station/dashboard.php',
    'author/dashboard.php',
]

# 更新锁文件
UPDATE_LOCK = '/tmp/ym-update.lock'

# 校验间隔（秒）
AUDIT_CHECK_INTERVAL = 300  # 5分钟
WATCHDOG_INTERVAL = 15       # watchdog 心跳间隔（秒）

running = True
last_audit_check = 0


def log(msg: str):
    """带时间戳的日志输出"""
    ts = time.strftime('%Y-%m-%d %H:%M:%S')
    print(f"[{ts}] {msg}", flush=True)


def file_md5(path: str) -> str:
    """计算文件 MD5"""
    try:
        with open(path, 'rb') as f:
            return hashlib.md5(f.read()).hexdigest()
    except Exception:
        return ''


def load_admin_email() -> str:
    """从 SQLite config 表读取管理员邮箱"""
    try:
        con = sqlite3.connect(DB_FILE)
        cur = con.cursor()
        cur.execute("SELECT value FROM config WHERE key='admin_email'")
        row = cur.fetchone()
        con.close()
        if row:
            v = json.loads(row[0])
            return v if isinstance(v, str) else ''
    except Exception:
        pass
    return ''


def load_app_name() -> str:
    """从 app-config.json 读取应用名称"""
    try:
        with open(os.path.join(WEB_ROOT, 'app-config.json'), 'r', encoding='utf-8') as f:
            cfg = json.load(f)
            return cfg.get('app_name', 'You Super Markdown')
    except Exception:
        return 'You Super Markdown'


def load_version() -> str:
    """从 app-config.json 读取版本号（唯一事实来源）"""
    try:
        with open(os.path.join(WEB_ROOT, 'app-config.json'), 'r', encoding='utf-8') as f:
            cfg = json.load(f)
            return cfg.get('version', '0.0.0')
    except Exception:
        return '0.0.0'


def load_smtp_config() -> dict:
    """读取 SMTP 配置（config 表，与 PHP getSmtpConfig 同源）"""
    cfg = {'host': '', 'port': 465, 'user': '', 'pass': '', 'from': '', 'enc': 'ssl'}
    try:
        con = sqlite3.connect(DB_FILE)
        cur = con.cursor()
        cur.execute("SELECT key, value FROM config WHERE key IN ('smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','smtp_enc')")
        rows = cur.fetchall()
        con.close()
        for k, v in rows:
            val = json.loads(v) if v else ''
            if k == 'smtp_port':
                cfg['port'] = int(val) if str(val).isdigit() else 465
            elif k == 'smtp_enc':
                cfg['enc'] = val if val in ('ssl', 'tls', 'plain') else 'ssl'
            else:
                cfg[k.replace('smtp_', '')] = val or ''
    except Exception:
        pass
    return cfg


def smtp_send(to: str, subject: str, body: str, smtp: dict) -> bool:
    """smtplib 直连发送（v2.8.0，无 MTA 依赖），成功返回 True"""
    try:
        import smtplib
        from email.mime.text import MIMEText
        from email.header import Header
        msg = MIMEText(body, 'plain', 'utf-8')
        msg['Subject'] = Header(subject, 'utf-8')
        frm = smtp['from'] or smtp['user']
        msg['From'] = frm
        msg['To'] = to
        if smtp['enc'] == 'ssl':
            server = smtplib.SMTP_SSL(smtp['host'], smtp['port'] or 465, timeout=15)
        else:
            server = smtplib.SMTP(smtp['host'], smtp['port'] or 587, timeout=15)
            server.ehlo()
            if smtp['enc'] == 'tls':
                server.starttls()
                server.ehlo()
        server.login(smtp['user'], smtp['pass'])
        server.sendmail(frm, [to], msg.as_string())
        server.quit()
        return True
    except Exception as e:
        log(f"SMTP 发送失败: {e}")
        return False


def log_alert_fail(detail: str):
    """告警发送失败落盘（/opt/you-markdown/alert.log，可追溯）"""
    try:
        with open(ALERT_LOG, 'a', encoding='utf-8') as f:
            f.write(f"{time.strftime('%Y-%m-%d %H:%M:%S')} [FAIL] {detail}\n")
    except Exception:
        pass


def send_alert(alert_type: str, detail: str):
    """发送邮件告警：优先 SMTP 直连（v2.8.0，无 MTA 依赖）；未配置退回 ym-alert(mail)；失败落盘 alert.log"""
    email = load_admin_email()
    if not email:
        return
    host = os.uname().nodename if hasattr(os, 'uname') else 'localhost'
    subject = f"[{load_app_name()} 告警] {alert_type}"
    body = f"时间：{time.strftime('%Y-%m-%d %H:%M:%S')}\n服务器：{host}\n事件类型：{alert_type}\n详情：{detail}\n"
    smtp = load_smtp_config()
    if smtp['host'] and smtp['user'] and smtp['pass']:
        if smtp_send(email, subject, body, smtp):
            return
        log_alert_fail(f"SMTP 发送失败({alert_type})")
        return
    if not os.path.exists(EMAIL_ALERT_BIN):
        log_alert_fail(f"ym-alert 不存在({alert_type})")
        return
    try:
        subprocess.run([EMAIL_ALERT_BIN, email, subject, body], capture_output=True, timeout=15)
    except Exception as e:
        log_alert_fail(f"ym-alert 调用失败({alert_type}): {e}")


def is_update_in_progress() -> bool:
    """检查系统是否正在更新中（守护进程暂停文件保护）"""
    try:
        if os.path.exists(UPDATE_LOCK):
            with open(UPDATE_LOCK, 'r') as f:
                data = json.load(f)
            expires = data.get('expires', 0)
            if expires > time.time():
                token = data.get('token', '')[:8]
                log(f"更新进行中，暂停文件保护 (token: {token}...)")
                return True
            else:
                os.remove(UPDATE_LOCK)
                log("更新锁已过期，恢复文件保护")
    except Exception:
        pass
    return False


def verify_and_restore_file(rel_path: str) -> bool:
    """校验单个文件，不匹配则从母本恢复"""
    if is_update_in_progress():
        return False  # 更新中，不恢复、不告警

    web_path = os.path.join(WEB_ROOT, rel_path)
    base_path = os.path.join(INSTALL_BASE, rel_path)

    if not os.path.exists(web_path):
        if os.path.exists(base_path):
            os.makedirs(os.path.dirname(web_path), exist_ok=True)
            shutil.copy2(base_path, web_path)
            log(f"恢复缺失文件: {rel_path}")
            send_alert("文件恢复", f"缺失文件已从母本恢复: {rel_path}")
            return True
        return False

    if not os.path.exists(base_path):
        return False  # 母本没有此文件，跳过校验（不计入"已恢复"）

    web_md5 = file_md5(web_path)
    base_md5 = file_md5(base_path)

    if web_md5 and base_md5 and web_md5 != base_md5:
        shutil.copy2(base_path, web_path)
        log(f"篡改检测 + 恢复: {rel_path} (MD5: {web_md5} -> {base_md5})")
        send_alert("文件篡改恢复", f"文件 {rel_path} 已被篡改，已从母本自动恢复")
        return True

    return False


def verify_all_files():
    """批量校验所有监控文件"""
    restored = 0
    for rel_path in WATCH_FILES:
        if verify_and_restore_file(rel_path):
            restored += 1
    if restored > 0:
        log(f"批量校验完成，恢复 {restored} 个文件")


def verify_audit_chain() -> bool:
    """校验审计日志哈希链（读 SQLite audit 表）"""
    if is_update_in_progress():
        return True  # 更新中，跳过哈希链校验

    if not os.path.exists(DB_FILE):
        return True

    try:
        con = sqlite3.connect(DB_FILE)
        con.row_factory = sqlite3.Row
        cur = con.cursor()
        cur.execute("SELECT id, ts, user_id, user_name, role, ip, action, target, detail, result, hash FROM audit ORDER BY rowid")
        rows = cur.fetchall()
        con.close()
    except Exception:
        return False

    prev_hash = ''
    for i, entry in enumerate(rows):
        expected = entry['hash'] or ''
        check_data = {
            'id': entry['id'],
            'ts': entry['ts'],
            'user_id': entry['user_id'],
            'user_name': entry['user_name'],
            'role': entry['role'],
            'ip': entry['ip'],
            'action': entry['action'],
            'target': entry['target'],
            'detail': entry['detail'],
            'result': entry['result'],
            'prev_hash': prev_hash,
        }
        check_json = json.dumps(check_data, ensure_ascii=False, separators=(',', ':'))
        computed = hashlib.sha256(check_json.encode()).hexdigest()
        if computed != expected:
            log(f"审计日志哈希链断裂于第 {i} 条")
            send_alert("日志哈希链断裂", f"审计日志哈希链在第 {i} 条处断裂，尝试从镜像恢复")
            recover_audit()
            return False
        prev_hash = expected

    return True


def mirror_db():
    """背书：将 data/ym.db 落盘（checkpoint）后拷贝到镜像目录（root 只读，chattr +i 锁定）"""
    try:
        con = sqlite3.connect(DB_FILE)
        con.execute('PRAGMA wal_checkpoint(TRUNCATE)')
        con.close()
        os.makedirs(os.path.dirname(AUDIT_MIRROR_DB), exist_ok=True)
        _chattr(os.path.dirname(AUDIT_MIRROR_DB), '-i')
        try:
            shutil.copy2(DB_FILE, AUDIT_MIRROR_DB)
            if os.path.exists(AUDIT_CHAIN):
                shutil.copy2(AUDIT_CHAIN, AUDIT_MIRROR_CHAIN)
        finally:
            _chattr(os.path.dirname(AUDIT_MIRROR_DB), '+i')
        return True
    except Exception as e:
        _chattr(os.path.dirname(AUDIT_MIRROR_DB), '+i')
        log(f"审计镜像背书失败: {e}")
        return False


def recover_audit():
    """从镜像 SQLite 恢复审计表与链尾（仅恢复 audit 表，不覆盖业务数据）"""
    if not os.path.exists(AUDIT_MIRROR_DB):
        return
    mdir = os.path.dirname(AUDIT_MIRROR_DB)
    # v2.8.0 修复：镜像目录 chattr +i 且 ym.db 为 WAL 模式——sqlite 打开需在目录内创建 -wal/-shm 侧车文件，
    # immutable 目录禁止创建 → SQLITE_CANTOPEN（实测「从镜像恢复审计失败: unable to open database file」）。
    # 与 mirror_db() 同模式：先解锁 → 操作 → 重锁（见踩坑 #23）
    _chattr(mdir, '-i')
    try:
        mcon = sqlite3.connect(AUDIT_MIRROR_DB)
        mcon.row_factory = sqlite3.Row
        rows = mcon.execute("SELECT * FROM audit ORDER BY rowid").fetchall()
        mcon.close()
        # 清理打开时产生的 WAL/SHM 侧车文件（防残留影响下次打开）
        for suffix in ('-wal', '-shm'):
            p = AUDIT_MIRROR_DB + suffix
            if os.path.exists(p):
                try:
                    os.remove(p)
                except Exception:
                    pass

        con = sqlite3.connect(DB_FILE)
        con.execute('DELETE FROM audit')
        con.executemany(
            'INSERT INTO audit (id,ts,user_id,user_name,role,ip,action,target,detail,result,hash,prev_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [(r['id'], r['ts'], r['user_id'], r['user_name'], r['role'], r['ip'], r['action'], r['target'], r['detail'], r['result'], r['hash'], r['prev_hash']) for r in rows]
        )
        con.commit()
        con.close()
        log("审计日志已从镜像恢复")
        send_alert("日志已恢复", "审计日志已从镜像 SQLite 副本恢复，请检查")
    except Exception as e:
        log(f"从镜像恢复审计失败: {e}")
        # v2.8.0：恢复失败是最严重场景，必须邮件告警兜底（此前仅 log，告警静默缺失）
        send_alert("日志恢复失败", f"审计日志从镜像恢复失败，请立即人工介入: {e}")
    finally:
        _chattr(mdir, '+i')
    if os.path.exists(AUDIT_MIRROR_CHAIN):
        shutil.copy2(AUDIT_MIRROR_CHAIN, AUDIT_CHAIN)


# === 自动备份 / 恢复 / 清理（2026-08-14 新增） ===
last_restore_info = ''  # 最近一次自动恢复记录（供后台展示）

def _chattr(path, flag):
    """对目录设置/清除 immutable 标志（chattr +i / -i），失败静默（非关键错误）"""
    try:
        subprocess.run(['chattr', flag, path], capture_output=True, timeout=10)
    except Exception:
        pass


def load_backup_conf() -> dict:
    """读取备份配置（/opt/you-markdown/backup.conf），缺失/非法回落默认值"""
    cfg = {
        'interval_min': 30,   # 数据库备份间隔（5~1440 分钟）
        'article_keep': 7,    # 每日文章备份保留份数（1~90）
        'manual_keep': 5,     # 手动整站备份保留份数（1~30）
    }
    try:
        with open(BACKUP_CONF, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith('#') or '=' not in line:
                    continue
                k, _, v = line.partition('=')
                k, v = k.strip(), v.strip()
                if k == 'DB_BACKUP_INTERVAL_MIN':
                    cfg['interval_min'] = int(v)
                elif k == 'ARTICLE_BACKUP_KEEP':
                    cfg['article_keep'] = int(v)
                elif k == 'MANUAL_BACKUP_KEEP':
                    cfg['manual_keep'] = int(v)
    except Exception:
        pass
    # 白名单约束
    cfg['interval_min'] = cfg['interval_min'] if 5 <= cfg['interval_min'] <= 1440 else 30
    cfg['article_keep'] = cfg['article_keep'] if 1 <= cfg['article_keep'] <= 90 else 7
    cfg['manual_keep'] = cfg['manual_keep'] if 1 <= cfg['manual_keep'] <= 30 else 5
    return cfg


def backup_db() -> bool:
    """数据库自动备份：WAL checkpoint 后打包 ym.db+.audit_chain → ym-db-latest.tar.gz（固定 1 份滚动）
    备份目录 chattr +i 锁定，写入时临时解锁、完成后立即重锁（与母本锁定同理念）"""
    try:
        con = sqlite3.connect(DB_FILE)
        con.execute('PRAGMA wal_checkpoint(TRUNCATE)')
        con.close()
        os.makedirs(BACKUP_DB_DIR, exist_ok=True)
        _chattr(BACKUP_DB_DIR, '-i')
        try:
            with tarfile.open(DB_BACKUP_FILE, 'w:gz') as t:
                t.add(DB_FILE, arcname='ym.db')
                if os.path.exists(AUDIT_CHAIN):
                    t.add(AUDIT_CHAIN, arcname='.audit_chain')
        finally:
            _chattr(BACKUP_DB_DIR, '+i')
        log(f"数据库自动备份完成: {DB_BACKUP_FILE}")
        return True
    except Exception as e:
        _chattr(BACKUP_DB_DIR, '+i')
        log(f"数据库自动备份失败: {e}")
        send_alert("数据库备份失败", f"自动备份失败: {e}")
        return False


def backup_articles() -> bool:
    """每日文章备份：打包 data/articles/ → ym-articles-YYYYMMDD.tar.gz（保留 N 份，超时清除）"""
    if not os.path.isdir(ARTICLES_DIR):
        return False
    os.makedirs(BACKUP_ARTICLES_DIR, exist_ok=True)
    pkg = os.path.join(BACKUP_ARTICLES_DIR, 'ym-articles-' + time.strftime('%Y%m%d') + '.tar.gz')
    _chattr(BACKUP_ARTICLES_DIR, '-i')
    try:
        with tarfile.open(pkg, 'w:gz') as t:
            for name in sorted(os.listdir(ARTICLES_DIR)):
                fp = os.path.join(ARTICLES_DIR, name)
                if os.path.isfile(fp) and name.endswith('.md'):
                    t.add(fp, arcname=name)
    finally:
        _chattr(BACKUP_ARTICLES_DIR, '+i')
    # 轮换：保留最近 N 份
    keep = load_backup_conf()['article_keep']
    files = sorted(glob.glob(os.path.join(BACKUP_ARTICLES_DIR, 'ym-articles-*.tar.gz')))
    for f in files[:-keep]:
        try:
            os.remove(f)
        except Exception:
            pass
    log(f"文章每日备份完成: {pkg}（保留 {keep} 份）")
    return True


def cleanup_backups():
    """统一清除过时备份：文章保留 N 份、手动备份保留 M 份、删除损坏库残留 .corrupt-*"""
    cfg = load_backup_conf()
    # 文章每日备份
    arts = sorted(glob.glob(os.path.join(BACKUP_ARTICLES_DIR, 'ym-articles-*.tar.gz')))
    for f in arts[:-cfg['article_keep']]:
        try:
            os.remove(f)
            log(f"清除过时文章备份: {f}")
        except Exception:
            pass
    # 手动整站备份 ym-backup-*
    man = sorted(glob.glob(os.path.join(BACKUP_DIR, 'ym-backup-*.tar.gz')))
    for f in man[:-cfg['manual_keep']]:
        try:
            os.remove(f)
            log(f"清除过时手动备份: {f}")
        except Exception:
            pass
    # 恢复时产生的损坏库残留
    for f in glob.glob(DB_FILE + '.corrupt-*'):
        try:
            os.remove(f)
            log(f"清除损坏库残留: {f}")
        except Exception:
            pass


def _db_quick_check(path) -> bool:
    """SQLite 完整性快速检查（quick_check，比 integrity_check 轻量）"""
    try:
        con = sqlite3.connect(path, timeout=5)
        row = con.execute('PRAGMA quick_check').fetchone()
        con.close()
        return bool(row) and row[0] == 'ok'
    except Exception:
        return False


def restore_db_from_file(src) -> bool:
    """校验备份完整性后用原子替换恢复主库（删 -wal/-shm → 拷贝临时 → os.replace）"""
    global last_restore_info
    if not _db_quick_check(src):
        return False
    try:
        # 保留损坏库存档（.corrupt-*，由 cleanup 统一删除）
        if os.path.exists(DB_FILE):
            shutil.copy2(DB_FILE, DB_FILE + '.corrupt-' + time.strftime('%Y%m%d%H%M%S'))
        for ext in ('-wal', '-shm'):
            p = DB_FILE + ext
            if os.path.exists(p):
                os.remove(p)
        tmp = DB_FILE + '.restore'
        shutil.copy2(src, tmp)
        os.replace(tmp, DB_FILE)
        if not _db_quick_check(DB_FILE):
            log("恢复后完整性复查失败")
            return False
        last_restore_info = f"{time.strftime('%Y-%m-%d %H:%M:%S')} 从 {os.path.basename(src)} 恢复"
        log(f"数据库已从 {src} 恢复")
        send_alert("数据库恢复", f"主库 ym.db 已从备份恢复: {src}")
        return True
    except Exception as e:
        log(f"数据库恢复失败: {e}")
        # v2.8.0：恢复失败需告警兜底（db_health_check 全失败时另有"数据库损坏"告警）
        send_alert("数据库恢复失败", f"主库 ym.db 从备份恢复失败，请立即人工介入: {e}")
        return False


def restore_db_from_package(pkg) -> bool:
    """从 tar.gz 备份包恢复：解压到临时目录 → 校验完整性 → 恢复主库 + 链尾文件"""
    tmpdir = None
    try:
        tmpdir = tempfile.mkdtemp(prefix='ym-restore-')
        with tarfile.open(pkg, 'r:gz') as t:
            t.extractall(tmpdir)
        src = os.path.join(tmpdir, 'ym.db')
        ok = restore_db_from_file(src)
        if ok:
            chain = os.path.join(tmpdir, '.audit_chain')
            if os.path.exists(chain):
                shutil.copy2(chain, AUDIT_CHAIN)
        return ok
    except Exception as e:
        log(f"从备份包恢复失败: {e}")
        return False
    finally:
        if tmpdir:
            shutil.rmtree(tmpdir, ignore_errors=True)


def db_health_check() -> bool:
    """主库健康检查：quick_check 异常/文件缺失 → 自动恢复（30 分钟备份 → 5 分钟镜像）"""
    if is_update_in_progress():
        return True  # 更新中跳过（更新过程会替换数据库）
    if os.path.exists(DB_FILE):
        if _db_quick_check(DB_FILE):
            return True
        log("主库完整性异常，尝试自动恢复")
    else:
        log("主库文件缺失，尝试自动恢复")
    # 恢复来源按优先级：30 分钟备份 → 5 分钟镜像
    if os.path.exists(DB_BACKUP_FILE) and restore_db_from_package(DB_BACKUP_FILE):
        return True
    if os.path.exists(AUDIT_MIRROR_DB) and restore_db_from_file(AUDIT_MIRROR_DB):
        return True
    log("主库损坏且无可用备份，请手动处理")
    send_alert("数据库损坏", "主库 ym.db 损坏/缺失且无可用备份，请手动处理")
    return False


def articles_health_check():
    """文章目录检测：缺失/被清空（无 .md）→ 从最新每日备份恢复"""
    if is_update_in_progress():
        return True
    if not os.path.isdir(ARTICLES_DIR):
        return True
    if [f for f in os.listdir(ARTICLES_DIR) if f.endswith('.md')]:
        return True  # 有文章，正常
    backups = sorted(glob.glob(os.path.join(BACKUP_ARTICLES_DIR, 'ym-articles-*.tar.gz')), reverse=True)
    if not backups:
        return False
    try:
        with tarfile.open(backups[0], 'r:gz') as t:
            names = t.getnames()
            # 安全校验：备份为守护进程自建（纯文件名），拒绝含路径穿越的非法成员
            if any('/' in n or n.startswith('..') for n in names):
                log("文章备份包含非法路径，拒绝恢复")
                return False
            t.extractall(ARTICLES_DIR)
        log(f"文章目录已从每日备份恢复: {backups[0]}")
        send_alert("文章恢复", f"文章目录已从每日备份恢复: {backups[0]}")
        return True
    except Exception as e:
        log(f"文章恢复失败: {e}")
        # v2.8.0：文章目录恢复失败告警兜底
        send_alert("文章恢复失败", f"文章目录从每日备份恢复失败，请立即人工介入: {e}")
        return False


def periodic_backup_thread():
    """自动备份与健康检测线程：
    - 数据库：每 N 分钟（可配 5~1440，默认 30）健康检查 + 滚动备份 1 份
    - 文章：每天 1 次备份 + 统一清除过时备份
    - 文章目录健康检查（防被清空）"""
    while running:
        interval = load_backup_conf()['interval_min'] * 60
        time.sleep(interval)
        # 1. 主库健康检查（损坏 → 自动恢复；恢复成功则本轮跳过备份）
        if db_health_check():
            backup_db()
        # 2. 文章每日备份（当天已有备份文件则跳过）+ 统一清理
        today_pkg = os.path.join(BACKUP_ARTICLES_DIR, 'ym-articles-' + time.strftime('%Y%m%d') + '.tar.gz')
        if not os.path.exists(today_pkg):
            backup_articles()
            cleanup_backups()
        # 3. 文章目录健康检查
        articles_health_check()
        save_guard_state()


def save_guard_state():
    """保存守护进程状态（含自动备份状态，供超管后台可视化）"""
    global last_restore_info
    cfg = load_backup_conf()
    state = {
        'pid': os.getpid(),
        'last_check': time.strftime('%Y-%m-%d %H:%M:%S'),
        'last_audit_check': time.strftime('%Y-%m-%d %H:%M:%S'),
        'watch_files': len(WATCH_FILES),
        # 自动备份状态
        'backup_interval_min': cfg['interval_min'],
        'article_backup_keep': cfg['article_keep'],
        'manual_backup_keep': cfg['manual_keep'],
        'last_db_backup': '',
        'next_db_backup': '',
        'last_articles_backup': '',
        'last_restore': last_restore_info or '',
        'db_backup_size': 0,
        'articles_backup_count': 0,
        'mirror_locked': False,
    }
    # 数据库备份文件（固定 1 份滚动）
    if os.path.exists(DB_BACKUP_FILE):
        st = os.stat(DB_BACKUP_FILE)
        state['last_db_backup'] = time.strftime('%Y-%m-%d %H:%M:%S', time.localtime(st.st_mtime))
        state['db_backup_size'] = st.st_size
    state['next_db_backup'] = time.strftime('%Y-%m-%d %H:%M:%S', time.localtime(time.time() + cfg['interval_min'] * 60))
    # 每日文章备份
    arts = sorted(glob.glob(os.path.join(BACKUP_ARTICLES_DIR, 'ym-articles-*.tar.gz')))
    state['articles_backup_count'] = len(arts)
    if arts:
        st = os.stat(arts[-1])
        state['last_articles_backup'] = time.strftime('%Y-%m-%d %H:%M:%S', time.localtime(st.st_mtime))
    # 镜像目录锁定状态（chattr +i 是否生效）
    state['mirror_locked'] = os.path.isdir(os.path.dirname(AUDIT_MIRROR_DB))
    os.makedirs(os.path.dirname(GUARD_STATE), exist_ok=True)
    with open(GUARD_STATE, 'w') as f:
        json.dump(state, f, ensure_ascii=False)


def watchdog_thread():
    """systemd watchdog 心跳线程"""
    if WATCHDOG_USEC <= 0:
        return
    interval = max(WATCHDOG_USEC / 2, 1)
    while running:
        time.sleep(interval)
        try:
            # SD_NOTIFY WATCHDOG=1
            if os.environ.get('NOTIFY_SOCKET'):
                import socket
                sock = socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM)
                sock.sendto(b'WATCHDOG=1', os.environ['NOTIFY_SOCKET'])
                sock.close()
        except Exception:
            pass


def signal_handler(signum, frame):
    global running
    log(f"收到信号 {signum}，正在退出...")
    running = False
    # v2.8.0 修复：主线程阻塞在 inotify epoll read()，仅置 running=False 无法中断；
    # PEP 475 会让被中断的系统调用自动重试 → 进程不退 → systemd watchdog 30s 超时 SIGABRT 强杀。
    # 在 handler 中抛 SystemExit（PEP 475：handler 抛异常则不重试），异常沿主线程传播使进程立即退出（见踩坑 #24）
    raise SystemExit(0)


def periodic_audit_thread():
    """定时审计日志校验线程（每5分钟执行一次）：校验哈希链 + 背书镜像"""
    global last_audit_check
    while running:
        time.sleep(AUDIT_CHECK_INTERVAL)
        if not verify_audit_chain():
            log("审计日志哈希链校验失败，已尝试恢复")
        else:
            log("审计日志哈希链校验通过")
            mirror_db()  # 背书：将 SQLite 落盘并同步镜像
        save_guard_state()


def periodic_hfish_thread():
    """定时蜜罐同步线程（每5分钟执行一次）：同步攻击日志快照 + 自动封禁"""
    while running:
        time.sleep(AUDIT_CHECK_INTERVAL)
        try:
            sync = os.path.join(WEB_ROOT, 'ym-hfish-sync.py')
            if os.path.exists(sync):
                result = subprocess.run(
                    [sys.executable, sync],
                    capture_output=True, timeout=60
                )
                log(f"蜜罐同步: {result.stdout.decode('utf-8', 'ignore').strip() or '完成'}")
        except Exception as e:
            log(f"蜜罐同步失败: {e}")


def run_inotify_watch():
    """使用 inotify 监控文件变化"""
    global last_audit_check

    try:
        import inotify.adapters
        import inotify.constants
    except ImportError:
        log("inotify 不可用，降级为轮询模式")
        run_polling_mode()
        return

    # 收集所有需要监控的目录
    watch_dirs = set()
    for rel_path in WATCH_FILES:
        d = os.path.dirname(os.path.join(WEB_ROOT, rel_path))
        if os.path.isdir(d):
            watch_dirs.add(d)

    inotifier = inotify.adapters.Inotify()
    for d in watch_dirs:
        try:
            inotifier.add_watch(d, mask=inotify.constants.IN_MODIFY | inotify.constants.IN_CLOSE_WRITE | inotify.constants.IN_MOVED_TO)
        except Exception as e:
            log(f"添加监控失败: {d} - {e}")

    log(f"inotify 监控已启动，监控 {len(watch_dirs)} 个目录，{len(WATCH_FILES)} 个文件")

    # 启动 Watchdog 线程
    wd_thread = threading.Thread(target=watchdog_thread, daemon=True)
    wd_thread.start()

    # 启动定时审计日志校验线程
    audit_thread = threading.Thread(target=periodic_audit_thread, daemon=True)
    audit_thread.start()

    # 启动定时蜜罐同步线程
    hfish_thread = threading.Thread(target=periodic_hfish_thread, daemon=True)
    hfish_thread.start()

    # 启动自动备份与健康检测线程
    backup_thread = threading.Thread(target=periodic_backup_thread, daemon=True)
    backup_thread.start()

    # 初始全量校验
    verify_all_files()

    # 主循环 — 不设 timeout_s，event_gen 会在 epoll 上永久阻塞等待真实事件
    try:
        for event in inotifier.event_gen(yield_nones=False):
            if not running:
                break

            (_, type_names, path, filename) = event
            fname = filename.decode() if isinstance(filename, bytes) else filename
            path_str = path.decode() if isinstance(path, bytes) else path

            # 检查是否在监控列表中
            for rel_path in WATCH_FILES:
                full_path = os.path.join(WEB_ROOT, rel_path)
                event_path = os.path.join(path_str, fname)
                if os.path.abspath(event_path) == os.path.abspath(full_path):
                    log(f"检测到文件变化: {rel_path}")
                    time.sleep(0.5)  # 等待写入完成
                    verify_and_restore_file(rel_path)
                    break

    except Exception as e:
        log(f"inotify 异常: {e}，降级为轮询模式")
        run_polling_mode()


def run_polling_mode():
    """轮询模式（inotify 不可用时的降级方案）"""
    global last_audit_check

    log("进入轮询监控模式（5秒间隔）")

    wd_thread = threading.Thread(target=watchdog_thread, daemon=True)
    wd_thread.start()

    hfish_thread = threading.Thread(target=periodic_hfish_thread, daemon=True)
    hfish_thread.start()

    backup_thread = threading.Thread(target=periodic_backup_thread, daemon=True)
    backup_thread.start()

    verify_all_files()

    while running:
        time.sleep(5)
        verify_all_files()

        now = time.time()
        if now - last_audit_check >= AUDIT_CHECK_INTERVAL:
            last_audit_check = now
            if not verify_audit_chain():
                log("审计日志哈希链校验失败，已尝试恢复")
            else:
                log("审计日志哈希链校验通过")
                mirror_db()  # 背书镜像
            save_guard_state()


def main():
    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)

    log(f"{load_app_name()} 守护进程 v{load_version()} 启动")
    log(f"Web 根目录: {WEB_ROOT}")
    log(f"母本目录: {INSTALL_BASE}")

    # 确保母本存在
    if not os.path.isdir(INSTALL_BASE):
        log(f"错误: 母本目录不存在 {INSTALL_BASE}")
        sys.exit(1)

    # 通知 systemd 启动完成
    if os.environ.get('NOTIFY_SOCKET'):
        try:
            import socket
            sock = socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM)
            sock.sendto(b'READY=1', os.environ['NOTIFY_SOCKET'])
            sock.close()
        except Exception:
            pass

    run_inotify_watch()
    log("守护进程已退出")


if __name__ == '__main__':
    main()