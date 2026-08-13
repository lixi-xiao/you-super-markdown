# 🧰 You Super Markdown 命令与功能使用指南

嗨，又见面了！上一篇 WELCOME.md 带你把网站跑起来了，这一篇专门讲**各种命令和功能到底怎么用**。

放心，不整那些官方文档的弯弯绕，全是实操干货。看完这篇，你基本就能把这套系统玩明白了。

> 小提示：凡是"写操作"的命令（建号、备份、更新、改配置）都要在前面加 `sudo`；只读的（看状态、验日志）不用。记不住没关系，后面每一条都标清楚了。

***

## 一、CLI 命令全家桶（ym-admin）

`ym-admin` 是超管手里的瑞士军刀，在服务器 SSH 里敲。先说最重要的规则：

```
只读命令：ym-admin xxx          （不用 sudo）
写操作命令：sudo ym-admin xxx    （必须 sudo）
```

### 🔑 登录相关

**`sudo ym-admin login` — 生成超管登录入口**

超管没有固定密码，想进后台就先跑这个：

```bash
sudo ym-admin login
```

输出长这样：

```
入口 URL: https://你的域名/admin/entry/Xk7Mp2QvR9zN
一次性密码: Tx9#pL4!mW6y
```

然后浏览器打开 URL、输入 OTP 就进去了。注意：**10 分钟有效、只能用一次**，用完了再来一条，随用随生成。

### 👥 用户管理

**`sudo ym-admin create-station <名称>` — 创建站长**

```bash
sudo ym-admin create-station 张三
# 输出：QQ号 + 密码 + 角色(站长)
```

**`sudo ym-admin create-author <名称>` — 创建写作者**

```bash
sudo ym-admin create-author 李四
# 输出：QQ号 + 密码 + 角色(写作者)
```

**`sudo ym-admin revoke-user <用户ID>` — 吊销用户**

```bash
sudo ym-admin revoke-user a3b9f2c8d1e4
```

用户 ID 在哪看？超管后台「人员管理」列表里就有。吊销后这个人就登不进去了。

### 🛡️ 安全相关

**`sudo ym-admin challenge` — 生成挑战码**

做敏感操作（比如更新系统）时，后台会弹窗要一个"确认码"。跑这个生成：

```bash
sudo ym-admin challenge
# 挑战码: c418bd (300秒有效)
```

**300 秒够你慢慢输入了**，不像以前 60 秒赶死。用完作废，过期重新生成。

**`ym-admin log-verify` — 校验审计日志**

想确认日志没被人动过手脚？跑一下，哈希链一验便知：

```bash
ym-admin log-verify
# 审计日志哈希链校验通过 (N 条)
```

### 🔄 更新与备份

**`sudo ym-admin backup` — 备份数据**

把 `data/`（用户、文章、日志）打包存到 `/opt/you-markdown/backups/`：

```bash
sudo ym-admin backup
```

**`sudo ym-admin apply-update` — 执行更新**

这是更新流程的最后一步。**必须先**在后台"在线更新"页上传包 + 输入挑战码，生成更新请求后，再回来跑它：

```bash
sudo ym-admin apply-update
# 自动完成：备份 → 停守护 → 解锁母本 → 应用 → 锁定 → 重启 → 记日志
```

**`sudo ym-admin rollback` — 回滚**

更新翻车了？用它回到上一个备份版本：

```bash
sudo ym-admin rollback
# 列出可用备份，输入编号选一个回滚
```

### 📊 状态查看

**`ym-admin status` — 看服务器状态**

```bash
ym-admin status
# 守护进程: active
# Nginx: active
# PHP-FPM: active
# 用户数: 5
```

### 🍯 蜜罐相关（v2.3.3）

**`ym-admin hfish-status` — 看蜜罐状态**

```bash
ym-admin hfish-status
```

**`ym-admin hfish-panel` — 打开蜜罐管理面板**

它会帮你建立 SSH 隧道，然后在浏览器访问蜜罐管理界面（只监听本机，很安全）：

```bash
ym-admin hfish-panel
```

***

## 二、超管后台功能（一个个板块讲）

登录超管后台后，左侧边栏就是全部功能。从左到右过一遍：

