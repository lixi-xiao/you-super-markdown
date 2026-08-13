# You Super Markdown v2.3.3 使用指南

> 基于 PHP 的轻量级 Markdown 在线阅读器，集成五层纵深防御体系。

***

## 一、项目简介

You Super Markdown 是一个基于 PHP 的在线 Markdown 阅读平台，支持文章发布、评论互动、音乐播放、多角色权限管理。v2.3.3 版本围绕"纵深防御"对系统进行了全面重构，在保持原有功能的基础上，从架构层面消除了 20 个已知漏洞，并新增**蜜罐安全联动**（超管后台查看蜜罐攻击日志 + 触发蜜罐行为自动封禁）。

***

## 二、功能特性

- Markdown 文章发布与实时渲染
- 评论系统（支持回复、点赞，可配置频率限制）
- 音乐播放（QQ 音乐 / 网易云音乐 ）
- 网站背景自定义（图片 / API / 纯色 + 模糊效果）
- 五层角色权限体系
- 动态 OTP 入口（高级管理员）
- 操作审计日志（哈希链防篡改 + 自动校验）
- 文件守护进程（inotify 监控 + 母本自动恢复）
- 邮件告警通知
- 在线更新（需挑战码验证）
- 蜜罐安全联动（攻击日志查看 + 触发自动封禁，v2.3.3 新增）

***

## 三、系统架构

### 五层防御体系

```
第一层：隐藏入口（动态 OTP + 自定义后台路径，不可预测）→ 阻断攻击链 1
第二层：角色分权（五层权限，最小权限原则）       → 阻断攻击链 2
第三层：审计日志（哈希链防篡改 + 自动校验 + 邮件告警）→ 阻断攻击链 3
第四层：文件守护（inotify 监控 + 母本秒级恢复）  → 阻断攻击链 1
第五层：数据隔离（Nginx deny + OAuth 白名单）   → 阻断攻击链 2
```

### 目录结构

```
you-markdown/
├── index.php              # 网站首页 / 文章阅读
├── api.php                # REST API 接口
├── utils.php              # 核心工具函数（角色、JWT、日志、CSRF、更新）
├── sc.php                 # Markdown 编辑器
├── 404.php                # 404 页面
├── music.php              # 音乐播放路由
├── admin/                 # 高级管理员后台
│   ├── entry.php          # OTP 动态入口验证页
│   └── dashboard.php      # 高级管理员后台（含蜜罐安全页签）
├── station/               # 站长后台（路径可通过配置自定义）
│   └── dashboard.php      # 站长后台（管理写作者）
├── author/                # 写作者后台（路径可通过配置自定义）
│   └── dashboard.php      # 写作者后台（管理自己的文章）
├── data/                  # 数据目录（Nginx 已隔离）
│   ├── .users.json        # 用户数据
│   ├── .config.json       # 站点配置
│   ├── .bans.json         # 封禁列表
│   ├── .logs.json         # 操作日志
│   ├── .unauthorized.json # 越权日志
│   ├── .audit.json        # 审计日志（哈希链）
│   ├── .hfish_snapshot.json # 蜜罐安全快照（v2.3.3）
│   ├── articles/          # 文章目录
│   ├── comments/          # 评论数据
│   ├── bg/                # 背景图片
│   └── avatars/           # 头像图片
├── css/                   # 样式文件
├── js/                    # JavaScript 文件
├── fonts/                 # 字体文件
├── music/                 # 音乐平台接口
│   ├── qq.php             # QQ 音乐
│   └── netease.php        # 网易云音乐
├── ym-guard.py            # 文件守护进程（含蜜罐周期同步）
├── ym-hfish-sync.py       # 蜜罐同步与自动封禁脚本（v2.3.3）
├── ym-install.sh          # 一键安装脚本
├── ym-admin               # CLI 管理工具
├── app-config.json        # 全局配置（版本号、仓库地址、蜜罐参数）
└── nginx-site.conf        # Nginx 配置模板
```

***

## 四、安装部署

### 环境要求

| 组件     | 最低版本                                              |
| ------ | ------------------------------------------------- |
| Linux  | Ubuntu 20.04+ / Debian 11+ / CentOS 8+            |
| PHP    | 8.0+（含 php-fpm, php-json, php-mbstring, php-curl） |
| Nginx  | 1.18+                                             |
| Python | 3.8+（守护进程需要，含 watchdog 包）                         |
| 磁盘     | 1GB 以上                                            |

