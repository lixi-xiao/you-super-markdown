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
    """从 SQLite bans 表读取封禁列表（保持原 [{ip,types,reason,time}] 格式）"""
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


def save_bans(bans):
    try:
        con = sqlite3.connect(DB_FILE)
        con.execute('DELETE FROM bans')
        con.executemany(
            'INSERT INTO bans (ip, types_json, reason, time) VALUES (?,?,?,?)',
            [(b.get('ip'), json.dumps(b.get('types', []), ensure_ascii=False), b.get('reason', ''), b.get('time', '')) for b in bans]
        )
        con.commit()
        con.close()
    except Exception:
        pass


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
    """攻击次数达到阈值 → 写入封禁（登录/注册/评论），已封禁则跳过"""
    try:
        con = sqlite3.connect(DB_FILE)
        cur = con.cursor()
        cur.execute("SELECT ip FROM bans WHERE ip=?", (ip,))
        if cur.fetchone():
            con.close()
            return False  # 已封禁
        cur.execute(
            "INSERT INTO bans (ip, types_json, reason, time) VALUES (?,?,?,?)",
            (ip, json.dumps(['login', 'register', 'comment'], ensure_ascii=False),
             '触发蜜罐行为达到 %d 次自动封禁' % threshold,
             datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S'))
        )
        con.commit()
        con.close()
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
    cfg = load_config()
    threshold = int(cfg.get('hfish_ban_threshold', 3) or 3)
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
