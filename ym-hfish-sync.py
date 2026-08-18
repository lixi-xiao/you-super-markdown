#!/usr/bin/env python3
"""You Super Markdown — 蜜罐(HFish)同步与自动封禁（版本见 app-config.json）
功能：
  1. 只读读取 HFish 蜜罐数据库(ip_profile 攻击者IP画像)
  2. 生成蜜罐安全快照 JSON 供超管后台展示
  3. 攻击总次数(attack_cnt)达到阈值(默认10)的 IP 自动封禁（登录/注册/评论）
     - 内网/私有 IP 默认豁免（hfish_ban_skip_private）
用法：
  python3 ym-hfish-sync.py            # 同步快照 + 执行封禁检查
  python3 ym-hfish-sync.py --check    # 仅检查（输出状态）
"""
import json
import os
import sqlite3
import sys
import datetime
import subprocess
import time

WEB_ROOT = os.environ.get('YM_WEB_ROOT', '/var/www/you-markdown')
APP_CONFIG = os.path.join(WEB_ROOT, 'app-config.json')
SNAPSHOT_FILE = os.path.join(WEB_ROOT, 'data', '.hfish_snapshot.json')
DB_FILE = os.path.join(WEB_ROOT, 'data', 'ym.db')


def load_config():
    cfg = {}
    try:
        with open(APP_CONFIG, 'r', encoding='utf-8') as f:
            cfg = json.load(f)
    except Exception:
        pass
    return cfg


def load_site_config():
    """站点配置：SQLite config 表优先（超管后台可配），app-config.json 兜底（v4.1.7）"""
    cfg = load_config()
    try:
        con = sqlite3.connect('file:%s?mode=ro' % DB_FILE, uri=True)
        cur = con.execute("SELECT key, value FROM config")
        for k, v in cur.fetchall():
            try:
                cfg[k] = json.loads(v)
            except Exception:
                cfg[k] = v
        con.close()
    except Exception:
        pass
    return cfg


def read_hfish_attacks(db_path):
    """只读读取 ip_profile 攻击者画像，返回列表"""
    result = []
    if not os.path.exists(db_path):
        return result, '数据库不存在: ' + db_path
    try:
        con = sqlite3.connect('file:%s?mode=ro' % db_path, uri=True)
        cur = con.cursor()
        cur.execute("SELECT ip, date, attack_cnt, attack_styles_cnt, attack_honeypots_cnt, "
                    "attack_nodes_cnt, attacker_uas, attacker_hosts, attacker_accounts "
                    "FROM ip_profile")
        for row in cur.fetchall():
            result.append({
                'ip': row[0] or '',
                'date': str(row[1] or ''),
                'attack_cnt': int(row[2] or 0),
                'styles': _parse_json(row[3]),
                'honeypots': _parse_json(row[4]),
                'nodes': _parse_json(row[5]),
                'uas': _parse_json(row[6]),
                'hosts': _parse_json(row[7]),
                'accounts': _parse_json(row[8]),
            })
        con.close()
        return result, None
    except Exception as e:
        return result, '读取蜜罐数据库失败: %s' % e


def _parse_json(s):
    if not s:
        return {}
    try:
        if isinstance(s, dict):
            return s
        return json.loads(s)
    except Exception:
        return {}


def load_bans():
    """从 SQLite bans 表读取封禁列表（仅用于快照展示，不再用于封禁决策）"""
    try:
        con = sqlite3.connect(DB_FILE)
        con.row_factory = sqlite3.Row
        rows = con.execute("SELECT ip, types_json, reason, time FROM bans ORDER BY time DESC").fetchall()
        con.close()
        bans = []
        for r in rows:
            bans.append({
                'ip': r['ip'],
                'types': json.loads(r['types_json'] or '[]'),
                'reason': r['reason'],
                'time': r['time'],
            })
        return bans
    except Exception:
        return []


def is_private_ip(ip):
    """判断 IP 是否为内网/私有地址（10/8、172.16/12、192.168/16、127/8、169.254/16）"""
    try:
        from ipaddress import ip_address, ip_network
        addr = ip_address(ip)
        return any(addr in ip_network(net) for net in [
            '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
            '127.0.0.0/8', '169.254.0.0/16', '0.0.0.0/8'])
    except Exception:
        return False