### 方式一：一键安装（推荐）

```bash
# 上传项目文件到服务器后
sudo bash ym-install.sh
```

脚本会自动完成：

1. 环境检查（PHP / Nginx / Python）
2. 交互收集参数（域名、邮箱、Web 根目录）
3. 部署项目文件 + 权限设置
4. 创建超级管理员（随机密码，仅输出一次）
5. 生成 OTP 动态入口（仅输出一次）
6. 配置 Nginx（安全头 + data 目录隔离 + PHP-FPM）
7. 申请 Let's Encrypt SSL 证书
8. 部署守护进程（systemd + cron 兜底）
9. 配置防火墙（ufw）
10. 安装 CLI 管理工具
11. （可选）部署 Hfish 蜜罐

安装完成后终端会输出类似：

```
========================================
  安装完成！

  网站地址：https://your-domain.com
  管理入口：https://your-domain.com/admin/entry/a3Bf9xQ2mZ1k
  一次性密码：Kx9#mZ2!pTq8

  ⚠️ 以上信息仅显示一次，请立即保存！
========================================
```

### 方式二：手动部署

**1. 部署文件**

```bash
sudo mkdir -p /var/www/you-markdown
sudo cp -r * /var/www/you-markdown/
sudo chown -R www-data:www-data /var/www/you-markdown
sudo find /var/www/you-markdown -type d -exec chmod 755 {} \;
sudo find /var/www/you-markdown -type f -exec chmod 644 {} \;
```

**2. 创建数据目录**

```bash
sudo mkdir -p /var/www/you-markdown/data/{articles,comments,bg,avatars}
sudo chown -R www-data:www-data /var/www/you-markdown/data
```

**3. 创建超级管理员**

```bash
cd /var/www/you-markdown
sudo php -r "
require_once 'utils.php';
\$users = [];
\$users[] = [
    'id' => genId(),
    'qq' => 'admin',
    'nickname' => '超级管理员',
    'password' => password_hash('你的密码', PASSWORD_DEFAULT),
    'role' => ROLE_SUPER_ADMIN,
];
saveUsers(\$users);
echo '超级管理员已创建\n';
"
```

**4. 配置 Nginx**

将 `nginx-site.conf` 复制到 `/etc/nginx/sites-available/` 并启用：

```bash
sudo cp nginx-site.conf /etc/nginx/sites-available/you-markdown
sudo ln -s /etc/nginx/sites-available/you-markdown /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

**5. 部署守护进程（可选但强烈推荐）**

```bash
# 创建母本目录
sudo mkdir -p /opt/you-markdown/install-base
sudo cp /var/www/you-markdown/index.php /opt/you-markdown/install-base/
sudo chattr +i /opt/you-markdown/install-base/index.php  # 锁定母本

# 安装守护进程
sudo cp ym-guard.py /opt/you-markdown/
sudo pip3 install watchdog

# 创建 systemd 服务
sudo tee /etc/systemd/system/ym-guard.service << 'EOF'
[Unit]
Description=You Super Markdown File Guard
After=network.target
Before=nginx.service