| 板块 | 干嘛的 | 常用操作 |
|------|--------|---------|
| **人员管理** | 管账号 | 建/删站长、写作者，封禁/解封用户，看用户列表 |
| **日志** | 看记录 | 登录日志、越权日志、操作日志 |
| **系统配置** | 网站设置 | 改标题、注册开关、访客评论开关、更新通道 |
| **安全** | 封禁与权限 | 手动封禁 IP、解封、更新用户权限 |
| **背景** | 颜值担当 | 上传背景图 / 接 API / 纯色 + 模糊调节 |
| **在线更新** | 升级系统 | 检查更新、上传更新包、触发更新、回滚 |
| **守护进程** | 看门狗 | 看守护状态、手动触发校验、看母本状态 |
| **蜜罐安全** | 反侦察 | 看蜜罐攻击日志、立即同步、看封禁状态 |

### 在线更新（最容易卡壳的）

1. 点「上传更新包」，选全量包或增量包
2. 点「执行更新」→ 弹窗要挑战码
3. SSH 跑 `sudo ym-admin challenge` 拿码（300 秒）
4. 回弹窗输入 → 提示去 SSH 执行 `sudo ym-admin apply-update`
5. 跑完回后台刷新，版本号变了就成

> 记牢：上传完包之后**别刷新页面**，直接一路点到底，不然 package_path 会丢。

### 蜜罐安全（v2.3.3 新玩具）

- 能看到攻击者的 IP、攻击次数、扫了什么、用的什么 UA
- 攻击次数 ≥ 阈值（默认 3 次）自动封禁登录/注册/评论
- 点「立即同步」手动刷新一次（守护进程每 5 分钟也会自动同步）

***

## 三、站长后台 & 写作者后台

### 站长后台（/station/dashboard.php）

| 功能 | 说明 |
|------|------|
| 作者管理 | 创建/删除自己旗下的写作者 |
| 文章管理 | 看/改/删**所有**文章（含别人的） |

### 写作者后台（/author/dashboard.php）

| 功能 | 说明 |
|------|------|
| 我的文章 | 只能动**自己**的文章 |
| 创建文章 | 发新文章 |

权限边界一句话：**超管管一切 → 站长管团队和所有文章 → 写作者只管自己的**。

***

## 四、前台使用（普通用户视角）

- **发文章**：编辑器在 `sc.php`，Markdown 语法直接写
- **评论**：文章底部留言，支持回复、点赞（有频率限制防刷屏）
- **听歌**：QQ / 网易云播放器都在页面上
- **换背景**：超管在后台统一配，用户不用管

***

## 五、实用运维小命令（顺手收藏）

```bash
# 检查 PHP 文件有没有语法错误（改完代码必查）
php -l /var/www/you-markdown/admin/dashboard.php

# 看守护进程状态 / 日志
systemctl status ym-guard
sudo journalctl -u ym-guard -f

# 手动跑一次蜜罐同步（不依赖守护进程）
sudo python3 /var/www/you-markdown/ym-hfish-sync.py

# 手工验证审计日志链
ym-admin log-verify
```

***

## 六、高频操作速查表

| 我想… | 我该… |
|-------|-------|
| 进超管后台 | `sudo ym-admin login` → 打开 URL 输 OTP |
| 建一个站长 | `sudo ym-admin create-station 名字` |
| 建一个写作者 | `sudo ym-admin create-author 名字` |
| 踢掉一个用户 | 后台人员管理查 ID → `sudo ym-admin revoke-user ID` |
| 更新系统 | 后台上传包 → `sudo ym-admin challenge` → 输入 → `sudo ym-admin apply-update` |
| 更新翻车了 | `sudo ym-admin rollback` |
| 备份数据 | `sudo ym-admin backup` |
| 看服务器健康 | `ym-admin status` |
| 看日志有没有被篡改 | `ym-admin log-verify` |
| 看蜜罐抓了谁 | 后台「蜜罐安全」或 `ym-admin hfish-status` |
| 改网站标题 | 后台「系统配置」→ 网站标题 |

***

好了，命令和功能就这么些，多敲几遍就熟了。记住三个口诀：

1. **写操作加 sudo**，只读裸奔就行
2. **挑战码 300 秒**，别慌慢慢输
3. **data 别乱动**，它装着你所有的宝贝数据

遇到搞不定的，翻 WELCOME.md 或 README.md，或者回来找我。玩得开心！🚀
