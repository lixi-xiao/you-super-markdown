#!/usr/bin/env python3
"""You Super Markdown — 蜜罐(HFish)同步与自动封禁（版本见 app-config.json）
功能：
  1. 只读读取 HFish 蜜罐数据库(ip_profile 攻击者IP画像)
  2. 生成蜜罐安全快照 JSON 供超管后台展示
  3. 攻击总次数(attack_cnt)达到阈值(默认10)的 IP 自动封禁（登录/注册/评论）
     - 内网/私有 IP 默认豁免（hfish_ban_skip_private）
用法：
  python3 ysm-hfish-sync.py            # 同步快照 + 执行封禁检查
  python3 ysm-hfish-sync.py --check    # 仅检查（输出状态）
"""
import json
import os
import sqlite3
import sys
import datetime
import subprocess
import time

WEB_ROOT = os.environ.get('YSM_WEB_ROOT', '/var/www/you-super-markdown')
APP_CONFIG = os.path.join(WEB_ROOT, 'app-config.json')
SNAPSHOT_FILE = os.path.join(WEB_ROOT, 'data', '.hfish_snapshot.json')
DB_FILE = os.path.join(WEB_ROOT, 'data', 'ysm.db')


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


def hfish_tier(cnt, l1_cnt, l2_cnt, l3_cnt, w_l1, w_l2, w_l3):
    """按蜜罐攻击次数定级：返回 (等级名, 联动权重)。等级对应联动封锁 L1(15min)/L2(24h)/L3(永久)"""
    if cnt >= l3_cnt:
        return 'L3', w_l3
    if cnt >= l2_cnt:
        return 'L2', w_l2
    if cnt >= l1_cnt:
        return 'L1', w_l1
    return '', 0


def apply_ban(ip, weight):
    """v5.4.10：按攻击次数分级封禁 → 以【对应等级的权重】写入单条 hfish_attack 事件。

    关键修复：旧版权重恒为 20 且带 24h 去重，单 IP 评分上限 20，永远够不到 L1(线上 50)，
    蜜罐数据"只进不出、从不封禁"。现改为"先清该 IP 旧 hfish_attack 事件、再写当前等级权重"，
    保证评分恒等于当前等级（既不累加越级，也不因去重而失效）。
    事件超过 12h 会刷新时间戳，避免威胁评分衰减（threat_decay_days）把等级削弱。
    """
    if weight <= 0:
        return False
    try:
        con = sqlite3.connect(DB_FILE)
        now = int(time.time())
        cur = con.cursor()
        row = cur.execute(
            "SELECT weight, created FROM threat_events WHERE dim_type='ip' AND dim_key=? AND reason='hfish_attack' LIMIT 1",
            (ip,)).fetchone()
        if row and int(row[0]) == weight and (now - int(row[1] or 0)) < 43200:
            con.close()
            return False  # 等级未变且事件新鲜，无需重写
        cur.execute(
            "DELETE FROM threat_events WHERE dim_type='ip' AND dim_key=? AND reason='hfish_attack'", (ip,))
        import binascii, os as _os
        event_id = binascii.hexlify(_os.urandom(8)).decode('ascii')
        cur.execute(
            "INSERT INTO threat_events (id, dim_type, dim_key, weight, reason, created) VALUES (?,?,?,?,?,?)",
            (event_id, 'ip', ip, weight, 'hfish_attack', now))
        con.commit()
        con.close()
        return True
    except Exception:
        return False


def flush_bridge(ips):
    """v5.4.12：批量触发联动升级检查——**一次** PHP 进程处理全部 IP（走 stdin）。

    此前写法是对每个 IP 各 spawn 一个 PHP 进程；蜜罐历史积压（实测 760~1000 个超阈值 IP）
    在上线后首次同步会一次性起近千进程（每个还含同步 SMTP），把 2GB 小机器 CPU 打到 90%。
    改为批量后进程数 O(1)。
    """
    if not ips:
        return
    bridge = os.path.join(WEB_ROOT, '_hfish_bridge.php')
    if not os.path.exists(bridge):
        return
    try:
        subprocess.run(['php', bridge], input='\n'.join(ips).encode('utf-8'),
                       capture_output=True, timeout=180)
    except Exception:
        pass


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
    # v5.4.10：按攻击次数分级——阈值→L1(15min) / l2_cnt→L2(24h) / l3_cnt→L3(永久)
    newly_banned = []
    changed_ips = []
    skip_private = bool(cfg.get('hfish_ban_skip_private', True))
    l2_cnt = int(cfg.get('hfish_l2_cnt', 50) or 50)
    l3_cnt = int(cfg.get('hfish_l3_cnt', 200) or 200)
    w_l1 = int(cfg.get('threat_l1', 40) or 40)
    w_l2 = int(cfg.get('threat_l2', 150) or 150)
    w_l3 = int(cfg.get('threat_l3', 250) or 250)
    snapshot['l1_cnt'] = threshold
    snapshot['l2_cnt'] = l2_cnt
    snapshot['l3_cnt'] = l3_cnt
    if err is None:
        for a in attacks:
            if a['attack_cnt'] >= threshold:
                if skip_private and is_private_ip(a['ip']):
                    # v4.1.7-fix：超阈值但内网/链路本地豁免 → 快照标记，后台展示豁免原因（避免"超阈值未封禁"困惑）
                    a['skip'] = True
                    a['skip_reason'] = '内网/链路本地地址豁免'
                    continue  # 内网/私有 IP 豁免，仅记录不自动封禁
                level, weight = hfish_tier(a['attack_cnt'], threshold, l2_cnt, l3_cnt, w_l1, w_l2, w_l3)
                a['level'] = level
                if apply_ban(a['ip'], weight):
                    newly_banned.append('%s(%s)' % (a['ip'], level))
                    changed_ips.append(a['ip'])
    # v5.4.12：所有变更 IP 汇总后**一次**PHP 进程批量触发升级检查（不再每 IP 一个进程）
    flush_bridge(changed_ips)

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