[Service]
Type=notify
ExecStart=/usr/bin/python3 /opt/you-markdown/ym-guard.py
Restart=always
RestartSec=5
WatchdogSec=30
Environment=YM_WEB_ROOT=/var/www/you-markdown
ProtectSystem=full
OOMScoreAdjust=-900
NoNewPrivileges=yes

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now ym-guard
```

***

## 五、角色体系

### 五层角色

| 角色    | 常量                   | 优先级 | 登录方式     |  有效期  |
| ----- | -------------------- | :-: | -------- | :---: |
| 高级管理员 | `ROLE_SUPER_ADMIN`   |  50 | OTP 动态入口 | 30 分钟 |
| 站长    | `ROLE_STATION_ADMIN` |  40 | 账号 + 密码  | 24 小时 |
| 写作者   | `ROLE_AUTHOR`        |  30 | 账号 + 密码  | 24 小时 |
| 普通用户  | `ROLE_USER`          |  20 | 注册 + 密码  |  无限制  |
| 访客    | `ROLE_GUEST`         |  10 | 无需登录     |   —   |

### 权限矩阵

| 操作          | 高级管理员 |  站长 | 写作者 |  用户 |  访客 |
| ----------- | :---: | :-: | :-: | :-: | :-: |
| 浏览文章        |   ✅   |  ✅  |  ✅  |  ✅  |  ✅  |
| 评论          |   ✅   |  ✅  |  ✅  |  ✅  | 可配置 |
| 创建文章        |   ✅   |  ✅  |  ✅  |  —  |  —  |
| 编辑/删除自己的文章  |   ✅   |  ✅  |  ✅  |  —  |  —  |
| 编辑/删除任何人的文章 |   ✅   |  ✅  |  —  |  —  |  —  |
| 创建/删除写作者    |   ✅   |  ✅  |  —  |  —  |  —  |
| 创建/删除站长     |   ✅   |  —  |  —  |  —  |  —  |
| 网站配置        |   ✅   |  —  |  —  |  —  |  —  |
| 网站背景        |   ✅   |  —  |  —  |  —  |  —  |
| 封禁管理        |   ✅   |  —  |  —  |  —  |  —  |
| 查看日志（含蜜罐）    |   ✅   |  —  |  —  |  —  |  —  |
| 在线更新        |   ✅   |  —  |  —  |  —  |  —  |

***

## 六、登录方式

### 高级管理员（OTP 动态入口）

高级管理员不使用固定密码，而是通过 SSH 生成一次性入口。

**步骤：**

1. SSH 登录服务器，执行：
   ```bash
   sudo ym-admin login
   ```
2. 终端输出：
   ```
   入口 URL：https://your-domain.com/admin/entry/a3Bf9xQ2mZ1k
   OTP：Kx9#mZ2!pTq8
   ```
3. 浏览器访问入口 URL，输入 OTP，登录成功。
4. OTP 特性：
   - 10 分钟内有效
   - 一次性使用（用过即废）
   - 入口 URL 不可预测（12 位随机字符）
   - 不存在的入口统一返回 404

### 站长 / 写作者 / 普通用户

通过固定账号密码登录。访问网站首页，点击登录按钮，输入 QQ 号和密码。

***

### 自定义入口路径（L1 扩展）

v2.3.3 支持自定义站长和写作者后台的 URL 路径前缀，进一步加强隐藏入口防御。

**配置方式：**

1. **通过后台 UI**：高级管理员登录后台 → 系统配置 → 自定义入口路径
2. **通过 CLI**：
   ```bash
   sudo ym-admin set-paths my-station my-writer
   ym-admin show-paths          # 查看当前配置（只读，无需 sudo）
   ```

**规则：**

- 路径仅允许字母、数字和连字符，长度 4-30 字符
- 不能使用系统保留关键字（admin、api、data 等）
- 站长路径和写作者路径不能相同
- 设置自定义路径后，默认的 `/station/` 和 `/author/` 返回 404（可配置）

***

## 七、后台管理

### 高级管理员后台

**入口：** 通过 OTP 动态入口登录后自动跳转

**功能：**

| 板块   | 功能                       |
| ---- | ------------------------ |
| 人员管理 | 创建/删除站长、创建/删除写作者、封禁/解封用户 |
| 安全监控 | 查看登录日志、越权日志、审计日志（哈希链校验）  |
| 蜜罐安全 | 查看蜜罐攻击日志、立即同步、自动封禁状态（v2.3.3） |
| 系统配置 | 网站标题、注册开关、访客评论开关、更新通道    |
| 守护进程 | 查看守护状态、手动触发校验、查看母本状态     |
| 在线更新 | 检查更新、挑战验证、上传更新包、执行更新、回滚 |

### 站长后台

**入口：** 登录后访问 `/station/dashboard.php`

**功能：**

| 板块   | 功能           |
| ---- | ------------ |
| 作者管理 | 创建/删除下属写作者   |
| 文章管理 | 查看/编辑/删除所有文章 |

### 写作者后台

**入口：** 登录后访问 `/author/dashboard.php`

**功能：**

| 板块   | 功能            |
| ---- | ------------- |
| 我的文章 | 查看/编辑/删除自己的文章 |
| 创建文章 | 发布新文章         |

***

## 八、CLI 管理工具

`ym-admin` 是超级管理员的命令行管理工具，安装脚本会自动部署到 `/usr/local/bin/ym-admin`。

### 命令列表

```bash
sudo ym-admin login              # 生成新的 OTP 入口（管理员登录用）
sudo ym-admin create-station 名称  # 创建站长账号
sudo ym-admin create-author 名称   # 创建写作者账号
sudo ym-admin revoke-user 用户ID   # 吊销某用户
sudo ym-admin backup             # 备份 data 目录到 /opt/you-markdown/backups/
ym-admin status                  # 查看守护进程 + 服务状态（只读，无需 sudo）
ym-admin log-verify              # 手动触发审计日志哈希链校验（只读，无需 sudo）
sudo ym-admin challenge          # 生成 6 位挑战码（300 秒有效）
sudo ym-admin apply-update       # 执行系统更新（从 Web 后台触发后执行）
sudo ym-admin rollback           # 回滚到上一次更新前的备份
sudo ym-admin reset-admin        # 重置超级管理员密码
sudo ym-admin set-paths <站长> <作者>  # 设置自定义后台入口路径
ym-admin show-paths              # 查看当前入口路径配置（只读，无需 sudo）
ym-admin hfish-panel             # 建立 SSH 隧道访问 Hfish 管理面板（只读，无需 sudo）
ym-admin hfish-status            # 查看 Hfish 蜜罐状态（只读，无需 sudo）
```

> 💡 **权限说明**：`ym-admin` 中所有「写操作」命令都需要加 `sudo` 执行（普通用户无权写 `/var/www/you-markdown/data/` 或 `/opt/you-markdown/`），包括：`login`、`create-station`、`create-author`、`revoke-user`、`backup`、`challenge`、`apply-update`、`rollback`、`reset-admin`、`set-paths`。只读命令（`status`、`log-verify`、`show-paths`、`hfish-panel`、`hfish-status`）无需 `sudo`。

### 使用示例

```bash
# 创建站长
$ sudo ym-admin create-station 张三
站长账号已创建：
  QQ号：station_zhangsan
  密码：aB3xK9mZ2pQ
  角色：station_admin

