# You Super Markdown 开发规范与实现详解

> 面向所有参与 You Super Markdown 开发的成员，以及**接手本项目的新开发者**。本文档 = 开发规范 + 代码实现详解，配合根目录《README.md（项目总览）》阅读后，应能独立完成本项目的理解、修改、测试与发布。

- 适用范围：所有源码修改、构建、打包、部署相关活动
- 权威版本：工作区根目录 `DEVELOPMENT_GUIDELINES.md`；主库 `test_development/DEVELOPMENT_GUIDELINES.md` 为同内容副本
- 违反规范可能导致更新包无法发布或引入安全风险，请严格遵守

***

## 目录

- [一、项目架构决策总览](#一项目架构决策总览)
- [二、工作区与版本管理](#二工作区与版本管理)
- [三、代码结构逐文件详解](#三代码结构逐文件详解)
- [四、核心函数库参考（utils.php）](#四核心函数库参考utilsphp)
- [五、数据模型（data 目录 JSON 结构）](#五数据模型data-目录-json-结构)
- [六、API 接口文档（api.php）](#六api-接口文档apiphp)
- [七、认证与授权实现细节](#七认证与授权实现细节)
- [八、审计日志与哈希链实现](#八审计日志与哈希链实现)
- [九、文件守护进程实现（ym-guard.py）](#九文件守护进程实现ym-guardpy)
- [十、在线更新机制实现](#十在线更新机制实现)
- [十一、蜜罐联动实现](#十一蜜罐联动实现)
- [十二、代码提交规范（Git）](#十二代码提交规范git)
- [十三、安全开发规范（强制）](#十三安全开发规范强制)
- [十四、代码风格](#十四代码风格)
- [十五、测试与部署规范](#十五测试与部署规范)
- [十六、发布流程（版本升级 Checklist）](#十六发布流程版本升级-checklist)
- [十七、已知问题与踩坑记录](#十七已知问题与踩坑记录)

***

## 一、项目架构决策总览

### 1.1 核心决策

| 决策 | 选择 | 理由 |
|------|------|------|
| 语言/框架 | 原生 PHP 8.x（无框架） | 无依赖、部署简单、可控性强 |
| 存储 | JSON 文件（无数据库） | 数据量小、备份/迁移简单、符合自托管定位 |
| 前端 | 原生 JS（无构建） | 无需 Node 工具链，改完即用 |
| 认证 | Session + JWT + OTP | 前台 Session、后台 JWT、超管 OTP 三层 |
| 防护 | 纵深防御五层 | 单点防线可被绕过，多层互补（见 1.2） |
| 更新 | 更新包 + SSH 确认 | 安全可控，支持离线 |

### 1.2 五层防御与代码映射

```
L1 隐藏入口  → admin/entry.php（OTP）+ 自定义路径（index.php 路由 + api.php entry_path_config）
L2 角色分权  → utils.php checkRole()/requireRole()/ROLE_HIERARCHY + 各 dashboard 页首鉴权
L3 审计日志  → utils.php auditLog()/verifyAuditChain()/AUDIT_MIRROR_DIR
L4 文件守护  → ym-guard.py（inotify + 母本 chattr +i）+ index.php MD5 自校验钩子
L5 数据隔离  → nginx-site.conf（deny /data/*.json）+ 文件权限 644/600
```

跨层互补示例：OTP 入口泄露但攻击者无 SSH（L2 挑战码拦截）；日志被删但镜像在 root 目录（L3 备份）；文件被改但母本秒级恢复（L4）。

### 1.3 关键设计原则

1. **默认安全（Secure by Default）**：权限默认拒绝、注册/评论默认受限、超管无固定密码
2. **失败封闭（Fail Closed）**：鉴权失败即拒绝并记日志；校验失败不执行操作
3. **最小权限**：写作者只碰自己的文章；普通用户只评论/改资料
4. **原子操作**：所有 JSON 写入 `LOCK_EX`；先校验后写入再验证
5. **密钥不落盘**：JWT 密钥 600 权限文件；生产密码经 SSH/环境注入，不入 git

***

## 二、工作区与版本管理

### 2.1 工作区结构

```
工作区\
├── README.md                      # 项目总览（唯一权威介绍）
├── DEVELOPMENT_GUIDELINES.md      # 本文档（开发规范唯一权威）
├── WELCOME.md / COMMANDS.md       # 引导与命令速查
├── test_development\              # ★ 主库（唯一开发源，Git 管理）
│   ├── 全部源码 + ym-* 脚本 + app-config.json
│   ├── data\                      # 本地测试数据（git 排除）
│   └── .git\                      # Git 仓库（master 分支，本地）
├── test_development copy\         # 备份副本（仅对照，不直接开发）
└── update-package\                # ★ 更新包统一存放目录（唯一）
    ├── version.json               # 最新版本号
    ├── you-super-markdown-v2.3.3-full.tar.gz
    ├── you-super-markdown-v2.3.0-to-v2.3.3-inc.tar.gz
    └── ...（历史包）
```

**硬性规则**：
- 开发只改主库 `test_development`；改完**必须**同步到 `test_development copy`
- 更新包只产出到 `update-package`；禁止在源码目录创建 `update-package`/`staging`/`inc-stage` 等打包目录
- 备份副本的改动须同步回主库（两者互为备份，内容一致）
- `data/` 为运行数据，git 排除，禁止提交

### 2.2 分支与提交

| 分支 | 用途 |
|------|------|
| `master` | 唯一可发布分支 |
| `dev` | 开发集成 |
| `feature/*` | 功能分支 |
| `fix/*` | 修复分支 |

流程：`feature/*` → `dev` → 评审 → `master` → 打 tag `v<主>.<次>.<修订>`。

> 当前仓库为**本地 Git**（无远程）。身份已配置：`xiao` / `x071217ghi@163.com`。新环境执行 `git config --local user.name "..."` / `user.email "..."`。

***

## 三、代码结构逐文件详解

### 3.1 前台入口 `index.php`

职责：页面骨架 + 文章渲染 + 路由分发。

关键逻辑：
1. **MD5 自校验钩子**（文件头部）：比对 `md5_file(__FILE__)` 与母本 `/opt/you-markdown/install-base/index.php`，不一致立即从母本 `copy()` 恢复（守护进程之外的 PHP 层兜底）
2. **配置加载**：读取 `data/.config.json` → `$_siteConfig`；v2.3.3 起 `$musicEnabled = !empty($_siteConfig['music_cookies'])` 决定音乐按钮是否渲染
3. **自定义路径路由**：`getStationPath()`/`getAuthorPath()` 与首段 URL 匹配 → 转发 `station/dashboard.php` / `author/dashboard.php`；`hide_default_paths` 开启时默认 `/station`、`/author` 返回 404
4. **置顶**：`action=pin/unpin`（站长及以上，写 `data/.pinned.json`）
5. **页面主体**：文章列表、搜索、阅读视图、评论 UI、播放器 DOM（`musicPopup` 常驻页面，仅按钮按配置隐藏）

### 3.2 API 层 `api.php`

纯 JSON 接口，统一 `jsonOut($data, $code)` 输出。文件头部对所有 POST 做 CSRF 校验（`X-CSRF-Token` 头）。全部 action 见第六章。

### 3.3 工具库 `utils.php`

全项目唯一核心库（约 500 行），被所有 PHP 入口 `require_once`。函数清单见第四章。**禁止删除/改名核心函数**（`checkRole`/`checkCsrfToken`/`auditLog`/`appConfig` 等），否则守护进程与更新机制将失效。

### 3.4 超管后台 `admin/`

- `entry.php`：OTP 入口。校验 `data/.entries.json` 中 token 与 `otp_hash`（`password_verify`），10 分钟、单次；失败统一 404 或拒绝
- `dashboard.php`：约 1700 行，`?tab=` 驱动的 8 大板块 + 首部 AJAX/表单处理器。**重要结构**：页签内容用 `if/elseif ($tab === 'xxx')` 链组织，**禁止在链中间提前 `<?php endif; ?>`**（曾因此导致整页白屏）

### 3.5 站长/写作者后台 `station/dashboard.php`、`author/dashboard.php`

首部 `checkRole()` 鉴权 → `validateJWT()` 校验会话 → 各自业务。站长管全部文章 + 下属写作者；写作者只碰自己的文章（`author_id` 过滤）。

### 3.6 脚本文件

| 文件 | 语言 | 职责 |
|------|------|------|
| `ym-guard.py` | Python | 守护进程（inotify + 审计背书 + 蜜罐线程） |
| `ym-hfish-sync.py` | Python | 蜜罐同步 + 阈值封禁 |
| `ym-install.sh` | Bash | 一键安装 |
| `ym-admin` | Bash | CLI 管理（15 个命令） |

### 3.7 配置与模板

- `app-config.json`：应用级配置（版本号唯一事实来源）
- `nginx-site.conf`：Nginx 站点模板（含安全头、data 隔离、PHP-FPM、SSL）
- `music/qq.php`、`music/netease.php`：音乐接口代理（需要 cookies）

***

## 四、核心函数库参考（utils.php）

### 4.1 配置

| 函数 | 说明 |
|------|------|
| `loadAppConfig()` | 读取 `app-config.json`（静态缓存） |
| `appConfig($key, $default)` | 取配置项（空值回落默认） |
| 常量 `APP_VERSION` | `appConfig('version')` —— **版本唯一事实来源**，代码禁止硬编码版本 |

### 4.2 CSRF

| 函数 | 说明 |
|------|------|
| `generateCsrfToken()` | 生成 32 字节 token 存 Session，返回 |
| `verifyCsrfToken($token)` | **消费型**：校验后作废 token（单次高敏操作） |
| `checkCsrfToken($token)` | **非消费型**：可重复使用（同页多次操作） |

> ⚠️ 二者不可混用。同一页面先消费后校验会报 `csrf_error`。经验：`trigger_update`/`upload_package` 用 `checkCsrfToken`。

### 4.3 用户与角色

| 函数 | 说明 |
|------|------|
| `loadUsers()` / `saveUsers()` | 读写 `data/.users.json` |
| `genId()` | `bin2hex(random_bytes(8))` 16 位 ID |
| `loadRoles()` | 角色默认清单（含向后兼容 `admin`） |
| `checkRole($requiredRole)` | 等级比较鉴权（核心） |
| `requireRole($role)` | 鉴权失败：记越权日志 + 403 退出 |
| `getCurrentUserRole()` / `getCurrentUserId()` | 当前会话角色/ID |
| `getStationPath()` / `getAuthorPath()` / `isDefaultPathHidden()` | 后台路径 |
| `validateCustomPath($path)` | 路径白名单校验（正则 + 保留字） |

### 4.4 封禁与 IP

| 函数 | 说明 |
|------|------|
| `loadBansList()` / `saveBansList()` | 读写 `data/.bans.json` |
| `addBan($ip, $types, $reason)` | 追加封禁范围（同 IP 合并 types） |
| `isIPBanned($ip, $type)` | 判断某类型是否被封禁 |
| `getClientIP()` | **仅信任** `X-Real-IP`（且 `REMOTE_ADDR==127.0.0.1`），拒绝 `X-Forwarded-For` |

### 4.5 日志

| 函数 | 说明 |
|------|------|
| `logAbnormal($ip, $action)` | 异常行为日志（上限 500 条） |
| `logUnauthorized($action, $ban)` | 越权日志（UA 转义截断 256 字节）；`$ban=true` 且开启 `auto_ban_unauthorized` 时自动封禁 |
| `auditLog($action, $target, $detail, $result)` | 审计日志（哈希链）——见第八章 |
| `verifyAuditChain()` | 全链重算校验，返回 `['valid'=>bool, 'broken_at'=>i, 'count'=>n]` |
| `recoverAuditFromMirror()` | 从 `/opt/you-markdown/logs/` 恢复审计日志 |
| `sendAlert($type, $detail)` | 调用 `/usr/local/bin/ym-alert` 发邮件（`admin_email` 需配置） |

### 4.6 认证

| 函数 | 说明 |
|------|------|
| `getJWTSecret()` | 读取/生成 `data/.jwt_secret`（600） |
| `generateJWT($userId, $role, $ttl)` | 签发 HS256 JWT（payload 含 jti） |
| `validateJWT($token)` | 验签 + 过期校验，返回 payload 或 false |

### 4.7 站点配置

| 函数 | 说明 |
|------|------|
| `loadSiteConfig()` | 读 `data/.config.json`（带默认值） |
| `saveSiteConfig($config)` | 写回（注意：`file_put_contents` 需 `LOCK_EX` 建议，现有实现直接写） |

### 4.8 更新辅助

| 函数/常量 | 说明 |
|------|------|
| `UPDATE_REQUEST_FILE` = `/tmp/ym-update-request.json` | 更新请求（单例） |
| `UPDATE_LOCK_FILE` = `/tmp/ym-update.lock` | 更新锁（守护休眠标志） |
| `BACKUP_DIR` = `/opt/you-markdown/backups` | 备份目录 |
| `getUpdateRequest()` / `saveUpdateRequest($data)` | 读写请求文件 |
| `isUpdateInProgress()` | 锁存在且未过期？过期自动清理 |
| `setUpdateLock($token, $ttl)` / `clearUpdateLock()` | 设置/清理更新锁 |
| `getUpdateStatus()` | 返回请求状态（`idle` 或请求内容） |
| `checkForUpdates($channel)` | 检查远程仓库最新版本 |

### 4.9 其它

| 函数 | 说明 |
|------|------|
| `isPrivateHost($host)` | 内网/保留 IP 判定（防 SSRF 用） |

***

## 五、数据模型（data 目录 JSON 结构）

所有文件为 UTF-8 JSON（`JSON_PRETTY_PRINT`），写操作须 `LOCK_EX`。

### 5.1 `.users.json`（用户）

```json
[
  {
    "id": "a1b2c3d4e5f6a7b8",
    "qq": "station_abc123",
    "nickname": "张三",
    "password": "$2y$10$...（bcrypt）",
    "avatar": "https://q1.qlogo.cn/g?b=qq&nk=...&s=100",
    "signature": "",
    "role": "station_admin",
    "created": "2026-08-14 10:00:00"
  }
]
```

### 5.2 `.config.json`（站点配置）

默认值见 utils.php `loadSiteConfig()`。扩展字段：`bg_api_url`、`music_playlist_id`、`music_cookies` 等。

### 5.3 `.bans.json`（封禁）

```json
[{"ip": "1.2.3.4", "types": ["login", "register", "comment"], "reason": "蜜罐攻击达阈值", "time": "2026-08-14 10:00:00"}]
```

### 5.4 `.roles.json`（角色权限）

```json
{
  "super_admin": {"label": "高级管理员", "can": ["*"]},
  "station_admin": {"label": "站长", "can": ["article.create", "..."]},
  "author": {"label": "写作者", "can": ["article.create", "article.edit_own", "article.delete_own"]},
  "user": {"label": "用户", "can": ["comment.create", "profile.edit"]},
  "guest": {"label": "访客", "can": ["article.read"]}
}
```

### 5.5 `.audit.json`（审计日志，哈希链）

```json
[
  {
    "id": "hex",
    "ts": "2026-08-14 10:00:00.123",
    "user_id": "...", "user_name": "张三", "role": "super_admin",
    "ip": "x.x.x.x",
    "action": "system_update", "target": "", "detail": "...", "result": "success",
    "hash": "sha256（含 prev_hash 的整条记录）"
  }
]
```

> 哈希链校验逻辑：重放时对每条记录去掉 `hash` 字段、加入上一链尾作为 `prev_hash`、按原样 `json_encode`（`JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES`）后 SHA-256，与 `hash` 比对。

### 5.6 其它数据文件

| 文件 | 内容 |
|------|------|
| `.entries.json` | `[{token, otp_hash, expires, used, created}]` OTP 入口 |
| `.challenge.json` | `[{code, expires, used, created}]` 挑战码 |
| `.pinned.json` | 置顶文章文件名数组 |
| `.login_fails.json` / `.reg_rates.json` / `.comment_rates.json` | `[{ip, t}]` 滑动窗口计数 |
| `.unauthorized.json` | `[{ip, action, user, user_id, ua, time}]` 越权日志 |
| `.logs.json` | `[{ip, action, time}]` 异常日志 |
| `.hfish_snapshot.json` | 蜜罐快照（IP 聚合攻击数据） |
| `.comments/<安全化文章名>.json` | 评论树（含 replies 多级） |

***

## 六、API 接口文档（api.php）

全部 POST 需 CSRF（Header `X-CSRF-Token`）。统一响应 `{success, error?, ...}`。

| action | 方法 | 权限 | 说明 |
|--------|:---:|------|------|
| `avatar` | GET | 公开 | QQ 头像代理（5s 超时，失败返回 SVG 占位） |
| `register` | POST | 公开(开关) | 注册；封禁/频率/IP 限制检查；bcrypt 存密码 |
| `login` | POST | 公开 | 登录；失败计数 1 小时 → 自动封禁 |
| `logout` | POST | 登录 | 清 Session |
| `check` | GET | — | 会话校验（返回登录态 + 用户信息） |
| `user-status` | GET | 登录 | 返回角色、能否进后台、后台 URL（按路径配置） |
| `update_profile` | POST | 登录 | 改昵称/签名 |
| `admin_setup` | POST | 超管 | 修改超管自身 QQ/昵称/密码 |
| `get` | GET | 公开 | 拉某文章评论 |
| `post` | POST | 登录/访客(开关) | 发评论；封禁/频率限制/内容过滤 |
| `reply` | POST | 登录/访客(开关) | 回复（递归查找父评论） |
| `delete` | POST | 作者本人/站长+ | 删评论/回复；越权记日志 |
| `bg_upload` | POST | 超管 | 背景图上传（MIME 检测 + ≤10MB） |
| `bg_config` | POST/GET | 超管/公开 | 背景配置读写 |
| `entry_path_config` | POST/GET | 超管/公开 | 后台路径配置读写（POST 记审计） |

***

## 七、认证与授权实现细节

### 7.1 登录链路

```
用户提交 → api.php?action=login
  → isIPBanned(ip,'login') 检查
  → 遍历 users 匹配 qq + password_verify
  → 成功：session_regenerate_id(true) + 写 $_SESSION['cmt_user']（含 pw_hash）
  → 失败：写 .login_fails.json，1 小时内 >= max_login_fails → addBan(ip,['login'])
```

### 7.2 后台鉴权链路（以超管为例）

```
admin/dashboard.php 首部：
  checkRole(ROLE_SUPER_ADMIN)      # Session 角色等级 >= 50
    ↓ 失败 → logUnauthorized + 跳 /?admin_login=1
  validateJWT($_SESSION['cmt_user']['jwt'])
    ↓ 失败 → 清 Session + 跳 /?admin_login=1&expired=1
```

站长/写作者后台用 `ROLE_STATION_ADMIN`/`ROLE_AUTHOR` 同理。**写操作还须挑战码 + CSRF**。

### 7.3 OTP 入口（admin/entry.php）

1. `ym-admin login` 生成 `{token, otp_hash, expires=+600, used=0}` 写 `.entries.json`
2. 访问 `/admin/entry/<token>` → 匹配 token
3. 提交 OTP → `password_verify(otp, otp_hash)` + 未过期 + 未使用 → 标记 used
4. 成功 → 签发超管 JWT（30 分钟）→ 进后台

### 7.4 挑战码（敏感操作）

1. `ym-admin challenge` → `{code: 6位hex, expires=+300, used=0}` 写 `.challenge.json`
2. 后台输入 → 遍历校验（code 匹配 + 未过期 + 未使用）→ 标记 used
3. 一次一码；300 秒；失败拒绝并提示重新生成

***

## 八、审计日志与哈希链实现

### 8.1 写入（auditLog）

```
entry = {id, ts(毫秒), user_id, user_name, role, ip, action, target, detail, result}
prev_hash = 读 .audit_chain（链尾）
entryJson = json_encode(entry + {prev_hash}, UNESCAPED_UNICODE|UNESCAPED_SLASHES)
entry.hash = sha256(entryJson)          # 注意 hash 字段不参与自身计算
写 .audit.json（追加，上限 10000 条）
写 .audit_chain = entry.hash            # 新链尾
镜像写 /opt/you-markdown/logs/{audit.json,audit_chain}
```

### 8.2 校验（verifyAuditChain）

从头逐条重算：`json_encode(entry去掉hash + {prev_hash=上一hash})` → sha256 与 `entry.hash` 比对。任一失配即返回 `broken_at`。

### 8.3 恢复（recoverAuditFromMirror）

从 `/opt/you-markdown/logs/` 拷贝覆盖主日志 + 链尾。

### 8.4 守护背书

`ym-guard.py` 每 5 分钟校验一次：正常 → 把链尾追加写入 `/opt/you-markdown/logs/audit_chain`（背书）；断裂 → 尝试从镜像恢复 → 成功记日志 + 告警，失败 → 告警。

***

## 九、文件守护进程实现（ym-guard.py）

### 9.1 启动流程

1. 自校验自身完整性（比对母本中的 `ym-guard.py`）
2. 读 `data/.config.json` 加载配置
3. 注册 systemd watchdog（`WatchdogSec=30s` 心跳）
4. 启动 inotify 监控线程（14 个文件，WATCH_FILES 列表）
5. 启动后台线程：每 5 分钟审计校验 + 蜜罐同步
6. 启动子进程心跳监控（父进程守护工作子进程）

### 9.2 inotify 事件处理

```
修改/删除/移动 → 与母本比对 → 不一致 → copy(母本, web) 恢复 → 审计记录 + （可配）告警
更新锁存在（/tmp/ym-update.lock 未过期）→ 休眠：不恢复不告警
inotify 句柄耗尽 → 降级为轮询模式
```

### 9.3 WATCH_FILES（14 个，改动需同步三处）

`index.php`、`api.php`、`utils.php`、`admin/entry.php`、`admin/dashboard.php`、`station/dashboard.php`、`author/dashboard.php`、`data/.users.json`、`data/.roles.json`、`data/.config.json`、`data/.bans.json`、`data/.audit.json`、`data/.audit_chain`、`data/.entries.json`

> ⚠️ 新增监控文件时：1) 改 `ym-guard.py` 的 `WATCH_FILES`；2) 同步更新 README/开发文档的监控清单；3) 更新包需包含新 `ym-guard.py`。

### 9.4 六层保活（勿删）

systemd `Restart=always` + `WatchdogSec=30s` + 子进程心跳 + cron 5 分钟兜底 + inotify 降级 + 启动自校验。

***

## 十、在线更新机制实现

### 10.1 状态机与文件

```
触发更新（后台 trigger_update，校验挑战码+CSRF+package_path 白名单）
  → saveUpdateRequest({status:'pending', expires:+600, ...}) → chmod 0666
  → setUpdateLock()（守护休眠）
  → 后台提示：SSH 执行 sudo ym-admin apply-update
apply-update（ym-admin）：
  1. 读请求（必须 pending）→ 标记 in_progress
  2. 备份（排除 data/.git，保留最近 2 次）
  3. systemctl stop ym-guard；chattr -R -i 母本
  4. 解压包 → 定位 STAGE_DIR → 全量(--delete)/增量(无 --delete 且排除 version.json) rsync 到 web + 母本
  5. chattr -R +i 母本；重启守护
  6. preg_replace 更新 utils.php 的 APP_VERSION → 审计日志 → 标记 completed
```

### 10.2 增量识别（ym-admin apply-update 关键）

```bash
# 解压后：无 index.php/utils.php 但存在 version.json 且 type=incremental → 增量
STAGE_DIR 定位失败且 version.json 存在 → PTYPE=$(php -r 'echo $v["type"]')
PTYPE=incremental → rsync -a --exclude='data/' --exclude='version.json'（无 --delete）
否则全量 → rsync -a --delete（必须含核心文件，否则中止）
```

### 10.3 CSRF 选择（重要）

- 上传包：`checkCsrfToken`（非消费型，同页后续还要触发更新）
- 触发更新：`checkCsrfToken`（同上）
- 其它单次高敏表单：`verifyCsrfToken`（消费型）

### 10.4 已知缺陷（v2.3.3，待修复）

| 问题 | 现象 | 建议修复 |
|------|------|---------|
| 请求无超时处理 | `pending/in_progress` 永不失效，后台"等待中/进行中"卡死 | `getUpdateStatus()` 中对 `expires<time()` 的 pending/in_progress 自动标记 `failed('请求超时')` |
| apply-update 失败不写状态 | 失败后停留 in_progress | 各失败分支统一写 `status=failed + error=<原因>` |
| 手动清理 | `sudo rm -f /tmp/ym-update-request.json /tmp/ym-update.lock` | — |

***

## 十一、蜜罐联动实现

```
ym-guard.py（每 5 分钟线程）
  → 调 ym-hfish-sync.py
    → 只读 sqlite hfish.db（ip_profile 表）
    → 聚合各 IP 攻击总次数
    → 写 data/.hfish_snapshot.json（后台展示数据）
    → attack_cnt >= hfish_ban_threshold（默认 3）且未封禁 → addBan(ip, [login,register,comment])
超管后台 hfish 页签：读快照展示 + 「立即同步」手动触发（CSRF + 审计）
```

> 蜜罐库**只读**；快照文件不在守护监控列表（允许变化）。

***

## 十二、代码提交规范（Git）

### 12.1 提交信息（Conventional Commits）

```
<type>(<scope>): <subject>
```

type：`feat` / `fix` / `docs` / `refactor` / `perf` / `security` / `chore` / `revert`。

示例：
```
feat(hfish): 新增超管后台蜜罐日志查看页签
fix(update): 修复上传包后 package_path 丢失导致更新失败
security(csrf): trigger_update 改用非消费型 token
```

### 12.2 提交前检查

1. **不提交**：密码/密钥、`data/` 用户数据、`.pw.txt`、`*.pem/key`、`*.log`、调试文件（`debug*`/`test*`/`entry_fixed*`）、构建产物（`*.tar.gz`/`*.zip`/`staging/`/`inc-stage/`）
2. **语法自检**：PHP `php -l <file>`；Shell `bash -n <file>`；Python `python3 -m py_compile <file>`
3. 一次提交一件事
4. 版本一致性：`app-config.json` version == README 标题 == git tag == 更新包版本

### 12.3 PowerShell 提交提示

PowerShell 不支持 `cat <<EOF` heredoc 与 `&&`。提交用单行 `-m` 即可：

```powershell
git add <files...>; git commit -m "feat: 描述"
```

***

## 十三、安全开发规范（强制）

1. **鉴权**：每请求先读角色再判权限；禁止绕过 `checkRole()`/`validateJWT()`
2. **CSRF**：POST 表单/AJAX 必须带 token；多次操作 `checkCsrfToken()`，单次高敏 `verifyCsrfToken()`，不得混用
3. **挑战码**：300 秒、单次；敏感操作（更新/用户管理/守护控制/系统配置）必须验证
4. **审计**：所有操作走 `auditLog()`（哈希链），禁止绕过
5. **数据隔离**：`data/*.json` Nginx deny；日志/报错不输出敏感路径
6. **输入校验**：用户输入 `htmlspecialchars()`；上传路径白名单（`/tmp/ym-update-packages/` 前缀）；上传文件 MIME 检测
7. **文件写入**：`file_put_contents` 一律 `LOCK_EX`
8. **密钥**：不落盘、不入库；生产密码经 SSH/环境注入
9. **文件守护**：核心文件被篡改会从母本恢复；本地源码与服务器必须同步（否则守护进程会把服务器恢复成旧版）
10. **蜜罐**：蜜罐库只读；封禁阈值默认 3 次
11. **IP**：只信 `X-Real-IP`（REMOTE_ADDR=127.0.0.1 时）；拒绝 `X-Forwarded-For`
12. **更新包**：全量排除 `data/`；增量必须带 `type=incremental` 的 version.json

***

## 十四、代码风格

| 语言 | 规范 |
|------|------|
| PHP | PSR-12 兼容；`<?php` 起始；函数下划线命名；严格比较 `===`；核心函数勿改名 |
| Python | PEP8；`snake_case`；模块 docstring 注明版本号 |
| Shell | 变量加引号；非关键错误 `2>/dev/null || true` |
| 注释 | 复杂逻辑写"为什么"；中文注释；禁止冗余 |

HTML/JS：原生写法，保持与现有文件一致；新交互先确认不破坏 `main.js` 现有事件绑定。

***

## 十五、测试与部署规范

1. **部署**：`sudo bash ym-install.sh` 或 SSH 手动部署
2. **功能**：三大后台核心操作、蜜罐页签、挑战码流程、播放器显隐（配置 `music_cookies` 空/非空双向验证）
3. **更新**：全量/增量包各执行一次完整流程，确认版本号、文件完整性、`data/` 保留
4. **攻击**：验证五层防御（OTP 入口/角色分权/审计哈希链/文件守护/数据隔离）
5. **回归**：登录、评论、文章、背景等旧功能不受影响
6. **打包前**：对 PHP 文件执行 `php -l`（必查）；对改动脚本 `bash -n`/`py_compile`
7. **服务器验证**：部署后 `curl -skL https://127.0.0.1/ | grep 关键内容` + `systemctl is-active ym-guard` + 版本号 grep

***

## 十六、发布流程（版本升级 Checklist）

1. 改 `app-config.json` 的 `version`
2. 同步 README 标题、`ym-guard.py`、`ym-install.sh`、`ym-hfish-sync.py`、`css/admin.css` 版本注释
3. `php -l` 全量语法检查（PHP）；`bash -n`/`py_compile`（脚本）
4. 组装全量包到 `update-package`（含 `version.json` `{"version":"x.y.z"}`，排除 data/）
5. 若有需要，组装增量包（仅变更文件 + `version.json` `{"version":"<to>","type":"incremental","from":"<from>"}`）
6. 更新 `update-package/version.json`
7. git commit + tag `v<版本>`
8. 服务器走更新流程验证（全量或增量）：
   - 上传包 → `sudo ym-admin challenge` → 后台输入 → `sudo ym-admin apply-update`
   - 验证：版本号、页面功能、守护进程 active、母本一致
9. 同步主库 → `test_development copy` → 根目录文档

***

## 十七、已知问题与踩坑记录

### 17.1 已知问题

| 问题 | 状态 |
|------|------|
| 更新历史无超时/失败处理（卡"等待中/进行中"） | 待修复（见 10.4） |
| `saveSiteConfig()` 未加 `LOCK_EX` | 低风险，建议补齐 |
| `reset-admin` 后旧 OTP 入口仍可能残留 | 用 `ym-admin login` 生成新入口即可 |

### 17.2 踩坑记录（务必阅读）

1. **后台白屏**：`dashboard.php` 的 tab `if/elseif` 链中提前 `<?php endif; ?>` 会制造孤儿 `elseif`。改后台必须 `php -l` 后再部署
2. **csrf_error**：消费型与非消费型 token 混用。上传包 + 触发更新必须用 `checkCsrfToken`
3. **package_path 丢失**：前端 trigger_update 必须把上传响应的 `package_path` 一并提交，否则 apply-update 走仓库分支报 `repo_url 为空`
4. **写请求文件 Permission denied**：容器下 root 写 www-data 0666 文件可能失败 → 创建后 `@chmod(UPDATE_REQUEST_FILE, 0666)`；apply-update 前再 `chmod 666`
5. **挑战码 60 秒**：旧版 ym-admin 是 60 秒；标准版必须为 `+300 seconds`（v2.2.4+）。部署前 grep 确认 `/usr/local/bin/ym-admin` 字节数约 21017
6. **本地源码与服务器不同步**：守护进程会从母本恢复，若本地改了文件而服务器未更新，会把服务器"恢复"成旧版。改动后必须走更新流程
7. **沙箱文件系统漂移**（Windows 本地）：PowerShell 沙箱视图可能与真实文件系统不一致（曾导致 robocopy /MIR 误删 ym-*.py）。大范围复制用单文件 `Copy-Item`，避免 `/MIR`
8. **网站标题不变**：标题来自 `data/.config.json` 的 `site_title`（更新包排除 data/），改后台配置而非改代码
9. **增量包不能再用旧 ym-admin 应用**：旧版 `--delete` 会删掉目标多余文件。先全量升级到含增量识别的新 ym-admin
10. **tar 打包**：Windows tar 不支持 `--force-local`；用 `-C <目录> .` 形式

***

## 附：文档矩阵

| 文档 | 位置 | 面向 |
|------|------|------|
| README.md（项目总览） | 根目录 / 主库 | 使用/运维/了解 |
| DEVELOPMENT_GUIDELINES.md（本文档） | 根目录 / 主库 | 开发/接手/发布 |
| WELCOME.md | 根目录 / 主库 | 首次部署引导 |
| COMMANDS.md | 根目录 / 主库 | 命令速查 |
