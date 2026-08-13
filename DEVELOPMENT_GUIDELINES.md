# You Super Markdown 开发规范（DEVELOPMENT GUIDELINES）

> 面向所有参与 You Super Markdown 开发的团队成员，统一**代码提交、文件组织、更新管理、安全开发**等标准。本规范为唯一权威版本（存放于工作区根目录），主库 `test_development/DEVELOPMENT_GUIDELINES.md` 为同内容副本。违反规范可能导致更新包无法发布或引入安全风险，请严格遵守。

---

## 一、工作区结构

```
工作区\
├── README.md                      # 项目使用指南（v2.3.0）
├── DEVELOPMENT_GUIDELINES.md      # 本开发规范（唯一权威）
├── test_development\              # ★ 主库（唯一开发源，Git 管理）
│   ├── 全部源码 + ym-* 脚本 + app-config.json
│   └── .git\                      # Git 仓库（master 分支）
├── test_development copy\         # 备份副本（仅对照，不直接开发）
└── update-package\                # ★ 更新包统一存放目录（唯一）
    ├── version.json               # 最新版本号
    ├── you-super-markdown-v2.3.0-full.tar.gz
    ├── you-super-markdown-v2.2.1-to-v2.3.0-inc.tar.gz
    └── ...（历史包）
```

**原则**：开发只改主库 `test_development`；更新包只产出到 `update-package`；备份副本改动后须同步回主库；禁止在其他目录创建 `update-package`/`staging`/`inc-stage` 等打包目录。

---

## 二、代码提交规范（Git）

### 2.1 身份配置（已完成）

主库本地仓库已配置：`user.name = xiao`，`user.email = x071217ghi@163.com`。新克隆环境请执行：

```bash
git config --local user.name "你的名字"
git config --local user.email "你的邮箱"
```

### 2.2 提交信息格式（Conventional Commits）

```
<type>(<scope>): <subject>
```

| type | 用途 |
|------|------|
| `feat` | 新功能 |
| `fix` | 缺陷修复 |
| `docs` | 文档变更 |
| `refactor` | 重构（不改变行为） |
| `perf` | 性能优化 |
| `security` | 安全加固 |
| `chore` | 构建/工具/杂务 |
| `revert` | 回滚提交 |

示例：

```
feat(hfish): 新增超管后台蜜罐日志查看页签
fix(update): 修复上传包后 package_path 丢失导致更新失败
security(csrf): trigger_update 改用非消费型 token
docs: README 补充守护进程完整监控文件列表
```

### 2.3 提交前检查

1. **不得提交**：密码、密钥、`data/` 用户数据、`.pw.txt`、`*.pem`/`*.key`、`*.log`、调试文件（`debug*`/`test*`/`entry_fixed*` 等）、构建产物（`*.tar.gz`/`*.zip`/`staging/`/`inc-stage/`）
2. **语法自检**：PHP 用 `php -l <file>`；Shell 用 `bash -n <file>`；Python 用 `python3 -m py_compile <file>`
3. **一次提交一件事**，不要混入无关改动
4. **版本一致性**：`app-config.json` 的 `version`、README 标题、git tag、更新包版本必须一致

### 2.4 分支策略与 Tag

| 分支 | 用途 |
|------|------|
| `master` | 唯一可发布分支，受保护 |
| `dev` | 开发集成分支 |
| `feature/*` | 功能分支 |
| `fix/*` | 修复分支 |

流程：`feature/*` → `dev` → 评审 → `master`。发布时打 tag `v<主>.<次>.<修订>`。

---

## 三、文件组织规范

### 3.1 目录结构（稳定，只增不删）

```
you-markdown/
├── index.php / api.php / utils.php / sc.php / 404.php / music.php
├── admin/（entry.php + dashboard.php）   # 超管后台
├── station/dashboard.php                 # 站长后台
├── author/dashboard.php                  # 写作者后台
├── css/ js/ fonts/ music/                # 静态资源
├── data/                                 # 数据（Nginx 隔离，不入库）
├── ym-guard.py            # 文件守护（含蜜罐周期同步）
├── ym-hfish-sync.py       # 蜜罐同步与自动封禁
├── ym-install.sh          # 一键安装
├── ym-admin               # CLI 管理工具
├── app-config.json        # 全局配置（版本号/仓库/蜜罐）
└── nginx-site.conf
```

### 3.2 命名与新增文件

- PHP 文件小写下划线；工具脚本统一 `ym-` 前缀
- 新增文件先找归属目录，禁止根目录乱堆临时/调试文件
- 新增敏感文件确认被 `.gitignore` 覆盖
- **版本号唯一事实来源**：`app-config.json` 的 `version`，代码通过 `appConfig('version')` 读取，禁止硬编码

---

## 四、更新管理规范

### 4.1 更新包统一存放

所有更新包只存放于 **`工作区\update-package`**，禁止在其他目录（含各源码目录内）创建 `update-package` 或类似目录。

### 4.2 命名规则

| 类型 | 命名 | 示例 |
|------|------|------|
| 全量包 | `you-super-markdown-v<版本>-full.tar.gz` | `you-super-markdown-v2.3.0-full.tar.gz` |
| 增量包 | `you-super-markdown-v<from>-to-v<to>-inc.tar.gz` | `you-super-markdown-v2.2.1-to-v2.3.0-inc.tar.gz` |