# 创建写作者（归属某个站长）
$ sudo ym-admin create-author 李四 --station station_zhangsan
写作者账号已创建：
  QQ号：author_lisi
  密码：qW5yJ8nR1tE
  角色：author
  所属站长：station_zhangsan

# 生成登录入口
$ sudo ym-admin login
入口 URL：https://your-domain.com/admin/entry/Xk7Mp2QvR9zN
OTP：Tx9#pL4!mW6y
⚠️ 以上信息仅显示一次，10 分钟内有效！
```

***

## 九、守护进程

### 概况

`ym-guard.py` 是独立于 PHP 的 Python 守护进程，提供文件完整性保护、审计日志校验和蜜罐周期同步。

**六层保活机制：**

|  层级 | 机制                      | 说明                   |
| :-: | ----------------------- | -------------------- |
|  1  | systemd Restart=always  | 进程崩溃自动重启             |
|  2  | systemd WatchdogSec=30s | 30 秒无心跳则强杀重启         |
|  3  | 子进程心跳                   | 父进程监控子进程，卡死即重启       |
|  4  | cron 5 分钟兜底             | 定时检查守护进程是否存活         |
|  5  | inotify 耗尽降级            | inotify 句柄耗尽时自动切换为轮询 |
|  6  | 自校验                     | 启动时验证自身完整性           |

### 监控文件

守护进程使用 inotify 实时监控以下文件（共 14 个，一旦被修改/删除，秒级从只读母本恢复）：

- `index.php` — 网站首页
- `api.php` — API 接口
- `utils.php` — 核心工具函数
- `admin/entry.php` — 超管 OTP 入口
- `admin/dashboard.php` — 超管后台
- `station/dashboard.php` — 站长后台
- `author/dashboard.php` — 写作者后台
- `data/.users.json` — 用户数据
- `data/.roles.json` — 角色数据
- `data/.config.json` — 站点配置
- `data/.bans.json` — 封禁列表
- `data/.audit.json` — 审计日志
- `data/.audit_chain` — 审计哈希链尾
- `data/.entries.json` — OTP 动态入口

> 母本 `/opt/you-markdown/install-base/` 由安装/更新流程全量同步（排除 `data/`），所有监控文件均有母本副本，被篡改时逐文件秒级恢复并记录审计日志。

### 审计日志校验

守护进程每 5 分钟自动校验审计日志哈希链：

1. 从头到尾重算哈希链，比对链尾是否一致
2. 发现断裂 → 从 `/opt/you-markdown/logs/audit.json` 镜像恢复
3. 恢复成功 → 写一条"恢复成功"日志 + 邮件通知超管
4. 恢复失败 → 邮件告警

### 蜜罐周期同步（v2.3.3）

守护进程每 5 分钟自动运行 `ym-hfish-sync.py`：

1. 只读读取 Hfish 蜜罐数据库（`ip_profile` 攻击者画像）
2. 生成蜜罐快照 `data/.hfish_snapshot.json` 供超管后台展示
3. 攻击总次数达到阈值（默认 3 次）的 IP 自动封禁登录/注册/评论

### 更新锁机制

系统更新时，守护进程检测到 `/tmp/ym-update.lock` 文件后自动进入休眠模式：

- 不恢复文件（避免更新过程中反复恢复旧版本）
- 不触发哈希链告警
- 仍记录日志（便于审计）
- 10 分钟超时自动恢复保护

更新完成后，CLI 自动清理锁文件，守护进程恢复主动保护。

### 状态查看

```bash
# 查看守护进程状态
ym-admin status