def apply_ban(ip, threshold):
    """v4.8.0：攻击次数达到阈值 → 写入 threat_events 表走联动封禁（不再直接写 bans 表）"""
    try:
        con = sqlite3.connect(DB_FILE)
        now = int(time.time())
        # 写入 threat_events 表（hfish_attack 事件，权重 20，24h 去重窗口）
        cur = con.cursor()
        cur.execute(
            "SELECT 1 FROM threat_events WHERE dim_type='ip' AND dim_key=? AND reason='hfish_attack' AND created > ? LIMIT 1",
            (ip, now - 86400))
        if cur.fetchone():
            con.close()
            return False  # 24h 内已有 HFish 事件，不再重复写入
        import binascii, os as _os
        event_id = binascii.hexlify(_os.urandom(8)).decode('ascii')
        cur.execute(
            "INSERT INTO threat_events (id, dim_type, dim_key, weight, reason, created) VALUES (?,?,?,?,?,?)",
            (event_id, 'ip', ip, 20, 'hfish_attack', now))
        con.commit()
        con.close()
        # 调用 PHP 桥接脚本触发 maybeLinkedBlock 升级检查
        bridge = os.path.join(WEB_ROOT, '_hfish_bridge.php')
        if os.path.exists(bridge):
            subprocess.run(['php', bridge, ip], capture_output=True, timeout=30)
        return True
    except Exception:
        return False


def find_hfish_db(cfg):
    """探测 HFish 数据库路径：优先 app-config.json 的 hfish_db_path，其次常见安装位置。
    官方 webinstall.sh 装在 /opt/hfish；早期手动安装可能在 /usr/share/hfish（v2.10.2 公网部署实测）"""
    db_path = cfg.get('hfish_db_path') or ''
    for p in [db_path, '/usr/share/hfish/database/hfish.db', '/opt/hfish/database/hfish.db']:
        if p and os.path.exists(p):
            return p
    return db_path or '/usr/share/hfish/database/hfish.db'


def main():
    cfg = load_site_config()  # v4.1.7：config 表优先（后台可配），回退 app-config.json
    threshold = int(cfg.get('hfish_ban_threshold', 10) or 10)
    db_path = find_hfish_db(cfg)

    attacks, err = read_hfish_attacks(db_path)
    snapshot = {
        'updated_at': datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
        'threshold': threshold,
        'db_path': db_path,
        'error': err,
        'total': len(attacks),
        'attacks': sorted(attacks, key=lambda a: a['attack_cnt'], reverse=True),
    }

    # 封禁检查（支持内网 IP 豁免，避免内网测试环境误封真实访客）
    newly_banned = []
    skip_private = bool(cfg.get('hfish_ban_skip_private', True))
    if err is None:
        for a in attacks:
            if a['attack_cnt'] >= threshold:
                if skip_private and is_private_ip(a['ip']):
                    # v4.1.7-fix：超阈值但内网/链路本地豁免 → 快照标记，后台展示豁免原因（避免"超阈值未封禁"困惑）
                    a['skip'] = True
                    a['skip_reason'] = '内网/链路本地地址豁免'
                    continue  # 内网/私有 IP 豁免，仅记录不自动封禁
                if apply_ban(a['ip'], threshold):
                    newly_banned.append(a['ip'])

    # 标记封禁状态（供后台展示）
    bans = load_bans()
    banned_map = {b['ip'] for b in bans}
    for a in snapshot['attacks']:
        a['banned'] = a['ip'] in banned_map

    try:
        os.makedirs(os.path.dirname(SNAPSHOT_FILE), exist_ok=True)
        with open(SNAPSHOT_FILE, 'w', encoding='utf-8') as f:
            json.dump(snapshot, f, ensure_ascii=False, indent=2)
    except Exception as e:
        print('写快照失败:', e)

    if '--check' in sys.argv:
        print(json.dumps(snapshot, ensure_ascii=False))
    else:
        print('蜜罐同步完成: %d 条攻击记录, 新增封禁 %s' % (len(attacks), newly_banned or '无'))


if __name__ == '__main__':
    main()
