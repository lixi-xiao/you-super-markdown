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
import hashlib
import shutil
import time
import signal
import subprocess
import threading
from pathlib import Path

# === 配置 ===
WEB_ROOT = os.environ.get('YM_WEB_ROOT', '/var/www/you-markdown')
INSTALL_BASE = '/opt/you-markdown/install-base'
AUDIT_LOG = os.path.join(WEB_ROOT, 'data', '.audit.json')
AUDIT_CHAIN = os.path.join(WEB_ROOT, 'data', '.audit_chain')
AUDIT_MIRROR = '/opt/you-markdown/logs/audit.json'
AUDIT_MIRROR_CHAIN = '/opt/you-markdown/logs/audit_chain'
GUARD_STATE = '/opt/you-markdown/guard-state.json'
EMAIL_ALERT_BIN = '/usr/local/bin/ym-alert'
CONFIG_FILE = os.path.join(WEB_ROOT, 'data', '.config.json')
WATCHDOG_USEC = int(os.environ.get('WATCHDOG_USEC', 0)) / 1_000_000  # systemd watchdog 间隔（秒）

# 核心监控文件列表（相对 WEB_ROOT 的路径）
# 注：仅监控代码类文件；data/ 数据文件不在此列（母本排除 data/，无对照，由哈希链/镜像负责审计完整性）
WATCH_FILES = [
    'index.php',
    'api.php',
    'utils.php',
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
    """从配置读取管理员邮箱"""
    try:
        with open(CONFIG_FILE, 'r') as f:
            cfg = json.load(f)
            return cfg.get('admin_email', '')
    except Exception:
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


def send_alert(alert_type: str, detail: str):
    """发送邮件告警"""
    email = load_admin_email()
    if not email or not os.path.exists(EMAIL_ALERT_BIN):
        return
    host = os.uname().nodename if hasattr(os, 'uname') else 'localhost'
    subject = f"[{load_app_name()} 告警] {alert_type}"
    body = f"时间：{time.strftime('%Y-%m-%d %H:%M:%S')}\n服务器：{host}\n事件类型：{alert_type}\n详情：{detail}\n"
    try:
        subprocess.run(
            [EMAIL_ALERT_BIN, email, subject, body],
            capture_output=True, timeout=10
        )
    except Exception:
        pass


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
    """校验审计日志哈希链"""
    if is_update_in_progress():
        return True  # 更新中，跳过哈希链校验

    if not os.path.exists(AUDIT_LOG):
        return True

    try:
        with open(AUDIT_LOG, 'r') as f:
            logs = json.load(f)
    except Exception:
        return False

    if not logs:
        return True

    prev_hash = ''
    for i in range(len(logs)):
        entry = logs[i]
        expected = entry.get('hash', '')
        check_data = dict(entry)
        check_data.pop('hash', None)
        check_data['prev_hash'] = prev_hash
        check_json = json.dumps(check_data, ensure_ascii=False, separators=(',', ':'))
        computed = hashlib.sha256(check_json.encode()).hexdigest()
        if computed != expected:
            log(f"审计日志哈希链断裂于第 {i} 条")
            send_alert("日志哈希链断裂", f"审计日志哈希链在第 {i} 条处断裂，尝试从镜像恢复")
            recover_audit()
            return False
        prev_hash = expected

    return True


def recover_audit():
    """从镜像恢复审计日志"""
    if os.path.exists(AUDIT_MIRROR):
        shutil.copy2(AUDIT_MIRROR, AUDIT_LOG)
        log("审计日志已从镜像恢复")
        send_alert("日志已恢复", "审计日志已从镜像副本恢复，请检查")
    if os.path.exists(AUDIT_MIRROR_CHAIN):
        shutil.copy2(AUDIT_MIRROR_CHAIN, AUDIT_CHAIN)


def save_guard_state():
    """保存守护进程状态"""
    state = {
        'pid': os.getpid(),
        'last_check': time.strftime('%Y-%m-%d %H:%M:%S'),
        'last_audit_check': time.strftime('%Y-%m-%d %H:%M:%S'),
        'watch_files': len(WATCH_FILES),
    }
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


def periodic_audit_thread():
    """定时审计日志校验线程（每5分钟执行一次）"""
    global last_audit_check
    while running:
        time.sleep(AUDIT_CHECK_INTERVAL)
        if not verify_audit_chain():
            log("审计日志哈希链校验失败，已尝试恢复")
        else:
            log("审计日志哈希链校验通过")
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