# 或手动
systemctl status ym-guard
```

### 告警邮件

告警触发条件：

- 日志哈希链断裂
- 核心文件被篡改
- 核心文件从母本恢复
- 守护进程异常退出
- 越权访问激增（5 分钟内超过阈值）

***

## 十、Hfish 蜜罐（可选安全组件）

### 概况

Hfish 是一个开源的蜜罐平台，部署在服务器上用于诱捕和检测攻击行为。You Super Markdown v2.3.3 可选集成 Hfish，在安装脚本中一键部署。**v2.3.3 新增蜜罐安全联动：超管后台可查看蜜罐攻击日志，攻击次数达到阈值（默认 3 次）的 IP 将自动封禁登录/注册/评论。**

### 安装

```bash
# 默认安装（推荐）
sudo bash ym-install.sh

# 跳过蜜罐安装
sudo bash ym-install.sh --skip-hfish
```

安装过程中会交互式配置蜜罐端口（默认 8080 假 HTTP + 2222 假 SSH）。

### 访问管理面板

管理面板不对外开放，通过 SSH 隧道安全访问：

```bash
ym-admin hfish-panel
```

### 查看状态

```bash
ym-admin hfish-status
```

### v2.3.3 蜜罐安全联动

v2.3.3 新增超管后台「蜜罐安全」页签与蜜罐触发自动封禁机制：

1. **蜜罐日志查看**：超管后台侧边栏点击「蜜罐安全」，可查看蜜罐攻击日志（攻击 IP / 攻击次数 / 攻击行为 / 命中蜜罐 / UA / 最近时间 / 封禁状态），数据来自蜜罐数据库的只读快照。
2. **立即同步**：点击「立即同步」按钮手动刷新蜜罐数据并执行封禁检查（需要 CSRF Token，同步操作会写入操作审计日志）。
3. **触发蜜罐自动封禁**：攻击者的蜜罐攻击总次数达到阈值（默认 3 次，可在 `app-config.json` 的 `hfish_ban_threshold` 调整）时，该 IP 自动封禁登录、注册、评论（写入 `data/.bans.json`）。已封禁 IP 不会重复封禁。
4. **自动同步机制**：文件守护进程 `ym-guard.py` 每 5 分钟自动运行蜜罐同步脚本 `ym-hfish-sync.py`（只读读取蜜罐数据库，不修改蜜罐数据），保证封禁及时生效。

**相关配置（`app-config.json`）**：

```json
{
  "hfish_user": "xiao",
  "hfish_ban_threshold": 3,
  "hfish_db_path": "/usr/share/hfish/database/hfish.db"
}
```

**手动同步命令**：

```bash
sudo python3 /var/www/you-markdown/ym-hfish-sync.py
```

### 安全注意事项

- 蜜罐端口在安装时交互配置，不在源码中硬编码
- 管理面板仅监听 127.0.0.1，不暴露到公网
- 通过 SSH 隧道访问，无需开放额外防火墙端口
- 攻击者触碰蜜罐端口即被记录，可作为入侵检测的早期预警

***

## 十一、安全机制

### CSRF 防护

所有 POST 表单提交均需携带 CSRF Token。Token 由 `random_bytes(32)` 生成，存储在 session 中；页面内可多次操作的操作使用非消费型校验，单次高敏操作使用消费型校验。

### JWT 会话管理

- 超管 JWT 有效期 30 分钟
- 站长/写作者 JWT 有效期 24 小时
- 密钥随机生成 32 字节，存储在 `data/.jwt_secret`（权限 600）
- 每个 JWT 包含 `jti`（唯一 ID），可用于吊销

### 密码存储

所有密码使用 PHP `password_hash()` 函数 bcrypt 加密存储，自动加盐，每次生成不同哈希值。

### 数据目录隔离

`data/*.json` 文件通过 Nginx 配置禁止外部访问：

```nginx
location ~ ^/data/.*\.json$ {
    deny all;
    return 403;
}
```

### 输入过滤

- 所有用户输入经过 `htmlspecialchars()` 转义
- 评论内容过滤控制字符
- 评论长度限制 1000 字
- 昵称长度限制 20 字
- 评论频率限制（默认 3 条/分钟，可配置）

### IP 安全

- 仅信任本地 Nginx 中转的 `X-Real-IP` 头
- 拒绝 `X-Forwarded-For` 伪造
- 登录失败次数过多 → 自动封禁 IP
- 越权访问次数过多 → 自动封禁 IP

### 审计日志

所有操作（包括登录、评论、文章创建/编辑、配置修改、用户管理、蜜罐同步）全部记录到 `data/.audit.json`。每条日志包含：

- 时间（精确到毫秒）
- 操作人（ID + 角色）
- 操作类型
- 操作对象
- 操作细节
- 来源 IP
- 结果（成功/失败）

日志使用哈希链保护：每条日志的 SHA256 哈希链接上一条日志，形成链式结构。修改任意一条，整条链的哈希就断裂。

***

## 十二、在线更新

在线更新采用 **Web 后台发起 + SSH 确认 + CLI 执行** 的混合模式，确保安全可控。

### 流程概览

```
 超管后台                            SSH 终端
 ──────────                        ──────────
 ① 检查更新
    → 检测最新版本
 ② 点击"执行更新"
    → 弹出挑战码输入框
                                  ③ sudo ym-admin challenge
                                     → 生成 6 位确认码（300 秒有效）
 ④ 输入确认码
    → 验证通过
    → 写入更新请求 + 守护进程休眠标志
    → 显示"请在 SSH 中执行：sudo ym-admin apply-update"
                                  ⑤ sudo ym-admin apply-update
                                     → 自动完成 6 步：
                                       1. 备份 Web 目录（保留最近 2 次）
                                       2. 停止守护进程
                                       3. 解锁母本目录
                                       4. 应用更新（上传包或仓库）
                                       5. 锁定母本 + 重启守护进程
                                       6. 记录审计日志
 ⑥ 轮询更新状态
    → 显示"更新成功"
    → 显示版本变更: v2.2.4 → v2.3.3
    → 3 秒后自动刷新页面
```

### 更新方式

| 方式 | 触发方式 | 适用场景 |
|------|---------|---------|
| **GitHub 拉取** | 后台点击"检查更新"自动检测 | 有外网访问权限的服务器 |
| **手动上传 ZIP/tar.gz** | 后台选择更新包文件上传 | 内网服务器或需要离线更新 |

### 包类型

| 类型 | 文件名约定 | 说明 |
|------|-----------|------|
| 全量包 | `you-super-markdown-v2.3.3-full.tar.gz` | 包含所有文件，直接覆盖（含 `version.json`） |
| 增量包 | `you-super-markdown-v2.3.0-to-v2.3.3-inc.tar.gz` | 仅包含变更文件 + `version.json`（`{"version":"2.3.3","type":"incremental","from":"2.3.0"}`），应用时**覆盖式合并**：只更新包内文件，不删除现有文件 |

> ⚠️ **注意**：应用增量包需要支持增量识别的 `ym-admin`（v2.3.3 及以上版本）。若服务器仍是旧版 `ym-admin`，请先全量升级至 v2.3.3 或手动替换 `/usr/local/bin/ym-admin`。

### 回滚机制

更新失败时自动回滚：

```
更新失败检测（exit code ≠ 0 或校验和不匹配）
  → 自动恢复到最近备份（rsync 还原）
  → 重新锁定母本目录
  → 重启守护进程
  → 记录审计日志："更新失败，自动回滚至 v{x}"
```

手动回滚：

```bash
sudo ym-admin rollback
# 列出可用备份：
#   [1] v2.2.4 (2026-08-14 01:00:00) - 12.3M
#   [2] v2.2.1 (2026-08-13 10:30:00) - 11.8M
# 输入编号选择要回滚的版本
```

### 备份策略

| 项目 | 方案 |
|------|------|
| 备份内容 | WEB_ROOT 全量（排除 `data/` 目录） |
| 保留数量 | 最近 **2 次** |
| 存储位置 | `/opt/you-markdown/backups/` |
| 命名格式 | `pre-update-{version}-{timestamp}.tar.gz` |

### SSH 手动更新（兜底）

```bash
sudo systemctl stop ym-guard
# ... 部署新版本 ...
sudo cp -r /var/www/you-markdown/* /opt/you-markdown/install-base/
sudo chattr -R +i /opt/you-markdown/install-base/
sudo systemctl start ym-guard
```

***

## 十三、常见问题

**Q: 忘记了高级管理员密码怎么办？**

高级管理员不使用固定密码，通过 SSH 执行 `sudo ym-admin login` 生成新的 OTP 入口即可登录。

**Q: 站长/写作者忘记了密码怎么办？**

高级管理员登录后台 → 人员管理 → 找到对应用户 → 重置密码。

**Q: 守护进程不工作了怎么办？**

```bash
# 查看状态
systemctl status ym-guard

# 重启
sudo systemctl restart ym-guard

# 查看日志
sudo journalctl -u ym-guard -f
```

**Q: 如何恢复被篡改的首页？**

守护进程会自动恢复。如果守护进程未运行，手动执行：

```bash
sudo cp /opt/you-markdown/install-base/index.php /var/www/you-markdown/index.php
```

**Q: 如何添加站长？**

高级管理员登录后台 → 人员管理 → 创建站长。

或者通过 CLI：

```bash
sudo ym-admin create-station 站长名称
```

**Q: 如何启用/禁用访客评论？**

高级管理员登录后台 → 系统配置 → 访客评论开关。

**Q: 蜜罐攻击次数达到多少会封禁？**

默认 3 次（`app-config.json` 的 `hfish_ban_threshold`），达到后自动封禁登录/注册/评论，可在超管后台「蜜罐安全」页查看。

**Q: 如何从旧版本迁移？**

1. 备份旧版 `data/` 目录
2. 部署新版项目文件
3. 将备份的 `data/` 复制回去
4. 运行 `sudo ym-admin reset-admin` 重新创建超级管理员

***

## 十四、配置参考

### 全局配置（app-config.json）

| 配置项 | 默认值 | 说明 |
| ------ | ------ | ---- |
| `app_name` | `You Super Markdown` | 应用名称 |
| `version` | `2.3.3` | 当前版本（唯一事实来源） |
| `repo_owner` / `repo_name` / `repo_url` | 空 | GitHub 仓库信息（在线更新用） |
| `hfish_user` | `xiao` | Hfish 蜜罐管理账户 |
| `hfish_ban_threshold` | `3` | 蜜罐攻击次数封禁阈值 |
| `hfish_db_path` | `/usr/share/hfish/database/hfish.db` | 蜜罐数据库路径 |

### 站点配置项（data/.config.json）

| 配置项                      | 类型     | 默认值            | 说明                   |
| ------------------------ | ------ | -------------- | -------------------- |
| `site_title`             | string | `You Super Markdown` | 网站标题                 |
| `guest_comments_enabled` | bool   | `false`        | 是否允许访客评论             |
| `registration_enabled`   | bool   | `true`         | 是否允许新用户注册            |
| `update_channel`         | string | `stable`       | 更新通道（stable/beta）    |
| `bg_type`                | string | `none`         | 背景类型（none/image/api） |
| `bg_image`               | string | —              | 背景图片路径               |
| `bg_blur_enabled`        | bool   | `false`        | 是否启用背景模糊             |
| `bg_blur_level`          | int    | `0`            | 模糊程度（0-50）           |
| `bg_card_opacity`        | int    | `100`          | 卡片透明度（20-100）        |
| `auto_ban`               | bool   | `true`         | 是否自动封禁               |
| `auto_ban_threshold`     | int    | `5`            | 自动封禁阈值               |
| `reg_limit_per_ip`       | int    | `3`            | 每 IP 注册限制            |
| `comment_rate_limit`     | int    | `3`            | 每分钟评论限制              |
| `station_path`           | string | `station`      | 站长后台 URL 路径前缀        |
| `author_path`            | string | `author`       | 写作者后台 URL 路径前缀       |
| `hide_default_paths`     | bool   | `true`         | 自定义路径生效后隐藏默认路径       |