### 4.3 全量包要求

- 根目录含 `version.json`（`{"version":"x.y.z"}`）
- 含核心文件 `index.php`/`utils.php`
- **排除 `data/`**；打包前确认版本统一、无调试文件、无临时目录

### 4.4 增量包要求

- 仅含**变更文件** + `version.json`：`{"version":"<to>","type":"incremental","from":"<from>"}`
- 应用方式为**覆盖式合并**（不删除目标多余文件）
- 生成前用 MD5/`git diff` 确认变更清单准确

### 4.5 更新流程

```
后台触发（上传更新包）
  → 上传包记录 package_path（/tmp/ym-update-packages/ 开头）
  → SSH: sudo ym-admin challenge（生成 6 位挑战码，300 秒有效）
  → 后台输入挑战码（trigger_update 提交 package_path，创建请求）
  → SSH: sudo ym-admin apply-update
      （备份→停守护→解锁母本→应用→锁定→重启→审计）
  → 后台轮询确认成功
```

**要点**：
- 请求文件 `/tmp/ym-update-request.json` 创建后须 `chmod 0666`；`apply-update` 写前先 `chmod 666`（容器环境 root 权限受限）
- `trigger_update`/`upload_package` 用**非消费型** CSRF（`checkCsrfToken`），避免同页连续操作冲突

### 4.6 版本升级步骤

1. 改 `app-config.json` 版本号
2. 同步 README 标题、`ym-guard.py`、`ym-install.sh`、`css/admin.css` 版本注释
3. 打包全量/增量到 `update-package`
4. 更新 `update-package/version.json`
5. git tag + 推送

---

## 五、安全开发规范（强制）

1. **角色鉴权**：每请求先读角色再判权限，禁止绕过 `checkRole()`/`validateJWT()`
2. **CSRF**：POST 表单/AJAX 必须带 token；可多次操作用 `checkCsrfToken()`，单次高敏用 `verifyCsrfToken()`，不得混用冲突
3. **挑战码**：300 秒有效、单次使用；敏感操作（更新/用户管理/守护控制）必须验证
4. **审计日志**：所有操作走 `auditLog()`（哈希链），禁止绕过
5. **数据隔离**：`data/*.json` Nginx deny；日志/报错不输出敏感路径
6. **输入校验**：用户输入 `htmlspecialchars()`；上传路径白名单校验（`/tmp/ym-update-packages/` 前缀）
7. **文件写入**：`file_put_contents` 一律 `LOCK_EX`
8. **密钥**：不落盘、不入库；生产密码经 SSH/环境注入
9. **文件守护**：核心文件被篡改会从母本恢复（14 个监控文件，含三大后台），本地源码与服务器必须同步
10. **蜜罐**：蜜罐攻击达阈值（默认 3 次，`app-config.json` 的 `hfish_ban_threshold`）自动封禁登录/注册/评论；蜜罐库只读访问

---

## 六、代码风格

| 语言 | 规范 |
|------|------|
| PHP | PSR-12 兼容；`<?php` 起始；函数下划线命名；严格比较 `===` |
| Python | PEP8；`snake_case`；模块 docstring 注明版本号 |
| Shell | `set -euo pipefail`；变量加引号；非关键错误 `2>/dev/null || true` |
| SQL | 仅参数化查询，禁止拼接 |

注释：复杂逻辑必须写"为什么"；中文注释；禁止冗余注释。

---

## 七、测试规范

1. **部署**：`sudo bash ym-install.sh` 或 SSH 手动部署脚本
2. **功能**：三大后台核心操作、蜜罐页签、挑战码流程
3. **更新**：全量/增量包各执行一次完整流程，确认版本号、文件完整性、`data/` 保留
4. **攻击**：验证五层防御（OTP 入口/角色分权/审计哈希链/文件守护/数据隔离）
5. **回归**：登录、评论、文章、背景等旧功能不受影响
6. **打包前**：对 PHP 文件执行 `php -l` 语法检查（必查）

---

## 八、禁止事项

- ❌ 修改 `data/` 生产数据并提交 git
- ❌ 在源码目录创建 `update-package/`、`staging/`、`inc-stage/` 等打包目录
- ❌ 提交密码/密钥/日志/调试文件
- ❌ 绕过挑战码/CSRF/权限校验执行敏感操作
- ❌ 直接改服务器文件而不经更新流程（调试除外，事后必须通过更新包同步）
- ❌ 删除/重命名 `utils.php` 核心函数（`checkRole`/`checkCsrfToken`/`auditLog`/`appConfig` 等）
- ❌ 在 `dashboard.php` 的 tab `if/elseif` 链中提前 `endif`（曾导致超管后台白屏）

---

## 九、开发流程建议

1. 新建分支：`git checkout -b feature/xxx` 或 `fix/xxx`
2. 开发 → 本地自测（含 `php -l`）
3. 提交（Conventional Commits）→ 推送
4. 合并 `dev` 评审 → 合并 `master`
5. 发布：打 tag → 打包更新包到 `update-package` → 服务器走更新流程验证
