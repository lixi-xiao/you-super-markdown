# You Super Markdown v2.3.3 项目总览

> 基于 PHP 的轻量级 Markdown 在线阅读平台，集成五层纵深防御体系。本文档为**项目唯一权威介绍**，面向使用者、运维与接手人，覆盖功能、架构、部署、使用、安全、更新与配置的全部要点。看完本文档 + 同目录下的《开发规范》（DEVELOPMENT_GUIDELINES.md）即可完整了解本项目。

- 当前版本：**v2.3.3**
- 运行环境：Linux + Nginx + PHP 8.x + Python 3（守护进程）
- 定位：自托管式 Markdown 阅读/写作/评论平台，强调**安全默认、纵深防御**

***

## 目录

- [一、项目简介](#一项目简介)
- [二、功能特性](#二功能特性)
- [三、版本演进](#三版本演进)
- [四、技术栈与环境要求](#四技术栈与环境要求)
- [五、系统架构](#五系统架构)
- [六、目录结构与数据文件](#六目录结构与数据文件)
- [七、安装部署](#七安装部署)
- [八、五层角色体系与权限](#八五层角色体系与权限)
- [九、登录与认证方式](#九登录与认证方式)
- [十、三个管理后台](#十三个管理后台)
- [十一、CLI 管理工具（ym-admin）](#十一cli-管理工具ym-admin)
- [十二、文件守护进程（ym-guard）](#十二文件守护进程ym-guard)
- [十三、Hfish 蜜罐联动（可选）](#十三hfish-蜜罐联动可选)
- [十四、安全机制详解](#十四安全机制详解)
- [十五、在线更新机制](#十五在线更新机制)
- [十六、配置参考](#十六配置参考)
- [十七、常见问题（FAQ）](#十七常见问题faq)

***

## 一、项目简介

You Super Markdown 是一个自托管（Self-hosted）的 Markdown 在线阅读平台。用户可以在网页上浏览、搜索、阅读 Markdown 文章，登录后可发表评论、回复、点赞；写作者可以发布自己的文章；站长可以管理写作者与全部文章；高级管理员（超管）掌握全部系统权限（配置、封禁、日志、更新、守护进程）。

项目从 v2.0 起围绕"**纵深防御（Defense in Depth）**"进行了全面重构，消除 20 个已知安全漏洞，并将防御体系归纳为五层独立防线：

| 防线 | 名称 | 作用 | 主要阻断目标 |
|:-:|------|------|------|
| L1 | 隐藏入口 | 动态 OTP 入口 + 自定义后台路径，攻击者无法预测管理入口 | 扫描/爆破管理入口 |
| L2 | 角色分权 | 五层权限 + 最小权限原则，每次请求先鉴权 | 越权操作 |
| L3 | 审计日志 | 哈希链防篡改 + 自动校验 + 镜像备份 + 邮件告警 | 删改日志抹除痕迹 |
| L4 | 文件守护 | inotify 秒级监控 + 母本（chattr +i）自动恢复 | 篡改核心文件 |
| L5 | 数据隔离 | Nginx deny `data/*.json` + 目录权限收紧 | 直接下载数据 |

**设计原则**（贯穿全部代码）：纵深防御、最小权限、默认安全、失败封闭（fail-closed）、原子操作、密钥不落盘。

***

## 二、功能特性

### 阅读与内容
- Markdown 文章在线渲染与阅读（支持目录、返回顶部、字号/主题/配色调整）
- 文章置顶（站长及以上）、上一篇/下一篇导航
- 全站搜索、文章收藏与分享（二维码）
- 网站背景自定义：纯色 / 图片上传 / 第三方 API 图源 + 模糊效果 + 卡片透明度

### 互动
- 评论系统：登录用户评论，支持多级回复与点赞
- 访客评论开关（可由超管配置）
- 评论频率限制（每 IP 每分钟条数可配）、评论内容控制字符过滤、长度限制

### 音乐播放（v2.3.3 起按配置隐藏）
- 内置 QQ 音乐 / 网易云音乐热歌榜播放器（`music/qq.php`、`music/netease.php`）
- **v2.3.3 行为变更**：音乐入口默认**隐藏**，仅当后台配置了非空的 `music_cookies` 后才显示。未配置时前端不渲染音乐按钮（DOM 保留、JS 判空保护，无报错）

### 账号与角色
- 五层角色：`super_admin` > `station_admin` > `author` > `user` > `guest`
- 超管 OTP 动态入口登录（无固定密码）；站长/写作者/用户账号密码登录
- 账号注册开关、注册频率限制（每 IP）、登录失败自动封禁

### 安全
- 操作审计日志（SHA-256 哈希链防篡改 + 三冗余备份 + 守护进程背书）
- 服务器挑战码机制（敏感操作二次确认，6 位码、300 秒、单次使用）
- CSRF 双重防护（消费型/非消费型 Token）
- JWT 会话（含 `jti`，超管 30 分钟 / 站长写作者 24 小时）
- IP 安全（仅信任 `X-Real-IP`、封禁登录/注册/评论、越权封禁）
- 文件守护进程（inotify 秒级恢复 + 母本锁定 + 六层保活）
- 蜜罐安全联动（Hfish 攻击日志查看 + 攻击次数达阈值自动封禁）
- 数据目录 Nginx 隔离、上传文件类型校验、输入输出转义

### 管理
- 超管后台：人员/安全/日志/配置/背景/更新/守护/蜜罐 8 大板块
- 站长后台：写作者管理 + 全站文章管理
- 写作者后台：仅管理自己的文章
- 在线更新：Web 后台发起 + SSH 挑战码确认 + CLI 执行（支持全量包与增量包）
- CLI 管理工具 `ym-admin`：登录入口、建号、吊销、备份、状态、审计校验、挑战码、更新、回滚等

***

## 三、版本演进

| 版本 | 核心内容 |
|------|---------|
| v2.0 | 基线版本；修复首批漏洞（CSRF、IP 伪造、原子锁、CDN 保护等） |
| v2.2.x | 五层角色体系替代 admin/user 二元体系；三大独立后台；OTP 动态入口；JWT 短时效登录；服务器挑战码；操作日志哈希链 + 三冗余备份；消除中高危漏洞 |
| v2.3.0 | 新增**蜜罐安全联动**：超管后台蜜罐日志页签 + 攻击达阈值（默认 3 次）自动封禁登录/注册/评论；守护进程每 5 分钟周期同步 |
| v2.2.4 | 中间版本：`ym-admin` 支持**增量包识别**（覆盖式合并，不删除目标文件）+ 挑战码 300 秒 |
| v2.3.3 | 音乐播放器**按配置隐藏**（默认隐藏，配置 `music_cookies` 后显示）；`ym-admin` 标准版定为含增量识别 + 300 秒挑战码；文档体系完善 |

> 说明：更新包命名中的版本基线以实际包为准（例如增量包 `you-super-markdown-v2.3.0-to-v2.3.3-inc.tar.gz` 表示从 v2.3.0 升级到 v2.3.3）。

***

## 四、技术栈与环境要求

### 技术栈

| 层面 | 技术 |
|------|------|
| 后端 | PHP 8.x（FPM），无框架，原生 PHP |
| 前端 | 原生 HTML/CSS/JavaScript（无构建工具） |
| Web 服务器 | Nginx 1.18+ |
| 守护进程 | Python 3.8+（`watchdog` 库，inotify） |
| 数据库 | **无**。全部数据以 JSON 文件存储于 `data/` 目录 |
| 会话/认证 | PHP Session + JWT（HS256）+ OTP 一次性密码 |
| 蜜罐（可选） | Hfish 开源蜜罐平台 |
| 证书 | Let's Encrypt（安装脚本自动申请） |

### 环境要求

| 组件 | 最低版本 | 说明 |
|------|---------|------|
| Linux | Ubuntu 20.04+ / Debian 11+ / CentOS 8+ | 需 root 安装 |
| PHP | 8.0+ | 需 `php-fpm`、`php-json`、`php-mbstring`、`php-curl` |
| Nginx | 1.18+ | 需 `nginx` 主包 |
| Python | 3.8+ | 需 `pip3` 与 `watchdog` |
| 磁盘 | 1GB 以上 | 数据量增长按文章/日志估算 |
| 内存 | 512MB+ | 守护进程 + PHP-FPM + Nginx |
| 端口 | 80、443、22 | 防火墙仅放行这三个（安装脚本自动配置） |

***

## 五、系统架构

### 请求流程

```
浏览器 ──HTTPS──▶ Nginx（静态资源 + 安全头 + data 隔离）
                    │  PHP-FPM
                    ▼
              index.php / api.php / music.php / admin / station / author
                    │
                    ▼
              utils.php（鉴权/日志/CSRF/JWT/封禁/更新辅助）
                    │
                    ▼
              data/*.json（JSON 文件存储）
                ▲
                │ inotify 秒级监控（被篡改→从母本恢复）
        ── ym-guard.py（Python 守护进程）
```

### 模块划分

| 模块 | 文件 | 职责 |
|------|------|------|
| 前台 | `index.php` | 首页、文章阅读、渲染、评论 UI、播放器 |
| API | `api.php` | 登录/注册/评论/背景/入口路径等全部 JSON 接口 |
| 编辑器 | `sc.php` | Markdown 写作编辑器页面 |
| 工具库 | `utils.php` | 全局核心函数（角色/JWT/日志/CSRF/更新/封禁/IP） |
| 超管后台 | `admin/entry.php`、`admin/dashboard.php` | OTP 入口 + 8 大管理板块 |
| 站长后台 | `station/dashboard.php` | 写作者管理 + 文章管理 |
| 写作者后台 | `author/dashboard.php` | 我的文章管理 |
| 守护 | `ym-guard.py` | 文件完整性保护 + 蜜罐周期同步 |
| 蜜罐同步 | `ym-hfish-sync.py` | 蜜罐只读同步 + 阈值封禁 |
| 安装 | `ym-install.sh` | 一键安装（环境/部署/建号/Nginx/守护/防火墙） |
| CLI | `ym-admin` | 管理命令行工具 |
| 配置 | `app-config.json` | 应用级配置（版本号/仓库/蜜罐） |
| 站点配置 | `data/.config.json` | 站点级配置（标题/开关/路径/背景） |

***

## 六、目录结构与数据文件

### 目录结构

```
you-markdown/
├── index.php              # 网站首页 / 文章阅读（含 MD5 自校验钩子）
├── api.php                # REST 风格 JSON API
├── utils.php              # 核心工具函数（约 500 行，含全部安全原语）
├── sc.php                 # Markdown 编辑器
├── music.php              # 音乐播放路由
├── 404.php                # 404 页面
├── admin/
│   ├── entry.php          # 超管 OTP 动态入口验证页
│   └── dashboard.php      # 超管后台（8 大板块）
├── station/
│   └── dashboard.php      # 站长后台（路径前缀可自定义）
├── author/
│   └── dashboard.php      # 写作者后台（路径前缀可自定义）
├── css/                   # 样式（style.css 前台 / admin.css 后台）
├── js/
│   └── main.js            # 前台全部交互逻辑
├── fonts/
│   └── luoliti.ttf        # 站内字体
├── music/
│   ├── qq.php             # QQ 音乐热歌接口（需 cookies）
│   └── netease.php        # 网易云音乐热歌接口（需 cookies）
├── youyou/                # 预留目录
├── data/                  # ★ 数据目录（Nginx 隔离，git 排除）
│   ├── .users.json        # 用户数据
│   ├── .config.json       # 站点配置
│   ├── .bans.json         # 封禁列表
│   ├── .roles.json        # 角色定义与权限清单
│   ├── .logs.json         # 异常行为日志
│   ├── .unauthorized.json # 越权日志
│   ├── .audit.json        # 审计日志（哈希链）
│   ├── .audit_chain       # 审计哈希链尾（根只读）
│   ├── .entries.json      # OTP 动态入口（超管登录）
│   ├── .challenge.json    # 挑战码（300 秒，单次）
│   ├── .jwt_secret        # JWT 密钥（权限 600）
│   ├── .pinned.json       # 置顶文章列表
│   ├── .login_fails.json  # 登录失败计数
│   ├── .reg_rates.json    # 注册频率计数
│   ├── .comment_rates.json# 评论频率计数
│   ├── .hfish_snapshot.json # 蜜罐安全快照
│   ├── articles/          # Markdown 文章
│   ├── .comments/         # 评论数据（按文章分文件）
│   ├── bg/                # 背景图上传
│   └── avatars/           # 头像缓存
├── ym-guard.py            # 文件守护进程（含蜜罐周期同步线程）
├── ym-hfish-sync.py       # 蜜罐同步与自动封禁脚本
├── ym-install.sh          # 一键安装脚本
├── ym-admin               # CLI 管理工具（12 个命令）
├── app-config.json        # 全局配置
├── nginx-site.conf        # Nginx 站点配置模板
├── robots.txt / .htaccess # 爬虫协议 / 兼容配置
└── version.json           # 更新包版本标识（随包分发，部署后由 app-config 主导）
```

### 数据文件详解（重要）

| 文件 | 结构 | 说明 |
|------|------|------|
| `.users.json` | 数组，元素含 `id/qq/nickname/password(bcrypt)/avatar/signature/role/created` | 全部用户；`password` 为 `password_hash()` 哈希 |
| `.config.json` | 对象 | 站点配置（见第十六章），含 `station_path/author_path/music_cookies` 等 |
| `.bans.json` | 数组，元素含 `ip/types[login,register,comment]/reason/time` | 封禁 IP 与封禁范围 |
| `.roles.json` | 对象：`{角色: {label, can[]}}` | 角色权限清单，默认含五角色 + 向后兼容 `admin` |
| `.audit.json` | 数组，元素含 `id/ts(毫秒)/user_id/user_name/role/ip/action/target/detail/result/hash` | 审计日志；`hash` 为含 `prev_hash` 的整条记录的 SHA-256 |
| `.audit_chain` | 单行文本 | 哈希链尾（最新一条的 hash）；同时镜像到 `/opt/you-markdown/logs/` |
| `.entries.json` | 数组，元素含 `token/otp_hash/expires/used/created` | OTP 登录入口（10 分钟、单次） |
| `.challenge.json` | 数组，元素含 `code/expires/used/created` | 服务器挑战码（300 秒、单次） |
| `.jwt_secret` | 单行 64 hex | JWT 签名密钥（生成即 `chmod 600`） |
| `articles/*.md` | Markdown 文本 | 文章主体 |
| `.comments/*.json` | 数组，元素含 `id/user_id/qq/nickname/avatar/signature/content/likes/replies[]/created_at` | 每篇文章一个文件，文件名以文章名安全化命名 |

> **审计日志哈希链原理**：每条日志写入前取链尾 hash 作为自己的 `prev_hash`，对整条记录（含 `prev_hash`）计算 SHA-256 作为 `hash` 并成为新链尾。修改/删除/插入任何一条都会导致后续所有 `hash` 失配，`verifyAuditChain()` 可逐条重算定位断裂点。链尾实时镜像到 `/opt/you-markdown/logs/`（root 目录），守护进程每 5 分钟背书一次。

***

## 七、安装部署

### 方式一：一键安装（推荐）

```bash
# 以 root 或 sudo 执行
sudo bash ym-install.sh

# 跳过蜜罐组件安装
sudo bash ym-install.sh --skip-hfish
```

脚本自动完成（按顺序）：

1. 前置检查：root 权限、系统发行版（Ubuntu/Debian/CentOS）、PHP 8.x、Nginx、Python 3
2. 交互收集参数：**域名、邮箱、Web 根目录**（默认 `/var/www/you-markdown`）
3. 部署项目文件到 Web 根目录并设置权限（`www-data:www-data`）
4. 初始化 `data/` 数据结构（用户/角色/配置/入口）
5. 创建超级管理员：随机账号 + 一次性密码（**仅输出一次**）
6. 生成 OTP 动态管理入口 URL（**仅输出一次**）
7. 配置 Nginx：安全响应头、`data/*.json` deny、PHP-FPM 对接、443 监听、HTTP 跳转
8. 申请 Let's Encrypt 证书
9. 部署母本目录 `/opt/you-markdown/install-base/` 并 `chattr +i` 锁定（root 只读）
10. 部署守护进程 `ym-guard.py`：systemd 服务（`Restart=always`、`WatchdogSec=30s`）+ cron 5 分钟兜底
11. 配置防火墙 ufw：仅放行 80/443/22
12. 安装 CLI 管理工具到 `/usr/local/bin/ym-admin`
13. （可选）部署 Hfish 蜜罐（交互配置端口）

安装完成后终端输出（仅一次）：

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

```bash
# 1. 部署文件
sudo mkdir -p /var/www/you-markdown
sudo cp -r ./* /var/www/you-markdown/
sudo chown -R www-data:www-data /var/www/you-markdown
sudo find /var/www/you-markdown -type d -exec chmod 755 {} \;
sudo find /var/www/you-markdown -type f -exec chmod 644 {} \;

# 2. 创建数据目录
sudo mkdir -p /var/www/you-markdown/data/{articles,comments,bg,avatars}
sudo chown -R www-data:www-data /var/www/you-markdown/data

# 3. 配置 Nginx（使用 nginx-site.conf）
sudo cp nginx-site.conf /etc/nginx/sites-available/you-markdown
sudo ln -s /etc/nginx/sites-available/you-markdown /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# 4. 创建超级管理员（用 ym-admin，或直接初始化 users 文件）
sudo /usr/local/bin/ym-admin reset-admin   # 交互式设置超管

# 5. 部署守护进程（强烈推荐，见第十二章）
```

### 生产环境服务器规划

| 路径 | 用途 | 权限 |
|------|------|------|
| `/var/www/you-markdown` | Web 根目录 | `www-data:www-data` |
| `/opt/you-markdown/install-base` | 母本（只读镜像） | root + `chattr +i` |
| `/opt/you-markdown/logs` | 审计镜像（root 只读） | root |
| `/opt/you-markdown/backups` | 更新前备份 | root |
| `/usr/local/bin/ym-admin` | CLI 管理工具 | root 可执行 |
| `/etc/systemd/system/ym-guard.service` | 守护进程服务 | root |
| `/tmp/ym-update-packages` | 后台上传更新包暂存 | www-data（0666） |
| `/tmp/ym-update-request.json` | 更新请求（单例） | www-data（0666） |
| `/tmp/ym-update.lock` | 更新锁（守护休眠） | www-data |

***

## 八、五层角色体系与权限

### 角色等级

| 角色 | 常量 | 等级值 | 登录方式 | JWT 有效期 |
|------|------|:---:|----------|:---:|
| 高级管理员 | `ROLE_SUPER_ADMIN` | 50 | OTP 动态入口（无固定密码） | 30 分钟 |
| 站长 | `ROLE_STATION_ADMIN` | 40 | 账号 + 密码 | 24 小时 |
| 写作者 | `ROLE_AUTHOR` | 30 | 账号 + 密码 | 24 小时 |
| 普通用户 | `ROLE_USER` | 20 | 注册 + 密码 | —（Session） |
| 访客 | `ROLE_GUEST` | 10 | 无需登录 | — |

> 鉴权规则：`checkRole(required)` 将当前用户角色等级与目标角色等级比较，`当前等级 >= 目标等级` 即通过。每个请求（页面/AJAX）**先读角色再判权限**，越权行为写 `logUnauthorized()`。

### 权限矩阵

| 操作 | 超管 | 站长 | 写作者 | 用户 | 访客 |
|------|:---:|:---:|:---:|:---:|:---:|
| 浏览文章 | ✅ | ✅ | ✅ | ✅ | ✅ |
| 评论 / 回复 | ✅ | ✅ | ✅ | ✅ | 可配置 |
| 创建文章 | ✅ | ✅ | ✅ | — | — |
| 编辑/删除自己的文章 | ✅ | ✅ | ✅ | — | — |
| 编辑/删除任何人的文章 | ✅ | ✅ | — | — | — |
| 创建/删除写作者 | ✅ | ✅ | — | — | — |
| 创建/删除站长 | ✅ | — | — | — | — |
| 封禁/解封用户 | ✅ | — | — | — | — |
| 网站配置/背景/更新/守护 | ✅ | — | — | — | — |
| 查看日志（登录/越权/审计/蜜罐） | ✅ | — | — | — | — |

***

## 九、登录与认证方式

### 超管：OTP 动态入口（唯一方式）

超管**没有固定密码**。每次登录都需要在 SSH 生成一次性入口：

```bash
sudo ym-admin login
```

输出（仅一次）：

```
管理入口（仅显示一次，10分钟有效）
入口 URL: https://your-domain.com/admin/entry/a3Bf9xQ2mZ1k
一次性密码: Kx9#mZ2!pTq8
```

特性：入口 URL 12 位随机 token 不可预测；OTP 10 分钟有效、单次使用；不存在的入口统一 404。

### 站长 / 写作者 / 普通用户

账号（QQ 号）+ 密码登录，走 `api.php?action=login`。登录成功写 Session；站长/写作者在后台签发 JWT 维持 24 小时会话。

### 服务器挑战码（敏感操作二次确认）

超管在后台执行**敏感操作**（创建/删除用户、系统配置、在线更新、守护进程控制等）时，后台弹出挑战窗口：

1. SSH 执行 `sudo ym-admin challenge` → 生成 6 位码（**300 秒有效**、单次使用）
2. 后台输入码 → 校验通过 → 放行操作
3. 过期/已用 → 拒绝并提示重新生成

***

## 十、三个管理后台

### 1. 超管后台 `admin/dashboard.php`（OTP 登录后进入）

侧边栏 8 大板块：

| 页签 | 功能 |
|------|------|
| overview 概览 | 系统信息、版本、用户/文章/评论统计 |
| users 人员管理 | 创建/删除站长、创建/删除写作者、查看用户列表、封禁/解封、重置密码、修改角色 |
| logs 日志 | 查看登录日志、越权日志、操作日志（审计，自动校验哈希链，断裂自动告警） |
| config 系统配置 | 网站标题/描述、注册开关、访客评论开关、更新通道 |
| security 安全 | 封禁管理（添加/解除）、登录日志清理、越权日志清理、审计链校验与恢复 |
| background 背景 | 背景类型（none/image/api）、图片上传、模糊、卡片透明度 |
| update 在线更新 | 检查更新、上传更新包、挑战码验证、触发更新、更新状态、历史、备份 |
| guard 守护进程 | 状态、启停控制、手动校验、母本状态 |
| hfish 蜜罐安全 | 蜜罐攻击日志（IP/次数/行为/命中/UA/时间/封禁状态）、立即同步、自动封禁状态 |

### 2. 站长后台 `station/dashboard.php`（默认 `/station/dashboard.php`）

- 作者管理：创建/删除自己的写作者
- 文章管理：查看/编辑/删除**所有**文章

### 3. 写作者后台 `author/dashboard.php`（默认 `/author/dashboard.php`）

- 我的文章：仅查看/编辑/删除**自己**的文章
- 创建文章

### 自定义后台路径（L1 扩展，v2.2 起）

站长/写作者后台的 URL 前缀可通过超管后台「系统配置」或 CLI 修改：

```bash
sudo ym-admin set-paths my-station my-writer   # 设置自定义前缀
ym-admin show-paths                             # 查看当前前缀
```

规则：仅字母/数字/连字符，长度 4-30，首尾字母或数字；禁止保留字（admin/api/data/css/js/fonts/music/sc/index/404/youyou/oauth/login/logout/register）；两者不可相同；设置后默认 `/station`、`/author` 返回 404（可配 `hide_default_paths`）。

***

## 十一、CLI 管理工具（ym-admin）

安装后位于 `/usr/local/bin/ym-admin`。**写操作需 `sudo`，只读操作无需。**

| 命令 | 说明 | sudo |
|------|------|:---:|
| `ym-admin login` | 生成新的 OTP 入口（超管登录） | ✅ |
| `ym-admin create-station <名称>` | 创建站长账号 | ✅ |
| `ym-admin create-author <名称> [站长ID]` | 创建写作者账号 | ✅ |
| `ym-admin revoke-user <用户ID>` | 吊销某用户 | ✅ |
| `ym-admin backup` | 备份 data 目录到 `/opt/you-markdown/backups/` | ✅ |
| `ym-admin status` | 守护进程 + Nginx + PHP-FPM + 用户数 | — |
| `ym-admin log-verify` | 手动校验审计日志哈希链 | — |
| `ym-admin challenge` | 生成 6 位挑战码（300 秒、单次） | ✅ |
| `ym-admin apply-update` | 执行 Web 后台发起的更新（全量/增量自动识别） | ✅ |
| `ym-admin rollback` | 回滚到最近一次更新前备份 | ✅ |
| `ym-admin reset-admin` | 重置超管信息/密码 | ✅ |
| `ym-admin set-paths <站长> <作者>` | 设置自定义后台路径前缀 | ✅ |
| `ym-admin show-paths` | 查看当前路径前缀 | — |
| `ym-admin hfish-panel` | 建立 SSH 隧道访问 Hfish 管理面板 | — |
| `ym-admin hfish-status` | 查看 Hfish 蜜罐状态 | — |

> `apply-update` 的**增量识别**逻辑：解压包后若未发现核心文件（`index.php`/`utils.php`）但发现 `version.json` 且 `type=incremental`，则判定为增量包，使用**覆盖式合并**（`rsync` 不带 `--delete`，且排除 `version.json`）；否则按全量包处理（带 `--delete`，需要核心文件）。

***

## 十二、文件守护进程（ym-guard）

### 职责

- **完整性保护**：inotify 实时监控 14 个关键文件，被修改/删除秒级从母本恢复
- **审计背书**：每 5 分钟校验审计哈希链，链尾写入 `/opt/you-markdown/logs/`
- **蜜罐同步**：每 5 分钟运行 `ym-hfish-sync.py`（只读蜜罐库 + 阈值封禁）
- **告警**：通过 `sendAlert()` 调用 `/usr/local/bin/ym-alert` 发送邮件

### 六层保活

| 层级 | 机制 | 说明 |
|:-:|------|------|
| 1 | systemd `Restart=always` | 崩溃自动重启 |
| 2 | systemd `WatchdogSec=30s` | 30 秒无心跳强杀重启 |
| 3 | 子进程心跳 | 父进程监控工作子进程，卡死即重启 |
| 4 | cron 5 分钟兜底 | 定时检查存活 |
| 5 | inotify 耗尽降级 | 句柄耗尽自动切换轮询 |
| 6 | 自校验 | 启动时校验自身完整性 |

### 监控文件（14 个）

`index.php`、`api.php`、`utils.php`、`admin/entry.php`、`admin/dashboard.php`、`station/dashboard.php`、`author/dashboard.php`、`data/.users.json`、`data/.roles.json`、`data/.config.json`、`data/.bans.json`、`data/.audit.json`、`data/.audit_chain`、`data/.entries.json`

### 更新锁（休眠机制）

检测到 `/tmp/ym-update.lock`（10 分钟 TTL）后守护进程进入休眠：不恢复文件、不触发哈希链告警，避免更新过程中反复恢复旧文件。更新完成由 `ym-admin` 清理锁文件。

### 常用命令

```bash
systemctl status ym-guard     # 状态
sudo systemctl restart ym-guard
sudo journalctl -u ym-guard -f  # 实时日志
```

***

## 十三、Hfish 蜜罐联动（可选）

### 简介

Hfish 是开源蜜罐平台，安装脚本可一键部署（默认假 HTTP 8080 + 假 SSH 2222）。攻击者触碰蜜罐端口即被记录，形成入侵早期预警。

### 安全联动（v2.3.0+）

1. 守护进程每 5 分钟运行 `ym-hfish-sync.py`：只读读取蜜罐数据库（`ip_profile`）→ 生成快照 `data/.hfish_snapshot.json`
2. 超管后台「蜜罐安全」页签展示攻击日志（攻击 IP / 次数 / 行为 / 命中蜜罐 / UA / 最近时间 / 封禁状态），可手动「立即同步」
3. 攻击总次数达到阈值（默认 **3** 次，`app-config.json` 的 `hfish_ban_threshold`）→ 自动封禁该 IP 的登录/注册/评论（写入 `.bans.json`）；已封禁不重复
4. 蜜罐库始终**只读**访问，不修改蜜罐数据

### 访问管理面板

```bash
ym-admin hfish-panel    # 建立 SSH 隧道后浏览器访问（仅监听本机）
ym-admin hfish-status   # 查看蜜罐状态
```

### 相关配置（app-config.json）

```json
{
  "hfish_user": "xiao",
  "hfish_ban_threshold": 3,
  "hfish_db_path": "/usr/share/hfish/database/hfish.db"
}
```

***

## 十四、安全机制详解

### 14.1 请求层防护（5 层中间件链）

1. **安全头**：Nginx 统一输出 `X-Frame-Options`、`X-Content-Type-Options`、`Content-Security-Policy`、`Strict-Transport-Security` 等
2. **CORS**：仅同源允许；公网模式下自动收敛（不输出宽松跨域头）
3. **限速**：登录/注册/评论分别按 IP 滑动窗口限速（见 14.6）
4. **监控**：异常行为计数（登录失败、越权、频繁评论）
5. **黑名单**：`data/.bans.json` 命中即拒绝（登录/注册/评论）

### 14.2 CSRF 双重防护

- Token 由 `random_bytes(32)` 生成存 Session
- **消费型** `verifyCsrfToken()`：校验后立即作废（单次高敏操作）
- **非消费型** `checkCsrfToken()`：可重复使用（同页多次操作）
- 规则：`api.php` 所有 POST 校验 `X-CSRF-Token` 头；后台表单校验隐藏域 token；**不得混用两种机制**（曾导致 csrf_error bug）

### 14.3 JWT 会话

- HS256 签名，密钥 32 字节随机存 `data/.jwt_secret`（600）
- Payload 含 `sub/role/iat/exp/jti`；超管 30 分钟、站长/写作者 24 小时
- `jti` 唯一 ID 支持吊销；后台校验时校验签名 + 过期时间

### 14.4 密码存储

`password_hash(PASSWORD_DEFAULT)`（bcrypt）自动加盐。禁止明文/弱哈希。

### 14.5 IP 安全

- `getClientIP()`：仅当 `REMOTE_ADDR == 127.0.0.1` 且存在 `X-Real-IP` 时信任 `X-Real-IP`；**拒绝 `X-Forwarded-For`**（防伪造）
- 登录失败 ≥ `max_login_fails`（默认 10，1 小时内）→ 自动封禁登录
- 注册 ≥ `max_registrations_per_ip`（默认 3）→ 自动封禁注册
- 评论 ≥ `max_comments_per_minute`（默认 5）→ 自动封禁评论
- 越权操作（`auto_ban_unauthorized` 开启时）→ 自动封禁全部

### 14.6 速率限制

滑动窗口（1 分钟/1 小时）+ 按 IP 复合计数 + JSON 文件原子写入（`LOCK_EX`）。登录/注册/评论各独立计数文件（`.login_fails.json`/`.reg_rates.json`/`.comment_rates.json`）。

### 14.7 输入验证

- 用户输入输出一律 `htmlspecialchars()`（UA 日志截断 256 字节）
- 评论/回复：控制字符过滤、≤1000 字；昵称 ≤20 字；签名 ≤16 字
- 上传图片：`getimagesize()` MIME 检测 + 扩展名白名单（JPG/PNG/GIF/WebP）+ ≤10MB
- 自定义路径：正则白名单 + 保留字黑名单
- 更新包路径：仅接受 `/tmp/ym-update-packages/` 前缀

### 14.8 审计日志（防篡改）

- 所有操作走 `auditLog()`：ts(毫秒)/user_id/user_name/role/ip/action/target/detail/result + 哈希链
- 三冗余：主日志 `data/.audit.json` + 镜像 `/opt/you-markdown/logs/audit.json`（root 目录）+ 守护进程每 5 分钟背书链尾
- 超管查看日志时自动执行 `verifyAuditChain()`；断裂 → 从镜像恢复；恢复失败 → 邮件告警
- CLI 校验：`ym-admin log-verify`

### 14.9 文件保护

- 母本 `/opt/you-markdown/install-base/` 全量同步（排除 data/）且 `chattr +i` 锁定
- 更新/安装流程临时解锁 → 应用 → 重新锁定
- 核心文件被篡改 → inotify 秒级从母本恢复（`index.php` 前端另有 PHP 层 MD5 自校验钩子兜底）

### 14.10 公网/内网模式

`lan/public` 双模式自动切换：公网模式收敛 CORS/Swagger/XFF 信任，`BEHIND_PROXY` 条件信任必须正确配置；`WORKERS=1` 保证密钥一致性。

***

## 十五、在线更新机制

### 流程（Web + SSH 混合）

```
超管后台                              SSH 终端
─────────                           ─────────
① 检查更新 / 上传更新包（ZIP/tar.gz）
② 点击"执行更新"
    → 弹出挑战码输入框
                                    ③ sudo ym-admin challenge → 6 位码（300 秒）
④ 输入挑战码 → 校验通过
    → 写入更新请求（/tmp/ym-update-request.json）
    → 显示"请在 SSH 中执行：sudo ym-admin apply-update"
                                    ⑤ sudo ym-admin apply-update
                                       1. 备份（保留最近 2 次）
                                       2. 停守护 + 写更新锁
                                       3. 解锁母本
                                       4. 解压包 → 全量(rsync --delete)/增量(覆盖式合并) 应用
                                       5. 锁定母本 + 重启守护
                                       6. 更新 utils.php 版本 + 审计日志
⑥ 后台轮询更新状态 → 成功刷新
```

### 包类型

| 类型 | 命名 | 内容 | 应用方式 |
|------|------|------|---------|
| 全量包 | `you-super-markdown-v<版本>-full.tar.gz` | 全部文件 + `version.json` | `rsync --delete`（排除 data/） |
| 增量包 | `you-super-markdown-v<from>-to-v<to>-inc.tar.gz` | 仅变更文件 + `version.json`(`type=incremental`,`from`) | 覆盖式合并（不删多余文件） |

### 回滚

- 失败自动回滚：校验失败 → 从最近备份 rsync 还原 → 锁母本 → 重启守护 → 审计
- 手动：`sudo ym-admin rollback`（列出备份供选择）

### 备份策略

| 项目 | 方案 |
|------|------|
| 内容 | WEB_ROOT 全量（排除 data/） |
| 保留 | 最近 2 次 |
| 位置 | `/opt/you-markdown/backups/` |
| 命名 | `pre-update-v{版本}-{时间戳}.tar.gz` |

### 已知问题（v2.3.3 现状）

更新历史状态机为 `pending → in_progress → completed`，**暂无异常处理**：请求文件创建时带 10 分钟 `expires`，但没有任何代码消费该字段；`apply-update` 失败分支不写回状态（保持 in_progress），也不会标记 `failed`。表现为"更新历史一直显示 等待中/进行中"，需手动删除 `/tmp/ym-update-request.json` 恢复。建议后续在 `getUpdateStatus()` 中对过期请求自动标记失败，并在 `ym-admin` 失败分支写 `status=failed + error`。

***

## 十六、配置参考

### 16.1 app-config.json（应用级，随版本分发）

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `app_name` | `You Super Markdown` | 应用名称 |
| `version` | `2.3.3` | **版本唯一事实来源**（`APP_VERSION = appConfig('version')`） |
| `repo_owner` / `repo_name` / `repo_url` | 空 | 仓库信息（在线更新仓库拉取用，未配置则必须上传包） |
| `hfish_user` | `xiao` | Hfish 管理账户 |
| `hfish_ban_threshold` | `3` | 蜜罐攻击封禁阈值（次数） |
| `hfish_db_path` | `/usr/share/hfish/database/hfish.db` | 蜜罐数据库路径 |

### 16.2 data/.config.json（站点级，用户数据，更新包排除）

| 配置项 | 默认 | 说明 |
|--------|------|------|
| `site_title` | `You Super Markdown` | 网站标题 |
| `guest_comments_enabled` | `false` | 访客评论开关 |
| `registration_enabled` | `true` | 注册开关 |
| `comments_enabled` | `true` | 评论总开关 |
| `update_channel` | `stable` | 更新通道 |
| `bg_type` / `bg_image` / `bg_api_url` | `none` / 空 / 空 | 背景类型与源 |
| `bg_blur_enabled` / `bg_blur_level` | `false` / `0` | 背景模糊 |
| `bg_card_opacity` | `100` | 卡片透明度 |
| `auto_ban` | `true` | 自动封禁总开关 |
| `auto_ban_threshold` | `5` | 自动封禁阈值 |
| `auto_ban_unauthorized` | `false` | 越权自动封禁 |
| `reg_limit_per_ip` / `max_registrations_per_ip` | `3` | 每 IP 注册限制 |
| `comment_rate_limit` / `max_comments_per_minute` | `3`/`5` | 每分钟评论限制 |
| `max_login_fails` | `10` | 1 小时内登录失败封禁阈值 |
| `station_path` / `author_path` | `station` / `author` | 后台路径前缀 |
| `hide_default_paths` | `true` | 隐藏默认路径 |
| `admin_email` | 空 | 告警邮箱（配合 `/usr/local/bin/ym-alert`） |
| `music_playlist_id` | `3778678` | 播放器歌单 ID |
| `music_cookies` | 空 | **音乐播放器开关**：非空才显示播放器入口（v2.3.3） |

### 16.3 常见路径常量（代码内）

| 常量 | 值 |
|------|-----|
| `APP_CONFIG_FILE` | `<web>/app-config.json` |
| `AUDIT_LOG_FILE` / `AUDIT_CHAIN_FILE` | `<web>/data/.audit.json` / `.audit_chain` |
| `AUDIT_MIRROR_DIR` | `/opt/you-markdown/logs/` |
| `UPDATE_REQUEST_FILE` | `/tmp/ym-update-request.json` |
| `UPDATE_LOCK_FILE` | `/tmp/ym-update.lock` |
| `BACKUP_DIR` | `/opt/you-markdown/backups` |
| `EMAIL_ALERT` | `/usr/local/bin/ym-alert` |

***

## 十七、常见问题（FAQ）

**Q: 忘记了超管入口/密码？**
A: 超管无固定密码，SSH 执行 `sudo ym-admin login` 生成新 OTP 入口即可。

**Q: 站长/写作者忘记密码？**
A: 超管后台 → 人员管理 → 重置密码。

**Q: 为什么首页没有音乐播放器？**
A: v2.3.3 起播放器默认隐藏。在超管后台系统配置中设置非空的 `music_cookies` 后刷新即显示。

**Q: 更新历史一直"等待中/进行中"？**
A: 当前版本的已知问题（见第十五章）：请求文件没有超时处理。SSH 执行 `sudo rm -f /tmp/ym-update-request.json /tmp/ym-update.lock` 即可恢复。

**Q: 挑战码老过期？**
A: 挑战码 300 秒有效、单次使用。若提示过期请重新 `sudo ym-admin challenge` 生成；确认服务器的 `/usr/local/bin/ym-admin` 是含 `+300 seconds` 的新版。

**Q: 守护进程不工作？**
```bash
systemctl status ym-guard
sudo systemctl restart ym-guard
sudo journalctl -u ym-guard -f
```

**Q: 如何恢复被篡改的首页？**
A: 守护进程会自动恢复。未运行时：`sudo cp /opt/you-markdown/install-base/index.php /var/www/you-markdown/index.php`。

**Q: 蜜罐多少次封禁？**
A: 默认 3 次（`hfish_ban_threshold`），超管后台「蜜罐安全」可见。

**Q: 如何从旧版本迁移？**
A: 1) 备份旧版 `data/`；2) 部署新版文件；3) 拷贝 `data/` 回新目录；4) `sudo ym-admin reset-admin` 重建超管。

**Q: 网站上名称还是旧名字？**
A: 网站标题由 `data/.config.json` 的 `site_title` 决定（更新包排除 data/，不会覆盖），需在超管后台「系统配置」修改。

**Q: 如何让文章置顶？**
A: 站长及以上在阅读页点置顶按钮（写入 `data/.pinned.json`）。

**Q: 播放器歌单怎么换？**
A: 改 `data/.config.json` 的 `music_playlist_id`（默认 3778678），或联系超管调整。

***

## 附：文档索引

| 文档 | 位置 | 用途 |
|------|------|------|
| 项目总览（本文档） | 工作区根目录 `README.md` | 介绍/使用/运维 |
| 开发规范 | 工作区根目录 `DEVELOPMENT_GUIDELINES.md` | 开发/接手/发布 |
| 网站引导 | `WELCOME.md` | 轻松风格首次引导 |
| 命令速查 | `COMMANDS.md` | 命令与功能速查 |
