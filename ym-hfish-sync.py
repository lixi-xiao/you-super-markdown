#!/usr/bin/env python3
"""You Super Markdown v2.3.0 — 蜜罐(HFish)同步与自动封禁
功能：
  1. 只读读取 HFish 蜜罐数据库(ip_profile 攻击者IP画像)
  2. 生成蜜罐安全快照 JSON 供超管后台展示
  3. 攻击总次数(attack_cnt)达到阈值(默认3次)的 IP 自动封禁（登录/注册/评论）
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
BANS_FILE = os.path.join(WEB_ROOT, 'data', '.bans.json')


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
    try:
        with open(BANS_FILE, 'r', encoding='utf-8') as f:
            data = json.load(f)
        return data if isinstance(data, list) else []
    except Exception:
        return []


def save_bans(bans):
    with open(BANS_FILE, 'w', encoding='utf-8') as f:
        json.dump(bans, f, ensure_ascii=False, indent=4)


def apply_ban(ip, threshold):
    """攻击次数达到阈值 → 写入封禁（登录/注册/评论），已封禁则跳过"""
    bans = load_bans()
    for b in bans:
        if b.get('ip') == ip:
            return False  # 已封禁
    bans.append({
        'ip': ip,
        'types': ['login', 'register', 'comment'],
        'reason': '触发蜜罐行为达到 %d 次自动封禁' % threshold,
        'time': datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
    })
    save_bans(bans)
    return True


def main():
    cfg = load_config()
    threshold = int(cfg.get('hfish_ban_threshold', 3) or 3)
    db_path = cfg.get('hfish_db_path') or '/usr/share/hfish/database/hfish.db'

    attacks, err = read_hfish_attacks(db_path)
    snapshot = {
        'updated_at': datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
        'threshold': threshold,
        'db_path': db_path,
        'error': err,
        'total': len(attacks),
        'attacks': sorted(attacks, key=lambda a: a['attack_cnt'], reverse=True),
    }

    # 封禁检查
    newly_banned = []
    if err is None:
        for a in attacks:
            if a['attack_cnt'] >= threshold:
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
